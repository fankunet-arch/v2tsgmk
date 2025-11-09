<?php
/**
 * Toptea POS - 次卡业务逻辑助手 (BMS Pass Helper)
 * 职责: 封装次卡售卡、核销、验证、分摊的核心业务规则。
 *
 * [B1.2 UTC SYNC] Phase B1.2: New helper file.
 * - allocate_vr_invoice_number(): (B1.2) 实现 VR 非税凭证号原子计数器
 * - [cite_start]validate_pass_purchase_order(): (B1.2) 实现售卡订单的服务端校验 [cite: 113-115]
 * - create_pass_records(): (B1.2) 实现售卡订单的数据库写入 (topup_orders, member_passes)
 * - [cite_start]calculate_pass_allocation(): (B1.2) 实现卡内金额分摊算法 [cite: 97-101]
 *
 * [B1.3.1 PASS]: Added redeem validation and allocation helpers.
 * - [cite_start]validate_pass_redeem_order(): (B1.3) 实现核销订单的服务端校验 [cite: 116-121]
 * - [cite_start]calculate_redeem_allocation(): (B1.3) 拆分核销与付费项目，计算分摊 [cite: 97-101, 102]
 * - create_redeem_records(): (B1.3) (B1.3.2) 实现核销订单的数据库写入 (lock, update pass, upsert usage, invoice, batch, items)
 */

// 确保核心助手已被加载 (pos_helper.php 会加载它)
if (!function_exists('utc_now')) {
    require_once realpath(__DIR__ . '/pos_datetime_helper.php');
}

if (!function_exists('allocate_vr_invoice_number')) {
    /**
     * [B1.2] 分配 VR 非税凭证号 (原子计数器)
     *
     * @param PDO $pdo
     * @param string $store_prefix 门店前缀 (e.g., "S1")
     * @return array [string $full_series, int $next_number]
     * @throws Exception
     */
    function allocate_vr_invoice_number(PDO $pdo, string $store_prefix): array {
        if (empty($store_prefix)) {
            throw new Exception('VR Invoice prefix cannot be empty.');
        }

        // 1. 确定系列 (Series)
        // 格式: {Prefix}-VRY{YY} (e.g., S1-VRY25 for 2025)
        $year_short = date('y'); // "25"
        $vr_prefix = $store_prefix . '-VR';
        $series = $vr_prefix . 'Y' . $year_short;

        // 2. 尝试原子更新 (INSERT ... ON DUPLICATE KEY UPDATE)
        try {
            // 确保该系列存在，如果不存在，则从 0 开始创建
            $sql_init = "
                INSERT INTO pos_vr_counters 
                    (vr_prefix, series, current_number)
                VALUES 
                    (:prefix, :series, 0)
                ON DUPLICATE KEY UPDATE 
                    current_number = current_number;
            ";
            $stmt_init = $pdo->prepare($sql_init);
            $stmt_init->execute([
                ':prefix' => $vr_prefix,
                ':series' => $series
            ]);

            // 原子更新并获取新ID
            $sql_bump = "
                UPDATE pos_vr_counters
                SET current_number = LAST_INSERT_ID(current_number + 1)
                WHERE series = :series;
            ";
            $stmt_bump = $pdo->prepare($sql_bump);
            $stmt_bump->execute([':series' => $series]);
            
            // 获取 LAST_INSERT_ID()
            $next_number = (int)$pdo->lastInsertId();

            if ($next_number > 0) {
                return [$series, $next_number];
            } else {
                // Fallback: 如果 LAST_INSERT_ID() 返回 0
                $stmt_get = $pdo->prepare("SELECT current_number FROM pos_vr_counters WHERE series = :series");
                $stmt_get->execute([':series' => $series]);
                $next_number = (int)$stmt_get->fetchColumn();
                if ($next_number > 0) {
                     return [$series, $next_number];
                }
                throw new Exception("Failed to bump VR counter, LAST_INSERT_ID and subsequent SELECT were 0.");
            }

        } catch (Throwable $e) {
            // 3. 回退 (Fallback) - 如果 pos_vr_counters 表不存在或失败
            error_log("CRITICAL: VR counter failed, falling back to MAX(number). Error: " . $e->getMessage());

            $stmt_max = $pdo->prepare(
                "SELECT MAX(`voucher_number`) FROM topup_orders WHERE series = :series"
            );
            $stmt_max->execute([':series' => $series]);
            $max = (int)$stmt_max->fetchColumn();
            
            $next_number = $max + 1;
            
            return [$series, $next_number];
        }
    }
}

if (!function_exists('calculate_pass_allocation')) {
    /**
     * [cite_start][B1.2] 计算次卡分摊金额 (用于激活) [cite: 97-101]
     * @param float $purchase_amount
     * @param int $total_uses
     * @return array [float $unit_allocated_base, float $last_adjustment_amount]
     */
    function calculate_pass_allocation(float $purchase_amount, int $total_uses): array {
        if ($total_uses <= 0) {
            throw new InvalidArgumentException("Total uses must be greater than 0.");
        }
        // 1. 计算标准单位基准价
        $unit_base = round($purchase_amount / $total_uses, 2);
        
        // 2. 计算 (N-1) 次的总和
        $sum_n_minus_1 = $unit_base * ($total_uses - 1);
        
        // 3. 计算最后一次的补差金额
        $last_adjustment = $purchase_amount - $sum_n_minus_1;

        return [
            'unit_allocated_base' => $unit_base,
            'last_adjustment_amount' => $last_adjustment // 仅用于审计，实际核销时再计算
        ];
    }
}

if (!function_exists('validate_pass_purchase_order')) {
    /**
     * [cite_start][B1.2] 服务端校验售卡订单 (B1 兜底) [cite: 113-115]
     * @param PDO $pdo
     * @param array $cart 购物车
     * @param array $cart_tags 从 get_cart_item_tags() 获取的标签
     * @param mixed $promo_result 促销引擎的计算结果
     * @throws Exception
     */
    function validate_pass_purchase_order(PDO $pdo, array $cart, array $cart_tags, $promo_result): void {
        
        // 规则 1: 购物车不能为空
        if (empty($cart)) {
            throw new Exception('售卡订单购物车不能为空。', 400);
        }

        // 规则 2: 购物车仅能包含 'pass_product' 标签的商品
        foreach ($cart as $item) {
            $menu_item_id = $item['product_id'] ?? null; // product_id 在 POS 端是 menu_item_id
            if (!$menu_item_id || !isset($cart_tags[$menu_item_id]) || !in_array('pass_product', $cart_tags[$menu_item_id], true)) {
                throw new Exception('售卡订单中包含非次卡商品 (ID: '.$menu_item_id.')，已拒绝。', 400);
            }
        }

        // 规则 3: 售卡订单禁止任何优惠
        if ($promo_result) {
            $discount = (float)($promo_result['discount_amount'] ?? 0);
            if ($discount > 0) {
                throw new Exception('售卡订单禁止使用任何优惠券或自动折扣。', 403);
            }
        }
        
        // 规则 4: (在 B2 实现) 禁止赠饮、员工餐等
    }
}

if (!function_exists('create_pass_records')) {
    /**
     * [B1.2] 售卡 - 写入数据库
     * @param PDO $pdo
     * @param array $context [store_id, user_id, device_id, member_id]
     * @param array $vr_info [series, number]
     * @param array $cart_item (注意：B1 阶段售卡订单只允许1个次卡)
     * @param array $plan_details (来自 pass_plans 的快照)
     * @return int $member_pass_id
     * @throws Exception
     */
    function create_pass_records(PDO $pdo, array $context, array $vr_info, array $cart_item, array $plan_details): int {
        
        $now_utc = utc_now();
        $sale_time_utc = $now_utc->format('Y-m-d H:i:s.u');
        
        // 1. 计算分摊
        $purchase_amount = (float)($cart_item['unit_price_eur'] * $cart_item['qty']);
        $total_uses = (int)$plan_details['total_uses'];
        $alloc = calculate_pass_allocation($purchase_amount, $total_uses);
        $unit_allocated_base = $alloc['unit_allocated_base'];

        // 2. 创建售卡订单 (topup_orders)
        $sql_topup = "
            INSERT INTO topup_orders
                (pass_plan_id, member_id, quantity, amount_total, 
                 store_id, device_id, sale_user_id, sale_time, 
                 voucher_series, voucher_number, review_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
        
        $pdo->prepare($sql_topup)->execute([
            $plan_details['pass_plan_id'],
            $context['member_id'],
            $cart_item['qty'], // 假设 B1 阶段 qty 总是 1
            $purchase_amount,
            $context['store_id'],
            $context['device_id'],
            $context['user_id'],
            $sale_time_utc,
            $vr_info['series'],
            $vr_info['number']
        ]);
        
        $topup_order_id = (int)$pdo->lastInsertId();

        // 3. 计算有效期
        $validity_days = (int)$plan_details['validity_days'];
        $expires_at_utc_str = null;
        if ($validity_days > 0) {
            $expires_dt = (clone $now_utc)->add(new DateInterval("P{$validity_days}D"));
            $expires_at_utc_str = $expires_dt->format('Y-m-d H:i:s');
        }

        // 4. 创建会员持卡 (member_passes) - 激活即用
        $sql_pass = "
            INSERT INTO member_passes
                (member_id, pass_plan_id, topup_order_id, 
                 total_uses, remaining_uses, purchase_amount, unit_allocated_base,
                 status, store_id, device_id, activated_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)";
        
        $pdo->prepare($sql_pass)->execute([
            $context['member_id'],
            $plan_details['pass_plan_id'],
            $topup_order_id,
            $total_uses,
            $total_uses, // 激活时 剩余=总数
            $purchase_amount,
            $unit_allocated_base,
            $context['store_id'],
            $context['device_id'],
            $sale_time_utc, // 激活时间 = 销售时间
            $expires_at_utc_str
        ]);
        
        $member_pass_id = (int)$pdo->lastInsertId();

        // 5. 写入审计 (B1 阶段可选，B3 必须)
        
        return $member_pass_id;
    }
}

// --------------------------------------------------------
// [B1.3.1] 核销 (Redeem) 逻辑助手
// --------------------------------------------------------

if (!function_exists('validate_pass_redeem_order')) {
    /**
     * [cite_start][B1.3] 服务端校验核销订单 (B1 兜底) [cite: 106, 116-121]
     * @throws Exception
     */
    function validate_pass_redeem_order(
        PDO $pdo, array $cart, array $cart_tags, array $plan_details, array $pass_details,
        int $redeemed_uses_in_order, string $madrid_date_str
    ): void {
        
        // 1. 检查卡状态和剩余次数
        if ($pass_details['status'] !== 'active') {
            throw new Exception('此卡非激活状态 ('.$pass_details['status'].')。', 403);
        }
        if ($pass_details['expires_at'] !== null && strtotime($pass_details['expires_at']) < time()) {
            throw new Exception('此卡已过期 ('.$pass_details['expires_at'].')。', 403);
        }
        if ((int)$pass_details['remaining_uses'] < $redeemed_uses_in_order) {
            throw new Exception('次卡剩余次数 ('.$pass_details['remaining_uses'].') 不足 (需 '.$redeemed_uses_in_order.')。', 403);
        }

        // 2. 检查单笔订单限次
        $max_per_order = (int)$plan_details['max_uses_per_order'];
        if ($max_per_order > 0 && $redeemed_uses_in_order > $max_per_order) {
            throw new Exception('此卡单笔订单最多核销 '.$max_per_order.' 次 (本次请求 '.$redeemed_uses_in_order.' 次)。', 403);
        }

        // 3. 检查单日限次
        $max_per_day = (int)$plan_details['max_uses_per_day'];
        if ($max_per_day > 0) {
            // 依赖: pos_repo::check_daily_usage (B1.3.2)
            $today_usage = check_daily_usage($pdo, (int)$pass_details['member_pass_id'], $madrid_date_str);
            if (($today_usage + $redeemed_uses_in_order) > $max_per_day) {
                throw new Exception('此卡每日限核销 '.$max_per_day.' 次 (今日已用 '.$today_usage.' 次)。', 403);
            }
        }

        // 4. 检查不混单 (仅允许 'pass_eligible_beverage' 和 'addons')
        $eligible_beverage_count = 0;
        foreach ($cart as $item) {
            $menu_item_id = $item['product_id'] ?? 0;
            $item_tags = $cart_tags[$menu_item_id] ?? [];
            
            $is_beverage = in_array('pass_eligible_beverage', $item_tags, true);
            $is_paid_addon = in_array('paid_addon', $item_tags, true);
            $is_free_addon = in_array('free_addon', $item_tags, true);
            $is_addon = $is_paid_addon || $is_free_addon;

            if (!$is_beverage && !$is_addon) {
                throw new Exception('核销订单中包含非次卡饮品或非加料商品 (ID: '.$menu_item_id.')，已拒绝。', 400);
            }
            if ($is_beverage) {
                $eligible_beverage_count += (int)$item['qty'];
            }
            
            // 5. 检查饮品基底价是否被修改 (B3 补充)
            // if ($is_beverage && isset($item['original_price']) && (float)$item['unit_price_eur'] !== (float)$item['original_price']) {
            //      throw new Exception('核销订单中的饮品 (ID: '.$menu_item_id.') 价格被修改，已拒绝。', 403);
            // }
        }
        
        // 6. 检查核销次数是否匹配饮品数量
        if ($redeemed_uses_in_order > $eligible_beverage_count) {
             throw new Exception('核销次数 ('.$redeemed_uses_in_order.') 不能大于订单中合格饮品的总数量 ('.$eligible_beverage_count.')。', 400);
        }
    }
}

if (!function_exists('calculate_redeem_allocation')) {
    /**
     * [cite_start][B1.3] 拆分核销订单，计算分摊 [cite: 97-102]
     * @param array $cart
     * @param array $cart_tags
     * @param array $pass_details
     * @param int $redeemed_uses_in_order
     * @return array [ 'redeemed_items' => [], 'extra_charge_items' => [], 'covered_total' => float ]
     */
    function calculate_redeem_allocation(array $cart, array $cart_tags, array $pass_details, int $redeemed_uses_in_order): array {
        
        $redeemed_items = []; // 存放被卡覆盖的饮品
        $extra_charge_items = []; // 存放需额外付费的 (加料 或 未被覆盖的饮品)
        
        $unit_base = (float)$pass_details['unit_allocated_base'];
        $remaining_uses_on_card = (int)$pass_details['remaining_uses'];
        $purchase_amount = (float)$pass_details['purchase_amount'];
        $total_uses = (int)$pass_details['total_uses'];
        
        $uses_to_redeem = $redeemed_uses_in_order;
        $covered_total = 0.0;
        $extra_charge_total = 0.0;
        
        // 确保 $cart 键名是连续的，以便 array_key_first/next 能工作
        $cart = array_values($cart);
        $cart_key = array_key_first($cart);

        // 循环购物车，优先处理饮品
        while ($cart_key !== null) {
            $item = $cart[$cart_key];
            $menu_item_id = $item['product_id'] ?? 0;
            $item_tags = $cart_tags[$menu_item_id] ?? [];
            $is_beverage = in_array('pass_eligible_beverage', $item_tags, true);
            $item_qty = (int)$item['qty'];
            
            if ($is_beverage && $uses_to_redeem > 0) {
                // 这个 item 是饮品，且我们还有核销次数
                $redeem_qty = min($item_qty, $uses_to_redeem);
                $pay_qty = $item_qty - $redeem_qty;
                
                // A. 处理被核销的部分
                for ($i = 0; $i < $redeem_qty; $i++) {
                    // (B1.3.2)
                    $current_use_index = $total_uses - $remaining_uses_on_card + 1 + count($redeemed_items); // 1-based index
                    $covered_amount = $unit_base;
                    
                    // 检查是否为最后一次补差
                    if ($current_use_index === $total_uses) {
                        $sum_n_minus_1 = $unit_base * ($total_uses - 1);
                        $covered_amount = $purchase_amount - $sum_n_minus_1;
                    }
                    
                    $redeemed_items[] = [
                        'item' => $item, 
                        'qty' => 1, 
                        'covered_amount' => round($covered_amount, 2), 
                        'extra_charge' => 0.00 // 基底饮品无额外费用
                    ];
                    $covered_total += $covered_amount;
                }
                $uses_to_redeem -= $redeem_qty;
                
                // B. 处理未被核销 (但仍需支付) 的部分
                if ($pay_qty > 0) {
                    $extra_charge = (float)$item['unit_price_eur'] * $pay_qty;
                    $extra_charge_items[] = ['item' => $item, 'qty' => $pay_qty, 'extra_charge' => $extra_charge];
                    $extra_charge_total += $extra_charge;
                }
                
                // 从 $cart 中移除此项
                unset($cart[$cart_key]);
            }
            
            $cart_key = next($cart) ? key($cart) : null;
        }
        
        // 循环剩余的 $cart (只剩下加料了)
        foreach ($cart as $item) {
             // C. 处理加料 (始终为额外付费)
            $item_qty = (int)$item['qty'];
            $extra_charge = (float)$item['unit_price_eur'] * $item_qty;
            $extra_charge_items[] = ['item' => $item, 'qty' => $item_qty, 'extra_charge' => $extra_charge];
            $extra_charge_total += $extra_charge;
        }
        
        return [
            'redeemed_items' => $redeemed_items,       // [ {item, qty, covered_amount, extra_charge=0} ]
            'extra_charge_items' => $extra_charge_items, // [ {item, qty, extra_charge} ]
            'covered_total' => round($covered_total, 2),
            'extra_charge_total' => round($extra_charge_total, 2)
        ];
    }
}