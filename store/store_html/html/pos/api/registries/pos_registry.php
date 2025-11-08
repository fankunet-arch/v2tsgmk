<?php
/**
 * Toptea Store - POS 统一 API 注册表
 * 迁移所有 store/html/pos/api/ 的逻辑
 * Version: 1.2.1 (Phase 4: Load StoreConfig & CupCode)
 * Date: 2025-11-08
 */

// 1. 加载所有 POS 业务逻辑函数 (来自 pos_repo.php)
require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_helper.php');

// 2. 定义门店端角色常量 (必须与 pos_api_core.php 一致)
if (!defined('ROLE_STORE_MANAGER')) {
    define('ROLE_STORE_MANAGER', 'manager');
}
if (!defined('ROLE_STORE_USER')) {
    define('ROLE_STORE_USER', 'staff');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/submit_order.php                     */
/* -------------------------------------------------------------------------- */
function handle_order_submit(PDO $pdo, array $config, array $input_data): void {
    // 依赖: ensure_active_shift_or_fail (来自 pos_helper.php)
    ensure_active_shift_or_fail($pdo);

    $json_data = $input_data; // 网关已解析
    
    if (empty($json_data['cart']) || !is_array($json_data['cart'])) {
        json_error('Cart data is missing or empty.', 400);
    }

    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    $user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
    
    $member_id  = isset($json_data['member_id']) ? (int)$json_data['member_id'] : null;
    $points_redeemed_from_payload = (int)($json_data['points_redeemed'] ?? 0);

    $couponCode = null;
    foreach (['coupon_code','coupon','code','promo_code','discount_code'] as $k) {
        if (!empty($json_data[$k])) { $couponCode = trim((string)$json_data[$k]); break; }
    }

    $payment_payload_raw = $json_data['payment'] ?? $json_data['payments'] ?? [];
    // 依赖: extract_payment_totals (来自 pos_repo.php)
    [, , , $sumPaid, $payment_summary] = extract_payment_totals($payment_payload_raw);

    // 依赖: get_store_config_full (来自 pos_repo.php)
    $store_config = get_store_config_full($pdo, $store_id);
    $vat_rate = (float)($store_config['default_vat_rate'] ?? 21.0);

    // 依赖: PromotionEngine (来自 pos_helper.php)
    $engine = new PromotionEngine($pdo);
    $promoResult = $engine->applyPromotions($json_data['cart'], $couponCode);
    $cart = $promoResult['cart'];
    $discount_from_promo = (float)($promoResult['discount_amount'] ?? 0.0);
    $final_total_after_promo = (float)($promoResult['final_total'] ?? 0.0);

    $pdo->beginTransaction();

    // 积分抵扣
    $points_discount_final = 0.0;
    $points_to_deduct = 0;
    if ($member_id && $points_redeemed_from_payload > 0) {
        $stmt_member = $pdo->prepare("SELECT points_balance FROM pos_members WHERE id = ? AND is_active = 1 FOR UPDATE");
        $stmt_member->execute([$member_id]);
        if ($m = $stmt_member->fetch(PDO::FETCH_ASSOC)) {
            $current_points = (int)$m['points_balance'];
            $max_possible_discount = $final_total_after_promo;
            $max_points_for_discount = (int)floor($max_possible_discount * 100);
            $points_to_deduct = min($points_redeemed_from_payload, $current_points, $max_points_for_discount);
            if ($points_to_deduct > 0) $points_discount_final = $points_to_deduct / 100.0;
            else $points_to_deduct = 0;
        }
    }

    $final_total = round($final_total_after_promo - $points_discount_final, 2);
    $discount_amount = round($discount_from_promo + $points_discount_final, 2);

    if ($sumPaid < $final_total - 0.01) {
        $pdo->rollBack();
        json_error('Payment breakdown does not match final total.', 422, [
          'final_total'=>$final_total,
          'sum_paid'=>$sumPaid,
        ]);
    }
    
    // 积分扣减与累计
    if ($member_id && $points_to_deduct > 0 && $points_discount_final > 0) {
        $pdo->prepare("UPDATE pos_members SET points_balance = points_balance - ? WHERE id = ?")
            ->execute([$points_to_deduct, $member_id]);
        $pdo->prepare("INSERT INTO pos_member_points_log (member_id, invoice_id, points_change, reason_code, notes, user_id)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$member_id, null, -$points_to_deduct, 'REDEEM_DISCOUNT', "兑换抵扣 {$points_discount_final} EUR", $user_id]);
    }
    if ($member_id && $final_total > 0) {
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM pos_settings WHERE setting_key = 'points_euros_per_point'");
        $settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $euros_per_point = isset($settings['points_euros_per_point']) ? (float)$settings['points_euros_per_point'] : 1.0;
        if ($euros_per_point <= 0) $euros_per_point = 1.0;
        
        $points_to_add = (int)floor($final_total / $euros_per_point);
        if ($points_to_add > 0) {
            $pdo->prepare("UPDATE pos_members SET points_balance = points_balance + ? WHERE id = ?")
                ->execute([$points_to_add, $member_id]);
            $pdo->prepare("INSERT INTO pos_member_points_log (member_id, invoice_id, points_change, reason_code, user_id)
                         VALUES (?,?,?,?,?)")
                ->execute([$member_id, null, $points_to_add, 'PURCHASE', $user_id]);
        }
    }

    // 检查是否需要开票
    // 依赖: is_invoicing_enabled (来自 pos_helper.php)
    if (!is_invoicing_enabled($store_config)) {
        $pdo->commit();
        json_ok(['invoice_id' => null, 'invoice_number' => 'NO_INVOICE', 'qr_content' => null], 'Order processed without invoice.');
    }

    // --- 开票流程 ---
    $compliance_system = $store_config['billing_system'];
    
    // --- [PHASE 3a MODIFIED] ---
    // 
    // 依赖: allocate_invoice_number (来自 pos_repo.php)
    // 传递 $store_config['invoice_prefix'] 而不是 $store_config
    if (empty($store_config['invoice_prefix'])) {
         $pdo->rollBack();
         json_error('开票失败：门店缺少票号前缀 (invoice_prefix) 配置。', 412);
    }
    [$series, $invoice_number] = allocate_invoice_number(
        $pdo, 
        $store_config['invoice_prefix'], 
        $compliance_system
    );
    // --- [PHASE 3a END MOD] ---

    $compliance_data = null;
    $qr_payload = null;
    if ($compliance_system) {
        $handler_path = realpath(__DIR__ . "/../../../pos_backend/compliance/{$compliance_system}Handler.php");
        if ($handler_path && file_exists($handler_path)) {
            require_once $handler_path;
            $class = $compliance_system . 'Handler';
            if (class_exists($class)) {
                $issuer_nif = (string)$store_config['tax_id'];
                // [PHASE 3a] TICKETBAI/VERIFACTU 依赖于前一个 hash
                $stmt_prev = $pdo->prepare(
                    "SELECT compliance_data FROM pos_invoices 
                     WHERE compliance_system=:system AND series=:series AND issuer_nif=:nif 
                     ORDER BY `number` DESC LIMIT 1"
                );
                $stmt_prev->execute([':system'=>$compliance_system, ':series'=>$series, ':nif'=>$issuer_nif]);
                $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);
                $previous_hash = $prev ? (json_decode($prev['compliance_data'] ?? '[]', true)['hash'] ?? null) : null;
                
                $issued_at_micro = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s.u');
                $invoiceData = ['series'=>$series,'number'=>$invoice_number,'issued_at'=>$issued_at_micro,'final_total'=>$final_total];
                $handler = new $class();
                $compliance_data = $handler->generateComplianceData($pdo, $invoiceData, $previous_hash);
                if (is_array($compliance_data)) $qr_payload = $compliance_data['qr_content'] ?? null;
            }
        }
    }

    $issued_at = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');
    $taxable_base = round($final_total / (1 + ($vat_rate / 100)), 2);
    $vat_amount   = round($final_total - $taxable_base, 2);

    $stmt_invoice = $pdo->prepare("
        INSERT INTO pos_invoices (invoice_uuid, store_id, user_id, shift_id, issuer_nif, series, `number`, issued_at, invoice_type, taxable_base, vat_amount, discount_amount, final_total, status, compliance_system, compliance_data, payment_summary) 
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt_invoice->execute([
        bin2hex(random_bytes(16)), $store_id, $user_id, $shift_id, (string)$store_config['tax_id'],
        $series, $invoice_number, $issued_at, 'F2', $taxable_base, $vat_amount, $discount_amount, $final_total,
        'ISSUED', $compliance_system, json_encode($compliance_data, JSON_UNESCAPED_UNICODE),
        json_encode($payment_summary, JSON_UNESCAPED_UNICODE)
    ]);
    $invoice_id = (int)$pdo->lastInsertId();

    if ($member_id) {
        $pdo->prepare("UPDATE pos_member_points_log SET invoice_id = ? WHERE user_id = ? AND invoice_id IS NULL ORDER BY id DESC LIMIT 2")
            ->execute([$invoice_id, $user_id]);
    }

    $sql_item = "INSERT INTO pos_invoice_items (
                   invoice_id, menu_item_id, variant_id, 
                   item_name, variant_name, 
                   item_name_zh, item_name_es, variant_name_zh, variant_name_es,
                   quantity, 
                   unit_price, unit_taxable_base, vat_rate, 
                   vat_amount, customizations
               ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt_item = $pdo->prepare($sql_item);

    // --- [PHASE 3b MOD] START: 打印任务 ---
    $print_jobs = [];
    $kitchen_items = []; // KDS厨房单的条目
    
    // [PHASE 3b] $series 已经是 {Prefix}Y{YY} (e.g., S1Y25)
    // $invoice_number 是序列号 (e.g., 1001)
    $full_invoice_number = $series . '-' . $invoice_number; // e.g., S1Y25-1001
    // [PHASE 3b] 使用纯数字 $invoice_number 作为取餐号
    $pickup_number_human = (string)$invoice_number; 

    $cup_index = 1; // [PHASE 3b] 杯序号计数器
    
    foreach ($cart as $i => $item) {
        $qty = max(1, (int)($item['qty'] ?? 1));
        $unit_price = (float)($item['final_price'] ?? $item['unit_price_eur'] ?? 0);
        $item_total = round($unit_price * $qty, 2);
        $item_tax_base_total = round($item_total / (1 + ($vat_rate / 100)), 2);
        $item_vat_amount = round($item_total - $item_tax_base_total, 2);
        $unit_tax_base = ($qty > 0) ? round($item_tax_base_total / $qty, 4) : 0;
        $custom = ['ice' => $item['ice'] ?? null, 'sugar' => $item['sugar'] ?? null, 'addons' => $item['addons'] ?? [], 'remark' => $item['remark'] ?? ''];
        
        $item_name_to_db = (string)($item['title'] ?? ($item['name'] ?? ''));
        $variant_name_to_db = (string)($item['variant_name'] ?? '');
        $item_name_zh_to_db = (string)($item['title_zh'] ?? '');
        $item_name_es_to_db = (string)($item['title_es'] ?? '');
        $variant_name_zh_to_db = (string)($item['variant_name_zh'] ?? '');
        $variant_name_es_to_db = (string)($item['variant_name_es'] ?? '');
        $menu_item_id_to_db = isset($item['product_id']) ? (int)$item['product_id'] : null;
        $variant_id_to_db = isset($item['variant_id']) ? (int)$item['variant_id'] : null;
        
        $stmt_item->execute([
            $invoice_id, $menu_item_id_to_db, $variant_id_to_db,
            $item_name_to_db, $variant_name_to_db,
            $item_name_zh_to_db, $item_name_es_to_db, $variant_name_zh_to_db, $variant_name_es_to_db,
            $qty, $unit_price, $unit_tax_base, $vat_rate, $item_vat_amount, 
            json_encode($custom, JSON_UNESCAPED_UNICODE)
        ]);

        // [PHASE 3b] 准备 KDS 厨房单 和 杯贴 的变量
        
        $customizations_parts = [];
        // TODO: 此处应按需从 $custom['ice'] 和 $custom['sugar'] 的 *code* 去查询翻译表
        if (!empty($custom['ice'])) $customizations_parts[] = 'Ice:' . $custom['ice']; 
        if (!empty($custom['sugar'])) $customizations_parts[] = 'Sugar:' . $custom['sugar'];
        if (!empty($custom['addons'])) $customizations_parts[] = '+' . implode(',+', $custom['addons']);
        $customization_detail_str = implode(' / ', $customizations_parts);
        
        // 厨房单条目
        $kitchen_items[] = [
            'item_name' => $item_name_zh_to_db,
            'variant_name' => $variant_name_zh_to_db,
            'customizations' => $customization_detail_str,
            'qty' => $qty,
            'remark' => (string)($custom['remark'] ?? ''),
        ];
        
        // [PHASE 3b] 依赖 pos_repo::get_cart_item_codes
        // $kds_codes = get_cart_item_codes($pdo, $item); // 依赖 cart.js (Phase 4.1)
        
        // [PHASE 4.1 修复] cart.js 尚未修改，我们先从 item 中读取已有的 kds_code
        $p_code = $item['product_code'] ?? 'NA';
        $cup_code = $item['cup_code'] ?? 'NA';
        $ice_code = $item['ice'] ?? 'NA';
        $sweet_code = $item['sugar'] ?? 'NA';

        // [PHASE 3b] 为每一杯生成一个杯贴任务
        for ($i_qty = 0; $i_qty < $qty; $i_qty++) {
            $kds_internal_id = $store_config['invoice_prefix'] . '-' . $invoice_number . '-' . $cup_index;
            
            $item_print_data = [
                // 计划书 3.1.B 定义的变量
                'pickup_number'    => $pickup_number_human, // e.g., "1001"
                'kds_id'           => $kds_internal_id, // e.g., "S1-1001-1"
                'store_prefix'     => $store_config['invoice_prefix'], // e.g., "S1"
                'invoice_sequence' => $invoice_number, // e.g., 1001
                'cup_index'        => $cup_index, // e.g., 1
                'product_code'     => $p_code,
                'cup_code'         => $cup_code,
                'ice_code'         => $ice_code,
                'sweet_code'       => $sweet_code,
                'cup_order_number' => $kds_internal_id, // 兼容旧模板
                // 附加变量
                'item_name'         => $item_name_to_db,
                'variant_name'      => $variant_name_to_db,
                'item_name_zh'      => $item_name_zh_to_db,
                'item_name_es'      => $item_name_es_to_db,
                'variant_name_zh'   => $variant_name_zh_to_db,
                'variant_name_es'   => $variant_name_es_to_db,
                'customization_detail' => $customization_detail_str,
                'remark'            => (string)($custom['remark'] ?? ''),
                'store_name'        => $store_config['store_name'] ?? ''
            ];

            $print_jobs[] = [
                'type'         => 'CUP_STICKER',
                'data'         => $item_print_data,
                'printer_role' => 'POS_STICKER' // <--- 关键：标记角色
            ];
            
            $cup_index++; // 递增杯序号
        }
    }

    // [PHASE 3b] 准备厨房单
    $kitchen_data = [
        'invoice_number' => $full_invoice_number,
        'issued_at'      => $issued_at,
        'items'          => $kitchen_items,
        // [PHASE 3b] 添加新变量
        'pickup_number' => $pickup_number_human,
        'invoice_full'  => $full_invoice_number,
    ];
    $print_jobs[] = [
        'type'         => 'KITCHEN_ORDER',
        'data'         => $kitchen_data,
        'printer_role' => 'KDS_PRINTER' // <--- 关键：标记角色
    ];

    // [PHASE 3b] 准备顾客小票
    $receipt_data = [
        'store_name'      => $store_config['store_name'] ?? '',
        'store_address'   => $store_config['store_address'] ?? '',
        'store_tax_id'    => $store_config['tax_id'] ?? '',
        'invoice_number'  => $full_invoice_number, // 旧变量 (兼容)
        'issued_at'       => $issued_at,
        'cashier_name'    => $_SESSION['pos_display_name'] ?? 'N/A',
        'qr_code'         => $qr_payload,
        'subtotal'        => number_format((float)($promoResult['subtotal'] ?? 0.0), 2),
        'discount_amount' => number_format($discount_amount, 2),
        'final_total'     => number_format($final_total, 2),
        'taxable_base'    => number_format($taxable_base, 2),
        'vat_amount'      => number_format($vat_amount, 2),
        'payment_methods' => '...', // TODO: 格式化 $payment_summary
        'change'          => number_format((float)($payment_summary['change'] ?? 0.0), 2),
        'items'           => $cart, // 模板引擎需要自己循环 cart
        // [PHASE 3b] 添加新变量
        'pickup_number'    => $pickup_number_human,
        'invoice_full'     => $full_invoice_number,
        'invoice_series'   => $series,
        'invoice_sequence' => $invoice_number,
    ];
    $print_jobs[] = [
        'type'         => 'RECEIPT',
        'data'         => $receipt_data,
        'printer_role' => 'POS_RECEIPT' // <--- 关键：标记角色
    ];
    // --- [PHASE 3b MOD] END: 打印任务 ---

    
    $pdo->commit();

    json_ok('Order created.',[
        'invoice_id'=>$invoice_id,
        'invoice_number'=>$full_invoice_number, // e.g., S1Y25-1001
        'qr_content'=>$qr_payload,
        'print_jobs' => $print_jobs
    ]);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/calculate_promotions.php              */
/* -------------------------------------------------------------------------- */
function handle_cart_calculate(PDO $pdo, array $config, array $input_data): void {
    $json_data = $input_data;
    if (!isset($json_data['cart'])) json_error('Cart data is missing.', 400);

    $cart = $json_data['cart'];
    $couponCode = $json_data['coupon_code'] ?? null;
    $member_id = isset($json_data['member_id']) ? (int)$json_data['member_id'] : null;
    $points_to_redeem = isset($json_data['points_to_redeem']) ? (int)$json_data['points_to_redeem'] : 0;
    
    // 依赖: PromotionEngine (来自 pos_helper.php)
    $engine = new PromotionEngine($pdo);
    $promoResult = $engine->applyPromotions($cart, $couponCode);
    
    $final_total = (float)$promoResult['final_total'];
    $points_discount = 0.0;
    $points_redeemed = 0;

    if ($member_id && $points_to_redeem > 0 && $final_total > 0) {
        $stmt_member = $pdo->prepare("SELECT points_balance FROM pos_members WHERE id = ? AND is_active = 1");
        $stmt_member->execute([$member_id]);
        $member = $stmt_member->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            $current_points = (float)$member['points_balance'];
            $max_possible_discount = $final_total;
            $max_points_for_discount = floor($max_possible_discount * 100);
            $points_can_be_used = min($points_to_redeem, $current_points, $max_points_for_discount);

            if ($points_can_be_used > 0) {
                $points_redeemed = $points_can_be_used;
                $points_discount = floor($points_can_be_used) / 100.0;
                $final_total -= $points_discount;
            }
        }
    }
    
    $total_discount_amount = (float)$promoResult['discount_amount'] + $points_discount;

    $result = [
        'cart' => $promoResult['cart'],
        'subtotal' => $promoResult['subtotal'],
        'discount_amount' => number_format($total_discount_amount, 2, '.', ''),
        'final_total' => number_format($final_total, 2, '.', ''),
        'points_redemption' => [
            'points_redeemed' => $points_redeemed,
            'discount_amount' => number_format($points_discount, 2, '.', '')
        ]
    ];

    json_ok($result, 'Promotions and points calculated successfully.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_shift_handler.php                 */
/* -------------------------------------------------------------------------- */
function handle_shift_status(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    
    $stmt_store = $pdo->prepare("SELECT eod_cutoff_hour FROM kds_stores WHERE id = ?");
    $stmt_store->execute([$store_id]);
    $eod_cutoff_hour = (int)($stmt_store->fetchColumn() ?: 3);
    $tzMadrid = new DateTimeZone(APP_TZ);
    $now_madrid = new DateTime('now', $tzMadrid);
    
    $today_cutoff_dt_madrid = (clone $now_madrid)->setTime($eod_cutoff_hour, 0, 0);
    $cutoff_dt_utc_str = (clone $today_cutoff_dt_madrid)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $stmt_any = $pdo->prepare(
        "SELECT s.id, s.user_id, s.start_time, u.display_name
         FROM pos_shifts s
         LEFT JOIN kds_users u ON s.user_id = u.id AND s.store_id = u.store_id
         WHERE s.store_id=? AND s.status='ACTIVE'
         ORDER BY s.id ASC LIMIT 1"
    );
    $stmt_any->execute([$store_id]);
    $active_shift = $stmt_any->fetch(PDO::FETCH_ASSOC);

    if (!$active_shift) {
        unset($_SESSION['pos_shift_id']);
        json_ok(['has_active_shift'=>false, 'ghost_shift_detected'=>false], 'No active shift.');
    }

    $is_ghost = ($active_shift['start_time'] < $cutoff_dt_utc_str);

    if ($is_ghost) {
        if ((int)$active_shift['user_id'] === $user_id) {
            unset($_SESSION['pos_shift_id']);
        }
        $ghost_start_dt_utc = new DateTime($active_shift['start_time'], new DateTimeZone('UTC'));
        json_ok([
            'has_active_shift' => false,
            'ghost_shift_detected' => true,
            'ghost_shift_user_name' => $active_shift['display_name'] ?? '未知员工',
            'ghost_shift_start_time' => $ghost_start_dt_utc->setTimezone($tzMadrid)->format('Y-m-d H:i') 
        ], 'Ghost shift detected.');
    } else {
        if ((int)$active_shift['user_id'] === $user_id) {
            $_SESSION['pos_shift_id'] = (int)$active_shift['id'];
            json_ok(['has_active_shift'=>true, 'shift'=>$active_shift], 'Active shift found for current user.');
        } else {
            unset($_SESSION['pos_shift_id']);
            json_ok(['has_active_shift'=>false, 'ghost_shift_detected'=>false], 'Another user shift is active.');
        }
    }
}
function handle_shift_start(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $starting_float = (float)($input_data['starting_float'] ?? -1);
    if ($starting_float < 0) json_error('Invalid starting_float.', 422);

    $now_utc_str = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $chk = $pdo->prepare("SELECT id FROM pos_shifts WHERE user_id=? AND store_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $chk->execute([$user_id, $store_id]);
    if ($existing_id = $chk->fetchColumn()) {
        $_SESSION['pos_shift_id'] = (int)$existing_id;
        if ($tx_started && $pdo->inTransaction()) $pdo->commit();
        json_ok(['shift_id' => $existing_id], 'Shift already active (reused).');
    }

    $chk_ghost = $pdo->prepare("SELECT id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' LIMIT 1 FOR UPDATE");
    $chk_ghost->execute([$store_id]);
    if ($chk_ghost->fetchColumn()) {
         if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
         json_error('Cannot start shift, another shift is still active.', 409);
    }

    $uuid = bin2hex(random_bytes(16));
    $ins = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $ins->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
    $shift_id = (int)$pdo->lastInsertId();

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    $_SESSION['pos_shift_id'] = $shift_id;

    json_ok('Shift started.',[
        'shift'=>[ 'id'=>$shift_id, 'start_time'=>$now_utc_str, 'starting_float'=>(float)$starting_float ]
    ]);
}
function handle_shift_end(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
    $counted_cash = (float)($input_data['counted_cash'] ?? -1);
    
    if ($shift_id <= 0) json_error('No active shift in session.', 400);
    if ($counted_cash < 0) json_error('Invalid counted_cash.', 422);

    $now_utc_str = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $lock = $pdo->prepare("SELECT id, start_time, starting_float FROM pos_shifts WHERE id=? AND user_id=? AND store_id=? AND status='ACTIVE' FOR UPDATE");
    $lock->execute([$shift_id, $user_id, $store_id]);
    $shift = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
        json_error('Active shift not found or already ended.', 404);
    }

    // 依赖: compute_expected_cash (来自 pos_repo.php)
    $totals = compute_expected_cash($pdo, $store_id, $shift['start_time'], $now_utc_str, (float)$shift['starting_float']);
    $expected_cash = (float)$totals['expected_cash'];
    $cash_diff     = round((float)$counted_cash - $expected_cash, 2);

    $upd = $pdo->prepare("UPDATE pos_shifts SET end_time=?, status='ENDED', counted_cash=? WHERE id=?");
    $upd->execute([$now_utc_str, $counted_cash, $shift_id]);

    if (table_exists($pdo, 'pos_eod_records')) {
        $ins = $pdo->prepare("INSERT INTO pos_eod_records
          (shift_id, store_id, user_id, started_at, ended_at, starting_float,
           cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([
            $shift_id, $store_id, $user_id, $shift['start_time'], $now_utc_str, (float)$totals['starting_float'],
            (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
            (float)$totals['cash_refunds'], $expected_cash, (float)$counted_cash, (float)$cash_diff
        ]);
        $eod_id = (int)$pdo->lastInsertId();
    } else {
        $eod_id = null;
    }

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    unset($_SESSION['pos_shift_id']);

    json_ok('Shift ended.',[
        'eod_id' => $eod_id,
        'eod' => [ 'shift_id' => $shift_id, 'started_at' => $shift['start_time'], 'ended_at' => $now_utc_str,
                   'starting_float' => $totals['starting_float'], 'cash_sales' => $totals['cash_sales'],
                   'cash_in' => $totals['cash_in'], 'cash_out' => $totals['cash_out'],
                   'cash_refunds' => $totals['cash_refunds'], 'expected_cash' => $totals['expected_cash'],
                   'counted_cash' => (float)$counted_cash, 'cash_diff' => $cash_diff ]
    ]);
}
function handle_shift_force_start(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $starting_float = (float)($input_data['starting_float'] ?? -1);
    if ($starting_float < 0) json_error('Invalid starting_float for new shift.', 422);

    $now_utc_str = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    
    $tx_started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

    $stmt_ghosts = $pdo->prepare("SELECT id, start_time, starting_float, user_id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' FOR UPDATE");
    $stmt_ghosts->execute([$store_id]);
    $ghosts = $stmt_ghosts->fetchAll(PDO::FETCH_ASSOC);

    if (empty($ghosts)) {
         if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
         json_error('No ghost shifts found. Please try starting a normal shift.', 404, ['redirect_action' => 'start']);
    }

    $closer_name = $_SESSION['pos_display_name'] ?? ('User #' . $user_id);

    foreach ($ghosts as $ghost) {
        $ghost_id = (int)$ghost['id'];
        // 依赖: compute_expected_cash (来自 pos_repo.php)
        $totals = compute_expected_cash($pdo, $store_id, $ghost['start_time'], $now_utc_str, (float)$ghost['starting_float']);
        
        $upd = $pdo->prepare(
            "UPDATE pos_shifts SET 
                end_time = ?, status = 'FORCE_CLOSED', counted_cash = NULL, expected_cash = ?, 
                cash_variance = NULL, payment_summary = ?, admin_reviewed = 0 
             WHERE id = ?"
        );
        $upd->execute([
            $now_utc_str,
            (float)$totals['expected_cash'],
            json_encode(['note' => 'Forcibly closed by ' . $closer_name]),
            $ghost_id
        ]);
        
        if (table_exists($pdo, 'pos_eod_records')) {
            $ins = $pdo->prepare("INSERT INTO pos_eod_records
              (shift_id, store_id, user_id, started_at, ended_at, starting_float,
               cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $ghost_id, $store_id, $ghost['user_id'], $ghost['start_time'], $now_utc_str, (float)$totals['starting_float'],
                (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
                (float)$totals['cash_refunds'], (float)$totals['expected_cash'], 0.00, 0.00
            ]);
        }
    }
    
    $uuid = bin2hex(random_bytes(16));
    $ins_new = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
    $ins_new->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
    $new_shift_id = (int)$pdo->lastInsertId();

    if ($tx_started && $pdo->inTransaction()) $pdo->commit();
    
    $_SESSION['pos_shift_id'] = $new_shift_id;
    
    json_ok('Ghost shifts closed and new shift started.', [
        'shift' => [ 'id' => $new_shift_id, 'start_time' => $now_utc_str, 'starting_float' => $starting_float ]
    ]);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_data_loader.php                   */
/* -------------------------------------------------------------------------- */
function handle_data_load(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);

    // [PHASE 4.1.A] 获取门店配置 (用于打印机)
    $store_config = get_store_config_full($pdo, $store_id);

    $categories_sql = "SELECT category_code AS `key`, name_zh AS label_zh, name_es AS label_es FROM pos_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC";
    $categories = $pdo->query($categories_sql)->fetchAll(PDO::FETCH_ASSOC);

    $gating_data = [ 'ice' => [], 'sweetness' => [] ];
    $ice_rules = $pdo->query("SELECT product_id, ice_option_id FROM kds_product_ice_options WHERE ice_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ice_rules as $rule) { $gating_data['ice'][(int)$rule['product_id']][] = (int)$rule['ice_option_id']; }
    $sweet_rules = $pdo->query("SELECT product_id, sweetness_option_id FROM kds_product_sweetness_options WHERE sweetness_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sweet_rules as $rule) { $gating_data['sweetness'][(int)$rule['product_id']][] = (int)$rule['sweetness_option_id']; }
    $managed_ice_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_ice_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_sweet_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_sweetness_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_ice_set = array_flip($managed_ice_products);
    $managed_sweet_set = array_flip($managed_sweet_products);

    // [PHASE 4.1.A] 修改 SQL, 增加 kc.cup_code
    $menu_sql = "
        SELECT 
            mi.id, mi.name_zh, mi.name_es, mi.image_url, pc.category_code,
            pv.id as variant_id, pv.variant_name_zh, pv.variant_name_es, pv.price_eur, pv.is_default,
            kp.product_code AS product_sku, kp.id AS kds_product_id,
            kc.cup_code AS cup_code,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        JOIN pos_item_variants pv ON mi.id = pv.menu_item_id
        JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN kds_products kp ON mi.product_code = kp.product_code
        LEFT JOIN kds_cups kc ON pv.cup_id = kc.id AND kc.deleted_at IS NULL
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL 
          AND mi.is_active = 1
          AND pv.deleted_at IS NULL
          AND pc.deleted_at IS NULL
        ORDER BY pc.sort_order, mi.sort_order, mi.id, pv.sort_order
    ";
    
    $stmt_menu = $pdo->prepare($menu_sql);
    $stmt_menu->execute([':store_id' => $store_id]);
    $results = $stmt_menu->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($results as $row) {
        $itemId = (int)$row['id'];
        if (!isset($products[$itemId])) {
            $kds_pid = $row['kds_product_id'] ? (int)$row['kds_product_id'] : null;
            $allowed_ice_ids = null;
            $allowed_sweetness_ids = null;
            if ($kds_pid) {
                if (isset($managed_ice_set[$kds_pid])) { $allowed_ice_ids = $gating_data['ice'][$kds_pid] ?? []; }
                if (isset($managed_sweet_set[$kds_pid])) { $allowed_sweetness_ids = $gating_data['sweetness'][$kds_pid] ?? []; }
            }
            $products[$itemId] = [
                'id' => $itemId, 'title_zh' => $row['name_zh'], 'title_es' => $row['name_es'],
                'image_url' => $row['image_url'], 'category_key' => $row['category_code'],
                'allowed_ice_ids' => $allowed_ice_ids, 'allowed_sweetness_ids' => $allowed_sweetness_ids,
                'is_sold_out' => (int)$row['is_sold_out'],
                'variants' => []
            ];
        }
        $products[$itemId]['variants'][] = [
            'id' => (int)$row['variant_id'], 
            'recipe_sku' => $row['product_sku'], // This is product_code
            'cup_code' => $row['cup_code'],   // [PHASE 4.1.A] 新增
            'name_zh' => $row['variant_name_zh'], 
            'name_es' => $row['variant_name_es'],
            'price_eur' => (float)$row['price_eur'], 
            'is_default' => (bool)$row['is_default']
        ];
    }
    
    try {
        $addons_sql = "
            SELECT addon_code AS `key`, name_zh AS label_zh, name_es AS label_es, price_eur 
            FROM pos_addons 
            WHERE is_active = 1 AND deleted_at IS NULL 
            ORDER BY sort_order ASC
        ";
        $addons = $pdo->query($addons_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $addons = []; }
    
    $ice_options_sql = "
        SELECT i.id, i.ice_code, it_zh.ice_option_name AS name_zh, it_es.ice_option_name AS name_es
        FROM kds_ice_options i
        LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN'
        LEFT JOIN kds_ice_option_translations it_es ON i.id = it_es.ice_option_id AND it_es.language_code = 'es-ES'
        WHERE i.deleted_at IS NULL ORDER BY i.ice_code ASC
    ";
    $ice_options = $pdo->query($ice_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    $sweetness_options_sql = "
        SELECT s.id, s.sweetness_code, st_zh.sweetness_option_name AS name_zh, st_es.sweetness_option_name AS name_es
        FROM kds_sweetness_options s
        LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN'
        LEFT JOIN kds_sweetness_option_translations st_es ON s.id = st_es.sweetness_option_id AND st_es.language_code = 'es-ES'
        WHERE s.deleted_at IS NULL ORDER BY s.sweetness_code ASC
    ";
    $sweetness_options = $pdo->query($sweetness_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    $redemption_rules = [];
    try {
        $rules_sql = "
            SELECT id, rule_name_zh, rule_name_es, points_required, reward_type, reward_value_decimal, reward_promo_id
            FROM pos_point_redemption_rules
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY points_required ASC
        ";
        $redemption_rules = $pdo->query($rules_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $redemption_rules = []; }

    $sif_declaration = '';
    try {
        $stmt_sif = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'sif_declaracion_responsable'");
        $stmt_sif->execute();
        $sif_declaration = $stmt_sif->fetchColumn();
        if ($sif_declaration === false) $sif_declaration = ''; 
    } catch (PDOException $e) { $sif_declaration = 'Error: No se pudo cargar la declaración.'; }

    $data_payload = [
        'store_config' => $store_config, // [PHASE 4.1.A] 新增
        'products' => array_values($products),
        'addons' => $addons,
        'categories' => $categories,
        'redemption_rules' => $redemption_rules,
        'ice_options' => $ice_options,
        'sweetness_options' => $sweetness_options,
        'sif_declaration' => $sif_declaration
    ];

    json_ok($data_payload);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_member_handler.php                */
/* -------------------------------------------------------------------------- */
function handle_member_find(PDO $pdo, array $config, array $input_data): void {
    $phone = trim($input_data['phone'] ?? $_GET['phone'] ?? '');
    if (empty($phone)) json_error('Phone number is required.', 400);

    $stmt = $pdo->prepare("
        SELECT m.*, ml.level_name_zh, ml.level_name_es
        FROM pos_members m
        LEFT JOIN pos_member_levels ml ON m.member_level_id = ml.id
        WHERE m.phone_number = ? AND m.deleted_at IS NULL
    ");
    $stmt->execute([$phone]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($member) {
        json_ok($member, 'Member found.');
    } else {
        json_error('Member not found.', 404);
    }
}
function handle_member_create(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? $input_data;
    $phone = trim($data['phone_number'] ?? '');

    if (empty($phone)) json_error('手机号为必填项。 (Phone number is required.)', 400);

    $stmt_check = $pdo->prepare("SELECT id FROM pos_members WHERE phone_number = ? AND deleted_at IS NULL");
    $stmt_check->execute([$phone]);
    if ($stmt_check->fetch()) json_error('此手机号已被注册。 (This phone number is already registered.)', 409);

    $first_name = !empty($data['first_name']) ? trim($data['first_name']) : null;
    $last_name = !empty($data['last_name']) ? trim($data['last_name']) : null;
    $email = !empty($data['email']) ? trim($data['email']) : null;
    $birthdate = !empty($data['birthdate']) ? trim($data['birthdate']) : null;
    
    if ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
         json_error('生日格式无效，请使用 YYYY-MM-DD 格式。', 400);
    }

    $stmt_insert = $pdo->prepare("
        INSERT INTO pos_members (member_uuid, phone_number, first_name, last_name, email, birthdate)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $uuid = bin2hex(random_bytes(16));
    $stmt_insert->execute([$uuid, $phone, $first_name, $last_name, $email, $birthdate]);
    
    $new_member_id = $pdo->lastInsertId();
    
    $stmt_get = $pdo->prepare("SELECT * FROM pos_members WHERE id = ?");
    $stmt_get->execute([$new_member_id]);
    $new_member_data = $stmt_get->fetch(PDO::FETCH_ASSOC);

    json_ok($new_member_data, '新会员已成功创建！ (Member created successfully!)', 201);
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_hold_handler.php                  */
/* -------------------------------------------------------------------------- */
function handle_hold_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $sort_by = $_GET['sort'] ?? 'time_desc';
    $order_clause = 'created_at DESC';
    if ($sort_by === 'amount_desc') {
        $order_clause = 'total_amount DESC';
    }
    $stmt = $pdo->prepare("SELECT id, note, created_at, total_amount FROM pos_held_orders WHERE store_id = ? ORDER BY $order_clause");
    $stmt->execute([$store_id]);
    $held_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($held_orders, 'Held orders retrieved.');
}
function handle_hold_save(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $user_id = (int)$_SESSION['pos_user_id'];
    $note = trim($input_data['note'] ?? '');
    $cart_data = $input_data['cart'] ?? [];

    if (empty($note)) json_error('备注/桌号不能为空 (Note cannot be empty).', 400);
    if (empty($cart_data)) json_error('不能挂起一个空的购物车 (Cannot hold an empty cart).', 400);
    
    $total_amount = 0;
    foreach ($cart_data as $item) {
        $total_amount += ($item['unit_price_eur'] ?? 0) * ($item['qty'] ?? 1);
    }

    $stmt = $pdo->prepare("INSERT INTO pos_held_orders (store_id, user_id, note, cart_data, total_amount) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$store_id, $user_id, $note, json_encode($cart_data), $total_amount]);
    $new_id = $pdo->lastInsertId();
    json_ok(['id' => $new_id], 'Order held successfully.');
}
function handle_hold_restore(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $id = (int)($_GET['id'] ?? $input_data['id'] ?? 0);
    if (!$id) json_error('Invalid hold ID.', 400);

    $pdo->beginTransaction();
    $stmt_get = $pdo->prepare("SELECT cart_data FROM pos_held_orders WHERE id = ? AND store_id = ? FOR UPDATE");
    $stmt_get->execute([$id, $store_id]);
    $cart_json = $stmt_get->fetchColumn();
    
    if ($cart_json === false || empty($cart_json)) {
        $pdo->rollBack();
        json_error('Held order not found or is empty.', 404);
    }
    
    $cart_data = json_decode($cart_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $pdo->rollBack();
        json_error('Failed to parse held cart data.', 500);
    }

    $stmt_delete = $pdo->prepare("DELETE FROM pos_held_orders WHERE id = ?");
    $stmt_delete->execute([$id]);
    $pdo->commit();
    
    json_ok($cart_data, 'Order restored.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_transaction_handler.php           */
/* -------------------------------------------------------------------------- */
function handle_txn_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    $sql = "SELECT id, series, number, issued_at, final_total, status FROM pos_invoices WHERE store_id = :store_id";
    $params = [':store_id' => $store_id];

    if ($start_date && $end_date) {
        $end_date_obj = new DateTime($end_date);
        $end_date_obj->modify('+1 day');
        $end_date_exclusive = $end_date_obj->format('Y-m-d');
        
        $sql .= " AND issued_at >= :start_date AND issued_at < :end_date_exclusive";
        $params[':start_date'] = $start_date;
        $params[':end_date_exclusive'] = $end_date_exclusive;
    }
    $sql .= " ORDER BY issued_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($transactions, 'Transactions retrieved.');
}
function handle_txn_get_details(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_error('Invalid Invoice ID.', 400);

    $stmt_invoice = $pdo->prepare("
        SELECT pi.*, ku.display_name AS cashier_name
        FROM pos_invoices pi
        LEFT JOIN kds_users ku ON pi.user_id = ku.id
        WHERE pi.id = ? AND pi.store_id = ?
    ");
    $stmt_invoice->execute([$id, $store_id]);
    $invoice = $stmt_invoice->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) json_error('Invoice not found.', 404);

    $stmt_items = $pdo->prepare("SELECT * FROM pos_invoice_items WHERE invoice_id = ?");
    $stmt_items->execute([$id]);
    $invoice['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $invoice['payment_summary_decoded'] = json_decode($invoice['payment_summary'] ?? '[]', true);
    $invoice['compliance_data_decoded'] = json_decode($invoice['compliance_data'] ?? '[]', true);

    json_ok($invoice, 'Invoice details retrieved.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_print_handler.php                */
/* -------------------------------------------------------------------------- */
function handle_print_get_templates(PDO $pdo, array $config, array $input_data): void {
    // [MODIFIED 2c] 只使用 POS store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0) json_error('无法确定门店ID。', 401);

    $stmt = $pdo->prepare(
        "SELECT template_type, template_content, physical_size
         FROM pos_print_templates 
         WHERE (store_id = :store_id OR store_id IS NULL) AND is_active = 1
         ORDER BY store_id DESC"
    );
    $stmt->execute([':store_id' => $store_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $templates = [];
    foreach ($results as $row) {
        if (!isset($templates[$row['template_type']])) {
            $templates[$row['template_type']] = [
                'content' => json_decode($row['template_content'], true),
                'size' => $row['physical_size']
            ];
        }
    }
    json_ok($templates, 'Templates loaded.');
}
function handle_print_get_eod_data(PDO $pdo, array $config, array $input_data): void {
    // [MODIFIED 2c] 只使用 POS store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0) json_error('无法确定门店ID。', 401);
    
    $report_id = (int)($_GET['report_id'] ?? 0);
    if (!$report_id) json_error('无效的报告ID。', 400);

    $stmt = $pdo->prepare(
        "SELECT r.*, s.store_name, u.display_name as user_name
         FROM pos_eod_reports r
         LEFT JOIN kds_stores s ON r.store_id = s.id
         LEFT JOIN cpsys_users u ON r.user_id = u.id
         WHERE r.id = ? AND r.store_id = ?"
    );
    $stmt->execute([$report_id, $store_id]);
    $report_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report_data) json_error('未找到指定的日结报告。', 404);

    $report_data['print_time'] = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d H:i:s');
    
    foreach(['system_gross_sales', 'system_discounts', 'system_net_sales', 'system_tax', 'system_cash', 'system_card', 'system_platform', 'counted_cash', 'cash_discrepancy'] as $key) {
        if (isset($report_data[$key])) {
            $report_data[$key] = number_format((float)$report_data[$key], 2, '.', '');
        }
    }
    json_ok($report_data, 'EOD report data for printing retrieved.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/pos_availability_handler.php          */
/* -------------------------------------------------------------------------- */
function handle_avail_get_all(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $sql = "
        SELECT 
            mi.id AS menu_item_id, mi.name_zh, mi.name_es, mi.product_code,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL AND mi.is_active = 1
        ORDER BY mi.pos_category_id, mi.sort_order
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':store_id' => $store_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok($items, 'Status loaded.');
}
function handle_avail_toggle(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $menu_item_id = (int)($input_data['menu_item_id'] ?? 0);
    $is_sold_out = isset($input_data['is_sold_out']) ? (int)$input_data['is_sold_out'] : 0;
    if ($menu_item_id <= 0) json_error('Invalid menu_item_id.', 400);

    $sql = "
        INSERT INTO pos_product_availability (store_id, menu_item_id, is_sold_out, updated_at)
        VALUES (:store_id, :menu_item_id, :is_sold_out, NOW())
        ON DUPLICATE KEY UPDATE
            is_sold_out = VALUES(is_sold_out),
            updated_at = NOW()
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':store_id' => $store_id,
        ':menu_item_id' => $menu_item_id,
        ':is_sold_out' => $is_sold_out
    ]);
    json_ok(null, 'Status updated.');
}
function handle_avail_reset_all(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $stmt = $pdo->prepare("DELETE FROM pos_product_availability WHERE store_id = :store_id");
    $stmt->execute([':store_id' => $store_id]);
    json_ok(null, 'All items restocked.');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_summary_handler.php               */
/* -------------------------------------------------------------------------- */
function handle_eod_get_preview(PDO $pdo, array $config, array $input_data): void {
    $tzMadrid = new DateTimeZone('Europe/Madrid');
    $utc      = new DateTimeZone('UTC');
    $store_id = (int)($_SESSION['pos_store_id'] ?? 1);

    $target_business_date = null;
    $date_input = $_GET['target_business_date'] ?? $input_data['target_business_date'] ?? null;
    if ($date_input) {
        $d = DateTime::createFromFormat('Y-m-d', $date_input, $tzMadrid);
        if ($d !== false) $target_business_date = $d->format('Y-m-d');
    }
    if ($target_business_date === null) {
        $target_business_date = (new DateTime('today', $tzMadrid))->format('Y-m-d');
    }
    
    $bd_start_utc = (new DateTime($target_business_date . ' 00:00:00', $tzMadrid))->setTimezone($utc)->format('Y-m-d H:i:s');
    $bd_end_utc   = (new DateTime($target_business_date . ' 23:59:59', $tzMadrid))->setTimezone($utc)->format('Y-m-d H:i:s');

    $eod_table = 'pos_eod_reports';
    $sql_check = "SELECT * FROM `{$eod_table}` WHERE store_id=:sid AND report_date = :bd LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([':sid' => $store_id, ':bd' => $target_business_date]);
    $existing_report = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if ($existing_report) {
        json_ok(['is_submitted' => true, 'existing_report' => $existing_report]);
    }

    // 依赖: getInvoiceSummaryForPeriod (来自 pos_repo.php)
    $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $bd_start_utc, $bd_end_utc);
    
    $preview_data = [
        'transactions_count'   => $full_summary['summary']['transactions_count'],
        'system_gross_sales'   => $full_summary['summary']['system_gross_sales'],
        'system_discounts'     => $full_summary['summary']['system_discounts'],
        'system_net_sales'     => $full_summary['summary']['system_net_sales'],
        'system_tax'           => $full_summary['summary']['system_tax'],
        'payments'             => $full_summary['payments'],
        'report_date'          => $target_business_date,
        'is_submitted'         => false
    ];
    json_ok($preview_data);
}
function handle_eod_submit_report(PDO $pdo, array $config, array $input_data): void {
    $json_data = $input_data;
    $tzMadrid = new DateTimeZone('Europe/Madrid');
    $utc      = new DateTimeZone('UTC');
    $store_id = (int)($_SESSION['pos_store_id'] ?? 1);
    
    $target_business_date = null;
    $date_input = $_GET['target_business_date'] ?? $json_data['target_business_date'] ?? null;
    if ($date_input) {
        $d = DateTime::createFromFormat('Y-m-d', $date_input, $tzMadrid);
        if ($d !== false) $target_business_date = $d->format('Y-m-d');
    }
    if ($target_business_date === null) {
        $target_business_date = (new DateTime('today', $tzMadrid))->format('Y-m-d');
    }
    
    $bd_start_utc = (new DateTime($target_business_date . ' 00:00:00', $tzMadrid))->setTimezone($utc)->format('Y-m-d H:i:s');
    $bd_end_utc   = (new DateTime($target_business_date . ' 23:59:59', $tzMadrid))->setTimezone($utc)->format('Y-m-d H:i:s');
    $eod_table = 'pos_eod_reports';

    $sql_check = "SELECT * FROM `{$eod_table}` WHERE store_id=:sid AND report_date = :bd LIMIT 1";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute([':sid' => $store_id, ':bd' => $target_business_date]);
    if ($stmt_check->fetch(PDO::FETCH_ASSOC)) {
        json_error('该业务日已完成日结，不可重复提交。', 409);
    }

    $counted_cash = isset($json_data['counted_cash']) ? (float)$json_data['counted_cash'] : 0.0;
    $notes = isset($json_data['notes']) ? trim($json_data['notes']) : '';

    // 依赖: getInvoiceSummaryForPeriod (来自 pos_repo.php)
    $full_summary = getInvoiceSummaryForPeriod($pdo, $store_id, $bd_start_utc, $bd_end_utc);
    $summary = $full_summary['summary'];
    $payments_breakdown = $full_summary['payments'];
    
    $cash_discrepancy = $counted_cash - $payments_breakdown['Cash'];

    $pdo->beginTransaction();
    $sql_insert = "INSERT INTO `{$eod_table}` (
                       report_date, store_id, user_id, executed_at,
                       transactions_count, system_gross_sales, system_discounts, system_net_sales, system_tax,
                       system_cash, system_card, system_platform,
                       counted_cash, cash_discrepancy, notes
                   ) VALUES (
                       :report_date, :store_id, :user_id, NOW(),
                       :transactions_count, :system_gross_sales, :system_discounts, :system_net_sales, :system_tax,
                       :system_cash, :system_card, :system_platform,
                       :counted_cash, :cash_discrepancy, :notes
                   )";
    $stmt_insert = $pdo->prepare($sql_insert);
    $stmt_insert->execute([
        ':report_date' => $target_business_date,
        ':store_id' => $store_id,
        ':user_id' => (int)($_SESSION['pos_user_id'] ?? $json_data['user_id'] ?? 1),
        ':transactions_count' => $summary['transactions_count'],
        ':system_gross_sales' => $summary['system_gross_sales'],
        ':system_discounts' => $summary['system_discounts'],
        ':system_net_sales' => $summary['system_net_sales'],
        ':system_tax' => $summary['system_tax'],
        ':system_cash' => $payments_breakdown['Cash'],
        ':system_card' => $payments_breakdown['Card'],
        ':system_platform' => $payments_breakdown['Platform'],
        ':counted_cash' => $counted_cash,
        ':cash_discrepancy' => $cash_discrepancy,
        ':notes' => $notes
    ]);

    $pdo->commit();
    json_ok(null, '日结报告已成功提交。');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_list.php                          */
/* -------------------------------------------------------------------------- */
function handle_eod_list(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $limit = isset($_GET['limit']) ? max(1,min(200,(int)$_GET['limit'])) : 50;

    $sql = "SELECT id, shift_id, store_id, user_id,
                 started_at, ended_at,
                 starting_float, cash_sales, cash_in, cash_out, cash_refunds,
                 expected_cash, counted_cash, cash_diff, created_at
          FROM pos_eod_records
          WHERE store_id = ?
          ORDER BY id DESC
          LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$store_id, $limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    json_ok(['items'=>$rows, 'count'=>count($rows)], 'ok');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/eod_get.php                           */
/* -------------------------------------------------------------------------- */
function handle_eod_get(PDO $pdo, array $config, array $input_data): void {
    $store_id = (int)$_SESSION['pos_store_id'];
    $eod_id   = isset($_GET['eod_id']) ? (int)$_GET['eod_id'] : 0;
    if ($eod_id <= 0) json_error('Missing eod_id', 400);

    $stmt = $pdo->prepare("SELECT * FROM pos_eod_records WHERE id=? AND store_id=? LIMIT 1");
    $stmt->execute([$eod_id, $store_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) json_error('Record not found', 404);
    json_ok(['item'=>$row], 'OK');
}

/* -------------------------------------------------------------------------- */
/* Handlers: 迁移自 /pos/api/check_eod_status.php                  */
/* -------------------------------------------------------------------------- */
function handle_check_eod_status(PDO $pdo, array $config, array $input_data): void {
    $tzMadrid = new DateTimeZone('Europe/Madrid');
    $utc = new DateTimeZone('UTC');
    $yesterday_date_str = (new DateTime('yesterday', $tzMadrid))->format('Y-m-d');
    $store_id = (int)($_GET['store_id'] ?? $_SESSION['pos_store_id'] ?? 1);

    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM pos_eod_reports WHERE store_id = :store_id AND report_date = :report_date");
    $stmt_check->execute([':store_id' => $store_id, ':report_date' => $yesterday_date_str]);
    $report_exists = (int)$stmt_check->fetchColumn() > 0;

    if ($report_exists) {
        json_ok(['previous_day_unclosed' => false, 'unclosed_date' => null]);
    }

    $yesterday_start_utc = (new DateTime($yesterday_date_str . ' 00:00:00', $tzMadrid))->setTimezone($utc)->format('Y-m-d H:i:s');
    $yesterday_end_utc   = (new DateTime($yesterday_date_str . ' 23:59:59', $tzMadrid))->setTimezone($utc)->format('Y-m-d H:i:s');

    $stmt_invoice = $pdo->prepare(
        "SELECT 1 FROM pos_invoices WHERE store_id = :store_id AND issued_at BETWEEN :start_utc AND :end_utc LIMIT 1"
    );
    $stmt_invoice->execute([
        ':store_id' => $store_id,
        ':start_utc' => $yesterday_start_utc,
        ':end_utc' => $yesterday_end_utc
    ]);
    $invoice_exists = $stmt_invoice->fetchColumn() !== false;
    
    if ($invoice_exists) {
        json_ok(['previous_day_unclosed' => true, 'unclosed_date' => $yesterday_date_str]);
    } else {
        json_ok(['previous_day_unclosed' => false, 'unclosed_date' => null]);
    }
}


/* -------------------------------------------------------------------------- */
/* 注册表                                                   */
/* -------------------------------------------------------------------------- */
return [
    
    // POS: Order
    'order' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'submit' => 'handle_order_submit',
        ],
    ],
    
    // POS: Cart
    'cart' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'calculate' => 'handle_cart_calculate',
        ],
    ],

    // POS: Shift
    'shift' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'status' => 'handle_shift_status',
            'start' => 'handle_shift_start',
            'end' => 'handle_shift_end',
            'force_start' => 'handle_shift_force_start',
        ],
    ],
    
    // POS: Data Loader
    'data' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'load' => 'handle_data_load',
        ],
    ],
    
    // POS: Member
    'member' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'find' => 'handle_member_find',
            'create' => 'handle_member_create',
        ],
    ],
    
    // POS: Hold
    'hold' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list' => 'handle_hold_list',
            'save' => 'handle_hold_save',
            'restore' => 'handle_hold_restore',
        ],
    ],
    
    // POS: Transaction
    'transaction' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'list' => 'handle_txn_list',
            'get_details' => 'handle_txn_get_details',
        ],
    ],
    
    // POS: Print
    'print' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_templates' => 'handle_print_get_templates',
            'get_eod_data' => 'handle_print_get_eod_data',
        ],
    ],
    
    // POS: Availability (估清)
    'availability' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_all' => 'handle_avail_get_all',
            'toggle' => 'handle_avail_toggle',
            'reset_all' => 'handle_avail_reset_all',
        ],
    ],
    
    // POS: EOD (日结)
    'eod' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            'get_preview' => 'handle_eod_get_preview',
            'submit_report' => 'handle_eod_submit_report',
            'list' => 'handle_eod_list',
            'get' => 'handle_eod_get',
            'check_status' => 'handle_check_eod_status',
        ],
    ],
];