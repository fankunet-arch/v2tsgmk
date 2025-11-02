<?php
/**
 * TopTea POS - Data Loader API (Self-Contained)
 * Engineer: Gemini | Date: 2025-11-02 | Revision: 6.0 (RMS V2.2 - Gating Implementation)
 */

require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Fetch all active POS categories
    $categories_sql = "SELECT category_code AS `key`, name_zh AS label_zh, name_es AS label_es FROM pos_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC";
    $categories = $pdo->query($categories_sql)->fetchAll(PDO::FETCH_ASSOC);

    // (V2.2 GATING) Step 1: Pre-fetch all Gating rules into maps
    $gating_data = [
        'ice' => [],
        'sweetness' => []
    ];
    $ice_rules = $pdo->query("SELECT product_id, ice_option_id FROM kds_product_ice_options")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ice_rules as $rule) {
        $gating_data['ice'][(int)$rule['product_id']][] = (int)$rule['ice_option_id'];
    }
    $sweet_rules = $pdo->query("SELECT product_id, sweetness_option_id FROM kds_product_sweetness_options")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sweet_rules as $rule) {
        $gating_data['sweetness'][(int)$rule['product_id']][] = (int)$rule['sweetness_option_id'];
    }


    // 2. Fetch all active menu items and their variants
    // (V2.2 GATING) Added kp.id
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
            kp.id AS kds_product_id
        FROM pos_menu_items mi
        JOIN pos_item_variants pv ON mi.id = pv.menu_item_id
        JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN kds_products kp ON mi.product_code = kp.product_code
        WHERE mi.deleted_at IS NULL 
          AND mi.is_active = 1
          AND pv.deleted_at IS NULL
          AND pc.deleted_at IS NULL
        ORDER BY pc.sort_order, mi.sort_order, mi.id, pv.sort_order
    ";
    
    $results = $pdo->query($menu_sql)->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($results as $row) {
        $itemId = (int)$row['id'];
        if (!isset($products[$itemId])) {
            $kds_pid = $row['kds_product_id'] ? (int)$row['kds_product_id'] : null;
            
            // (V2.2 GATING) Get allowed IDs. 
            // null = All allowed (legacy/no rules set)
            // array = Only these are allowed (even if empty array)
            $allowed_ice_ids = null;
            $allowed_sweetness_ids = null;

            if ($kds_pid) {
                if (array_key_exists($kds_pid, $gating_data['ice'])) {
                    $allowed_ice_ids = $gating_data['ice'][$kds_pid];
                }
                if (array_key_exists($kds_pid, $gating_data['sweetness'])) {
                    $allowed_sweetness_ids = $gating_data['sweetness'][$kds_pid];
                }
            }
            
            $products[$itemId] = [
                'id' => $itemId, 
                'title_zh' => $row['name_zh'],
                'title_es' => $row['name_es'],
                'image_url' => $row['image_url'],
                'category_key' => $row['category_code'],
                'allowed_ice_ids' => $allowed_ice_ids,         // (V2.2)
                'allowed_sweetness_ids' => $allowed_sweetness_ids, // (V2.2)
                'variants' => []
            ];
        }
        
        $products[$itemId]['variants'][] = [
            'id' => (int)$row['variant_id'],
            'recipe_sku' => $row['product_sku'], // product_sku 可能为 NULL (如果 LEFT JOIN 失败)
            'name_zh' => $row['variant_name_zh'],
            'name_es' => $row['variant_name_es'],
            'price_eur' => (float)$row['price_eur'],
            'is_default' => (bool)$row['is_default']
        ];
    }

    $addons = [
        ['key' => 'boba', 'label_zh' => '珍珠', 'label_es' => 'Boba', 'price_eur' => 0.6],
        ['key' => 'coconut', 'label_zh' => '椰果', 'label_es' => 'Coco', 'price_eur' => 0.5],
        ['key' => 'pudding', 'label_zh' => '布丁', 'label_es' => 'Pudin', 'price_eur' => 0.7],
    ];

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

    // --- 健壮性修复：添加 try-catch 以防 pos_point_redemption_rules 表不存在 ---
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
    // -------------------------------------------------------------------------------------------------

    $data_payload = [
        'products' => array_values($products),
        'addons' => $addons,
        'categories' => $categories,
        'redemption_rules' => $redemption_rules,
        'ice_options' => $ice_options,             // (V2.2)
        'sweetness_options' => $sweetness_options    // (V2.2)
    ];

    echo json_encode(['status' => 'success', 'data' => $data_payload]);

} catch (PDOException $e) {
    error_log("POS Data Loader CRITICAL ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '从数据库加载POS数据失败。', 'debug' => $e->getMessage()]);
}
?>