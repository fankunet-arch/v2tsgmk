<?php
/**
 * Toptea HQ - RMS API Handler
 * Handles all CRUD for the new dynamic recipe engine.
 * Engineer: Gemini | Date: 2025-11-02 | Revision: 6.0 (RMS V2.2 - Save Gating Logic)
 */
// CORE FIX: Corrected the relative path to the core config file.
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $json_data = json_decode(file_get_contents('php://input'), true);
    $action = $json_data['action'] ?? $action;
}

try {
    switch($action) {
        case 'get_next_product_code':
            $next_code = getNextAvailableCustomCode($pdo, 'kds_products', 'product_code', 101);
            send_json_response('success', 'Next available product code retrieved.', ['next_code' => $next_code]);
            break;

        case 'get_product_details':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id) send_json_response('error', '无效的产品ID。', null, 400);
            
            // kds_helper->getProductDetailsForRMS() now includes gating data
            $data = getProductDetailsForRMS($pdo, $id); 

            if ($data) {
                // Group adjustments by conditions
                $groupedAdjustments = [];
                foreach ($data['adjustments'] as $adj) {
                    $key = ($adj['cup_id'] ?? 'null') . '-' . ($adj['sweetness_option_id'] ?? 'null') . '-' . ($adj['ice_option_id'] ?? 'null');
                    if (!isset($groupedAdjustments[$key])) {
                        $groupedAdjustments[$key] = [
                            'cup_id' => $adj['cup_id'],
                            'sweetness_option_id' => $adj['sweetness_option_id'],
                            'ice_option_id' => $adj['ice_option_id'],
                            'overrides' => []
                        ];
                    }
                    $groupedAdjustments[$key]['overrides'][] = [
                        'material_id' => $adj['material_id'],
                        'quantity' => $adj['quantity'],
                        'unit_id' => $adj['unit_id'],
                        'step_category' => $adj['step_category'] // 传递步骤分类
                    ];
                }
                $data['adjustments'] = array_values($groupedAdjustments);
                send_json_response('success', '产品详情加载成功。', $data);
            } else {
                send_json_response('error', '未找到产品。', null, 404);
            }
            break;
        
        case 'save_product':
            $productData = $json_data['product'];
            if (empty($productData)) send_json_response('error', '无效的产品数据。', null, 400);

            $pdo->beginTransaction();

            $productId = (int)($productData['id'] ?? 0);
            
            // 1. 保存产品主表和翻译
            if ($productId > 0) {
                $stmt = $pdo->prepare("UPDATE kds_products SET product_code = ?, status_id = ? WHERE id = ?");
                $stmt->execute([$productData['product_code'], $productData['status_id'], $productId]);

                $stmt_trans = $pdo->prepare("REPLACE INTO kds_product_translations (product_id, language_code, product_name) VALUES (?, ?, ?), (?, ?, ?)");
                $stmt_trans->execute([$productId, 'zh-CN', $productData['name_zh'], $productId, 'es-ES', $productData['name_es']]);
            } else {
                $stmt_check_code = $pdo->prepare("SELECT id FROM kds_products WHERE product_code = ? AND deleted_at IS NULL");
                $stmt_check_code->execute([$productData['product_code']]);
                if($stmt_check_code->fetch()) {
                    $pdo->rollBack();
                    send_json_response('error', '产品编码 ' . htmlspecialchars($productData['product_code']) . ' 已存在，请使用其他编码。', null, 409);
                }
                
                $stmt = $pdo->prepare("INSERT INTO kds_products (product_code, status_id, is_active) VALUES (?, ?, 1)");
                $stmt->execute([$productData['product_code'], $productData['status_id']]);
                $productId = $pdo->lastInsertId();

                $stmt_trans = $pdo->prepare("INSERT INTO kds_product_translations (product_id, language_code, product_name) VALUES (?, ?, ?), (?, ?, ?)");
                $stmt_trans->execute([$productId, 'zh-CN', $productData['name_zh'], $productId, 'es-ES', $productData['name_es']]);
            }

            // 2. 保存基础配方 (Layer 1)
            $pdo->prepare("DELETE FROM kds_product_recipes WHERE product_id = ?")->execute([$productId]);
            if (!empty($productData['base_recipes'])) {
                $stmt_recipe = $pdo->prepare("INSERT INTO kds_product_recipes (product_id, material_id, quantity, unit_id, step_category, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($productData['base_recipes'] as $recipe) {
                     if (!empty($recipe['material_id']) && isset($recipe['quantity']) && !empty($recipe['unit_id'])) {
                        $stmt_recipe->execute([
                            $productId, 
                            $recipe['material_id'], 
                            $recipe['quantity'], 
                            $recipe['unit_id'],
                            $recipe['step_category'] ?? 'base',
                            $recipe['sort_order'] ?? 0
                        ]);
                     }
                }
            }
            
            // 3. 保存特例规则 (Layer 3)
            $pdo->prepare("DELETE FROM kds_recipe_adjustments WHERE product_id = ?")->execute([$productId]);
            if (!empty($productData['adjustments'])) {
                $stmt_adj = $pdo->prepare("
                    INSERT INTO kds_recipe_adjustments 
                        (product_id, material_id, cup_id, sweetness_option_id, ice_option_id, quantity, unit_id, step_category) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($productData['adjustments'] as $adj) {
                    if (!empty($adj['material_id']) && isset($adj['quantity']) && !empty($adj['unit_id'])) {
                        $step_category = (!empty($adj['step_category'])) ? $adj['step_category'] : null;
                        $stmt_adj->execute([
                            $productId, $adj['material_id'],
                            empty($adj['cup_id']) ? null : $adj['cup_id'],
                            empty($adj['sweetness_option_id']) ? null : $adj['sweetness_option_id'],
                            empty($adj['ice_option_id']) ? null : $adj['ice_option_id'],
                            $adj['quantity'],
                            $adj['unit_id'],
                            $step_category
                        ]);
                    }
                }
            }

            // 4. (V2.2 GATING) 保存 Gating 规则
            // 4a. 甜度 Gating
            $pdo->prepare("DELETE FROM kds_product_sweetness_options WHERE product_id = ?")->execute([$productId]);
            if (!empty($productData['allowed_sweetness_ids'])) {
                $stmt_sweet = $pdo->prepare("INSERT INTO kds_product_sweetness_options (product_id, sweetness_option_id) VALUES (?, ?)");
                foreach ($productData['allowed_sweetness_ids'] as $opt_id) {
                    $stmt_sweet->execute([$productId, (int)$opt_id]);
                }
            }

            // 4b. 冰量 Gating
            $pdo->prepare("DELETE FROM kds_product_ice_options WHERE product_id = ?")->execute([$productId]);
            if (!empty($productData['allowed_ice_ids'])) {
                $stmt_ice = $pdo->prepare("INSERT INTO kds_product_ice_options (product_id, ice_option_id) VALUES (?, ?)");
                foreach ($productData['allowed_ice_ids'] as $opt_id) {
                    $stmt_ice->execute([$productId, (int)$opt_id]);
                }
            }
            
            $pdo->commit();
            send_json_response('success', '产品配方已成功保存！', ['new_id' => $productId]);
            break;

        case 'delete_product':
             $id = (int)($json_data['id'] ?? 0);
             if (!$id) send_json_response('error', '无效的产品ID。', null, 400);
             // 软删除
             $stmt = $pdo->prepare("UPDATE kds_products SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
             $stmt->execute([$id]);
             send_json_response('success', '产品已成功删除。');
             break;

        default:
            send_json_response('error', '无效的操作请求。', null, 400);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    error_log("RMS API Error: " . $e->getMessage());
    send_json_response('error', '服务器内部错误: ' . $e->getMessage());
}
}