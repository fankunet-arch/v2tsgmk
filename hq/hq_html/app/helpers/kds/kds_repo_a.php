<?php
/**
 * KDS Repo A - Misc A (Phase 2 consolidation)
 *
 * [GEMINI REFACTOR]:
 * 1. Added getAllRedemptionRules() (moved from index.php)
 * 2. Added getAllPrintTemplates() (moved from index.php)
 *
 * [GEMINI 3-LEVEL-UNIT FIX]:
 * 1. Renamed large_unit -> medium_unit.
 * 2. Added large_unit fields to getAllMaterials() and getMaterialById().
 * 3. Added joins for medium_unit_name and large_unit_name.
 */

/**
 * KDS Repo - Misc A
 * Split from kds_repo_misc.php (Phase 1b).
 */

function getNextAvailableCustomCode(PDO $pdo, string $tableName, string $codeColumnName, int $start_from = 1): int {
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $codeColumnName = preg_replace('/[^a-zA-Z0-9_]/', '', $codeColumnName);

    // SQL查询现在只选择未被软删除的记录
    $sql = "SELECT {$codeColumnName} FROM {$tableName} WHERE deleted_at IS NULL AND {$codeColumnName} >= :start_from ORDER BY {$codeColumnName} ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start_from' => $start_from]);
    $existing_codes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    $i = $start_from;
    while (in_array($i, $existing_codes)) {
        $i++;
    }
    return $i;
}

function getAllUnits(PDO $pdo): array {
    $sql = "SELECT u.id, u.unit_code, ut_zh.unit_name AS name_zh, ut_es.unit_name AS name_es FROM kds_units u LEFT JOIN kds_unit_translations ut_zh ON u.id = ut_zh.unit_id AND ut_zh.language_code = 'zh-CN' LEFT JOIN kds_unit_translations ut_es ON u.id = ut_es.unit_id AND ut_es.language_code = 'es-ES' WHERE u.deleted_at IS NULL ORDER BY u.unit_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUnitById(PDO $pdo, int $id) {
    $sql = "SELECT u.id, u.unit_code, ut_zh.unit_name AS name_zh, ut_es.unit_name AS name_es FROM kds_units u LEFT JOIN kds_unit_translations ut_zh ON u.id = ut_zh.unit_id AND ut_zh.language_code = 'zh-CN' LEFT JOIN kds_unit_translations ut_es ON u.id = ut_es.unit_id AND ut_es.language_code = 'es-ES' WHERE u.id = ? AND u.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

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
        LEFT JOIN kds_material_translations mt_zh ON m.id = mt_zh.material_id AND mt_zh.language_code = 'zh-CN'
        LEFT JOIN kds_material_translations mt_es ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
        LEFT JOIN kds_unit_translations ut_base_zh ON m.base_unit_id = ut_base_zh.unit_id AND ut_base_zh.language_code = 'zh-CN'
        LEFT JOIN kds_unit_translations ut_medium_zh ON m.medium_unit_id = ut_medium_zh.unit_id AND ut_medium_zh.language_code = 'zh-CN'
        LEFT JOIN kds_unit_translations ut_large_zh ON m.large_unit_id = ut_large_zh.unit_id AND ut_large_zh.language_code = 'zh-CN'
        WHERE m.deleted_at IS NULL 
        ORDER BY m.material_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMaterialById(PDO $pdo, int $id) {
    $sql = "
        SELECT 
            m.id, m.material_code, m.material_type, 
            m.base_unit_id, 
            m.medium_unit_id, m.medium_conversion_rate, 
            m.large_unit_id, m.large_conversion_rate,
            m.expiry_rule_type, m.expiry_duration,
            mt_zh.material_name AS name_zh, 
            mt_es.material_name AS name_es,
            ut_base.unit_name AS base_unit_name,
            ut_medium.unit_name AS medium_unit_name,
            ut_large.unit_name AS large_unit_name
        FROM kds_materials m 
        LEFT JOIN kds_material_translations mt_zh ON m.id = mt_zh.material_id AND mt_zh.language_code = 'zh-CN' 
        LEFT JOIN kds_material_translations mt_es ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
        LEFT JOIN kds_unit_translations ut_base ON m.base_unit_id = ut_base.unit_id AND ut_base.language_code = 'zh-CN'
        LEFT JOIN kds_unit_translations ut_medium ON m.medium_unit_id = ut_medium.unit_id AND ut_medium.language_code = 'zh-CN'
        LEFT JOIN kds_unit_translations ut_large ON m.large_unit_id = ut_large.unit_id AND ut_large.language_code = 'zh-CN'
        WHERE m.id = ? AND m.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllStores(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM kds_stores WHERE deleted_at IS NULL ORDER BY store_code ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getStoreById(PDO $pdo, int $id) {
    // [GEMINI PRINTER_CONFIG_UPDATE]
    // Added new printer fields to the SELECT statement
    $stmt = $pdo->prepare("
        SELECT *,
               printer_type, printer_ip, printer_port, printer_mac 
        FROM kds_stores 
        WHERE id = ? AND deleted_at IS NULL
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllKdsUsersByStoreId(PDO $pdo, int $store_id): array {
    $stmt = $pdo->prepare("SELECT id, username, display_name, role, is_active, last_login_at FROM kds_users WHERE store_id = ? AND deleted_at IS NULL ORDER BY id ASC");
    $stmt->execute([$store_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getKdsUserById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id, username, display_name, role, is_active, store_id FROM kds_users WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllPosCategories(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM pos_categories WHERE deleted_at IS NULL ORDER BY sort_order ASC, id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllPromotions(PDO $pdo): array {
    $sql = "SELECT id, promo_name, promo_trigger_type, promo_start_date, promo_end_date, promo_is_active FROM pos_promotions ORDER BY promo_priority ASC, id DESC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getPromotionById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM pos_promotions WHERE id = ?");
    $stmt->execute([$id]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($promo) {
        $promo['promo_conditions'] = json_decode($promo['promo_conditions'], true);
        $promo['promo_actions'] = json_decode($promo['promo_actions'], true);
    }
    return $promo;
}

function getAllMemberLevels(PDO $pdo): array {
    $sql = "
        SELECT 
            pml.*, 
            pp.promo_name 
        FROM pos_member_levels pml
        LEFT JOIN pos_promotions pp ON pml.level_up_promo_id = pp.id
        ORDER BY pml.sort_order ASC, pml.points_threshold ASC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getMemberLevelById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM pos_member_levels WHERE id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function getAllMembers(PDO $pdo): array {
    $sql = "
        SELECT 
            m.*, 
            ml.level_name_zh 
        FROM pos_members m
        LEFT JOIN pos_member_levels ml ON m.member_level_id = ml.id
        WHERE m.deleted_at IS NULL
        ORDER BY m.id DESC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getMemberById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM pos_members WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

// --- START: Functions moved from index.php ---

function getAllRedemptionRules(PDO $pdo): array {
    $sql = "SELECT r.*, p.promo_name 
            FROM pos_point_redemption_rules r
            LEFT JOIN pos_promotions p ON r.reward_promo_id = p.id
            WHERE r.deleted_at IS NULL
            ORDER BY r.points_required ASC, r.id ASC";
    try {
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ($e->getCode() == '42S02') { 
             error_log("Warning: pos_point_redemption_rules table not found when fetching rules for routing. " . $e->getMessage());
             return [];
        }
        throw $e;
    }
}

function getAllPrintTemplates(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM pos_print_templates ORDER BY template_type, template_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ($e->getCode() == '42S02') {
             error_log("Warning: pos_print_templates table not found. " . $e->getMessage());
             return [];
        }
        throw $e;
    }
}

// --- END: Functions moved from index.php ---

?>