<?php
/**
 * TopTea POS - Product Availability Handler API (估清处理器)
 *
 * Actions:
 * - get_all: 获取本店所有商品的估清状态 (用于估清面板)
 * - toggle: 切换单个商品的估清状态
 * - reset_all: 重新上架所有商品 (用于新员工交接班)
 */
require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');
require_once realpath(__DIR__ . '/../../../pos_backend/core/api_auth_core.php'); // 引入鉴权

header('Content-Type: application/json; charset=utf-8');

function send_json_response($status, $message, $data = null, $http = 200) {
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

$store_id = (int)($_SESSION['pos_store_id'] ?? 0);
if ($store_id === 0) {
    send_json_response('error', 'Unauthorized: Invalid store session.', null, 401);
}

$action = $_GET['action'] ?? null;
$json_data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($json_data['action'])) {
    $action = $json_data['action'];
}

try {
    switch ($action) {
        // [需求1] 获取所有商品状态 (用于估清面板)
        case 'get_all':
            $sql = "
                SELECT 
                    mi.id AS menu_item_id,
                    mi.name_zh,
                    mi.name_es,
                    mi.product_code,
                    COALESCE(pa.is_sold_out, 0) AS is_sold_out
                FROM pos_menu_items mi
                LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
                WHERE 
                    mi.deleted_at IS NULL 
                    AND mi.is_active = 1 -- 只管理全局上架的商品
                ORDER BY 
                    mi.pos_category_id, mi.sort_order
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':store_id' => $store_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            send_json_response('success', 'Status loaded.', $items);
            break;

        // [需求1] 切换单个商品的状态
        case 'toggle':
            $menu_item_id = (int)($json_data['menu_item_id'] ?? 0);
            $is_sold_out = isset($json_data['is_sold_out']) ? (int)$json_data['is_sold_out'] : 0;
            if ($menu_item_id <= 0) {
                send_json_response('error', 'Invalid menu_item_id.', null, 400);
            }

            $sql = "
                INSERT INTO pos_product_availability 
                    (store_id, menu_item_id, is_sold_out, updated_at)
                VALUES 
                    (:store_id, :menu_item_id, :is_sold_out, NOW())
                ON DUPLICATE KEY UPDATE
                    is_sold_out = VALUES(is_sold_out),
                    updated_at = NOW()
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':store_id' => $store_id,
                ':menu_item_id' => $menu_item_id,
                ':is_sold_out' => $is_sold_out
            ]);
            send_json_response('success', 'Status updated.');
            break;

        // [需求3] 新员工选择“全部重新上架”
        case 'reset_all':
            $stmt = $pdo->prepare("DELETE FROM pos_product_availability WHERE store_id = :store_id");
            $stmt->execute([':store_id' => $store_id]);
            send_json_response('success', 'All items restocked.');
            break;

        default:
            send_json_response('error', 'Invalid action.', null, 400);
    }
} catch (Exception $e) {
    send_json_response('error', 'Server error.', ['debug' => $e->getMessage()], 500);
}