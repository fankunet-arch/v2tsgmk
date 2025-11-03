<?php
/**
 * TopTea POS - Main Data Loader API (Consolidated Version)
 * Engineer: Gemini | Date: 2025-11-03
 * Revision: 2.0 (Consolidated SIF Declaration + Gating + Addons)
 *
 * This API is the primary endpoint for the POS frontend (main.js -> api.js -> fetchInitialData).
 * It loads all necessary data for the POS to operate:
 * 1. Categories (from pos_categories)
 * 2. Products (from pos_menu_items)
 * 3. Variants (from pos_item_variants)
 * 4. Gating rules (from kds_product_ice_options, kds_product_sweetness_options)
 * 5. Gating master lists (from kds_ice_options, kds_sweetness_options)
 * 6. Addons (from pos_addons)
 * 7. Redemption Rules (from pos_point_redemption_rules)
 * 8. SIF Declaration (from pos_settings)
 */

declare(strict_types=1);

// 1. 包含核心配置和 API 认证
// 这将启动会话, 验证登录, 并提供 $pdo 连接
require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');
require_once realpath(__DIR__ . '/../../../pos_backend/core/api_auth_core.php');

// 2. 包含 HQ 的 kds_helper.php 以复用数据查询功能
// 路径: /store_html/html/pos/api/ -> /store_html/ -> /hq_html/app/helpers/
$hq_helper_path = realpath(__DIR__ . '/../../../../hq/hq_html/app/helpers/kds_helper.php');
if ($hq_helper_path && file_exists($hq_helper_path)) {
    require_once $hq_helper_path;
} else {
    // 关键错误：如果找不到 HQ 助手，POS 将无法获取数据
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Critical Error: HQ Helper file (kds_helper.php) not found.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function send_json_response($status, $message, $data = null) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

/**
 * [HELPER] 获取所有激活的积分兑换规则
 */
function getAllRedemptionRules(PDO $pdo): array {
    $sql = "SELECT r.*, p.promo_name 
            FROM pos_point_redemption_rules r
            LEFT JOIN pos_promotions p ON r.reward_promo_id = p.id
            WHERE r.deleted_at IS NULL AND r.is_active = 1
            ORDER BY r.points_required ASC, r.id ASC";
    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 如果表不存在 (e.g., 42S02)，返回空数组而不是崩溃
        error_log("POS Data Loader: Failed to get redemption rules: " . $e->getMessage());
        return [];
    }
}

/**
 * [HELPER] 格式化 POS 产品数据
 * 将 'pos_menu_items' 和 'pos_item_variants' 组合成前端期望的嵌套结构。
 * (V2.2 Gating Logic included)
 */
function getPosProducts(PDO $pdo): array {
    $products = [];
    $variants_map = [];

    // 1. 获取所有激活的规格
    $sql_variants = "
        SELECT 
            pv.id, pv.menu_item_id, pv.variant_name_zh, pv.variant_name_es, 
            pv.price_eur, pv.is_default
        FROM pos_item_variants pv
        WHERE pv.deleted_at IS NULL
        ORDER BY pv.menu_item_id, pv.sort_order ASC
    ";
    $stmt_variants = $pdo->query($sql_variants);
    while ($row = $stmt_variants->fetch(PDO::FETCH_ASSOC)) {
        $variants_map[(int)$row['menu_item_id']][] = [
            'id' => (int)$row['id'],
            'name_zh' => $row['variant_name_zh'],
            'name_es' => $row['variant_name_es'],
            'price_eur' => (float)$row['price_eur'],
            'is_default' => (bool)$row['is_default']
        ];
    }

    // 2. 获取所有激活的商品, 并预先连接 Gating 规则
    // (V2.2 Gating) LEFT JOIN kds_products to get kp.id
    // (V2.2 Gating) LEFT JOIN Gating tables and GROUP_CONCAT rules
    $sql_items = "
        SELECT 
            mi.id, mi.name_zh, mi.name_es, mi.image_url,
            pc.category_code,
            kp.product_code AS product_sku,
            kp.id AS kds_product_id,
            GROUP_CONCAT(DISTINCT pio.ice_option_id) AS allowed_ice_ids_str,
            GROUP_CONCAT(DISTINCT pso.sweetness_option_id) AS allowed_sweetness_ids_str
        FROM pos_menu_items mi
        JOIN pos_categories pc ON mi.pos_category_id = pc.id
        LEFT JOIN kds_products kp ON mi.product_code = kp.product_code AND kp.deleted_at IS NULL
        LEFT JOIN kds_product_ice_options pio ON kp.id = pio.product_id
        LEFT JOIN kds_product_sweetness_options pso ON kp.id = pso.product_id
        WHERE mi.deleted_at IS NULL AND mi.is_active = 1 AND pc.deleted_at IS NULL
        GROUP BY mi.id, mi.name_zh, mi.name_es, mi.image_url, pc.category_code, kp.product_code, kp.id
        ORDER BY pc.sort_order, mi.sort_order
    ";
    $stmt_items = $pdo->query($sql_items);
    
    while ($row = $stmt_items->fetch(PDO::FETCH_ASSOC)) {
        $itemId = (int)$row['id'];
        $kds_pid = $row['kds_product_id'] ? (int)$row['kds_product_id'] : null;
        $variants = $variants_map[$itemId] ?? [];
        
        if (empty($variants)) continue; // 如果一个商品没有规格，则不在 POS 显示

        // (V2.2 Gating) 解析 Gating 规则
        $allowed_ice_ids = null;
        $allowed_sweetness_ids = null;

        if ($kds_pid) {
            // Ice Gating:
            // $row['allowed_ice_ids_str'] 可能是:
            // 1. NULL (kds_product_ice_options 中没有该 product_id 的记录) -> 视为 "未配置", 返回 null (允许所有)
            // 2. "0" (有记录, 且 id 为 0) -> 视为 "已配置但为空", 返回 [] (禁止所有)
            // 3. "1,2,3" (有记录) -> 视为 "已配置", 返回 [1, 2, 3] (允许特定)
            if ($row['allowed_ice_ids_str'] !== null) {
                $ice_ids = array_map('intval', explode(',', $row['allowed_ice_ids_str']));
                if (count($ice_ids) === 1 && $ice_ids[0] === 0) {
                    $allowed_ice_ids = []; // 明确配置为空
                } else {
                    $allowed_ice_ids = array_filter($ice_ids, fn($id) => $id > 0); // 过滤掉可能的 0
                }
            }
            // else: $allowed_ice_ids 保持为 null (未配置)

            // Sweetness Gating (逻辑同上)
            if ($row['allowed_sweetness_ids_str'] !== null) {
                $sweet_ids = array_map('intval', explode(',', $row['allowed_sweetness_ids_str']));
                if (count($sweet_ids) === 1 && $sweet_ids[0] === 0) {
                    $allowed_sweetness_ids = []; // 明确配置为空
                } else {
                    $allowed_sweetness_ids = array_filter($sweet_ids, fn($id) => $id > 0);
                }
            }
            // else: $allowed_sweetness_ids 保持为 null (未配置)
        }
        // (如果 $kds_pid 为 null, 两个 Gating 规则都保持为 null)
        
        $products[] = [
            'id' => $itemId,
            'title_zh' => $row['name_zh'],
            'title_es' => $row['name_es'],
            'image_url' => $row['image_url'],
            'category_key' => $row['category_code'],
            'variants' => $variants,
            'allowed_ice_ids' => $allowed_ice_ids,
            'allowed_sweetness_ids' => $allowed_sweetness_ids
        ];
    }
    return $products;
}

/**
 * [HELPER] 格式化 POS 分类
 */
function getPosCategories(PDO $pdo): array {
    $categories = getAllPosCategories($pdo); // from kds_helper.php
    $formatted = [];
    foreach ($categories as $cat) {
        $formatted[] = [
            'key' => $cat['category_code'],
            'label_zh' => $cat['name_zh'],
            'label_es' => $cat['name_es']
        ];
    }
    return $formatted;
}

/**
 * [HELPER] 格式化 POS 加料 (来自 pos_addons)
 */
function getPosAddons(PDO $pdo): array {
    // kds_helper.php 中的 getAllPosAddons 已经包含了所需逻辑
    $addons = getAllPosAddons($pdo); 
    $formatted = [];
    foreach ($addons as $addon) {
        if (!$addon['is_active']) continue;
        $formatted[] = [
            'key' => $addon['addon_code'],
            'label_zh' => $addon['name_zh'],
            'label_es' => $addon['name_es'],
            'price_eur' => (float)$addon['price_eur']
        ];
    }
    return $formatted;
}

/**
 * [HELPER] 格式化冰量和甜度主列表 (用于 Gating)
 */
function getKdsOptions(PDO $pdo): array {
    // kds_helper.php 提供了所需函数
    $ice_options = [];
    foreach (getAllIceOptions($pdo) as $opt) {
        $ice_options[] = [
            'id' => (int)$opt['id'],
            'ice_code' => $opt['ice_code'],
            'name_zh' => $opt['name_zh'],
            'name_es' => $opt['name_es']
        ];
    }
    
    $sweetness_options = [];
    foreach (getAllSweetnessOptions($pdo) as $opt) {
        $sweetness_options[] = [
            'id' => (int)$opt['id'],
            'sweetness_code' => $opt['sweetness_code'],
            'name_zh' => $opt['name_zh'],
            'name_es' => $opt['name_es']
        ];
    }
    
    return [$ice_options, $sweetness_options];
}

try {
    // 从认证的会话中获取 store_id
    $store_id = (int)$_SESSION['pos_store_id'];

    // 1. 获取产品 (已嵌套规格, 已包含Gating逻辑)
    $products = getPosProducts($pdo);

    // 2. 获取分类
    $categories = getPosCategories($pdo);

    // 3. 获取加料
    $addons = getPosAddons($pdo);

    // 4. 获取冰量和甜度的主列表 (用于 Gating)
    list($ice_options, $sweetness_options) = getKdsOptions($pdo);
    
    // 5. 获取激活的积分兑换规则
    $redemption_rules = getAllRedemptionRules($pdo);

    // 6. 获取 SIF 合规性声明 (关键步骤)
    $stmt_sif = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'sif_declaracion_responsable'");
    $stmt_sif->execute();
    $sif_declaration_text = $stmt_sif->fetchColumn();
    if ($sif_declaration_text === false) {
        // 如果在数据库中找不到，返回一个空字符串或默认提示
        $sif_declaration_text = 'Declaración Responsable (SIF) no configurada en CPSYS.';
    }

    // 组装最终的 JSON 数据
    $data = [
        'products' => $products,
        'categories' => $categories,
        'addons' => $addons,
        'ice_options' => $ice_options,
        'sweetness_options' => $sweetness_options,
        'redemption_rules' => $redemption_rules,
        'sif_declaration' => $sif_declaration_text // 包含声明文本
    ];

    send_json_response('success', 'POS data loaded successfully.', $data);

} catch (Exception $e) {
    error_log("Error in pos_data_loader.php: " . $e->getMessage());
    send_json_response('error', 'Failed to load POS data.', ['debug' => $e->getMessage()], 500);
}
?>