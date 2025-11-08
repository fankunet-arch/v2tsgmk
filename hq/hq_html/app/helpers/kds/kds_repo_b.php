<?php
/**
 * KDS Repo B - Catalog + Misc B (Phase 2 consolidation)
 *
 * [GEMINI V17.0 REFACTOR]:
 * 1. Added norm_cat() (merged from kds_services.php)
 * 2. Added getAllGlobalRules() (moved from index.php)
 *
 * [GEMINI V18.0 RUNTIME FIX]:
 * 1. Removed all function definitions that were duplicates from kds_repo_a.php or kds_repo_c.php
 * (e.g., id_by_code, m_name, u_name, check_gating, get_base_recipe, etc.)
 * This file now ONLY contains functions unique to it or correctly moved here.
 */

/**
 * (Merged) KDS i18n helpers
 * (Merged from kds_services.php)
 */
function norm_cat(string $c): string {
    $c = trim(mb_strtolower($c));
    if (in_array($c, ['base', '底料', 'diliao'], true)) return 'base';
    if (in_array($c, ['top', 'topping', '顶料', 'dingliao'], true)) return 'topping';
    return 'mixing'; // 默认为 调杯
}


/**
 * KDS Repo - Catalog (products/SKU/menu)
 * Extracted from kds_repository.php (Phase 1 split).
 */

function getAllBaseProducts(PDO $pdo): array {
    $sql = "
        SELECT 
            p.id, 
            p.product_code, 
            pt_zh.product_name AS name_zh,
            pt_es.product_name AS name_es
        FROM kds_products p
        LEFT JOIN kds_product_translations pt_zh ON p.id = pt_zh.product_id AND pt_zh.language_code = 'zh-CN'
        LEFT JOIN kds_product_translations pt_es ON p.id = pt_es.product_id AND pt_es.language_code = 'es-ES'
        WHERE p.deleted_at IS NULL
        ORDER BY p.product_code ASC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getProductDetailsForRMS(PDO $pdo, int $productId): ?array {
    // 1. 获取基础产品信息
    $stmt_product = $pdo->prepare("
        SELECT 
            p.id, 
            p.product_code,
            pt_zh.product_name AS name_zh,
            pt_es.product_name AS name_es,
            p.status_id,
            p.is_active
        FROM kds_products p
        LEFT JOIN kds_product_translations pt_zh ON p.id = pt_zh.product_id AND pt_zh.language_code = 'zh-CN'
        LEFT JOIN kds_product_translations pt_es ON p.id = pt_es.product_id AND pt_es.language_code = 'es-ES'
        WHERE p.id = ? AND p.deleted_at IS NULL
    ");
    $stmt_product->execute([$productId]);
    $product = $stmt_product->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        return null;
    }

    // 2. 获取基础配方步骤
    $product['base_recipes'] = getRecipesByProductId($pdo, $productId);

    // 3. 获取所有调整规则
    $stmt_adjustments = $pdo->prepare("SELECT * FROM kds_recipe_adjustments WHERE product_id = ?");
    $stmt_adjustments->execute([$productId]);
    $product['adjustments'] = $stmt_adjustments->fetchAll(PDO::FETCH_ASSOC);

    // 4. (V2.2 GATING) 获取已勾选的 Gating 选项
    $gatingOptions = getProductSelectedOptions($pdo, $productId);
    $product['allowed_ice_ids'] = $gatingOptions['ice_ids'];
    $product['allowed_sweetness_ids'] = $gatingOptions['sweetness_ids'];

    return $product;
}

function getAllProducts(PDO $pdo): array {
    $sql = "SELECT p.id, p.product_code, pt_zh.product_name AS name_zh, pt_es.product_name AS name_es, ps.status_name_zh AS status_name, p.is_active, p.created_at FROM kds_products p LEFT JOIN kds_product_translations pt_zh ON p.id = pt_zh.product_id AND pt_zh.language_code = 'zh-CN' LEFT JOIN kds_product_translations pt_es ON p.id = pt_es.product_id AND pt_es.language_code = 'es-ES' LEFT JOIN kds_product_statuses ps ON p.status_id = ps.id WHERE p.deleted_at IS NULL ORDER BY p.product_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProductById(PDO $pdo, int $id) {
    $sql = "SELECT p.*, pt_zh.product_name AS name_zh, pt_es.product_name AS name_es FROM kds_products p LEFT JOIN kds_product_translations pt_zh ON p.id = pt_zh.product_id AND pt_zh.language_code = 'zh-CN' LEFT JOIN kds_product_translations pt_es ON p.id = pt_es.product_id AND pt_es.language_code = 'es-ES' WHERE p.id = ? AND p.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getRecipesByProductId(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM kds_product_recipes WHERE product_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProductSelectedOptions(PDO $pdo, int $product_id): array {
    $options = ['sweetness_ids' => [], 'ice_ids' => []];
    
    $stmt_sweetness = $pdo->prepare("SELECT sweetness_option_id FROM kds_product_sweetness_options WHERE product_id = ?");
    $stmt_sweetness->execute([$product_id]);
    $options['sweetness_ids'] = $stmt_sweetness->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt_ice = $pdo->prepare("SELECT ice_option_id FROM kds_product_ice_options WHERE product_id = ?");
    $stmt_ice->execute([$product_id]);
    $options['ice_ids'] = $stmt_ice->fetchAll(PDO::FETCH_COLUMN, 0);
    
    return $options;
}

function getProductAdjustments(PDO $pdo, int $product_id): array {
    $stmt = $pdo->prepare("SELECT * FROM kds_product_adjustments WHERE product_id = ?");
    $stmt->execute([$product_id]);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[$row['option_type']][$row['option_id']] = $row;
    }
    return $results;
}

function getAllMenuItems(PDO $pdo): array {
    $sql = "
        SELECT 
            mi.id,
            mi.name_zh,
            mi.sort_order,
            mi.is_active,
            pc.name_zh AS category_name_zh,
            GROUP_CONCAT(pv.variant_name_zh SEPARATOR ', ') AS variants
        FROM pos_menu_items mi
        LEFT JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN pos_item_variants pv ON mi.id = pv.menu_item_id AND pv.deleted_at IS NULL
        WHERE mi.deleted_at IS NULL
        GROUP BY mi.id
        ORDER BY pc.sort_order ASC, mi.sort_order ASC, mi.id ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMenuItemById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id, name_zh FROM pos_menu_items WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllVariantsByMenuItemId(PDO $pdo, int $menu_item_id): array {
    // --- START: [GEMINI 500_ERROR_FIX] ---
    // 1. 修复了 JOIN kds_product_translations pt ON p.id ... 的歧义性
    // 2. 添加了 COALESCE，防止 $variant['product_sku'] 或 $variant['recipe_name_zh'] 为 NULL，
    //    这会导致 view 文件中 `NULL . ' - ' . NULL` 尝试连接空值，引发 500 错误。
    $sql = "
        SELECT 
            pv.id,
            pv.variant_name_zh,
            pv.price_eur,
            pv.sort_order,
            pv.is_default,
            COALESCE(p.product_code, 'N/A') AS product_sku,
            COALESCE(pt.product_name, '未关联配方') AS recipe_name_zh,
            p.id AS product_id,
            pv.cup_id
        FROM pos_item_variants pv
        INNER JOIN pos_menu_items mi ON pv.menu_item_id = mi.id
        LEFT JOIN kds_products p ON mi.product_code = p.product_code AND p.deleted_at IS NULL
        LEFT JOIN kds_product_translations pt ON p.id = pt.product_id AND pt.language_code = 'zh-CN'
        WHERE pv.menu_item_id = ? AND pv.deleted_at IS NULL
        ORDER BY pv.sort_order ASC, pv.id ASC
    ";
    // --- END: [GEMINI 500_ERROR_FIX] ---
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$menu_item_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllProductRecipesForSelect(PDO $pdo): array {
    $sql = "
        SELECT 
            p.id,
            p.product_code AS product_sku,
            pt.product_name AS name_zh
        FROM kds_products p
        LEFT JOIN kds_product_translations pt ON p.id = pt.product_id AND pt.language_code = 'zh-CN'
        WHERE p.deleted_at IS NULL
        ORDER BY p.product_code ASC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getAllMenuItemsForSelect(PDO $pdo): array {
    $sql = "SELECT id, name_zh FROM pos_menu_items WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name_zh ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// --- [GEMINI V18.0 RUNTIME FIX] START: Removed duplicate functions that exist in other repo files ---
/*
 * All functions below were causing a "Cannot redeclare function" fatal error.
 * They are already correctly defined in kds_repo_a.php or kds_repo_c.php
 *
 * function id_by_code(...) { ... }
 * function m_name(...) { ... }
 * function u_name(...) { ... }
 * function check_gating(...) { ... }
 * function get_base_recipe(...) { ... }
 * function apply_global_rules(...) { ... }
 * function apply_overrides(...) { ... }
 * function best_adjust_l3(...) { ... }
 * function get_available_options(...) { ... }
 * function get_product(...) { ... }
 * function get_product_info_bilingual(...) { ... }
 * function get_base_recipe_bilingual(...) { ... }
 * function getKdsSopByCode(...) { ... }
 */
// --- [GEMINI V18.0 RUNTIME FIX] END ---


/**
 * KDS Repo - Misc B
 * Split from kds_repo_misc.php (Phase 1b).
 */

// --- START: Function moved from index.php ---

// (V2.2) Helper to get global rules
function getAllGlobalRules(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM kds_global_adjustment_rules ORDER BY priority ASC, id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Handle if table doesn't exist yet
        if ($e->getCode() == '42S02') { 
             error_log("Warning: kds_global_adjustment_rules table not found. " . $e->getMessage());
             return [];
        }
        throw $e;
    }
}

// --- END: Function moved from index.php ---
