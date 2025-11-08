<?php
/**
 * Toptea HQ - cpsys
 * Main Entry Point
 * Engineer: Gemini | Date: 2025-11-05 | Revision: 1.19.001 (Add KDS SOP Rules Route)
 *
 * [REFACTOR - MINIMAL]:
 * - 确保加载 app/helpers/kds/kds_repo_a.php；若仍缺函数，则内联兜底实现（来源于老版本SQL）。
 * - 不新增文件，不修改 kds_repo_a.php。
 * - 结尾不输出 "?>"
 */

// === 可选：临时调试开关 ===
if (isset($_GET['__debug'])) {
    ini_set('display_errors','1');
    ini_set('display_startup_errors','1');
    error_reporting(E_ALL);
    set_exception_handler(function($e){
        http_response_code(500);
        header('Content-Type:text/plain; charset=utf-8');
        echo "EXCEPTION: {$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n".$e->getTraceAsString();
    });
    register_shutdown_function(function(){
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
            http_response_code(500);
            header('Content-Type:text/plain; charset=utf-8');
            echo "FATAL: {$e['message']}\n{$e['file']}:{$e['line']}\n";
        }
    });
}

// --- Core & Helpers ---
require_once realpath(__DIR__ . '/../../core/auth_core.php');
header('Content-Type: text/html; charset=utf-8');
require_once realpath(__DIR__ . '/../../core/config.php');
require_once APP_PATH . '/helpers/kds_helper.php';
require_once APP_PATH . '/helpers/auth_helper.php';

if (!isset($pdo)) {
    die("Critical Error: Core configuration could not be loaded.");
}

/**
 * 确保 kds_repo_a 加载；若函数仍缺失，则提供“老版本SQL”的兜底。
 * - 只在首次调用时执行
 * - 不抛异常，不新增文件
 */
if (!function_exists('__ensure_repo_a')) {
    function __ensure_repo_a(): void {
        static $done = false;
        if ($done) return;

        $repoA = realpath(__DIR__ . '/../../app/helpers/kds/kds_repo_a.php');
        if ($repoA && is_file($repoA)) {
            require_once $repoA; // 尝试加载正式仓库
        }

        // 兜底：如果仓库加载后仍缺函数，则内联实现（来自“老版本”）
        if (!function_exists('getAllMaterials')) {
            function getAllMaterials(PDO $pdo): array {
                $sql = "
                    SELECT 
                        m.id, m.material_code, m.material_type, 
                        m.medium_conversion_rate, m.large_conversion_rate,
                        mt_zh.material_name AS name_zh, 
                        mt_es.material_name AS name_es,
                        ut_base_zh.unit_name AS base_unit_name,
                        ut_medium_zh.unit_name AS medium_unit_name,
                        ut_large_zh.unit_name AS large_unit_name
                    FROM kds_materials m
                    LEFT JOIN kds_material_translations mt_zh 
                        ON m.id = mt_zh.material_id AND mt_zh.language_code = 'zh-CN'
                    LEFT JOIN kds_material_translations mt_es 
                        ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
                    LEFT JOIN kds_unit_translations ut_base_zh 
                        ON m.base_unit_id = ut_base_zh.unit_id AND ut_base_zh.language_code = 'zh-CN'
                    LEFT JOIN kds_unit_translations ut_medium_zh 
                        ON m.medium_unit_id = ut_medium_zh.unit_id AND ut_medium_zh.language_code = 'zh-CN'
                    LEFT JOIN kds_unit_translations ut_large_zh 
                        ON m.large_unit_id = ut_large_zh.unit_id AND ut_large_zh.language_code = 'zh-CN'
                    WHERE m.deleted_at IS NULL 
                    ORDER BY m.material_code ASC";
                $stmt = $pdo->query($sql);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        if (!function_exists('getAllUnits')) {
            function getAllUnits(PDO $pdo): array {
                $sql = "
                    SELECT u.id, u.unit_code,
                        ut_zh.unit_name AS name_zh, 
                        ut_es.unit_name AS name_es
                    FROM kds_units u
                    LEFT JOIN kds_unit_translations ut_zh 
                        ON u.id = ut_zh.unit_id AND ut_zh.language_code = 'zh-CN'
                    LEFT JOIN kds_unit_translations ut_es 
                        ON u.id = ut_es.unit_id AND ut_es.language_code = 'es-ES'
                    WHERE u.deleted_at IS NULL
                    ORDER BY u.unit_code ASC";
                $stmt = $pdo->query($sql);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        // （可选）当访问 RMS 全局规则页面时需要：
        if (!function_exists('getAllGlobalRules')) {
            function getAllGlobalRules(PDO $pdo): array {
                $sql = "SELECT * FROM kds_global_adjustment_rules ORDER BY priority ASC, id ASC";
                $stmt = $pdo->query($sql);
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $done = true;
    }
}
__ensure_repo_a();

// --- 路由 ---
$page = $_GET['page'] ?? 'dashboard';
$page_js = null;

// Allow product managers to also access the new RMS page
if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN && !in_array($page, ['dashboard', 'profile', 'rms_product_management'])) {
    $page = 'access_denied';
}

switch ($page) {
    case 'dashboard':
        $page_title = '仪表盘';
        $content_view = APP_PATH . '/views/cpsys/dashboard_view.php';
        break;

    case 'rms_product_management':
        $page_title = 'RMS - 产品配方 (L1/L3)';
        $base_products = getAllBaseProducts($pdo);
        $material_options = getAllMaterials($pdo);
        $unit_options = getAllUnits($pdo);
        $cup_options = getAllCups($pdo);
        $sweetness_options = getAllSweetnessOptions($pdo);
        $ice_options = getAllIceOptions($pdo);
        $status_options = getAllStatuses($pdo);
        $content_view = APP_PATH . '/views/cpsys/rms/rms_product_management_view.php';
        $page_js = 'rms/rms_product_management.js';
        break;

    case 'rms_global_rules':
        if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN) {
            $page = 'access_denied';
            break;
        }
        $page_title = 'RMS - 全局规则 (L2)';
        $global_rules = getAllGlobalRules($pdo);
        $material_options = getAllMaterials($pdo);
        $unit_options = getAllUnits($pdo);
        $cup_options = getAllCups($pdo);
        $sweetness_options = getAllSweetnessOptions($pdo);
        $ice_options = getAllIceOptions($pdo);
        $content_view = APP_PATH . '/views/cpsys/rms/rms_global_rules_view.php';
        $page_js = 'rms/rms_global_rules.js';
        break;

    case 'kds_sop_rules':
        if (($_SESSION['role_id'] ?? null) !== ROLE_SUPER_ADMIN) {
            $page = 'access_denied';
            break;
        }
        $page_title = 'KDS - SOP 解析规则';
        $stores = getAllStores($pdo);
        $content_view = APP_PATH . '/views/cpsys/kds_sop_rules_view.php';
        $page_js = 'kds_sop_rules.js';
        break;

    case 'expiry_management':
        $page_title = '效期管理';
        $expiry_items = getAllExpiryItems($pdo);
        $content_view = APP_PATH . '/views/cpsys/expiry_management_view.php';
        break;

    case 'warehouse_stock_management':
        $page_title = '总仓库存';
        $stock_items = getWarehouseStock($pdo);
        $content_view = APP_PATH . '/views/cpsys/warehouse_stock_management_view.php';
        $page_js = 'warehouse_stock_logic.js';
        break;

    case 'stock_allocation':
        $page_title = '库存调拨';
        $stores = getAllStores($pdo);
        $materials = getAllMaterials($pdo);
        $content_view = APP_PATH . '/views/cpsys/stock_allocation_view.php';
        $page_js = 'stock_allocation.js';
        break;

    case 'store_stock_view':
        $page_title = '门店库存';
        $stock_data = getAllStoreStock($pdo);
        $content_view = APP_PATH . '/views/cpsys/store_stock_view.php';
        break;

    case 'pos_menu_management':
        $page_title = 'POS 管理 - 菜单管理';
        $menu_items = getAllMenuItems($pdo);
        $pos_categories = getAllPosCategories($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_menu_management_view.php';
        $page_js = 'pos_menu_management.js';
        break;

    case 'pos_variants_management':
        $item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
        if (!$item_id) { die("无效的商品ID。"); }
        $menu_item = getMenuItemById($pdo, $item_id);
        if (!$menu_item) { die("未找到指定的商品。"); }
        $page_title = 'POS 管理 - 管理规格';
        $variants = getAllVariantsByMenuItemId($pdo, $item_id);
        $recipes = getAllProductRecipesForSelect($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_variants_management_view.php';
        $page_js = 'pos_variants_management.js';
        break;

    case 'pos_category_management':
        $page_title = '字典管理 - POS分类';
        $pos_categories = getAllPosCategories($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_category_management_view.php';
        $page_js = 'pos_category_management.js';
        break;

    case 'pos_addon_management':
        $page_title = 'POS 管理 - 加料管理';
        $addons = getAllPosAddons($pdo);
        $materials = getAllMaterials($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_addon_management_view.php';
        $page_js = 'pos_addon_management.js';
        break;

    case 'pos_invoice_list':
        $page_title = 'POS 管理 - 票据查询';
        $invoices = getAllInvoices($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_invoice_list_view.php';
        break;

    case 'pos_invoice_detail':
        $invoice_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$invoice_id) { die("无效的票据ID。"); }
        $invoice_data = getInvoiceDetails($pdo, $invoice_id);
        if (!$invoice_data) { die("未找到指定的票据。"); }
        $page_title = 'POS 票据详情: ' . htmlspecialchars($invoice_data['series'] . '-' . $invoice_data['number']);
        $content_view = APP_PATH . '/views/cpsys/pos_invoice_detail_view.php';
        $page_js = 'pos_invoice_management.js';
        break;

    case 'pos_promotion_management':
        $page_title = 'POS 管理 - 营销活动管理';
        $promotions = getAllPromotions($pdo);
        $menu_items_for_select = getAllMenuItemsForSelect($pdo);
        echo "<script>window.menuItemsForSelect = " . json_encode($menu_items_for_select) . ";</script>";
        $content_view = APP_PATH . '/views/cpsys/pos_promotion_management_view.php';
        $page_js = 'pos_promotion_management.js';
        break;

    case 'pos_eod_reports':
        $page_title = 'POS 管理 - 日结报告';
        $eod_reports = getAllEodReports($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_eod_reports_view.php';
        break;

    case 'pos_shift_review':
        $page_title = 'POS 管理 - 异常班次复核';
        $pending_reviews = getPendingShiftReviews($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_shift_review_view.php';
        $page_js = 'pos_shift_review.js';
        break;

    case 'pos_member_level_management':
        $page_title = 'POS 管理 - 会员等级';
        $member_levels = getAllMemberLevels($pdo);
        $promotions_for_select = getAllPromotions($pdo); 
        $content_view = APP_PATH . '/views/cpsys/pos_member_level_management_view.php';
        $page_js = 'pos_member_level_management.js';
        break;

    case 'pos_member_management':
        $page_title = 'POS 管理 - 会员列表';
        $members = getAllMembers($pdo);
        $member_levels = getAllMemberLevels($pdo); 
        $content_view = APP_PATH . '/views/cpsys/pos_member_management_view.php';
        $page_js = 'pos_member_management.js';
        break;

    case 'pos_member_settings':
        $page_title = 'POS 管理 - 会员积分设置';
        $content_view = APP_PATH . '/views/cpsys/pos_member_settings_view.php';
        $page_js = 'pos_member_settings.js'; 
        break;

    case 'pos_point_redemption_rules':
        $page_title = 'POS 管理 - 积分兑换规则';
        $rules = getAllRedemptionRules($pdo);
        $promotions_for_select = getAllPromotions($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_point_redemption_rules_view.php';
        $page_js = 'pos_point_redemption_rules.js';
        break;

    case 'pos_print_template_management':
        $page_title = '系统设置 - 打印模板管理';
        $templates = getAllPrintTemplates($pdo);
        $content_view = APP_PATH . '/views/cpsys/pos_print_template_management_view.php';
        $page_js = 'pos_print_template_editor.js'; // cache-fix
        break;

    case 'pos_print_template_variables':
        $page_title = '系统设置 - 打印模板变量';
        $default_templates = [];
        $stmt = $pdo->query("SELECT template_type, template_content FROM pos_print_templates WHERE store_id IS NULL AND is_active = 1");
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $default_templates[$row['template_type']] = $row['template_content'];
        }
        $content_view = APP_PATH . '/views/cpsys/pos_print_template_variables_view.php';
        break;

    case 'sif_declaration':
        $page_title = '系统设置 - 合规性声明 (SIF)';
        $stmt = $pdo->prepare("SELECT setting_value FROM pos_settings WHERE setting_key = 'sif_declaracion_responsable'");
        $stmt->execute();
        $sif_declaration_text = $stmt->fetchColumn();
        $content_view = APP_PATH . '/views/cpsys/sif_declaration_view.php';
        $page_js = 'sif_declaration.js';
        break;

    case 'cup_management':
        $page_title = '字典管理 - 杯型';
        $cups = getAllCups($pdo);
        $content_view = APP_PATH . '/views/cpsys/cup_management_view.php';
        $page_js = 'cup_management.js';
        break;

    case 'material_management':
        $page_title = '字典管理 - 物料';
        $materials = getAllMaterials($pdo);
        $unit_options = getAllUnits($pdo);
        $content_view = APP_PATH . '/views/cpsys/material_management_view.php';
        $page_js = 'material_management.js';
        break;

    case 'unit_management':
        $page_title = '字典管理 - 单位';
        $units = getAllUnits($pdo);
        $content_view = APP_PATH . '/views/cpsys/unit_management_view.php';
        $page_js = 'unit_management.js';
        break;

    case 'ice_option_management':
        $page_title = '字典管理 - 冰量选项';
        $ice_options = getAllIceOptions($pdo);
        $content_view = APP_PATH . '/views/cpsys/ice_option_management_view.php';
        $page_js = 'ice_option_management.js';
        break;

    case 'sweetness_option_management':
        $page_title = '字典管理 - 甜度选项';
        $sweetness_options = getAllSweetnessOptions($pdo);
        $content_view = APP_PATH . '/views/cpsys/sweetness_option_management_view.php';
        $page_js = 'sweetness_option_management.js';
        break;

    case 'product_status_management':
        $page_title = '字典管理 - 产品状态';
        $statuses = getAllStatuses($pdo);
        $content_view = APP_PATH . '/views/cpsys/product_status_management_view.php';
        $page_js = 'product_status_management.js';
        break;

    case 'user_management':
        $page_title = '系统设置 - 用户管理';
        $users = getAllUsers($pdo);
        $roles = getAllRoles($pdo);
        $content_view = APP_PATH . '/views/cpsys/user_management_view.php';
        $page_js = 'user_management.js';
        break;

    case 'store_management':
        $page_title = '系统设置 - 门店管理';
        $stores = getAllStores($pdo);
        $content_view = APP_PATH . '/views/cpsys/store_management_view.php';
        $page_js = 'store_management.js';
        break;

    case 'kds_user_management':
        $page_title = 'KDS 账户管理';
        $store_id = filter_input(INPUT_GET, 'store_id', FILTER_VALIDATE_INT);
        if (!$store_id) { die("无效的门店ID。"); }
        $store_data = getStoreById($pdo, $store_id);
        if (!$store_data) { die("未找到指定的门店。"); }
        $kds_users = getAllKdsUsersByStoreId($pdo, $store_id);
        $content_view = APP_PATH . '/views/cpsys/kds_user_management_view.php';
        $page_js = 'kds_user_management.js';
        break;

    case 'profile':
        $page_title = '个人资料';
        $current_user = getUserById($pdo, $_SESSION['user_id']);
        $content_view = APP_PATH . '/views/cpsys/profile_view.php';
        $page_js = 'profile.js';
        break;

    case 'access_denied':
        $page_title = '访问被拒绝';
        $content_view = APP_PATH . '/views/cpsys/access_denied_view.php';
        break;

    default:
        http_response_code(404);
        $page_title = '页面未找到';
        $content_view = null;
}

// --- Render Layout ---
if (isset($content_view) || $page === 'access_denied' || http_response_code() === 404) {
    if ($page !== 'access_denied' && http_response_code() !== 404 && (!isset($content_view) || !file_exists($content_view))) {
        $page_title = '错误';
        $content_view = APP_PATH . '/views/cpsys/error_view.php';
        $error_details = 'Expected view file not found: ' . ($content_view ?? 'N/A');
    } elseif (http_response_code() === 404 && !isset($content_view)) {
        $page_title = '页面未找到';
        $content_view = APP_PATH . '/views/cpsys/404_view.php';
    }
    include APP_PATH . '/views/cpsys/layouts/main.php';
} else {
    die("Critical Error: No view file determined and not a recognized error state.");
}
