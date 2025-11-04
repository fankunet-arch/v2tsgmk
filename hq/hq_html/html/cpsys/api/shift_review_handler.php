<?php
/**
 * Toptea HQ - API Handler for Shift Review (Ghost Shift Guardian)
 * Engineer: Gemini | Date: 2025-11-04
 */

require_once realpath(__DIR__ . '/../../../core/config.php');
require_once APP_PATH . '/helpers/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
function send_json_response($status, $message, $data = null, $http = 200) { 
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); 
    exit; 
}

@session_start();
// 仅限超级管理员
if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN) {
    send_json_response('error', '权限不足。', null, 403);
}

global $pdo;
$json_data = json_decode(file_get_contents('php://input'), true);
$action = $json_data['action'] ?? null;

if ($action !== 'review') {
    send_json_response('error', '无效的操作请求。', null, 400);
}

try {
    $shift_id = (int)($json_data['shift_id'] ?? 0);
    $counted_cash_str = $json_data['counted_cash'] ?? null;

    if ($shift_id <= 0 || $counted_cash_str === null || !is_numeric($counted_cash_str)) {
        send_json_response('error', '无效的参数 (shift_id or counted_cash)。', null, 400);
    }
    
    $counted_cash = (float)$counted_cash_str;

    $pdo->beginTransaction();

    // 1. 锁定并获取班次
    $stmt_get = $pdo->prepare(
        "SELECT id, expected_cash FROM pos_shifts 
         WHERE id = ? AND status = 'FORCE_CLOSED' AND admin_reviewed = 0 FOR UPDATE"
    );
    $stmt_get->execute([$shift_id]);
    $shift = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        $pdo->rollBack();
        send_json_response('error', '未找到待复核的班次，或该班次已被他人处理。', null, 404);
    }

    // 2. 计算差异
    $expected_cash = (float)$shift['expected_cash'];
    $cash_diff = $counted_cash - $expected_cash;

    // 3. 更新班次记录
    $stmt_update = $pdo->prepare(
        "UPDATE pos_shifts SET 
            counted_cash = ?,
            cash_variance = ?,
            admin_reviewed = 1,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt_update->execute([$counted_cash, $cash_diff, $shift_id]);
    
    // 4. [可选但推荐] 更新 eod_records 表（如果存在）
    // 我们假设 eod_records 已经有了一条记录（在 force_start 时创建的）
    if (function_exists('table_exists') && table_exists($pdo, 'pos_eod_records')) {
         $stmt_eod = $pdo->prepare(
            "UPDATE pos_eod_records SET 
                counted_cash = ?,
                cash_diff = ?,
                notes = CONCAT(COALESCE(notes, ''), ' | Admin Reviewed')
             WHERE shift_id = ?"
         );
         // 我们只更新 eod_records，即使它不存在也不报错
         $stmt_eod->execute([$counted_cash, $cash_diff, $shift_id]);
    }

    $pdo->commit();
    send_json_response('success', '班次复核成功！');

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log("Shift Review API Error: " . $e->getMessage());
    send_json_response('error', '服务器内部错误。', ['debug' => $e->getMessage()], 500);
}
?>