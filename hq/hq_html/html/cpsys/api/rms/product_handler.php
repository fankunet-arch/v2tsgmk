<?php
/**
 * TopTea HQ – RMS API (product_handler.php)
 * Minimal-change, robust fix for gating + adjustments schema mismatch.
 * Date: 2025-11-02
 *
 * [GEMINI V2.2 GATING FIX]:
 * 1. 修复 save_product 逻辑。
 * 2. 当 Gating 选项（如 ice/sweetness）被全部取消（保存空数组 '[]'）时，
 * 原逻辑会删除所有记录，导致 POS 端无法区分“未配置”和“配置为空”。
 * 3. 新逻辑：当保存空数组时，插入一条 (product_id, 0) 的标记记录。
 * 这允许 pos_data_loader.php 明确识别出“已配置但为空”的状态。
 */

declare(strict_types=1);

// ---------- bootstrap ----------
require_once realpath(__DIR__ . '/../../../../core/config.php'); // defines $pdo, APP_PATH
require_once APP_PATH . '/helpers/auth_helper.php';
require_once APP_PATH . '/helpers/kds_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
header('Content-Type: application/json; charset=utf-8');

function send_json_response(string $status, string $message = '', $data = null, int $http_code = 200): void {
    http_response_code($http_code);
    echo json_encode(['status'=>$status,'message'=>$message,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    send_json_response('error','数据库未初始化（PDO）。', null, 500);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// parse input
$action = $_GET['action'] ?? '';
$raw = file_get_contents('php://input');
$body = [];
if ($raw) {
    $tmp = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $body = $tmp; }
    if (!$action && isset($body['action'])) { $action = (string)$body['action']; }
}

function ensure_array($v): array { return is_array($v) ? $v : []; }

// ---------- actions ----------
try {
    switch ($action) {
        case 'get_next_product_code': {
            // 兼容你现有的辅助函数，若不存在则降级计算
            if (function_exists('getNextAvailableCustomCode')) {
                $next = getNextAvailableCustomCode($pdo, 'kds_products', 'product_code', 101);
            } else {
                $used = $pdo->query("SELECT product_code FROM kds_products WHERE deleted_at IS NULL ORDER BY product_code ASC")
                            ->fetchAll(PDO::FETCH_COLUMN);
                $next = 101; foreach ($used as $u) { if ((int)$u === $next) $next++; }
            }
            send_json_response('success','OK',['next_code'=>$next]);
        }

        case 'get_product_details': {
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            if (!$id) send_json_response('error','无效的产品ID。', null, 400);

            // 基本信息 + 翻译
            $stmt = $pdo->prepare("
                SELECT p.id, p.product_code, p.status_id, p.is_active,
                       COALESCE(tzh.product_name,'') AS name_zh,
                       COALESCE(tes.product_name,'') AS name_es
                FROM kds_products p
                LEFT JOIN kds_product_translations tzh ON tzh.product_id=p.id AND tzh.language_code='zh-CN'
                LEFT JOIN kds_product_translations tes ON tes.product_id=p.id AND tes.language_code='es-ES'
                WHERE p.id=? AND p.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $base = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$base) send_json_response('error','未找到产品。', null, 404);

            // Layer 1 基础配方（前端字段：base_recipes）
            $stmt = $pdo->prepare("
                SELECT id, material_id, unit_id, quantity, step_category, sort_order
                FROM kds_product_recipes
                WHERE product_id=?
                ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([$id]);
            $base_recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Layer 3 Overrides（表：kds_recipe_adjustments）→ 分组返回（前端渲染用）
            $stmt = $pdo->prepare("
                SELECT id, material_id, unit_id, quantity, step_category,
                       cup_id, sweetness_option_id, ice_option_id
                FROM kds_recipe_adjustments
                WHERE product_id=?
                ORDER BY id ASC
            ");
            $stmt->execute([$id]);
            $raw = ensure_array($stmt->fetchAll(PDO::FETCH_ASSOC));

            $grouped = [];
            foreach ($raw as $row) {
                $key = ($row['cup_id'] ?? 'null') . '-' . ($row['sweetness_option_id'] ?? 'null') . '-' . ($row['ice_option_id'] ?? 'null');
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'cup_id' => $row['cup_id'] !== null ? (int)$row['cup_id'] : null,
                        'sweetness_option_id' => $row['sweetness_option_id'] !== null ? (int)$row['sweetness_option_id'] : null,
                        'ice_option_id' => $row['ice_option_id'] !== null ? (int)$row['ice_option_id'] : null,
                        'overrides' => []
                    ];
                }
                $grouped[$key]['overrides'][] = [
                    'material_id'   => (int)$row['material_id'],
                    'quantity'      => (float)$row['quantity'],
                    'unit_id'       => (int)$row['unit_id'],
                    'step_category' => $row['step_category'] ?? 'base',
                ];
            }
            $adjustments = array_values($grouped);

            // gating（按前端字段名 *_ids 返回）
            $stmt = $pdo->prepare("SELECT sweetness_option_id FROM kds_product_sweetness_options WHERE product_id=?");
            $stmt->execute([$id]);
            $allowed_sweetness_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $stmt = $pdo->prepare("SELECT ice_option_id FROM kds_product_ice_options WHERE product_id=?");
            $stmt->execute([$id]);
            $allowed_ice_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $resp = $base;
            $resp['base_recipes'] = $base_recipes;                 // ✅ 符合前端字段
            $resp['adjustments']  = $adjustments;                  // 分组+overrides
            // [GEMINI GATING FIX] 过滤掉 0 标记记录，如果存在的话
            $resp['allowed_sweetness_ids'] = array_filter($allowed_sweetness_ids, fn($id) => $id > 0);
            $resp['allowed_ice_ids']       = array_filter($allowed_ice_ids, fn($id) => $id > 0);

            send_json_response('success','产品详情加载成功。', $resp);
        }

        case 'save_product': {
            $product = $body['product'] ?? null;
            if (!is_array($product)) send_json_response('error','无效的产品数据。', null, 400);

            $pdo->beginTransaction();

            $productId   = isset($product['id']) ? (int)$product['id'] : 0;
            $productCode = trim((string)($product['product_code'] ?? ''));
            $statusId    = (int)($product['status_id'] ?? 1);

            if ($productId > 0) {
                $stmt = $pdo->prepare("UPDATE kds_products SET product_code=?, status_id=? WHERE id=?");
                $stmt->execute([$productCode, $statusId, $productId]);
            } else {
                // 去重
                $stmt = $pdo->prepare("SELECT id FROM kds_products WHERE product_code=? AND deleted_at IS NULL");
                $stmt->execute([$productCode]);
                if ($stmt->fetchColumn()) { $pdo->rollBack(); send_json_response('error', '产品编码已存在：'.$productCode, null, 409); }
                $stmt = $pdo->prepare("INSERT INTO kds_products (product_code, status_id, is_active) VALUES (?, ?, 1)");
                $stmt->execute([$productCode, $statusId]);
                $productId = (int)$pdo->lastInsertId();
            }

            // 翻译 upsert
            $nameZh = trim((string)($product['name_zh'] ?? ''));
            $nameEs = trim((string)($product['name_es'] ?? ''));
            $qSel = $pdo->prepare("SELECT id FROM kds_product_translations WHERE product_id=? AND language_code=?");
            foreach ([['zh-CN',$nameZh], ['es-ES',$nameEs]] as [$lang,$name]) {
                $qSel->execute([$productId,$lang]);
                $tid = $qSel->fetchColumn();
                if ($tid) {
                    $pdo->prepare("UPDATE kds_product_translations SET product_name=? WHERE id=?")->execute([$name,$tid]);
                } else {
                    $pdo->prepare("INSERT INTO kds_product_translations (product_id, language_code, product_name) VALUES (?,?,?)")
                        ->execute([$productId,$lang,$name]);
                }
            }

            // gating：同时兼容 *_ids 与旧字段名 —— 并【去重规范化】以防重复主键
            $allowedSweet = ensure_array($product['allowed_sweetness_ids'] ?? $product['allowed_sweetness'] ?? []);
            $allowedSweet = array_values(array_unique(array_map('intval', $allowedSweet))); // ✅ 去重+整型
            $allowedIce   = ensure_array($product['allowed_ice_ids']       ?? $product['allowed_ice']       ?? []);
            $allowedIce   = array_values(array_unique(array_map('intval', $allowedIce)));   // ✅ 去重+整型

            // --- [GEMINI V2.2 GATING FIX] START ---
            $pdo->prepare("DELETE FROM kds_product_sweetness_options WHERE product_id=?")->execute([$productId]);
            if (!empty($allowedSweet)) {
                $ins = $pdo->prepare("INSERT INTO kds_product_sweetness_options (product_id, sweetness_option_id) VALUES (?,?)");
                foreach ($allowedSweet as $sid) { if ($sid > 0) $ins->execute([$productId, $sid]); }
            } else {
                // 插入 (product_id, 0) 标记记录，表示“已配置但为空”
                $pdo->prepare("INSERT INTO kds_product_sweetness_options (product_id, sweetness_option_id) VALUES (?,0)")->execute([$productId]);
            }

            $pdo->prepare("DELETE FROM kds_product_ice_options WHERE product_id=?")->execute([$productId]);
            if (!empty($allowedIce)) {
                $ins = $pdo->prepare("INSERT INTO kds_product_ice_options (product_id, ice_option_id) VALUES (?,?)");
                foreach ($allowedIce as $iid) { if ($iid > 0) $ins->execute([$productId, $iid]); }
            } else {
                // 插入 (product_id, 0) 标记记录
                $pdo->prepare("INSERT INTO kds_product_ice_options (product_id, ice_option_id) VALUES (?,0)")->execute([$productId]);
            }
            // --- [GEMINI V2.2 GATING FIX] END ---


            // 基础配方（前端字段：base_recipes）
            $base = ensure_array($product['base_recipes'] ?? $product['base_recipe'] ?? []);
            $pdo->prepare("DELETE FROM kds_product_recipes WHERE product_id=?")->execute([$productId]);
            if ($base) {
                $ins = $pdo->prepare("
                    INSERT INTO kds_product_recipes (product_id, material_id, unit_id, quantity, step_category, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $sort = 1;
                foreach ($base as $row) {
                    $ins->execute([
                        $productId,
                        (int)($row['material_id'] ?? 0),
                        (int)($row['unit_id'] ?? 0),
                        (float)($row['quantity'] ?? 0),
                        (string)($row['step_category'] ?? 'base'),
                        $sort++,
                    ]);
                }
            }

            // 覆盖（L3）：兼容“分组+overrides”与“扁平行列表”
            $adjInput = ensure_array($product['adjustments'] ?? []);
            $pdo->prepare("DELETE FROM kds_recipe_adjustments WHERE product_id=?")->execute([$productId]);

            // 判断是否为分组结构
            $isGrouped = false;
            foreach ($adjInput as $it) { if (is_array($it) && array_key_exists('overrides', $it)) { $isGrouped = true; break; } }

            if ($adjInput) {
                $ins = $pdo->prepare("
                    INSERT INTO kds_recipe_adjustments
                    (product_id, material_id, unit_id, quantity, step_category, cup_id, sweetness_option_id, ice_option_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                if ($isGrouped) {
                    foreach ($adjInput as $g) {
                        $cup   = isset($g['cup_id']) ? (int)$g['cup_id'] : null;
                        $sweet = isset($g['sweetness_option_id']) ? (int)$g['sweetness_option_id'] : null;
                        $ice   = isset($g['ice_option_id']) ? (int)$g['ice_option_id'] : null;
                        $ovs   = ensure_array($g['overrides'] ?? []);
                        foreach ($ovs as $ov) {
                            $ins->execute([
                                $productId,
                                (int)($ov['material_id'] ?? 0),
                                (int)($ov['unit_id'] ?? 0),
                                (float)($ov['quantity'] ?? 0),
                                (string)($ov['step_category'] ?? 'base'),
                                $cup, $sweet, $ice
                            ]);
                        }
                    }
                } else {
                    // 扁平行列表：每条都自带条件 + 物料
                    foreach ($adjInput as $ov) {
                        $ins->execute([
                            $productId,
                            (int)($ov['material_id'] ?? 0),
                            (int)($ov['unit_id'] ?? 0),
                            (float)($ov['quantity'] ?? 0),
                            (string)($ov['step_category'] ?? 'base'),
                            isset($ov['cup_id']) ? (int)$ov['cup_id'] : null,
                            isset($ov['sweetness_option_id']) ? (int)$ov['sweetness_option_id'] : null,
                            isset($ov['ice_option_id']) ? (int)$ov['ice_option_id'] : null,
                        ]);
                    }
                }
            }

            $pdo->commit();
            send_json_response('success','产品数据已保存。', ['id'=>$productId]);
        }

        case 'list_products': {
            $rows = $pdo->query("
                SELECT p.id, p.product_code, p.status_id, p.is_active,
                       COALESCE(tzh.product_name,'') AS name_zh,
                       COALESCE(tes.product_name,'') AS name_es
                FROM kds_products p
                LEFT JOIN kds_product_translations tzh ON tzh.product_id=p.id AND tzh.language_code='zh-CN'
                LEFT JOIN kds_product_translations tes ON tes.product_id=p.id AND tes.language_code='es-ES'
                WHERE p.deleted_at IS NULL
                ORDER BY p.product_code ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            send_json_response('success','OK',$rows);
        }

        case 'delete_product': {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) send_json_response('error','无效的产品ID。', null, 400);
            $pdo->prepare("UPDATE kds_products SET is_active=0, deleted_at=NOW() WHERE id=?")->execute([$id]);
            send_json_response('success','产品已删除。');
        }

        default:
            send_json_response('error','无效的 action。', null, 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('RMS product_handler error: '.$e->getMessage().' @'.$e->getFile().':'.$e->getLine());
    send_json_response('error', '服务器内部错误：'.$e->getMessage(), null, 500);
}