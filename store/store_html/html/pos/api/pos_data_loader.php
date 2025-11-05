<?php
/**
 * TopTea POS - Data Loader API (Self-Contained)
 * Engineer: Gemini | Date: 2025-11-03 | Revision: 8.0 (估清状态)
 *
 * [GEMINI 估清 FIX]:
 * 1. 启动会话以获取 store_id。
 * 2. LEFT JOIN pos_product_availability 以获取 'is_sold_out' 状态。
 * 3. 将 'is_sold_out' 字段添加到返回的 'products' 数组中。
 */

require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');

@session_start(); // <--- [估清] 启动会话
header('Content-Type: application/json; charset=utf-8');

// [估清] 获取当前登录的 store_id
// 注意：这要求在调用此API之前，用户必须已登录 (index.php 会保证这一点)
$store_id = (int)($_SESSION['pos_store_id'] ?? 0);
if ($store_id === 0) {
    // 如果没有 store_id (例如，在登录页面)，我们仍然加载数据，但估清状态将全部为 0
    // 这没问题，因为估清面板只在登录后可用。
}

try {
    // 1. Fetch all active POS categories
    // ... (此部分代码不变) ...
    $categories_sql = "SELECT category_code AS `key`, name_zh AS label_zh, name_es AS label_es FROM pos_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC";
    $categories = $pdo->query($categories_sql)->fetchAll(PDO::FETCH_ASSOC);


    // (V2.2 GATING) Step 1: Pre-fetch all Gating rules...
    // ... (此部分代码不变) ...
    $gating_data = [ 'ice' => [], 'sweetness' => [] ];
    $ice_rules = $pdo->query("SELECT product_id, ice_option_id FROM kds_product_ice_options WHERE ice_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ice_rules as $rule) { $gating_data['ice'][(int)$rule['product_id']][] = (int)$rule['ice_option_id']; }
    $sweet_rules = $pdo->query("SELECT product_id, sweetness_option_id FROM kds_product_sweetness_options WHERE sweetness_option_id > 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sweet_rules as $rule) { $gating_data['sweetness'][(int)$rule['product_id']][] = (int)$rule['sweetness_option_id']; }
    $managed_ice_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_ice_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_sweet_products = $pdo->query("SELECT DISTINCT product_id FROM kds_product_sweetness_options")->fetchAll(PDO::FETCH_COLUMN, 0);
    $managed_ice_set = array_flip($managed_ice_products);
    $managed_sweet_set = array_flip($managed_sweet_products);


    // 2. Fetch all active menu items and their variants
    // [估清] 
    // - 新增 LEFT JOIN pos_product_availability pa
    // - 新增 SELECT COALESCE(pa.is_sold_out, 0) AS is_sold_out
    // - 新增 AND pa.store_id = :store_id
    $menu_sql = "
        SELECT 
            mi.id,
            mi.name_zh,
            mi.name_es,
            mi.image_url,
            pc.category_code,
            pv.id as variant_id,
            pv.variant_name_zh,
            pv.variant_name_es,
            pv.price_eur,
            pv.is_default,
            kp.product_code AS product_sku,
            kp.id AS kds_product_id,
            COALESCE(pa.is_sold_out, 0) AS is_sold_out
        FROM pos_menu_items mi
        JOIN pos_item_variants pv ON mi.id = pv.menu_item_id
        JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN kds_products kp ON mi.product_code = kp.product_code
        LEFT JOIN pos_product_availability pa ON mi.id = pa.menu_item_id AND pa.store_id = :store_id
        WHERE mi.deleted_at IS NULL 
          AND mi.is_active = 1
          AND pv.deleted_at IS NULL
          AND pc.deleted_at IS NULL
        ORDER BY pc.sort_order, mi.sort_order, mi.id, pv.sort_order
    ";
    
    // [估清] 绑定 :store_id
    $stmt_menu = $pdo->prepare($menu_sql);
    $stmt_menu->execute([':store_id' => $store_id]);
    $results = $stmt_menu->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($results as $row) {
        $itemId = (int)$row['id'];
        if (!isset($products[$itemId])) {
            $kds_pid = $row['kds_product_id'] ? (int)$row['kds_product_id'] : null;
            
            // (V2.2 GATING Logic)
            $allowed_ice_ids = null;
            $allowed_sweetness_ids = null;
            if ($kds_pid) {
                if (isset($managed_ice_set[$kds_pid])) {
                    $allowed_ice_ids = $gating_data['ice'][$kds_pid] ?? [];
                }
                if (isset($managed_sweet_set[$kds_pid])) {
                    $allowed_sweetness_ids = $gating_data['sweetness'][$kds_pid] ?? [];
                }
            }
            
            $products[$itemId] = [
                'id' => $itemId, 
                'title_zh' => $row['name_zh'],
                'title_es' => $row['name_es'],
                'image_url' => $row['image_url'],
                'category_key' => $row['category_code'],
                'allowed_ice_ids' => $allowed_ice_ids,
                'allowed_sweetness_ids' => $allowed_sweetness_ids,
                'is_sold_out' => (int)$row['is_sold_out'], // <--- [估清] 添加状态
                'variants' => []
            ];
        }
        
        $products[$itemId]['variants'][] = [
            'id' => (int)$row['variant_id'],
            'recipe_sku' => $row['product_sku'],
            'name_zh' => $row['variant_name_zh'],
            'name_es' => $row['variant_name_es'],
            'price_eur' => (float)$row['price_eur'],
            'is_default' => (bool)$row['is_default']
        ];
    }
    
    // ... (后续的 Addons, Ice Options, Sweetness Options, SIF Declaration 逻辑保持不变) ...

    // [GEMINI ADDON_FIX] Load addons from database instead of hardcoding
    try {
        $addons_sql = "
            SELECT 
                addon_code AS `key`, 
                name_zh AS label_zh, 
                name_es AS label_es, 
                price_eur 
            FROM pos_addons 
            WHERE is_active = 1 AND deleted_at IS NULL 
            ORDER BY sort_order ASC
        ";
        $addons = $pdo->query($addons_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("POS Data Loader Warning: Could not load addons. Error: " . $e->getMessage());
        $addons = []; // Fallback to empty
    }
    
    // (V2.2 GATING) 3. Fetch Ice Options Master List
    $ice_options_sql = "
        SELECT i.id, i.ice_code, it_zh.ice_option_name AS name_zh, it_es.ice_option_name AS name_es
        FROM kds_ice_options i
        LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN'
        LEFT JOIN kds_ice_option_translations it_es ON i.id = it_es.ice_option_id AND it_es.language_code = 'es-ES'
        WHERE i.deleted_at IS NULL ORDER BY i.ice_code ASC
    ";
    $ice_options = $pdo->query($ice_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    // (V2.2 GATING) 4. Fetch Sweetness Options Master List
    $sweetness_options_sql = "
        SELECT s.id, s.sweetness_code, st_zh.sweetness_option_name AS name_zh, st_es.sweetness_option_name AS name_es
        FROM kds_sweetness_options s
        LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN'
        LEFT JOIN kds_sweetness_option_translations st_es ON s.id = st_es.sweetness_option_id AND st_es.language_code = 'es-ES'
        WHERE s.deleted_at IS NULL ORDER BY s.sweetness_code ASC
    ";
    $sweetness_options = $pdo->query($sweetness_options_sql)->fetchAll(PDO::FETCH_ASSOC);

    // --- Redemption Rules ---
    $redemption_rules = [];
    try {
        $rules_sql = "
            SELECT id, rule_name_zh, rule_name_es, points_required, reward_type, reward_value_decimal, reward_promo_id
            FROM pos_point_redemption_rules
            WHERE is_active = 1 AND deleted_at IS NULL
            ORDER BY points_required ASC
        ";
        $redemption_rules = $pdo->query($rules_sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("POS Data Loader Warning: Could not load point redemption rules (Table might be missing). Error: " . $e->getMessage());
        $redemption_rules = []; 
    }

    // --- [GEMINI SIF_DR_FIX] START: Fetch SIF Declaration ---
    $sif_declaration = '';
    try {
        $stmt_sif = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'sif_declaracion_responsable'");
        $stmt_sif->execute();
        $sif_declaration = $stmt_sif->fetchColumn();
        if ($sif_declaration === false) {
            $sif_declaration = ''; 
        }
    } catch (PDOException $e) {
        error_log("POS Data Loader Warning: Could not load SIF Declaration. Error: " . $e->getMessage());
        $sif_declaration = 'Error: No se pudo cargar la declaración.';
    }
    // --- [GEMINI SIF_DR_FIX] END ---

    $data_payload = [
        'products' => array_values($products),
        'addons' => $addons,
        'categories' => $categories,
        'redemption_rules' => $redemption_rules,
        'ice_options' => $ice_options,
        'sweetness_options' => $sweetness_options,
        'sif_declaration' => $sif_declaration
    ];

    echo json_encode(['status' => 'success', 'data' => $data_payload]);

} catch (PDOException $e) {
    error_log("POS Data Loader CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '从数据库加载POS数据失败。', 'debug' => $e->getMessage()]);
}
?>