<?php
/**
 * Toptea HQ - CPSYS API 注册表 (RMS - Recipe/Stock Management)
 * 注册物料、库存、配方等资源
 * Version: 1.1.0 (V1.5 Path Fix)
 * Date: 2025-11-05
 *
 * [GEMINI V1.5 FIX]: Corrected realpath() to ../../../
 */

// 确保 kds_helper 和 auth_helper 已加载
require_once realpath(__DIR__ . '/../../../../app/helpers/kds_helper.php');
require_once realpath(__DIR__ . '/../../../../app/helpers/auth_helper.php');

// --- 处理器: 物料 (kds_materials) ---
function handle_material_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $data = getMaterialById($pdo, (int)$id);
    $data ? json_ok($data) : json_error('未找到物料', 404);
}
function handle_material_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = trim($data['material_code'] ?? '');
    $type = trim($data['material_type'] ?? '');
    $name_zh = trim($data['name_zh'] ?? ''); $name_es = trim($data['name_es'] ?? '');
    $base_unit_id = (int)($data['base_unit_id'] ?? 0);
    $large_unit_id = (int)($data['large_unit_id'] ?? 0);
    $conversion_rate = (float)($data['conversion_rate'] ?? 0.0);
    $expiry_rule_type = (string)($data['expiry_rule_type'] ?? '');
    $expiry_duration = (int)($data['expiry_duration'] ?? 0);

    if (empty($code) || empty($type) || empty($name_zh) || empty($name_es) || empty($base_unit_id)) json_error('编号、类型、双语名称和基础单位为必填项。', 400);
    if ($large_unit_id !== 0 && ($conversion_rate <= 1)) json_error('选择大单位后，换算率必须是一个大于1的数字。', 400);
    if ($expiry_rule_type !== '' && in_array($expiry_rule_type, ['HOURS', 'DAYS']) && ($expiry_duration <= 0)) json_error('选择按小时或天计算效期后，必须填写一个大于0的时长。', 400);
    if ($expiry_rule_type === 'END_OF_DAY' || $expiry_rule_type === '') $expiry_duration = 0;
    if ($large_unit_id === 0) $conversion_rate = 0.0;
    
    $pdo->beginTransaction();
    try {
        if ($id) {
            $stmt_check = $pdo->prepare("SELECT id FROM kds_materials WHERE material_code = ? AND id != ? AND deleted_at IS NULL");
            $stmt_check->execute([$code, $id]);
            if ($stmt_check->fetch()) json_error('自定义编号 "' . htmlspecialchars($code) . '" 已被另一个有效物料使用。', 409);
            $stmt = $pdo->prepare("UPDATE kds_materials SET material_code = ?, material_type = ?, base_unit_id = ?, large_unit_id = ?, conversion_rate = ?, expiry_rule_type = ?, expiry_duration = ? WHERE id = ?");
            $stmt->execute([$code, $type, $base_unit_id, $large_unit_id, $conversion_rate, $expiry_rule_type, $expiry_duration, $id]);
            $stmt_trans = $pdo->prepare("UPDATE kds_material_translations SET material_name = ? WHERE material_id = ? AND language_code = ?");
            $stmt_trans->execute([$name_zh, $id, 'zh-CN']);
            $stmt_trans->execute([$name_es, $id, 'es-ES']);
            $pdo->commit();
            json_ok(['id' => $id], '物料已成功更新！');
        } else {
            $stmt_active = $pdo->prepare("SELECT id FROM kds_materials WHERE material_code = ? AND deleted_at IS NULL");
            $stmt_active->execute([$code]);
            if ($stmt_active->fetch()) json_error('自定义编号 "' . htmlspecialchars($code) . '" 已被一个有效物料使用。', 409);
            $stmt_deleted = $pdo->prepare("SELECT id FROM kds_materials WHERE material_code = ? AND deleted_at IS NOT NULL");
            $stmt_deleted->execute([$code]);
            $reclaimable_row = $stmt_deleted->fetch();
            $message = '新物料已成功创建！';
            if ($reclaimable_row) {
                $id = $reclaimable_row['id'];
                $stmt_reclaim = $pdo->prepare("UPDATE kds_materials SET material_type = ?, base_unit_id = ?, large_unit_id = ?, conversion_rate = ?, expiry_rule_type = ?, expiry_duration = ?, deleted_at = NULL WHERE id = ?");
                $stmt_reclaim->execute([$type, $base_unit_id, $large_unit_id, $conversion_rate, $expiry_rule_type, $expiry_duration, $id]);
                $stmt_trans = $pdo->prepare("UPDATE kds_material_translations SET material_name = ? WHERE material_id = ? AND language_code = ?");
                $stmt_trans->execute([$name_zh, $id, 'zh-CN']);
                $stmt_trans->execute([$name_es, $id, 'es-ES']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO kds_materials (material_code, material_type, base_unit_id, large_unit_id, conversion_rate, expiry_rule_type, expiry_duration) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$code, $type, $base_unit_id, $large_unit_id, $conversion_rate, $expiry_rule_type, $expiry_duration]);
                $id = (int)$pdo->lastInsertId();
                $stmt_trans = $pdo->prepare("INSERT INTO kds_material_translations (material_id, language_code, material_name) VALUES (?, ?, ?)");
                $stmt_trans->execute([$id, 'zh-CN', $name_zh]);
                $stmt_trans->execute([$id, 'es-ES', $name_es]);
            }
            $pdo->commit();
            json_ok(['id' => $id], $message);
        }
    } catch (PDOException $e) { $pdo->rollBack(); json_error('数据库操作失败', 500, ['debug' => $e->getMessage()]); }
}
function handle_material_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("UPDATE kds_materials SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '物料已成功删除。');
}
function handle_material_get_next_code(PDO $pdo, array $config, array $input_data): void {
    $next_code = getNextAvailableCustomCode($pdo, 'kds_materials', 'material_code');
    json_ok(['next_code' => $next_code], '下一个可用编号已找到。');
}

// --- 处理器: 库存 (stock_handler) ---
function handle_stock_actions(PDO $pdo, array $config, array $input_data): void {
    $action = $input_data['action'] ?? $_GET['act'] ?? null; // 兼容旧JS {action: 'add_...'}
    $data = $input_data['data'] ?? null;
    if ($action === 'add_warehouse_stock') {
        $material_id = (int)($data['material_id'] ?? 0);
        $quantity_to_add = (float)($data['quantity'] ?? 0);
        $unit_id = (int)($data['unit_id'] ?? 0);
        if ($material_id <= 0 || $quantity_to_add <= 0 || $unit_id <= 0) json_error('物料、数量和单位均为必填项。', 400);
        $material = getMaterialById($pdo, $material_id);
        if (!$material) json_error('找不到指定的物料。', 404);
        $final_quantity_to_add = $quantity_to_add;
        if ($material['large_unit_id'] == $unit_id) {
            if (empty($material['conversion_rate']) || $material['conversion_rate'] <= 0) json_error('该物料的大单位换算率未设置或无效。', 400);
            $final_quantity_to_add = $quantity_to_add * (float)$material['conversion_rate'];
        }
        $pdo->beginTransaction();
        $sql = "INSERT INTO expsys_warehouse_stock (material_id, quantity) VALUES (:material_id, :quantity) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity);";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':material_id' => $material_id, ':quantity' => $final_quantity_to_add]);
        $pdo->commit();
        json_ok(null, '总仓入库成功！');
    } 
    elseif ($action === 'allocate_to_store') {
        $store_id = (int)($data['store_id'] ?? 0);
        $material_id = (int)($data['material_id'] ?? 0);
        $quantity_to_allocate = (float)($data['quantity'] ?? 0);
        $unit_id = (int)($data['unit_id'] ?? 0);
        if ($store_id <= 0 || $material_id <= 0 || $quantity_to_allocate <= 0 || $unit_id <= 0) json_error('门店、物料、数量和单位均为必填项。', 400);
        $material = getMaterialById($pdo, $material_id);
        if (!$material) json_error('找不到指定的物料。', 404);
        $final_quantity_to_allocate = $quantity_to_allocate;
        if ($material['large_unit_id'] == $unit_id) {
            if (empty($material['conversion_rate']) || $material['conversion_rate'] <= 0) json_error('该物料的大单位换算率未设置或无效。', 400);
            $final_quantity_to_allocate = $quantity_to_allocate * (float)$material['conversion_rate'];
        }
        $pdo->beginTransaction();
        $stmt_warehouse = $pdo->prepare("INSERT INTO expsys_warehouse_stock (material_id, quantity) VALUES (?, ?) ON DUPLICATE KEY UPDATE quantity = quantity - ?;");
        $stmt_warehouse->execute([$material_id, -$final_quantity_to_allocate, $final_quantity_to_allocate]);
        $stmt_store = $pdo->prepare("INSERT INTO expsys_store_stock (store_id, material_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?;");
        $stmt_store->execute([$store_id, $material_id, $final_quantity_to_allocate, $final_quantity_to_allocate]);
        $pdo->commit();
        json_ok(null, '库存调拨成功！');
    }
    else {
        json_error("未知的库存动作: {$action}", 400);
    }
}

// --- 处理器: RMS 全局规则 (kds_global_adjustment_rules) ---
function handle_rms_global_rule_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM kds_global_adjustment_rules WHERE id = ?");
    $stmt->execute([(int)$id]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    $rule ? json_ok($rule, '规则已加载。') : json_error('未找到规则。', 404);
}
function handle_rms_global_rule_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = !empty($data['id']) ? (int)$data['id'] : null;
    $nullIfEmpty = fn($v) => ($v === '' || $v === null) ? null : $v;
    $params = [
        ':rule_name' => trim($data['rule_name'] ?? ''),
        ':priority' => (int)($data['priority'] ?? 100),
        ':is_active' => (int)($data['is_active'] ?? 0),
        ':cond_cup_id' => $nullIfEmpty($data['cond_cup_id']),
        ':cond_ice_id' => $nullIfEmpty($data['cond_ice_id']),
        ':cond_sweet_id' => $nullIfEmpty($data['cond_sweet_id']),
        ':cond_material_id' => $nullIfEmpty($data['cond_material_id']),
        ':cond_base_gt' => $nullIfEmpty($data['cond_base_gt']),
        ':cond_base_lte' => $nullIfEmpty($data['cond_base_lte']),
        ':action_type' => $data['action_type'] ?? '',
        ':action_material_id' => (int)($data['action_material_id'] ?? 0),
        ':action_value' => (float)($data['action_value'] ?? 0),
        ':action_unit_id' => $nullIfEmpty($data['action_unit_id']),
    ];
    if (empty($params[':rule_name']) || empty($params[':action_type']) || $params[':action_material_id'] === 0) json_error('规则名称、动作类型和目标物料为必填项。', 400);
    if ($params[':action_type'] === 'ADD_MATERIAL' && empty($params[':action_unit_id'])) json_error('当动作类型为“添加物料”时，必须指定单位。', 400);
    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE kds_global_adjustment_rules SET rule_name = :rule_name, priority = :priority, is_active = :is_active, cond_cup_id = :cond_cup_id, cond_ice_id = :cond_ice_id, cond_sweet_id = :cond_sweet_id, cond_material_id = :cond_material_id, cond_base_gt = :cond_base_gt, cond_base_lte = :cond_base_lte, action_type = :action_type, action_material_id = :action_material_id, action_value = :action_value, action_unit_id = :action_unit_id WHERE id = :id";
        $message = '全局规则已更新。';
    } else {
        $sql = "INSERT INTO kds_global_adjustment_rules (rule_name, priority, is_active, cond_cup_id, cond_ice_id, cond_sweet_id, cond_material_id, cond_base_gt, cond_base_lte, action_type, action_material_id, action_value, action_unit_id) VALUES (:rule_name, :priority, :is_active, :cond_cup_id, :cond_ice_id, :cond_sweet_id, :cond_material_id, :cond_base_gt, :cond_base_lte, :action_type, :action_material_id, :action_value, :action_unit_id)";
        $message = '新全局规则已创建。';
    }
    $pdo->prepare($sql)->execute($params);
    json_ok(['id' => $id ?? $pdo->lastInsertId()], $message);
}
function handle_rms_global_rule_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("DELETE FROM kds_global_adjustment_rules WHERE id = ?");
    $stmt->execute([(int)$id]);
    json_ok(null, '全局规则已删除。');
}

// --- 处理器: RMS 产品 (kds_products) ---

// [FIX V1.0.1] 重写此函数，不再依赖 kds_helper.php 中有缺陷的版本
function handle_rms_product_get_details(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('无效的产品ID。', 400);
    $productId = (int)$id;

    // 1. 基本信息 + 翻译
    $stmt = $pdo->prepare("
        SELECT p.id, p.product_code, p.status_id, p.is_active,
               COALESCE(tzh.product_name,'') AS name_zh,
               COALESCE(tes.product_name,'') AS name_es
        FROM kds_products p
        LEFT JOIN kds_product_translations tzh ON tzh.product_id=p.id AND tzh.language_code='zh-CN'
        LEFT JOIN kds_product_translations tes ON tes.product_id=p.id AND tes.language_code='es-ES'
        WHERE p.id=? AND p.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$productId]);
    $base = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$base) json_error('未找到产品。', 404);

    // 2. Layer 1 基础配方 (base_recipes)
    $stmt = $pdo->prepare("
        SELECT id, material_id, unit_id, quantity, step_category, sort_order
        FROM kds_product_recipes
        WHERE product_id=?
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$productId]);
    $base_recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Layer 3 Overrides (adjustments) - 获取并分组
    $stmt = $pdo->prepare("
        SELECT id, material_id, unit_id, quantity, step_category,
               cup_id, sweetness_option_id, ice_option_id
        FROM kds_recipe_adjustments
        WHERE product_id=?
        ORDER BY id ASC
    ");
    $stmt->execute([$productId]);
    $raw_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = [];
    foreach ($raw_adjustments as $row) {
        $key = ($row['cup_id'] ?? 'null') . '-' . ($row['sweetness_option_id'] ?? 'null') . '-' . ($row['ice_option_id'] ?? 'null');
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'cup_id' => $row['cup_id'] !== null ? (int)$row['cup_id'] : null,
                'sweetness_option_id' => $row['sweetness_option_id'] !== null ? (int)$row['sweetness_option_id'] : null,
                'ice_option_id' => $row['ice_option_id'] !== null ? (int)$row['ice_option_id'] : null,
                'overrides' => []
            ];
        }
        $grouped[$key]['overrides'][] = [
            'material_id'   => (int)$row['material_id'],
            'quantity'      => (float)$row['quantity'],
            'unit_id'       => (int)$row['unit_id'],
            'step_category' => $row['step_category'] ?? 'base',
        ];
    }
    $adjustments = array_values($grouped); // 转换为纯数组

    // 4. Gating (allowed_*_ids)
    $stmt_sweet = $pdo->prepare("SELECT sweetness_option_id FROM kds_product_sweetness_options WHERE product_id=?");
    $stmt_sweet->execute([$productId]);
    // 过滤掉 GATING FIX 引入的 0 标记
    $allowed_sweetness_ids = array_filter(
        array_map('intval', $stmt_sweet->fetchAll(PDO::FETCH_COLUMN)),
        fn($id) => $id > 0
    );

    $stmt_ice = $pdo->prepare("SELECT ice_option_id FROM kds_product_ice_options WHERE product_id=?");
    $stmt_ice->execute([$productId]);
    // 过滤掉 GATING FIX 引入的 0 标记
    $allowed_ice_ids = array_filter(
        array_map('intval', $stmt_ice->fetchAll(PDO::FETCH_COLUMN)),
        fn($id) => $id > 0
    );

    // 5. 组装响应
    $response = $base;
    $response['base_recipes'] = $base_recipes;
    $response['adjustments']  = $adjustments; // <-- 现在是正确的分组结构
    $response['allowed_sweetness_ids'] = $allowed_sweetness_ids;
    $response['allowed_ice_ids'] = $allowed_ice_ids;

    json_ok($response, '产品详情加载成功。');
}

function handle_rms_product_get_next_code(PDO $pdo, array $config, array $input_data): void {
    $next = getNextAvailableCustomCode($pdo, 'kds_products', 'product_code', 101);
    json_ok(['next_code' => $next]);
}
function handle_rms_product_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('无效的产品ID。', 400);
    $stmt = $pdo->prepare("UPDATE kds_products SET is_active=0, deleted_at=NOW() WHERE id=?");
    $stmt->execute([(int)$id]);
    json_ok(null, '产品已删除。');
}
function handle_rms_product_save(PDO $pdo, array $config, array $input_data): void {
    $product = $input_data['product'] ?? json_error('无效的产品数据。', 400);
    $pdo->beginTransaction();
    try {
        $productId   = isset($product['id']) ? (int)$product['id'] : 0;
        $productCode = trim((string)($product['product_code'] ?? ''));
        $statusId    = (int)($product['status_id'] ?? 1);
        if ($productId > 0) {
            $stmt = $pdo->prepare("UPDATE kds_products SET product_code=?, status_id=? WHERE id=?");
            $stmt->execute([$productCode, $statusId, $productId]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM kds_products WHERE product_code=? AND deleted_at IS NULL");
            $stmt->execute([$productCode]);
            if ($stmt->fetchColumn()) { $pdo->rollBack(); json_error('产品编码已存在：'.$productCode, 409); }
            $stmt = $pdo->prepare("INSERT INTO kds_products (product_code, status_id, is_active) VALUES (?, ?, 1)");
            $stmt->execute([$productCode, $statusId]);
            $productId = (int)$pdo->lastInsertId();
        }
        
        // 翻译
        $nameZh = trim((string)($product['name_zh'] ?? ''));
        $nameEs = trim((string)($product['name_es'] ?? ''));
        $qSel = $pdo->prepare("SELECT id FROM kds_product_translations WHERE product_id=? AND language_code=?");
        foreach ([['zh-CN',$nameZh], ['es-ES',$nameEs]] as [$lang,$name]) {
            $qSel->execute([$productId,$lang]);
            $tid = $qSel->fetchColumn();
            if ($tid) { $pdo->prepare("UPDATE kds_product_translations SET product_name=? WHERE id=?")->execute([$name,$tid]); }
            else { $pdo->prepare("INSERT INTO kds_product_translations (product_id, language_code, product_name) VALUES (?,?,?)")->execute([$productId,$lang,$name]); }
        }
        
        // Gating
        $allowedSweet = array_values(array_unique(array_map('intval', $product['allowed_sweetness_ids'] ?? [])));
        $allowedIce   = array_values(array_unique(array_map('intval', $product['allowed_ice_ids'] ?? [])));
        $pdo->prepare("DELETE FROM kds_product_sweetness_options WHERE product_id=?")->execute([$productId]);
        if (!empty($allowedSweet)) {
            $ins = $pdo->prepare("INSERT INTO kds_product_sweetness_options (product_id, sweetness_option_id) VALUES (?,?)");
            foreach ($allowedSweet as $sid) { if ($sid > 0) $ins->execute([$productId, $sid]); }
        } else { $pdo->prepare("INSERT INTO kds_product_sweetness_options (product_id, sweetness_option_id) VALUES (?,0)")->execute([$productId]); }
        $pdo->prepare("DELETE FROM kds_product_ice_options WHERE product_id=?")->execute([$productId]);
        if (!empty($allowedIce)) {
            $ins = $pdo->prepare("INSERT INTO kds_product_ice_options (product_id, ice_option_id) VALUES (?,?)");
            foreach ($allowedIce as $iid) { if ($iid > 0) $ins->execute([$productId, $iid]); }
        } else { $pdo->prepare("INSERT INTO kds_product_ice_options (product_id, ice_option_id) VALUES (?,0)")->execute([$productId]); }
        
        // L1
        $base = $product['base_recipes'] ?? [];
        $pdo->prepare("DELETE FROM kds_product_recipes WHERE product_id=?")->execute([$productId]);
        if ($base) {
            $ins = $pdo->prepare("INSERT INTO kds_product_recipes (product_id, material_id, unit_id, quantity, step_category, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            $sort = 1;
            foreach ($base as $row) { $ins->execute([ $productId, (int)($row['material_id'] ?? 0), (int)($row['unit_id'] ?? 0), (float)($row['quantity'] ?? 0), (string)($row['step_category'] ?? 'base'), $sort++ ]); }
        }
        
        // L3
        $adjInput = $product['adjustments'] ?? [];
        $pdo->prepare("DELETE FROM kds_recipe_adjustments WHERE product_id=?")->execute([$productId]);
        if ($adjInput) {
            $ins = $pdo->prepare("INSERT INTO kds_recipe_adjustments (product_id, material_id, unit_id, quantity, step_category, cup_id, sweetness_option_id, ice_option_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($adjInput as $ov) {
                $ins->execute([
                    $productId, (int)($ov['material_id'] ?? 0), (int)($ov['unit_id'] ?? 0), (float)($ov['quantity'] ?? 0),
                    (string)($ov['step_category'] ?? 'base'),
                    isset($ov['cup_id']) ? (int)$ov['cup_id'] : null,
                    isset($ov['sweetness_option_id']) ? (int)$ov['sweetness_option_id'] : null,
                    isset($ov['ice_option_id']) ? (int)$ov['ice_option_id'] : null,
                ]);
            }
        }
        $pdo->commit();
        json_ok(['id' => $productId], '产品数据已保存。');
    } catch (Throwable $e) { $pdo->rollBack(); json_error('保存失败: '.$e->getMessage(), 500); }
}


// --- 注册表 ---
return [
    
    'materials' => [
        'table' => 'kds_materials',
        'pk' => 'id',
        'soft_delete_col' => 'deleted_at',
        'auth_role' => ROLE_PRODUCT_MANAGER, // 允许产品经理
        'custom_actions' => [
            'get' => 'handle_material_get',
            'save' => 'handle_material_save',
            'delete' => 'handle_material_delete',
            'get_next_code' => 'handle_material_get_next_code',
        ],
    ],
    
    'stock' => [
        'table' => 'expsys_warehouse_stock', // (仅用于占位)
        'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'add_warehouse_stock' => 'handle_stock_actions',
            'allocate_to_store' => 'handle_stock_actions',
        ],
    ],
    
    'rms_global_rules' => [
        'table' => 'kds_global_adjustment_rules',
        'pk' => 'id',
        'soft_delete_col' => null, // 硬删除
        'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get' => 'handle_rms_global_rule_get',
            'save' => 'handle_rms_global_rule_save',
            'delete' => 'handle_rms_global_rule_delete',
        ],
    ],
    
    'rms_products' => [
        'table' => 'kds_products',
        'pk' => 'id',
        'soft_delete_col' => 'deleted_at',
        'auth_role' => ROLE_PRODUCT_MANAGER, // 允许产品经理
        'custom_actions' => [
            'get_product_details' => 'handle_rms_product_get_details',
            'get_next_product_code' => 'handle_rms_product_get_next_code',
            'save_product' => 'handle_rms_product_save',
            'delete_product' => 'handle_rms_product_delete',
        ],
    ],
    
];