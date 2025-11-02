<?php
/**
 * TopTea HQ - POS Item Variant Management API
 * Engineer: Gemini | Date: 2025-10-26
 *
 * [!!] 关键修复 (V2 - 2025-11-02):
 * 1. [GET] 修复 'get' 动作，使其能 JOIN 表以正确获取 product_id (kds_products.id)，
 * 以便在前端"关联配方"下拉框中正确显示当前值。
 * 2. [SAVE] 修复 'save' 动作，移除对 pos_item_variants 表中不存在的 "product_id" 列的
 * 写入操作。
 * 3. [SAVE] 新增 'save' 逻辑：在保存规格时，根据传入的 product_id (配方ID) 找到
 * product_code，并更新 *父级* pos_menu_items 表中的 product_code，
 * 这才是正确的配方关联方式。
 */
require_once realpath(__DIR__ . '/../../../core/config.php');

header('Content-Type: application/json; charset=utf-8');
function send_json_response($status, $message, $data = null) { echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); exit; }

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) { $action = $_GET['action']; }
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') { $json_data = json_decode(file_get_contents('php://input'), true); if (isset($json_data['action'])) { $action = $json_data['action']; } }

try {
    switch ($action) {
        case 'get':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id) { send_json_response('error', '无效的ID。'); }
            
            // [FIXED] 必须 JOIN 才能获取到 product_id
            $stmt = $pdo->prepare("
                SELECT 
                    pv.*, 
                    p.id AS product_id
                FROM pos_item_variants pv
                JOIN pos_menu_items mi ON pv.menu_item_id = mi.id
                LEFT JOIN kds_products p ON mi.product_code = p.product_code AND p.deleted_at IS NULL
                WHERE pv.id = ? AND pv.deleted_at IS NULL
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetch();
            
            if ($data) { send_json_response('success', 'ok', $data); } else { http_response_code(404); send_json_response('error', 'not found'); }
            break;

        case 'save':
            $data = $json_data['data'];
            $id = $data['id'] ? (int)$data['id'] : null;
            $menu_item_id = (int)$data['menu_item_id'];
            $product_id = (int)($data['product_id'] ?? 0); // 这是 kds_products.id (配方ID)

            // [FIXED] 验证配方ID
            if ($product_id <= 0) {
                 send_json_response('error', '必须关联一个配方。');
            }

            // [FIXED] 根据配方ID获取 product_code
            $stmt_code = $pdo->prepare("SELECT product_code FROM kds_products WHERE id = ?");
            $stmt_code->execute([$product_id]);
            $product_code = $stmt_code->fetchColumn();

            if (!$product_code) {
                send_json_response('error', '选择的关联配方无效。');
            }
            
            // [FIXED] 移除 :product_id
            $params = [
                ':menu_item_id' => $menu_item_id,
                ':variant_name_zh' => trim($data['variant_name_zh']),
                ':variant_name_es' => trim($data['variant_name_es']),
                ':price_eur' => (float)$data['price_eur'],
                ':sort_order' => (int)($data['sort_order'] ?? 99),
                ':is_default' => (int)($data['is_default'] ?? 0)
            ];

            if (empty($params[':variant_name_zh']) || empty($params[':variant_name_es']) || $params[':price_eur'] <= 0) {
                send_json_response('error', '双语名称、价格和关联配方均为必填项。');
            }

            $pdo->beginTransaction();

            // [FIXED] 新增步骤：更新父级 menu_item 的 product_code
            $stmt_update_menu = $pdo->prepare("UPDATE pos_menu_items SET product_code = ? WHERE id = ?");
            $stmt_update_menu->execute([$product_code, $menu_item_id]);

            if ($id) {
                $params[':id'] = $id;
                // [FIXED] 移除 product_id
                $sql = "UPDATE pos_item_variants SET menu_item_id = :menu_item_id, variant_name_zh = :variant_name_zh, variant_name_es = :variant_name_es, price_eur = :price_eur, sort_order = :sort_order, is_default = :is_default WHERE id = :id";
                $pdo->prepare($sql)->execute($params);
                $message = '规格信息已成功更新！';
            } else {
                 // [FIXED] 移除 product_id
                $sql = "INSERT INTO pos_item_variants (menu_item_id, variant_name_zh, variant_name_es, price_eur, sort_order, is_default) VALUES (:menu_item_id, :variant_name_zh, :variant_name_es, :price_eur, :sort_order, :is_default)";
                $pdo->prepare($sql)->execute($params);
                $message = '新规格已成功创建！';
            }
            
            $pdo->commit();
            send_json_response('success', $message);
            break;

        case 'delete':
            $id = (int)($json_data['id'] ?? 0);
            if (!$id) { send_json_response('error', '无效的ID。'); }
            $stmt = $pdo->prepare("UPDATE pos_item_variants SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$id]);
            send_json_response('success', '规格已成功删除。');
            break;

        default:
            http_response_code(400); send_json_response('error', '无效的操作请求。');
    }
} catch (PDOException $e) {
    if(isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    send_json_response('error', '数据库操作失败。', ['debug' => $e->getMessage()]);
}
}