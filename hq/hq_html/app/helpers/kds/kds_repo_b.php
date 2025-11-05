<?php
/**
 * KDS Repo B - Catalog + Misc B (Phase 2 consolidation)
 *
 * [GEMINI V17.0 REFACTOR]:
 * 1. Added norm_cat() (merged from kds_services.php)
 * 2. Added getAllGlobalRules() (moved from index.php)
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

// --- [GEMINI FIX V2] START: Functions moved from kds_repo_a.php ---

/**
 * @param PDO $pdo
 * @param string $table
 * @param string $col
 * @param mixed $val
 * @return int|null
 */
function id_by_code(PDO $pdo, string $table, string $col, $val): ?int {
    if ($val === null || $val === '') return null;
    $st = $pdo->prepare("SELECT id FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$val]);
    $id = $st->fetchColumn(); return $id ? (int)$id : null;
}

function m_name(PDO $pdo, int $mid): array {
    $st = $pdo->prepare("SELECT language_code, material_name FROM kds_material_translations WHERE material_id=?");
    $st->execute([$mid]);
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    return ['zh' => $names['zh-CN'] ?? ('#' . $mid), 'es' => $names['es-ES'] ?? ('#' . $mid)];
}

function u_name(PDO $pdo, int $uid): array {
    $st = $pdo->prepare("SELECT language_code, unit_name FROM kds_unit_translations WHERE unit_id=?");
    $st->execute([$uid]);
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    return ['zh' => $names['zh-CN'] ?? '', 'es' => $names['es-ES'] ?? ''];
}

function check_gating(PDO $pdo, int $pid, ?int $cup_id, ?int $ice_id, ?int $sweet_id) {
    // 2. 检查冰量 Gating
    $ice_rules = $pdo->prepare("SELECT ice_option_id FROM kds_product_ice_options WHERE product_id = ?");
    $ice_rules->execute([$pid]);
    $allowed_ice_ids = $ice_rules->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($allowed_ice_ids)) { // 存在冰量规则
        if ($ice_id === null) throw new Exception("此产品需要冰量 (M-code)，但未提供。", 403);
        if (!in_array(0, $allowed_ice_ids) && !in_array($ice_id, $allowed_ice_ids)) { // 允许 0 (标记) 或 匹配
            throw new Exception("冰量 (M-code) 不适用于此产品。", 403);
        }
    }

    // 3. 检查甜度 Gating
    $sweet_rules = $pdo->prepare("SELECT sweetness_option_id FROM kds_product_sweetness_options WHERE product_id = ?");
    $sweet_rules->execute([$pid]);
    $allowed_sweet_ids = $sweet_rules->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($allowed_sweet_ids)) { // 存在甜度规则
        if ($sweet_id === null) throw new Exception("此产品需要甜度 (T-code)，但未提供。", 403);
         if (!in_array(0, $allowed_sweet_ids) && !in_array($sweet_id, $allowed_sweet_ids)) { // 允许 0 (标记) 或 匹配
            throw new Exception("甜度 (T-code) 不适用于此产品。", 403);
        }
    }
}

function get_base_recipe(PDO $pdo, int $pid): array {
    $st = $pdo->prepare("SELECT material_id, quantity, unit_id, step_category, sort_order FROM kds_product_recipes WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $st->execute([$pid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $recipe_map = [];
    foreach ($rows as $row) {
        $recipe_map[(int)$row['material_id']] = [
            'material_id' => (int)$row['material_id'],
            'quantity' => (float)$row['quantity'],
            'unit_id' => (int)$row['unit_id'],
            'step_category' => norm_cat((string)$row['step_category']),
            'sort_order' => (int)$row['sort_order'],
            'source' => 'L1'
        ];
    }
    return $recipe_map;
}

function apply_global_rules(PDO $pdo, array $recipe, ?int $cup, ?int $ice, ?int $sweet): array {
    $st = $pdo->prepare("SELECT * FROM kds_global_adjustment_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC");
    $st->execute();
    $rules = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $rule) {
        if ($rule['cond_cup_id'] !== null && $rule['cond_cup_id'] != $cup) continue;
        if ($rule['cond_ice_id'] !== null && $rule['cond_ice_id'] != $ice) continue;
        if ($rule['cond_sweet_id'] !== null && $rule['cond_sweet_id'] != $sweet) continue;
        $target_mid = (int)$rule['action_material_id'];
        if ($rule['cond_material_id'] !== null) {
            if ($rule['cond_material_id'] != $target_mid) continue; 
            if (!isset($recipe[$target_mid])) continue; 
        }
        if (!isset($recipe[$target_mid]) && $rule['action_type'] !== 'ADD_MATERIAL') {
             continue;
        }
        $cond_base_gt = $rule['cond_base_gt'];
        $cond_base_lte = $rule['cond_base_lte'];
        if ($cond_base_gt !== null || $cond_base_lte !== null) {
            if (!isset($recipe[$target_mid])) {
                continue; 
            }
            $base_quantity = (float)$recipe[$target_mid]['quantity'];
            if ($cond_base_gt !== null && !($base_quantity > (float)$cond_base_gt)) {
                continue; 
            }
            if ($cond_base_lte !== null && !($base_quantity <= (float)$cond_base_lte)) {
                continue; 
            }
        }
        $value = (float)$rule['action_value'];
        switch ($rule['action_type']) {
            case 'SET_VALUE':
                $recipe[$target_mid]['quantity'] = $value;
                $recipe[$target_mid]['source'] = 'L2-SET';
                break;
            case 'ADD_MATERIAL':
                if (!isset($recipe[$target_mid])) { 
                    $recipe[$target_mid] = [
                        'material_id' => $target_mid,
                        'quantity' => $value,
                        'unit_id' => (int)$rule['action_unit_id'],
                        'step_category' => 'mixing', 
                        'sort_order' => 500 + $target_mid, 
                        'source' => 'L2-ADD'
                    ];
                }
                break;
            case 'CONDITIONAL_OFFSET':
                $recipe[$target_mid]['quantity'] += $value;
                $recipe[$target_mid]['source'] = 'L2-OFFSET';
                break;
            case 'MULTIPLY_BASE':
                $recipe[$target_mid]['quantity'] *= $value;
                $recipe[$target_mid]['source'] = 'L2-MULTIPLY';
                break;
        }
    }
    return $recipe;
}

function apply_overrides(PDO $pdo, int $pid, array $recipe, ?int $cup, ?int $ice, ?int $sweet): array {
    $st = $pdo->prepare("SELECT DISTINCT material_id FROM kds_recipe_adjustments WHERE product_id = ?");
    $st->execute([$pid]);
    $l3_material_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $all_mids_to_check = array_unique(array_merge(array_keys($recipe), $l3_material_ids));

    foreach ($all_mids_to_check as $mid) {
        $mid = (int)$mid;
        $adj = best_adjust_l3($pdo, $pid, $mid, $cup, $ice, $sweet);
        
        if ($adj) {
            $recipe[$mid] = [
                'material_id' => $mid,
                'quantity' => (float)$adj['quantity'],
                'unit_id' => (int)$adj['unit_id'],
                'step_category' => norm_cat((string)$adj['step_category']),
                'sort_order' => $recipe[$mid]['sort_order'] ?? (600 + $mid), 
                'source' => 'L3-OVERRIDE'
            ];
        }
    }
    return $recipe;
}

function best_adjust_l3(PDO $pdo, int $pid, int $mid, ?int $cup, ?int $ice, ?int $sweet): ?array {
    $cond = ["product_id=?", "material_id=?"]; $args = [$pid, $mid]; $score = [];
    if ($cup !== null) { $cond[] = "(cup_id IS NULL OR cup_id=?)"; $args[] = $cup; $score[] = "(cup_id IS NOT NULL)"; } else { $cond[] = "(cup_id IS NULL)"; }
    if ($ice !== null) { $cond[] = "(ice_option_id IS NULL OR ice_option_id=?)"; $args[] = $ice; $score[] = "(ice_option_id IS NOT NULL)"; } else { $cond[] = "(ice_option_id IS NULL)"; }
    if ($sweet !== null) { $cond[] = "(sweetness_option_id IS NULL OR sweetness_option_id=?)"; $args[] = $sweet; $score[] = "(sweetness_option_id IS NOT NULL)"; } else { $cond[] = "(sweetness_option_id IS NULL)"; }
    $scoreExpr = $score ? implode(' + ', $score) : '0';
    $sql = "SELECT material_id, quantity, unit_id, step_category FROM kds_recipe_adjustments
            WHERE " . implode(' AND ', $cond) . " ORDER BY {$scoreExpr} DESC, id DESC LIMIT 1";
    $st = $pdo->prepare($sql); $st->execute($args); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}

function get_available_options(PDO $pdo, int $pid): array {
    $options = ['cups' => [], 'ice_options' => [], 'sweetness_options' => []];
    
    // 1. Get Cups
    $cup_sql = "
        SELECT DISTINCT c.id, c.cup_code, c.cup_name, c.sop_description_zh, c.sop_description_es
        FROM kds_cups c
        JOIN pos_item_variants piv ON c.id = piv.cup_id
        JOIN pos_menu_items pmi ON piv.menu_item_id = pmi.id
        JOIN kds_products kp ON pmi.product_code = kp.product_code
        WHERE kp.id = ? AND c.deleted_at IS NULL AND piv.deleted_at IS NULL
    ";
    $stmt_cups = $pdo->prepare($cup_sql); $stmt_cups->execute([$pid]);
    $options['cups'] = $stmt_cups->fetchAll(PDO::FETCH_ASSOC);

    // 2. Get Ice Options (Gating)
    $ice_sql = "
        SELECT io.id, io.ice_code, iot_zh.ice_option_name AS name_zh, iot_es.ice_option_name AS name_es
        FROM kds_product_ice_options pio
        JOIN kds_ice_options io ON pio.ice_option_id = io.id
        LEFT JOIN kds_ice_option_translations iot_zh ON io.id = iot_zh.ice_option_id AND iot_zh.language_code = 'zh-CN'
        LEFT JOIN kds_ice_option_translations iot_es ON io.id = iot_es.ice_option_id AND iot_es.language_code = 'es-ES'
        WHERE pio.product_id = ? AND io.deleted_at IS NULL AND io.id > 0 ORDER BY io.ice_code
    ";
    $stmt_ice = $pdo->prepare($ice_sql); $stmt_ice->execute([$pid]);
    $options['ice_options'] = $stmt_ice->fetchAll(PDO::FETCH_ASSOC);
    if (empty($options['ice_options'])) { // Gating 未设置 (或只设了 0), 返回所有
        $options['ice_options'] = $pdo->query("SELECT io.id, io.ice_code, iot_zh.ice_option_name AS name_zh, iot_es.ice_option_name AS name_es FROM kds_ice_options io LEFT JOIN kds_ice_option_translations iot_zh ON io.id = iot_zh.ice_option_id AND iot_zh.language_code = 'zh-CN' LEFT JOIN kds_ice_option_translations iot_es ON io.id = iot_es.ice_option_id AND iot_es.language_code = 'es-ES' WHERE io.deleted_at IS NULL ORDER BY io.ice_code")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 3. Get Sweetness Options (Gating)
    $sweet_sql = "SELECT so.id, so.sweetness_code, sot_zh.sweetness_option_name AS name_zh, sot_es.sweetness_option_name AS name_es
                  FROM kds_product_sweetness_options pso
                  JOIN kds_sweetness_options so ON pso.sweetness_option_id = so.id
                  LEFT JOIN kds_sweetness_option_translations sot_zh ON so.id = sot_zh.sweetness_option_id AND sot_zh.language_code = 'zh-CN'
                  LEFT JOIN kds_sweetness_option_translations sot_es ON so.id = sot_es.sweetness_option_id AND sot_es.language_code = 'es-ES'
                  WHERE pso.product_id = ? AND so.deleted_at IS NULL AND so.id > 0 ORDER BY so.sweetness_code";
    $stmt_sweet = $pdo->prepare($sweet_sql); $stmt_sweet->execute([$pid]);
    $options['sweetness_options'] = $stmt_sweet->fetchAll(PDO::FETCH_ASSOC);
    if (empty($options['sweetness_options'])) { // Gating 未设置 (或只设了 0), 返回所有
        $options['sweetness_options'] = $pdo->query("SELECT so.id, so.sweetness_code, sot_zh.sweetness_option_name AS name_zh, sot_es.sweetness_option_name AS name_es FROM kds_sweetness_options so LEFT JOIN kds_sweetness_option_translations sot_zh ON so.id = sot_zh.sweetness_option_id AND sot_zh.language_code = 'zh-CN' LEFT JOIN kds_sweetness_option_translations sot_es ON so.id = sot_es.sweetness_option_id AND sot_es.language_code = 'es-ES' WHERE so.deleted_at IS NULL ORDER BY so.sweetness_code")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $options;
}
// --- [GEMINI FIX V2] END: Functions moved from kds_repo_a.php ---


function get_product(PDO $pdo, string $p_code): ?array {
        $st = $pdo->prepare("SELECT id, product_code, status_id, is_active, deleted_at FROM kds_products WHERE product_code=? LIMIT 1"); $st->execute([$p_code]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
    }

function get_product_info_bilingual(PDO $pdo, int $pid, int $status_id): array {
        $st_prod = $pdo->prepare("SELECT pt_zh.product_name AS name_zh, pt_es.product_name AS name_es FROM kds_product_translations pt_zh LEFT JOIN kds_product_translations pt_es ON pt_zh.product_id = pt_es.product_id AND pt_es.language_code = 'es-ES' WHERE pt_zh.product_id = ? AND pt_zh.language_code = 'zh-CN'");
        $st_prod->execute([$pid]);
        $info = $st_prod->fetch(PDO::FETCH_ASSOC) ?: [];
        $st_status = $pdo->prepare("SELECT status_name_zh, status_name_es FROM kds_product_statuses WHERE id = ? AND deleted_at IS NULL");
        $st_status->execute([$status_id]);
        $status_names = $st_status->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_merge($info, $status_names);
    }

/**
 * KDS Repo - Misc B
 * Split from kds_repo_misc.php (Phase 1b).
 */

function get_base_recipe_bilingual(PDO $pdo, int $pid): array {
        $st = $pdo->prepare("
            SELECT r.material_id, r.quantity, r.unit_id, r.step_category
            FROM kds_product_recipes r
            WHERE r.product_id = ? ORDER BY r.sort_order ASC, r.id ASC
        ");
        $st->execute([$pid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $recipe = [];
        foreach ($rows as $row) {
            $m_names = m_name($pdo, (int)$row['material_id']);
            $u_names = u_name($pdo, (int)$row['unit_id']);
            $recipe[] = [
                'material_id'   => (int)$row['material_id'],
                'material_zh' => $m_names['zh'],
                'material_es' => $m_names['es'],
                'quantity'      => (float)$row['quantity'],
                'unit_id'       => (int)$row['unit_id'],
                'unit_zh'       => $u_names['zh'],
                'unit_es'       => $u_names['es'],
                'step_category' => norm_cat((string)$row['step_category'])
            ];
        }
        return $recipe;
    }

function getKdsSopByCode(PDO $pdo, string $raw_code, int $store_id): array
    {
        try {
            $parser = new KdsSopParser($pdo, $store_id);
            $seg = $parser->parse($raw_code);
            if (!$seg || $seg['p'] === '') {
                return ['status' => 'error', 'message' => '编码不合法或未匹配任何解析规则。', 'data' => ['code' => $raw_code], 'http_code' => 400];
            }

            $prod = get_product($pdo, $seg['p']);
            if (!$prod || $prod['deleted_at'] !== null || (int)$prod['is_active'] !== 1) {
                return ['status' => 'error', 'message' => '找不到该产品或未上架 (P-Code: ' . htmlspecialchars($seg['p']) . ')', 'data' => null, 'http_code' => 404];
            }
            $pid = (int)$prod['id'];

            $prod_info = array_merge(
                ['product_id' => $pid, 'product_code' => $prod['product_code']],
                get_product_info_bilingual($pdo, $pid, (int)$prod['status_id'])
            );

            // 3. (P-Code ONLY) 仅查询基础信息
            if ($seg['a'] === null && $seg['m'] === null && $seg['t'] === null) {
                $data = [
                    'type' => 'base_info',
                    'product' => $prod_info,
                    'recipe' => get_base_recipe_bilingual($pdo, $pid), // L1
                    'options' => get_available_options($pdo, $pid) // Gating
                ];
                return ['status' => 'success', 'message' => 'OK', 'data' => $data, 'http_code' => 200];
            }

            // 4. (P-A-M-T) 动态计算配方

            // 4a. 将 A,M,T 码转换为 数据库 ID
            $cup_id = id_by_code($pdo, 'kds_cups', 'cup_code', $seg['a']);
            if ($seg['a'] !== null && $cup_id === null) return ['status' => 'error', 'message' => '杯型编码 (A-code) 无效: ' . htmlspecialchars($seg['a']), 'data' => null, 'http_code' => 404];
            
            $ice_id = id_by_code($pdo, 'kds_ice_options', 'ice_code', $seg['m']);
            if ($seg['m'] !== null && $ice_id === null) return ['status' => 'error', 'message' => '冰量编码 (M-code) 无效: ' . htmlspecialchars($seg['m']), 'data' => null, 'http_code' => 404];

            $sweet_id = id_by_code($pdo, 'kds_sweetness_options', 'sweetness_code', $seg['t']);
            if ($seg['t'] !== null && $sweet_id === null) return ['status' => 'error', 'message' => '甜度编码 (T-code) 无效: ' . htmlspecialchars($seg['t']), 'data' => null, 'http_code' => 404];

            // 4b. [GATING] 验证选项是否被允许
            try {
                check_gating($pdo, $pid, $cup_id, $ice_id, $sweet_id);
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => $e->getMessage(), 'data' => ['code' => $e->getCode()], 'http_code' => 403];
            }

            // 4c. [L1] 获取基础配方
            $recipe_map = get_base_recipe($pdo, $pid);
            if (empty($recipe_map)) return ['status' => 'error', 'message' => '产品 (P-Code: ' . htmlspecialchars($seg['p']) . ') 缺少基础配方 (L1)，无法计算。', 'data' => null, 'http_code' => 404];

            // 4d. [L2] 应用全局规则
            $recipe_map = apply_global_rules($pdo, $recipe_map, $cup_id, $ice_id, $sweet_id);

            // 4e. [L3] 应用特例覆盖
            $recipe_map = apply_overrides($pdo, $pid, $recipe_map, $cup_id, $ice_id, $sweet_id);

            // 4f. 转换最终 map 为双语数组
            $final_recipe = [];
            foreach ($recipe_map as $item) {
                // 过滤掉数量为0或负数的物料
                if ($item['quantity'] <= 0) continue; 
                
                $m_names = m_name($pdo, (int)$item['material_id']);
                $u_names = u_name($pdo, (int)$item['unit_id']);
                
                $final_recipe[] = [
                    'material_zh'   => $m_names['zh'],
                    'material_es'   => $m_names['es'],
                    'unit_zh'       => $u_names['zh'],
                    'unit_es'       => $u_names['es'],
                    'quantity'      => (float)$item['quantity'],
                    'step_category' => norm_cat((string)$item['step_category'])
                    // 'source' => $item['source'] // (Debug)
                ];
            }
            
            // 4g. 补充左侧概览所需的选项名称
            $names = array_merge(
                get_cup_names_bilingual($pdo, $cup_id),
                get_ice_names_bilingual($pdo, $ice_id),
                get_sweet_names_bilingual($pdo, $sweet_id)
            );
            $prod_info = array_merge($prod_info, $names);

            $data = [
                'type' => 'adjusted_recipe',
                'product' => $prod_info,
                'recipe' => $final_recipe // <--- 返回计算后的配方
            ];
            return ['status' => 'success', 'message' => 'OK', 'data' => $data, 'http_code' => 200];

        } catch (Throwable $e) {
            error_log('getKdsSopByCode error: ' . $e->getMessage());
            $error_message = "[SOP Engine] " . $e->getMessage();
            return ['status' => 'error', 'message' => $error_message, 'data' => ['debug' => $e->getMessage()], 'http_code' => 500];
        }
    }

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

?>