<?php
/**
 * Toptea HQ - POS Addon Management API
 * Engineer: Gemini | Date: 2025-11-02
 */
require_once realpath(__DIR__ . '/../../../core/config.php');
require_once APP_PATH . '/helpers/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
function send_json_response($status, $message, $data = null) { echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); exit; }

@session_start();
// 仅限超级管理员
if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN) {
    http_response_code(403);
    send_json_response('error', '权限不足 (Permission denied)');
}

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = json_decode(file_get_contents('php://input'), true);
    $action = $json_data['action'] ?? '';
}

try {
    switch ($action) {
        case 'get':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id) { send_json_response('error', '无效的ID。'); }
            $stmt = $pdo->prepare("SELECT * FROM pos_addons WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            if ($data) {
                send_json_response('success', 'ok', $data);
            } else {
                http_response_code(404);
                send_json_response('error', 'not found');
            }
            break;

        case 'save':
            $data = $json_data['data'];
            $id = $data['id'] ? (int)$data['id'] : null;

            $params = [
                ':addon_code' => trim($data['addon_code']),
                ':name_zh' => trim($data['name_zh']),
                ':name_es' => trim($data['name_es']),
                ':price_eur' => (float)($data['price_eur'] ?? 0),
                ':material_id' => !empty($data['material_id']) ? (int)$data['material_id'] : null,
                ':sort_order' => (int)($data['sort_order'] ?? 99),
                ':is_active' => (int)($data['is_active'] ?? 0)
            ];

            if (empty($params[':addon_code']) || empty($params[':name_zh']) || empty($params[':name_es'])) {
                send_json_response('error', '编码和双语名称均为必填项。');
            }

            // 检查编码唯一性
            $stmt_check = $pdo->prepare("SELECT id FROM pos_addons WHERE addon_code = ? AND deleted_at IS NULL" . ($id ? " AND id != ?" : ""));
            $params_check = $id ? [$params[':addon_code'], $id] : [$params[':addon_code']];
            $stmt_check->execute($params_check);
            if ($stmt_check->fetch()) {
                http_response_code(409);
                send_json_response('error', '此编码 (KEY)已被使用。');
            }

            if ($id) {
                $params[':id'] = $id;
                $sql = "UPDATE pos_addons SET 
                            addon_code = :addon_code, name_zh = :name_zh, name_es = :name_es, 
                            price_eur = :price_eur, material_id = :material_id, 
                            sort_order = :sort_order, is_active = :is_active 
                        WHERE id = :id";
                $pdo->prepare($sql)->execute($params);
                send_json_response('success', '加料已成功更新！');
            } else {
                $sql = "INSERT INTO pos_addons (addon_code, name_zh, name_es, price_eur, material_id, sort_order, is_active) 
                        VALUES (:addon_code, :name_zh, :name_es, :price_eur, :material_id, :sort_order, :is_active)";
                $pdo->prepare($sql)->execute($params);
                send_json_response('success', '新加料已成功创建！');
            }
            break;

        case 'delete':
            $id = (int)($json_data['id'] ?? 0);
            if (!$id) { send_json_response('error', '无效的ID。'); }
            
            $stmt = $pdo->prepare("UPDATE pos_addons SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$id]);
            send_json_response('success', '加料已成功删除。');
            break;

        default:
            http_response_code(400);
            send_json_response('error', '无效的操作请求。');
    }
} catch (PDOException $e) {
    http_response_code(500);
    send_json_response('error', '数据库操作失败。', ['debug' => $e->getMessage()]);
}
?>