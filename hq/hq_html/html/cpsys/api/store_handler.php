<?php
/**
 * Toptea HQ - cpsys
 * Unified API Handler for Store Management
 * Engineer: Gemini | Date: 2025-10-26 | Revision: 2.2 (Add NONE option for billing)
 *
 * [GEMINI PRINTER_CONFIG_UPDATE]:
 * 1. Added printer_type, printer_ip, printer_port, printer_mac to handleSave params.
 * 2. Added new fields to INSERT and UPDATE SQL statements.
 */

require_once realpath(__DIR__ . '/../../../core/config.php');
require_once APP_PATH . '/helpers/kds_helper.php';
require_once APP_PATH . '/helpers/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
function send_json_response($status, $message, $data = null) { echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); exit; }

@session_start();
if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] !== ROLE_SUPER_ADMIN) { http_response_code(403); send_json_response('error', '权限不足。'); }

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) { $action = $_GET['action']; }
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') { $json_data = json_decode(file_get_contents('php://input'), true); if (isset($json_data['action'])) { $action = $json_data['action']; } }

switch ($action) {
    case 'get':
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        // getStoreById from kds_helper.php is already updated
        $data = getStoreById($pdo, $id); 
        if ($data) { send_json_response('success', 'ok', $data); } else { http_response_code(404); send_json_response('error', 'not found'); }
        break;
    case 'save':
        handleSave($pdo, $json_data['data']);
        break;
    case 'delete':
        $id = (int)($json_data['id'] ?? 0);
        if (!$id) { send_json_response('error', '无效的ID。'); }
        $stmt = $pdo->prepare("UPDATE kds_stores SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
        send_json_response('success', '门店已成功删除。');
        break;
    default:
        http_response_code(400); send_json_response('error', '无效的操作请求。');
}

function handleSave($pdo, $data) {
    $id = $data['id'] ? (int)$data['id'] : null;
    $eod_hour = (int)($data['eod_cutoff_hour'] ?? 3);
    
    // [GEMINI PRINTER_CONFIG_UPDATE] Add new printer params
    $printer_type = in_array($data['printer_type'], ['NONE', 'WIFI', 'BLUETOOTH', 'USB']) ? $data['printer_type'] : 'NONE';
    $printer_ip = ($printer_type === 'WIFI' && !empty($data['printer_ip'])) ? trim($data['printer_ip']) : null;
    $printer_port = ($printer_type === 'WIFI' && !empty($data['printer_port'])) ? (int)$data['printer_port'] : null;
    $printer_mac = ($printer_type === 'BLUETOOTH' && !empty($data['printer_mac'])) ? trim($data['printer_mac']) : null;


    $params = [
        ':store_code' => trim($data['store_code']),
        ':store_name' => trim($data['store_name']),
        ':tax_id' => trim($data['tax_id']),
        ':billing_system' => in_array($data['billing_system'], ['TICKETBAI', 'VERIFACTU', 'NONE']) ? $data['billing_system'] : null,
        ':default_vat_rate' => (float)$data['default_vat_rate'],
        ':invoice_number_offset' => (int)$data['invoice_number_offset'],
        ':eod_cutoff_hour' => ($eod_hour >= 0 && $eod_hour <= 23) ? $eod_hour : 3, // Validate and save
        ':store_city' => trim($data['store_city']) ?: null,
        ':is_active' => (int)($data['is_active'] ?? 0),
        // [GEMINI PRINTER_CONFIG_UPDATE] Bind new params
        ':printer_type' => $printer_type,
        ':printer_ip' => $printer_ip,
        ':printer_port' => $printer_port,
        ':printer_mac' => $printer_mac,
    ];

    if (empty($params[':store_code']) || empty($params[':store_name']) || empty($params[':tax_id']) || empty($params[':billing_system'])) {
        send_json_response('error', '门店码、名称、税号和票据系统均为必填项。');
    }

    $sql_check = "SELECT id FROM kds_stores WHERE store_code = ? AND deleted_at IS NULL";
    $params_check = [$params[':store_code']];
    if ($id) { $sql_check .= " AND id != ?"; $params_check[] = $id; }
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->execute($params_check);
    if ($stmt_check->fetch()) { http_response_code(409); send_json_response('error', '门店码 "' . htmlspecialchars($params[':store_code']) . '" 已被使用。'); }

    if ($id) {
        $params[':id'] = $id;
        $sql = "UPDATE kds_stores SET 
                    store_code = :store_code, store_name = :store_name, tax_id = :tax_id, 
                    billing_system = :billing_system, default_vat_rate = :default_vat_rate, 
                    invoice_number_offset = :invoice_number_offset, eod_cutoff_hour = :eod_cutoff_hour, 
                    store_city = :store_city, is_active = :is_active,
                    printer_type = :printer_type, printer_ip = :printer_ip, 
                    printer_port = :printer_port, printer_mac = :printer_mac
                WHERE id = :id";
    } else {
        $sql = "INSERT INTO kds_stores (
                    store_code, store_name, tax_id, billing_system, default_vat_rate, 
                    invoice_number_offset, eod_cutoff_hour, store_city, is_active,
                    printer_type, printer_ip, printer_port, printer_mac
                ) VALUES (
                    :store_code, :store_name, :tax_id, :billing_system, :default_vat_rate, 
                    :invoice_number_offset, :eod_cutoff_hour, :store_city, :is_active,
                    :printer_type, :printer_ip, :printer_port, :printer_mac
                )";
    }
    $pdo->prepare($sql)->execute($params);
    send_json_response('success', $id ? '门店信息已成功更新！' : '新门店已成功创建！');
}