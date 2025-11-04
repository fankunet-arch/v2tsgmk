<?php
/**
 * TopTea HQ - POS Item Variants Handler (LEAN)
 * Endpoints:
 *   GET    ?action=get&id={variant_id}
 *   POST   JSON { action:"save", data:{ ... } }
 *   POST   JSON { action:"delete", id:123 }
 *
 * 数据模型假设：
 * - 规格表：pos_item_variants(id, menu_item_id, variant_name_zh, variant_name_es, price_eur, is_default, sort_order, deleted_at)
 * - 菜单项：pos_menu_items(id, product_code, deleted_at)
 * - 配方表：kds_products(id, product_code, deleted_at)
 *
 * “关联配方”在**菜单项维度**（menu_item.product_code），不是规格维度。
 * 勾选“设为默认规格”会把同一 menu_item 的其他规格 is_default 置 0。
 */

require_once realpath(__DIR__ . '/../../../core/config.php'); // 提供 $pdo, APP_PATH, 常量
// 可选的权限控制（若系统定义了角色常量）
if (defined('APP_PATH')) {
    $authHelper = APP_PATH . '/helpers/auth_helper.php';
    if (file_exists($authHelper)) {
        require_once $authHelper;
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
header('Content-Type: application/json; charset=utf-8');

function resp($status, $message, $data = null, $http_code = null) {
    if ($http_code) { http_response_code($http_code); }
    echo json_encode(['status'=>$status,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

// 可选的角色校验（保持和其它 cpsys API 一致）
if (defined('ROLE_SUPER_ADMIN')) {
    if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN) {
        resp('error', '权限不足 (Permission denied)', null, 403);
    }
}

// 解析动作
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = '';
$payload = [];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    // 兼容 application/json 与 x-www-form-urlencoded
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) { $payload = $_POST; }
    $action = $payload['action'] ?? '';
}

try {
    switch ($action) {
        case 'get': {
            $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            if ($id <= 0) { resp('error', '无效的ID', null, 400); }

            $sql = "SELECT 
                        v.id,
                        v.menu_item_id,
                        v.variant_name_zh,
                        v.variant_name_es,
                        v.price_eur,
                        v.sort_order,
                        v.is_default,
                        mi.product_code,
                        p.id AS product_id
                    FROM pos_item_variants v
                    INNER JOIN pos_menu_items mi ON v.menu_item_id = mi.id
                    LEFT JOIN kds_products p ON mi.product_code = p.product_code AND p.deleted_at IS NULL
                    WHERE v.id = ? AND v.deleted_at IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { resp('error', '记录不存在', null, 404); }
            resp('success', 'ok', $row);
        }

        case 'save': {
            $d = $payload['data'] ?? [];

            $id              = isset($d['id']) ? (int)$d['id'] : null;
            $menu_item_id    = isset($d['menu_item_id']) ? (int)$d['menu_item_id'] : 0;
            $variant_name_zh = trim($d['variant_name_zh'] ?? '');
            $variant_name_es = trim($d['variant_name_es'] ?? '');
            $price_eur       = isset($d['price_eur']) ? (float)$d['price_eur'] : 0.0;
            $sort_order      = isset($d['sort_order']) ? (int)$d['sort_order'] : 99;
            $is_default      = !empty($d['is_default']) ? 1 : 0;
            $product_id      = isset($d['product_id']) && $d['product_id'] !== '' ? (int)$d['product_id'] : null;

            if ($menu_item_id <= 0 || $variant_name_zh === '' || $variant_name_es === '' || $price_eur <= 0) {
                resp('error', '缺少必填项或价格无效', null, 400);
            }

            $pdo->beginTransaction();

            // 如选择了配方：把菜单项的 product_code 同步到该配方的 product_code
            if ($product_id) {
                $stmt = $pdo->prepare("SELECT product_code FROM kds_products WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$product_id]);
                $pc = $stmt->fetchColumn();
                if ($pc) {
                    $stmt2 = $pdo->prepare("UPDATE pos_menu_items SET product_code = ? WHERE id = ? AND deleted_at IS NULL");
                    $stmt2->execute([$pc, $menu_item_id]);
                }
            }

            if ($id) {
                $sql = "UPDATE pos_item_variants
                        SET variant_name_zh = :variant_name_zh,
                            variant_name_es = :variant_name_es,
                            price_eur       = :price_eur,
                            sort_order      = :sort_order,
                            is_default      = :is_default
                        WHERE id = :id AND deleted_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':variant_name_zh' => $variant_name_zh,
                    ':variant_name_es' => $variant_name_es,
                    ':price_eur'       => $price_eur,
                    ':sort_order'      => $sort_order,
                    ':is_default'      => $is_default,
                    ':id'              => $id
                ]);
            } else {
                $sql = "INSERT INTO pos_item_variants
                            (menu_item_id, variant_name_zh, variant_name_es, price_eur, is_default, sort_order)
                        VALUES
                            (:menu_item_id, :variant_name_zh, :variant_name_es, :price_eur, :is_default, :sort_order)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':menu_item_id'    => $menu_item_id,
                    ':variant_name_zh' => $variant_name_zh,
                    ':variant_name_es' => $variant_name_es,
                    ':price_eur'       => $price_eur,
                    ':is_default'      => $is_default,
                    ':sort_order'      => $sort_order
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            // 保证同一 menu_item 仅一个默认规格
            if ($is_default === 1) {
                $stmt = $pdo->prepare("UPDATE pos_item_variants SET is_default = 0 WHERE menu_item_id = ? AND id <> ?");
                $stmt->execute([$menu_item_id, $id]);
            }

            $pdo->commit();
            resp('success', '规格已保存');
        }

        case 'delete': {
            $id = isset($payload['id']) ? (int)$payload['id'] : 0;
            if ($id <= 0) { resp('error', '无效的ID', null, 400); }
            $stmt = $pdo->prepare("UPDATE pos_item_variants SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$id]);
            resp('success', '规格已删除');
        }

        default:
            resp('error', '无效的操作请求', null, 400);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    resp('error', '数据库操作失败', ['debug' => $e->getMessage()], 500);
} catch (Throwable $e) {
    resp('error', '服务器内部错误', ['debug' => $e->getMessage()], 500);
}
