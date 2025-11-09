<?php
/**
 * Toptea POS - API 注册表扩展 - 次卡 (BMS Pass)
 * 职责: 存放次卡相关的处理器函数 (e.g., 核销), 避免 pos_registry.php 膨胀。
 *
 * [B1.3.1 PASS] Phase B1.3.1: New handler extension file.
 * - handle_pass_redeem(): (B1.3) 实现核销 (redeem) 逻辑
 */

// 1. 确保核心助手已加载 (pos_helper.php 已在 gateway 加载)
if (!function_exists('validate_pass_redeem_order')) {
    // 明确依赖次卡业务助手
    require_once realpath(__DIR__ . '/../../../../pos_backend/helpers/pos_pass_helper.php');
}

if (!function_exists('handle_pass_redeem')) {
    /**
     * [B1.3] 处理核销请求 (POST /api/pos_api_gateway.php?res=pass&act=redeem)
     *
     * 接收 JSON 载荷:
     * {
     * "cart": [ ... ], // 购物车 (包含待核销饮品 + 付费加料)
     * "payment": { ... }, // 支付详情 (仅用于支付 extra_charge)
     * "member_id": 123,
     * "member_pass_id": 456,
     * "redeemed_uses_in_order": 2 // 本单希望核销的次数
     * }
     */
    function handle_pass_redeem(PDO $pdo, array $config, array $input_data): void {
        
        // 1. 验证班次 (依赖: shift_guard.php)
        ensure_active_shift_or_fail($pdo);

        // 2. 获取上下文
        $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
        $user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
        $device_id = (string)($input_data['device_id'] ?? null);
        $context = [
            'store_id' => $store_id,
            'user_id' => $user_id,
            'device_id' => $device_id,
            'shift_id' => (int)($_SESSION['pos_shift_id'] ?? 0)
        ];

        // 3. 解析载荷
        $cart        = $input_data['cart'] ?? [];
        $payment_raw = $input_data['payment'] ?? [];
        $member_id   = (int)($input_data['member_id'] ?? 0);
        $member_pass_id = (int)($input_data['member_pass_id'] ?? 0);
        $redeemed_uses_in_order = (int)($input_data['redeemed_uses_in_order'] ?? 0);
        
        if (empty($cart)) json_error('购物车不能为空。', 400);
        if ($member_id <= 0) json_error('必须绑定会员。', 400);
        if ($member_pass_id <= 0) json_error('必须指定要使用的次卡 (member_pass_id)。', 400);
        if ($redeemed_uses_in_order <= 0) json_error('核销次数必须大于 0。', 400);

        // 4. [A2 UTC] 确定本地营业日 (用于限次)
        // 依赖: datetime_helper.php
        $tz = new DateTimeZone(APP_DEFAULT_TIMEZONE);
        $madrid_now = new DateTime('now', $tz);
        $madrid_date_str = $madrid_now->format('Y-m-d'); // 统一使用 Y-m-d
        
        $pdo->beginTransaction();
        try {
            
            // 5. [B1.3] 锁定并获取次卡详情 (依赖: pos_repo.php)
            // (此函数将在 B1.3.2 中添加到 pos_repo.php)
            $pass_details = get_member_pass_for_update($pdo, $member_pass_id, $member_id);
            if (!$pass_details) {
                throw new Exception('次卡不存在或不属于该会员。', 404);
            }
            
            // 6. [B1.3] 获取次卡方案详情 (依赖: pos_repo.php)
            $plan_details = get_pass_plan_details($pdo, (int)$pass_details['pass_plan_id']);
            if (!$plan_details) {
                throw new Exception('次卡方案 (ID: '.$pass_details['pass_plan_id'].') 已失效。', 404);
            }

            // 7. [B1.3] 获取购物车所有商品的 Tags (依赖: pos_repo.php)
            $cart_menu_item_ids = array_map(fn($item) => $item['product_id'] ?? null, $cart);
            $cart_tags = get_cart_item_tags($pdo, $cart_menu_item_ids);

            // 8. [B1.3] 执行服务端硬校验 (依赖: pos_pass_helper.php)
            // (check_daily_usage 将在 B1.3.2 中添加到 pos_repo.php)
            validate_pass_redeem_order(
                $pdo, $cart, $cart_tags, $plan_details, $pass_details, 
                $redeemed_uses_in_order, $madrid_date_str
            );

            // 9. [B1.3] 拆分订单：哪些是核销的，哪些是付费的 (依赖: pos_pass_helper.php)
            $allocation_result = calculate_redeem_allocation(
                $cart, $cart_tags, $pass_details, $redeemed_uses_in_order
            );
            
            $extra_charge_total = (float)$allocation_result['extra_charge_total'];
            $final_total_payable = $extra_charge_total; // 核销单的最终应付金额 = 额外付费总和
            
            // 10. [B1.3] 校验支付金额 (仅针对 extra_charge)
            [, , , $sumPaid, $payment_summary] = extract_payment_totals($payment_raw);
            if ($sumPaid < $final_total_payable - 0.01) {
                throw new Exception(sprintf('支付金额 (%.2f) 不足 (应付: %.2f)。', $sumPaid, $final_total_payable), 422);
            }

            // 11. [B1.3] 获取门店配置 (用于 TP 票号)
            $store_config = get_store_config_full($pdo, $context['store_id']);
            if (empty($store_config['invoice_prefix'])) {
                 throw new Exception('开票失败：门店缺少票号前缀 (invoice_prefix) 配置。', 412);
            }

            // 12. [B1.3] 分配 TP 税票号 (沿用现有序列)
            [$series, $invoice_number] = allocate_invoice_number(
                $pdo, 
                $store_config['invoice_prefix'], 
                $store_config['billing_system'] // 使用 TP 系统
            );
            
            // 13. [A2 UTC] 获取开票时间
            $now_utc = utc_now();
            $issued_at_micro_utc_str = $now_utc->format('Y-m-d H:i:s.u');
            $issued_at_utc_str = $now_utc->format('Y-m-d H:i:s');

            // 14. [B1.3] 创建核销记录 (pos_invoices, batches, redemptions, member_passes, daily_usage)
            // (依赖: pos_pass_helper.php)
            // (此函数将在 B1.3.2 中添加到 pos_pass_helper.php)
            $invoice_id = create_redeem_records(
                $pdo, $context, $pass_details, $plan_details, $madrid_date_str,
                [
                    'series' => $series, 
                    'number' => $invoice_number, 
                    'issued_at_micro_utc' => $issued_at_micro_utc_str,
                    'issued_at_utc' => $issued_at_utc_str,
                    'payment_summary' => $payment_summary,
                    'store_config' => $store_config
                ],
                $allocation_result
            );

            // 15. [B1.3] (占位) 准备打印数据
            $print_jobs = []; // TODO B2

            // 16. 提交事务
            $pdo->commit();
            
            json_ok('核销成功', [
                'invoice_id' => $invoice_id,
                'invoice_number' => $series . '-' . $invoice_number,
                'qr_content' => null, // TODO B1.3 (Tax)
                'print_jobs' => $print_jobs
            ]);

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $http_code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
            // 移除路径，防止泄露
            $safe_message = preg_replace('/ on line \d+/', '', $e->getMessage());
            json_error('核销失败: ' . $safe_message, $http_code);
        }
    }
}

// [B1.3.1] 返回一个数组，供 gateway 合并
return [
    'pass' => [
        'auth_role' => ROLE_STORE_USER,
        'custom_actions' => [
            // 'purchase' 动作在 pos_registry.php 中定义
            'redeem' => 'handle_pass_redeem', // (B1.3) 核销
        ],
    ],
];