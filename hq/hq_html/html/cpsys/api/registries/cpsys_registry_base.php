<?php
/**
 * Toptea HQ - CPSYS API 注册表 (KDS)
 * 注册 KDS 特有的配置 (e.g., SOP 解析器)
 * Version: 1.1.001 (V1.6 Path Fix)
 * Date: 2025-11-05
 *
 * [GEMINI V1.6 FIX]: 路径从 ../../../ (错误) 修正为 ../../../../ (正确)
 * [GEMINI V1.5 FIX]: Corrected realpath() to ../../../
 */

// 确保助手已加载 (网关会处理，但作为保险)
require_once realpath(__DIR__ . '/../../../../app/helpers/kds_helper.php');
require_once realpath(__DIR__ . '/../../../../app/helpers/auth_helper.php');

/**
 * 处理器: KDS SOP 解析规则 (kds_sop_query_rules)
 * 这是一个标准 CRUD 处理器
 */
function handle_kds_rule_get_list(PDO $pdo, array $config, array $input_data): void {
    // 按门店优先、优先级排序
    $sql = "
        SELECT 
            r.*, 
            s.store_name 
        FROM kds_sop_query_rules r
        LEFT JOIN kds_stores s ON r.store_id = s.id
        ORDER BY 
            r.store_id IS NOT NULL DESC, -- 门店专属规则优先 (NULLs last)
            s.store_name ASC, 
            r.priority ASC
    ";
    $stmt = $pdo->query($sql);
    json_ok($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handle_kds_rule_get(PDO $pdo, array $config, array $input_data): void {
    $id = $_GET['id'] ?? json_error('缺少 id', 400);
    $stmt = $pdo->prepare("SELECT * FROM kds_sop_query_rules WHERE id = ?");
    $stmt->execute([(int)$id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($data) {
        // 解码 JSON 以便前端表单填充
        $data['config'] = json_decode($data['config_json'], true);
        unset($data['config_json']); // 移除原始 json
        json_ok($data);
    } else {
        json_error('未找到规则', 404);
    }
}

function handle_kds_rule_save(PDO $pdo, array $config, array $input_data): void {
    $data = $input_data['data'] ?? json_error('缺少 data', 400);
    $id = $data['id'] ? (int)$data['id'] : null;
    
    // 1. 基础字段
    $rule_name = trim($data['rule_name'] ?? '');
    $priority = (int)($data['priority'] ?? 100);
    $is_active = (int)($data['is_active'] ?? 0);
    $store_id = !empty($data['store_id']) ? (int)$data['store_id'] : null; // 0 或 '' 视为 NULL
    $extractor_type = $data['extractor_type'] ?? '';

    if (empty($rule_name) || empty($extractor_type)) {
        json_error('规则名称 和 解析器类型 不能为空。', 400);
    }

    // 2. 根据类型构建 config_json
    $config_json = [];
    if ($extractor_type === 'DELIMITER') {
        $format = $data['config_format'] ?? '';
        $separator = $data['config_separator'] ?? '';
        if (empty($format) || $separator === '') { // 分隔符可以是 ' ' (空格)，但不该是 ''
             json_error('分隔符模式下，组件顺序和分隔符不能为空。', 400);
        }
        if (mb_strlen($separator) > 1) {
             json_error('分隔符只能是单个字符。', 400);
        }
        $config_json = [
            'format' => $format,
            'separator' => $separator,
            'prefix' => trim($data['config_prefix'] ?? '')
        ];
    } elseif ($extractor_type === 'KEY_VALUE') {
        $p_key = trim($data['config_p_key'] ?? '');
        if (empty($p_key)) json_error('URL参数模式下，P (产品) 的键 不能为空。', 400);
        $config_json = [
            'P_key' => $p_key,
            'A_key' => trim($data['config_a_key'] ?? ''),
            'M_key' => trim($data['config_m_key'] ?? ''),
            'T_key' => trim($data['config_t_key'] ?? '')
        ];
    } else {
        json_error('无效的解析器类型。', 400);
    }

    // 3. 准备 SQL 参数
    $params = [
        ':store_id' => $store_id,
        ':rule_name' => $rule_name,
        ':priority' => $priority,
        ':is_active' => $is_active,
        ':extractor_type' => $extractor_type,
        ':config_json' => json_encode($config_json, JSON_UNESCAPED_UNICODE)
    ];

    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE kds_sop_query_rules SET 
                    store_id = :store_id, rule_name = :rule_name, priority = :priority, 
                    is_active = :is_active, extractor_type = :extractor_type, config_json = :config_json 
                WHERE id = :id";
        $message = 'SOP 解析规则已更新。';
    } else {
        $sql = "INSERT INTO kds_sop_query_rules 
                    (store_id, rule_name, priority, is_active, extractor_type, config_json) 
                VALUES 
                    (:store_id, :rule_name, :priority, :is_active, :extractor_type, :config_json)";
        $message = 'SOP 解析规则已创建。';
    }
    
    $pdo->prepare($sql)->execute($params);
    json_ok(['id' => $id ?? $pdo->lastInsertId()], $message);
}

function handle_kds_rule_delete(PDO $pdo, array $config, array $input_data): void {
    $id = $input_data['id'] ?? json_error('缺少 id', 400);
    $id = (int)$id;

    if ($id === 1) {
        json_error('删除失败：无法删除 ID 为 1 的 KDS 内部标准规则。', 403);
    }
    
    $stmt = $pdo->prepare("DELETE FROM kds_sop_query_rules WHERE id = ?");
    $stmt->execute([$id]);
    json_ok(null, 'SOP 解析规则已删除。');
}

// --- 注册表 ---
return [
    
    'kds_sop_rules' => [
        'table' => 'kds_sop_query_rules',
        'pk' => 'id',
        'soft_delete_col' => null, // 硬删除
        'auth_role' => ROLE_SUPER_ADMIN,
        'custom_actions' => [
            'get_list' => 'handle_kds_rule_get_list',
            'get' => 'handle_kds_rule_get',
            'save' => 'handle_kds_rule_save',
            'delete' => 'handle_kds_rule_delete',
        ],
    ],
    
];
?>