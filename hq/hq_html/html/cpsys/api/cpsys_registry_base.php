<?php
/**
 * Toptea HQ - CPSYS API 注册表 (Base/Common)
 * 注册通用字典资源 (单位, 杯型, 状态等)
 * Version: 1.0.0
 * Date: 2025-11-04
 */

// 加载领域助手 (包含 getNextAvailableCustomCode)
require_once APP_PATH . '/helpers/kds_helper.php';

// --- 单位 (kds_units) 自定义处理器 ---
// (因为单位涉及 kds_units 和 kds_unit_translations 两个表，需要自定义动作)

function handle_unit_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $unit = getUnitById($pdo, (int)$id); // 复用 kds_helper 中的函数
    $unit ? json_ok($unit) : json_error('未找到单位', 404);
}

function handle_unit_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    
    $id = $data['id'] ? (int)$data['id'] : null;
    $code = $data['unit_code'] ?? '';
    $name_zh = trim($data['name_zh'] ?? '');
    $name_es = trim($data['name_es'] ?? '');

    if (empty($code) || empty($name_zh) || empty($name_es)) {
        json_error('所有字段均为必填项。', 400);
    }
    
    // 检查编码唯一性 (只检查未软删除的)
    $sql_check = "SELECT id FROM kds_units WHERE unit_code = ? AND deleted_at IS NULL";
    $params_check = [$code];
    if ($id) {
        $sql_check .= " AND id != ?";
        $params_check[] = $id;
    }
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) {
        json_error('自定义编号 "' . htmlspecialchars($code) . '" 已被使用。', 409);
    }
    
    $pdo->beginTransaction();
    try {
        if ($id) {
            // 更新
            $stmt = $pdo->prepare("UPDATE kds_units SET unit_code = ? WHERE id = ?");
            $stmt->execute([$code, $id]);
            
            $stmt_trans_zh = $pdo->prepare("UPDATE kds_unit_translations SET unit_name = ? WHERE unit_id = ? AND language_code = 'zh-CN'");
            $stmt_trans_zh->execute([$name_zh, $id]);
            
            $stmt_trans_es = $pdo->prepare("UPDATE kds_unit_translations SET unit_name = ? WHERE unit_id = ? AND language_code = 'es-ES'");
            $stmt_trans_es->execute([$name_es, $id]);
            
            $message = '单位已成功更新！';
        } else {
            // 新增
            $stmt = $pdo->prepare("INSERT INTO kds_units (unit_code) VALUES (?)");
            $stmt->execute([$code]);
            $new_unit_id = (int)$pdo->lastInsertId();
            
            $stmt_trans = $pdo->prepare("INSERT INTO kds_unit_translations (unit_id, language_code, unit_name) VALUES (?, ?, ?)");
            $stmt_trans->execute([$new_unit_id, 'zh-CN', $name_zh]);
            $stmt_trans->execute([$new_unit_id, 'es-ES', $name_es]);
            
            $id = $new_unit_id;
            $message = '新单位已成功创建！';
        }
        $pdo->commit();
        json_ok(['id' => $id], $message);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_error('数据库操作失败', 500, ['debug' => $e->getMessage()]);
    }
}

function handle_unit_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    
    // 修复：使用软删除
    $stmt = $pdo->prepare("UPDATE kds_units SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([(int)$id]);
    
    json_ok(null, '单位已成功删除。');
}

function handle_unit_get_next_code(PDO $pdo, array $config, array $input_data): void {
    // 复用 kds_helper 中的函数
    $next_code = getNextAvailableCustomCode($pdo, 'kds_units', 'unit_code');
    json_ok(['next_code' => $next_code], '下一个可用编号已找到。');
}


// --- 注册表 ---
return [
    
    'units' => [
        'table' => 'kds_units',
        'pk' => 'id',
        'soft_delete_col' => 'deleted_at',
        'auth_role' => ROLE_SUPER_ADMIN,
        
        // 定义此资源的 API (act) 及其处理器函数
        'custom_actions' => [
            'get' => 'handle_unit_get',
            'save' => 'handle_unit_save',
            'delete' => 'handle_unit_delete',
            'get_next_code' => 'handle_unit_get_next_code',
            // 'get_list' => ... (如果 get_list 也需要 join 翻译表)
        ],

        // (标准动作的配置，虽然此处被 custom 覆盖，但作为示例保留)
        'visible_cols' => ['id', 'unit_code'],
        'writable_cols' => ['unit_code'],
        'default_order' => 'unit_code ASC',
    ],

    // ... 未来在这里添加 'cups', 'statuses', 'ice_options' 等 ...
    
];