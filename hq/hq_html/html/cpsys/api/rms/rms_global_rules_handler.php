<?php
/**
 * Toptea HQ - RMS Global Rules API Handler (Layer 2)
 * Engineer: Gemini | Date: 2025-11-02
 * Revision: 2.0 (Added Base Quantity Conditions)
 */
// (V2.2 PATH FIX)
require_once realpath(__DIR__ . '/../../../../core/config.php'); 
require_once APP_PATH . '/helpers/kds_helper.php';
require_once APP_PATH . '/helpers/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
function send_json_response($status, $message, $data = null, $http = 200) { 
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); 
    exit; 
}

@session_start();
if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN) {
    send_json_response('error', '权限不足。', null, 403);
}

global $pdo;
$action = $_GET['action'] ?? null;
$json_data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = json_decode(file_get_contents('php://input'), true);
    $action = $json_data['action'] ?? $action;
}

// Helper to convert empty strings to NULL
function nullIfEmpty($value) {
    return $value === '' ? null : $value;
}

try {
    switch($action) {
        case 'get':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id) send_json_response('error', '无效的ID。', null, 400);
            $stmt = $pdo->prepare("SELECT * FROM kds_global_adjustment_rules WHERE id = ?");
            $stmt->execute([$id]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($rule) {
                send_json_response('success', '规则已加载。', $rule);
            } else {
                send_json_response('error', '未找到规则。', null, 404);
            }
            break;

        case 'save':
            $data = $json_data['data'] ?? [];
            $id = !empty($data['id']) ? (int)$data['id'] : null;

            $params = [
                ':rule_name' => trim($data['rule_name'] ?? ''),
                ':priority' => (int)($data['priority'] ?? 100),
                ':is_active' => (int)($data['is_active'] ?? 0),
                ':cond_cup_id' => nullIfEmpty($data['cond_cup_id']),
                ':cond_ice_id' => nullIfEmpty($data['cond_ice_id']),
                ':cond_sweet_id' => nullIfEmpty($data['cond_sweet_id']),
                ':cond_material_id' => nullIfEmpty($data['cond_material_id']),
                ':cond_base_gt' => nullIfEmpty($data['cond_base_gt']), // ★★★ 新增字段 ★★★
                ':cond_base_lte' => nullIfEmpty($data['cond_base_lte']), // ★★★ 新增字段 ★★★
                ':action_type' => $data['action_type'] ?? '',
                ':action_material_id' => (int)($data['action_material_id'] ?? 0),
                ':action_value' => (float)($data['action_value'] ?? 0),
                ':action_unit_id' => nullIfEmpty($data['action_unit_id']),
            ];

            if (empty($params[':rule_name']) || empty($params[':action_type']) || $params[':action_material_id'] === 0) {
                send_json_response('error', '规则名称、动作类型和目标物料为必填项。', null, 400);
            }
            
            if ($params[':action_type'] === 'ADD_MATERIAL' && empty($params[':action_unit_id'])) {
                 send_json_response('error', '当动作类型为“添加物料”时，必须指定单位。', null, 400);
            }

            if ($id) {
                $params[':id'] = $id;
                $sql = "UPDATE kds_global_adjustment_rules SET
                            rule_name = :rule_name, priority = :priority, is_active = :is_active,
                            cond_cup_id = :cond_cup_id, cond_ice_id = :cond_ice_id, cond_sweet_id = :cond_sweet_id, cond_material_id = :cond_material_id,
                            cond_base_gt = :cond_base_gt, cond_base_lte = :cond_base_lte,
                            action_type = :action_type, action_material_id = :action_material_id, action_value = :action_value, action_unit_id = :action_unit_id
                        WHERE id = :id";
                $message = '全局规则已更新。';
            } else {
                $sql = "INSERT INTO kds_global_adjustment_rules 
                            (rule_name, priority, is_active, cond_cup_id, cond_ice_id, cond_sweet_id, cond_material_id, cond_base_gt, cond_base_lte, action_type, action_material_id, action_value, action_unit_id)
                        VALUES 
                            (:rule_name, :priority, :is_active, :cond_cup_id, :cond_ice_id, :cond_sweet_id, :cond_material_id, :cond_base_gt, :cond_base_lte, :action_type, :action_material_id, :action_value, :action_unit_id)";
                $message = '新全局规则已创建。';
            }
            
            $pdo->prepare($sql)->execute($params);
            send_json_response('success', $message);
            break;

        case 'delete':
            $id = (int)($json_data['id'] ?? 0);
            if (!$id) send_json_response('error', '无效的ID。', null, 400);
            $stmt = $pdo->prepare("DELETE FROM kds_global_adjustment_rules WHERE id = ?");
            $stmt->execute([$id]);
            send_json_response('success', '全局规则已删除。');
            break;

        default:
            send_json_response('error', '无效的操作请求。', null, 400);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("RMS Global Rules API Error: " . $e->getMessage());
    send_json_response('error', '服务器内部错误: ' . $e->getMessage());
}
?>