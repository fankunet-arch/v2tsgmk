<?php
/**
 * Toptea HQ - SIF Declaration API Handler
 * Handles loading and saving the SIF "Declaración Responsable" text.
 * Engineer: Gemini | Date: 2025-11-03
 */

require_once realpath(__DIR__ . '/../../../core/config.php'); 
require_once APP_PATH . '/helpers/auth_helper.php'; 

header('Content-Type: application/json; charset=utf-8');
function send_json_response($status, $message, $data = null) { echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); exit; }

@session_start();

global $pdo; 

// Security Check: Only Super Admins can manage this setting
if (!defined('ROLE_SUPER_ADMIN')) {
     http_response_code(500);
     send_json_response('error', '内部配置错误: 角色常量未定义。');
}
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] !== ROLE_SUPER_ADMIN) {
    http_response_code(403);
    send_json_response('error', '权限不足。');
}

if (!isset($pdo) || !$pdo instanceof PDO) {
     http_response_code(500);
     send_json_response('error', '数据库连接不可用。');
}

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = json_decode(file_get_contents('php://input'), true);
    if (is_array($json_data) && isset($json_data['action'])) { 
        $action = $json_data['action']; 
    }
}

const SIF_SETTING_KEY = 'sif_declaracion_responsable';

try {
    switch ($action) {
        case 'load':
            $stmt = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = ?");
            $stmt->execute([SIF_SETTING_KEY]);
            $value = $stmt->fetchColumn();
            
            if ($value === false) {
                // Setting not found, return empty string so the view uses the default
                $value = ''; 
            }
            send_json_response('success', 'Declaración cargada.', ['declaration_text' => $value]);
            break;

        case 'save':
            $declaration_text = $json_data['declaration_text'] ?? null;
            if ($declaration_text === null) {
                send_json_response('error', 'No se proporcionó texto de declaración.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO pos_settings (setting_key, setting_value, description)
                VALUES (:key, :value, :desc)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $stmt->execute([
                ':key' => SIF_SETTING_KEY,
                ':value' => $declaration_text,
                ':desc' => 'Declaración Responsable (SIF Compliance Statement)'
            ]);

            $pdo->commit();
            send_json_response('success', 'Declaración Responsable guardada con éxito.');
            break;

        default:
            http_response_code(400);
            send_json_response('error', '无效的操作请求。');
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    error_log("Database Error in sif_declaration_handler: " . $e->getMessage()); 
    send_json_response('error', '数据库操作失败，请检查日志。', ['code' => $e->getCode()]);
} catch (Throwable $e) { 
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    error_log("Server Error in sif_declaration_handler: " . $e->getMessage());
    send_json_response('error', '服务器内部发生错误，请检查日志。');
}
?>