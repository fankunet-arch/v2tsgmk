<?php
/**
 * TopTea · KDS · SOP 查询接口 (V11.3 - KDS Cup Gating Fix)
 *
 * 1. [GATING] 选项门控验证 (P-Code -> kds_product_..._options)
 * 2. [L1] Layer 1: kds_product_recipes (基础配方)
 * 3. [L2] Layer 2: kds_global_adjustment_rules (全局公式)
 * 4. [L3] Layer 3: kds_recipe_adjustments (特例覆盖)
 *
 * [V11.2 修复] 修正了 check_gating 和 get_available_options 中
 * 对 pos_item_variants.product_id 的错误引用。
 * [V11.3 修复] 移除了 check_gating 中对 A-code (杯型) 的错误校验。
 * KDS SOP 查询不应受 POS 销售规格 (pos_item_variants) 的限制。
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/* ========= 0) 引导配置 & 通用输出 ========= */
$pdo = $pdo ?? null;
try {
    $__html_root = dirname(__DIR__, 2); // /.../store_html/html
    $__config_path = realpath($__html_root . '/../kds/core/config.php');
    if (!$__config_path) throw new Exception('KDS config.php not found.');
    require_once $__config_path; // $pdo
    if (!$pdo) throw new Exception('PDO connection not initialized.');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '[V11 Boot] ' . $e->getMessage()]);
    exit;
}
function out_json(string $s, string $m, $d = null, int $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $s, 'message' => $m, 'data' => $d], JSON_UNESCAPED_UNICODE);
    exit;
}
function ok($d) { out_json('success', 'OK', $d, 200); }

/* ========= 1) V2.2 辅助函数 (双语, Gating, L1, L2, L3) ========= */

// --- 基础 ---
function parse_code(string $raw): ?array {
    $raw = strtoupper(trim($raw));
    if ($raw === '' || !preg_match('/^[A-Z0-9-]+$/', $raw)) return null;
    $seg = array_values(array_filter(explode('-', $raw), fn($s) => $s !== ''));
    if (count($seg) > 4) return null; // P / P-A / P-A-M / P-A-M-T
    return ['p' => $seg[0] ?? '', 'a' => $seg[1] ?? null, 'm' => $seg[2] ?? null, 't' => $seg[3] ?? null, 'raw' => $raw];
}
function id_by_code(PDO $pdo, string $table, string $col, $val): ?int {
    if ($val === null || $val === '') return null;
    $st = $pdo->prepare("SELECT id FROM {$table} WHERE {$col}=? LIMIT 1"); $st->execute([$val]);
    $id = $st->fetchColumn(); return $id ? (int)$id : null;
}
function get_product(PDO $pdo, string $p_code): ?array {
    $st = $pdo->prepare("SELECT id, product_code, status_id, is_active, deleted_at FROM kds_products WHERE product_code=? LIMIT 1"); $st->execute([$p_code]); $r = $st->fetch(PDO::FETCH_ASSOC); return $r ?: null;
}
function norm_cat(string $c): string {
    $c = trim(mb_strtolower($c));
    if (in_array($c, ['base', '底料', 'diliao'], true)) return 'base';
    if (in_array($c, ['top', 'topping', '顶料', 'dingliao'], true)) return 'topping';
    return 'mixing'; // 默认为 调杯
}

// --- 双语名称获取 ---
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
function get_product_info_bilingual(PDO $pdo, int $pid, int $status_id): array {
    $st_prod = $pdo->prepare("SELECT pt_zh.product_name AS name_zh, pt_es.product_name AS name_es FROM kds_product_translations pt_zh LEFT JOIN kds_product_translations pt_es ON pt_zh.product_id = pt_es.product_id AND pt_es.language_code = 'es-ES' WHERE pt_zh.product_id = ? AND pt_zh.language_code = 'zh-CN'");
    $st_prod->execute([$pid]);
    $info = $st_prod->fetch(PDO::FETCH_ASSOC) ?: [];
    $st_status = $pdo->prepare("SELECT status_name_zh, status_name_es FROM kds_product_statuses WHERE id = ? AND deleted_at IS NULL");
    $st_status->execute([$status_id]);
    $status_names = $st_status->fetch(PDO::FETCH_ASSOC) ?: [];
    return array_merge($info, $status_names);
}
function get_cup_names_bilingual(PDO $pdo, ?int $cid): array {
    if ($cid === null) return ['cup_name_zh' => null, 'cup_name_es' => null];
    $st = $pdo->prepare("SELECT cup_name, sop_description_zh, sop_description_es FROM kds_cups WHERE id = ?"); $st->execute([$cid]); $row = $st->fetch(PDO::FETCH_ASSOC);
    return ['cup_name_zh' => $row['sop_description_zh'] ?? $row['cup_name'] ?? null, 'cup_name_es' => $row['sop_description_es'] ?? $row['cup_name'] ?? null];
}
function get_ice_names_bilingual(PDO $pdo, ?int $iid): array {
    if ($iid === null) return ['ice_name_zh' => null, 'ice_name_es' => null];
    $st = $pdo->prepare("SELECT language_code, ice_option_name FROM kds_ice_option_translations WHERE ice_option_id = ?"); $st->execute([$iid]);
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    return ['ice_name_zh' => $names['zh-CN'] ?? null, 'ice_name_es' => $names['es-ES'] ?? $names['zh-CN'] ?? null];
}
function get_sweet_names_bilingual(PDO $pdo, ?int $sid): array {
    if ($sid === null) return ['sweetness_name_zh' => null, 'sweetness_name_es' => null];
    $st = $pdo->prepare("SELECT language_code, sweetness_option_name FROM kds_sweetness_option_translations WHERE sweetness_option_id = ?"); $st->execute([$sid]);
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    return ['sweetness_name_zh' => $names['zh-CN'] ?? null, 'sweetness_name_es' => $names['es-ES'] ?? $names['zh-CN'] ?? null];
}

// --- [GATING] 选项门控 ---
function check_gating(PDO $pdo, int $pid, ?int $cup_id, ?int $ice_id, ?int $sweet_id) {
    
    /*
    // 1. 检查杯型 Gating
    // [V11.3 修复] 禁用此块。 KDS SOP 查询不应受 POS 销售规格 (pos_item_variants) 的限制。
    // 只要 A-code 能通过 id_by_code 查到（即存在于 kds_cups），就应被视为有效。
    $cup_rules = $pdo->prepare("
        SELECT 1 FROM pos_item_variants piv
        JOIN pos_menu_items pmi ON piv.menu_item_id = pmi.id
        JOIN kds_products kp ON pmi.product_code = kp.product_code
        WHERE kp.id = ? AND piv.cup_id IS NOT NULL AND piv.deleted_at IS NULL
        LIMIT 1
    ");
    $cup_rules->execute([$pid]);
    if ($cup_rules->fetchColumn() !== false) { // 存在杯型规则
        if ($cup_id === null) throw new Exception("此产品需要杯型 (A-code)，但未提供。", 403);
        $cup_ok = $pdo->prepare("
            SELECT 1 FROM pos_item_variants piv
            JOIN pos_menu_items pmi ON piv.menu_item_id = pmi.id
            JOIN kds_products kp ON pmi.product_code = kp.product_code
            WHERE kp.id = ? AND piv.cup_id = ? AND piv.deleted_at IS NULL
            LIMIT 1
        ");
        $cup_ok->execute([$pid, $cup_id]);
        if ($cup_ok->fetchColumn() === false) throw new Exception("杯型 (A-code) 不适用于此产品。", 403);
    }
    */

    // 2. 检查冰量 Gating (此逻辑正确)
    $ice_rules = $pdo->prepare("SELECT ice_option_id FROM kds_product_ice_options WHERE product_id = ?");
    $ice_rules->execute([$pid]);
    $allowed_ice_ids = $ice_rules->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($allowed_ice_ids)) { // 存在冰量规则
        if ($ice_id === null) throw new Exception("此产品需要冰量 (M-code)，但未提供。", 403);
        if (!in_array($ice_id, $allowed_ice_ids)) throw new Exception("冰量 (M-code) 不适用于此产品。", 403);
    }

    // 3. 检查甜度 Gating (此逻辑正确)
    $sweet_rules = $pdo->prepare("SELECT sweetness_option_id FROM kds_product_sweetness_options WHERE product_id = ?");
    $sweet_rules->execute([$pid]);
    $allowed_sweet_ids = $sweet_rules->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($allowed_sweet_ids)) { // 存在甜度规则
        if ($sweet_id === null) throw new Exception("此产品需要甜度 (T-code)，但未提供。", 403);
        if (!in_array($sweet_id, $allowed_sweet_ids)) throw new Exception("甜度 (T-code) 不适用于此产品。", 403);
    }
}

// --- [L1] 基础配方 ---
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

// --- [L2] 全局规则 ---
function apply_global_rules(PDO $pdo, array $recipe, ?int $cup, ?int $ice, ?int $sweet): array {
    $st = $pdo->prepare("SELECT * FROM kds_global_adjustment_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC");
    $st->execute();
    $rules = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rules as $rule) {
        // 检查条件是否匹配
        if ($rule['cond_cup_id'] !== null && $rule['cond_cup_id'] != $cup) continue;
        if ($rule['cond_ice_id'] !== null && $rule['cond_ice_id'] != $ice) continue;
        if ($rule['cond_sweet_id'] !== null && $rule['cond_sweet_id'] != $sweet) continue;

        $target_mid = (int)$rule['action_material_id'];

        // 检查物料条件
        if ($rule['cond_material_id'] !== null) {
            // L2-A: 条件指定了物料 (e.g., IF is_sugar)
            if ($rule['cond_material_id'] != $target_mid) continue; // 仅当 动作物料 == 条件物料 时才应用
            if (!isset($recipe[$target_mid])) continue; // 基础配方里没有这个物料
        }

        // L2-B: 条件未指定物料 (e.g., IF hot)
        // 此时，动作物料 (target_mid) 必须存在于配方中，或是被 'ADD_MATERIAL' 动作添加
        if (!isset($recipe[$target_mid]) && $rule['action_type'] !== 'ADD_MATERIAL') {
             continue;
        }

        // ★★★ 新增逻辑：检查 L1 基础用量条件 ★★★
        $cond_base_gt = $rule['cond_base_gt'];
        $cond_base_lte = $rule['cond_base_lte'];

        if ($cond_base_gt !== null || $cond_base_lte !== null) {
            // 此规则需要检查基础用量。
            
            // 1. 检查基础配方中是否存在此物料
            if (!isset($recipe[$target_mid])) {
                continue; // L1基础配方没有此物料，跳过此规则
            }
            
            // 2. 获取 L1 (或已被 L2 前序规则修改) 的用量
            $base_quantity = (float)$recipe[$target_mid]['quantity'];

            // 3. 执行条件判断
            if ($cond_base_gt !== null && !($base_quantity > (float)$cond_base_gt)) {
                continue; // 未满足 "大于" 条件，跳过
            }
            if ($cond_base_lte !== null && !($base_quantity <= (float)$cond_base_lte)) {
                continue; // 未满足 "小于等于" 条件，跳过
            }
        }
        // ★★★ 新增逻辑结束 ★★★

        // 执行动作
        $value = (float)$rule['action_value'];
        switch ($rule['action_type']) {
            case 'SET_VALUE':
                $recipe[$target_mid]['quantity'] = $value;
                $recipe[$target_mid]['source'] = 'L2-SET';
                break;
            case 'ADD_MATERIAL':
                if (!isset($recipe[$target_mid])) { // 防止重复添加
                    $recipe[$target_mid] = [
                        'material_id' => $target_mid,
                        'quantity' => $value,
                        'unit_id' => (int)$rule['action_unit_id'],
                        'step_category' => 'mixing', // L2 添加的物料默认都在 "调杯"
                        'sort_order' => 500 + $target_mid, // 放在 L1 之后
                        'source' => 'L2-ADD'
                    ];
                }
                break;
            case 'CONDITIONAL_OFFSET':
                $recipe[$target_mid]['quantity'] += $value;
                $recipe[$target_mid]['source'] = 'L2-OFFSET';
                break;
            case 'MULTIPLY_BASE':
                // 乘法仅应基于 L1 的值，防止 L2 规则重复乘法。
                // 我们假设 apply_global_rules 在 L1 之后立即运行，因此当前 quantity 即 L1 a base。
                $recipe[$target_mid]['quantity'] *= $value;
                $recipe[$target_mid]['source'] = 'L2-MULTIPLY';
                break;
        }
    }
    return $recipe;
}

// --- [L3] 特例覆盖 ---
function apply_overrides(PDO $pdo, int $pid, array $recipe, ?int $cup, ?int $ice, ?int $sweet): array {
    // 1. 找出所有在L3中 *可能* 被引用的物料
    $st = $pdo->prepare("SELECT DISTINCT material_id FROM kds_recipe_adjustments WHERE product_id = ?");
    $st->execute([$pid]);
    $l3_material_ids = $st->fetchAll(PDO::FETCH_COLUMN);

    // 2. 遍历所有 L1/L2 配方物料 + L3 可能引用的物料
    $all_mids_to_check = array_unique(array_merge(array_keys($recipe), $l3_material_ids));

    foreach ($all_mids_to_check as $mid) {
        $mid = (int)$mid;
        $adj = best_adjust_l3($pdo, $pid, $mid, $cup, $ice, $sweet);
        
        if ($adj) {
            // L3 规则存在，它将 *覆盖* 或 *新增*
            $recipe[$mid] = [
                'material_id' => $mid,
                'quantity' => (float)$adj['quantity'],
                'unit_id' => (int)$adj['unit_id'],
                'step_category' => norm_cat((string)$adj['step_category']),
                'sort_order' => $recipe[$mid]['sort_order'] ?? (600 + $mid), // 保留原排序或设为高位
                'source' => 'L3-OVERRIDE'
            ];
        }
    }
    return $recipe;
}

// L3 专用的 best_adjust，因为它需要返回 step_category
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

// --- P-Code Only: 获取可用选项 (用于 KDS 左侧动态按钮) ---
function get_available_options(PDO $pdo, int $pid): array {
    $options = ['cups' => [], 'ice_options' => [], 'sweetness_options' => []];
    
    // 1. Get Cups (Linked via pos_item_variants)
    // [V11.2 GATING FIX]: 修复了 product_id 查询
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
        WHERE pio.product_id = ? AND io.deleted_at IS NULL ORDER BY io.ice_code
    ";
    $stmt_ice = $pdo->prepare($ice_sql); $stmt_ice->execute([$pid]);
    $options['ice_options'] = $stmt_ice->fetchAll(PDO::FETCH_ASSOC);
    if (empty($options['ice_options'])) { // 如果 Gating 未设置, 返回所有
        $options['ice_options'] = $pdo->query("SELECT io.id, io.ice_code, iot_zh.ice_option_name AS name_zh, iot_es.ice_option_name AS name_es FROM kds_ice_options io LEFT JOIN kds_ice_option_translations iot_zh ON io.id = iot_zh.ice_option_id AND iot_zh.language_code = 'zh-CN' LEFT JOIN kds_ice_option_translations iot_es ON io.id = iot_es.ice_option_id AND iot_es.language_code = 'es-ES' WHERE io.deleted_at IS NULL ORDER BY io.ice_code")->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 3. Get Sweetness Options (Gating)
    $sweet_sql = "SELECT so.id, so.sweetness_code, sot_zh.sweetness_option_name AS name_zh, sot_es.sweetness_option_name AS name_es
                  FROM kds_product_sweetness_options pso
                  JOIN kds_sweetness_options so ON pso.sweetness_option_id = so.id
                  LEFT JOIN kds_sweetness_option_translations sot_zh ON so.id = sot_zh.sweetness_option_id AND sot_zh.language_code = 'zh-CN'
                  LEFT JOIN kds_sweetness_option_translations sot_es ON so.id = sot_es.sweetness_option_id AND sot_es.language_code = 'es-ES'
                  WHERE pso.product_id = ? AND so.deleted_at IS NULL ORDER BY so.sweetness_code";
    $stmt_sweet = $pdo->prepare($sweet_sql); $stmt_sweet->execute([$pid]);
    $options['sweetness_options'] = $stmt_sweet->fetchAll(PDO::FETCH_ASSOC);
    if (empty($options['sweetness_options'])) { // 如果 Gating 未设置, 返回所有
        $options['sweetness_options'] = $pdo->query("SELECT so.id, so.sweetness_code, sot_zh.sweetness_option_name AS name_zh, sot_es.sweetness_option_name AS name_es FROM kds_sweetness_options so LEFT JOIN kds_sweetness_option_translations sot_zh ON so.id = sot_zh.sweetness_option_id AND sot_zh.language_code = 'zh-CN' LEFT JOIN kds_sweetness_option_translations sot_es ON so.id = sot_es.sweetness_option_id AND sot_es.language_code = 'es-ES' WHERE so.deleted_at IS NULL ORDER BY so.sweetness_code")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $options;
}


/* -------------------- 2) 主流程 (V11.2) -------------------- */
try {
    $raw = $_GET['code'] ?? '';
    $seg = parse_code($raw);
    if (!$seg || $seg['p'] === '') out_json('error', '编码不合法', null, 400);

    // 1. 验证产品
    $prod = get_product($pdo, $seg['p']);
    if (!$prod || $prod['deleted_at'] !== null || (int)$prod['is_active'] !== 1) {
        out_json('error', '找不到该产品或未上架 (P-Code: ' . htmlspecialchars($seg['p']) . ')', null, 404);
    }
    $pid = (int)$prod['id'];

    // 2. 获取产品基础信息 (名称, 状态)
    $prod_info = array_merge(
        ['product_id' => $pid, 'product_code' => $prod['product_code']],
        get_product_info_bilingual($pdo, $pid, (int)$prod['status_id'])
    );

    // 3. (P-Code ONLY) 仅查询基础信息
    if ($seg['a'] === null && $seg['m'] === null && $seg['t'] === null) {
        ok([
            'type' => 'base_info',
            'product' => $prod_info,
            'recipe' => get_base_recipe_bilingual($pdo, $pid), // L1 (使用 V11.2 新增的Bilingual函数)
            'options' => get_available_options($pdo, $pid) // Gating
        ]);
    }

    // 4. (P-A-M-T) 动态计算配方

    // 4a. 将 A,M,T 码转换为 数据库 ID
    $cup_id = id_by_code($pdo, 'kds_cups', 'cup_code', $seg['a']);
    if ($seg['a'] !== null && $cup_id === null) out_json('error', '杯型编码 (A-code) 无效: ' . htmlspecialchars($seg['a']), null, 404);
    
    $ice_id = id_by_code($pdo, 'kds_ice_options', 'ice_code', $seg['m']);
    if ($seg['m'] !== null && $ice_id === null) out_json('error', '冰量编码 (M-code) 无效: ' . htmlspecialchars($seg['m']), null, 404);

    $sweet_id = id_by_code($pdo, 'kds_sweetness_options', 'sweetness_code', $seg['t']);
    if ($seg['t'] !== null && $sweet_id === null) out_json('error', '甜度编码 (T-code) 无效: ' . htmlspecialchars($seg['t']), null, 404);

    // 4b. [GATING] 验证选项是否被允许
    try {
        check_gating($pdo, $pid, $cup_id, $ice_id, $sweet_id);
    } catch (Exception $e) {
        out_json('error', $e->getMessage(), ['code' => $e->getCode()], 403);
    }

    // 4c. [L1] 获取基础配方
    $recipe_map = get_base_recipe($pdo, $pid);
    if (empty($recipe_map)) out_json('error', '产品 (P-Code: ' . htmlspecialchars($seg['p']) . ') 缺少基础配方 (L1)，无法计算。', null, 404);

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

    ok([
        'type' => 'adjusted_recipe',
        'product' => $prod_info,
        'recipe' => $final_recipe // <--- 返回计算后的配方
    ]);

} catch (Throwable $e) {
    error_log('KDS sop_handler error (V11): ' . $e->getMessage());
    $error_message = "[V11.2] " . $e->getMessage() . " in " . basename($e->getFile()) . " on line " . $e->getLine();
    out_json('error', $error_message, ['debug' => $e->getMessage()], 500);
}

// [V11.2 GATING FIX] 新增 get_base_recipe_bilingual 函数 (用于 P-Code only 模式)
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
?>