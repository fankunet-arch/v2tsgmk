<?php
/**
 * KDS Repo C - Dicts + Ops (Phase 2 consolidation)
 */

/**
 * KDS Repo - Dicts (cup/ice/sweet/addon/status)
 * Extracted from kds_repository.php (Phase 1 split).
 */

function getAllActiveIceOptions(PDO $pdo): array {
    $sql = "SELECT i.id, i.ice_code, it_zh.ice_option_name AS name_zh, it_zh.sop_description AS sop_zh FROM kds_ice_options i LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN' WHERE i.deleted_at IS NULL ORDER BY i.ice_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllActiveSweetnessOptions(PDO $pdo): array {
    $sql = "SELECT s.id, s.sweetness_code, st_zh.sweetness_option_name AS name_zh, st_zh.sop_description AS sop_zh FROM kds_sweetness_options s LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN' WHERE s.deleted_at IS NULL ORDER BY s.sweetness_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllSweetnessOptions(PDO $pdo): array {
    $sql = "
        SELECT 
            s.id, s.sweetness_code,
            st_zh.sweetness_option_name AS name_zh, 
            st_es.sweetness_option_name AS name_es,
            st_zh.sop_description AS sop_zh
        FROM kds_sweetness_options s 
        LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN' 
        LEFT JOIN kds_sweetness_option_translations st_es ON s.id = st_es.sweetness_option_id AND st_es.language_code = 'es-ES'
        WHERE s.deleted_at IS NULL 
        ORDER BY s.sweetness_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSweetnessOptionById(PDO $pdo, int $id) {
    $sql = "
        SELECT 
            s.id, s.sweetness_code,
            st_zh.sweetness_option_name AS name_zh, 
            st_es.sweetness_option_name AS name_es,
            st_zh.sop_description AS sop_zh,
            st_es.sop_description AS sop_es
        FROM kds_sweetness_options s 
        LEFT JOIN kds_sweetness_option_translations st_zh ON s.id = st_zh.sweetness_option_id AND st_zh.language_code = 'zh-CN' 
        LEFT JOIN kds_sweetness_option_translations st_es ON s.id = st_es.sweetness_option_id AND st_es.language_code = 'es-ES' 
        WHERE s.id = ? AND s.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllIceOptions(PDO $pdo): array {
    $sql = "
        SELECT 
            i.id, i.ice_code,
            it_zh.ice_option_name AS name_zh, 
            it_es.ice_option_name AS name_es,
            it_zh.sop_description AS sop_zh
        FROM kds_ice_options i 
        LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN' 
        LEFT JOIN kds_ice_option_translations it_es ON i.id = it_es.ice_option_id AND it_es.language_code = 'es-ES'
        WHERE i.deleted_at IS NULL 
        ORDER BY i.ice_code ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getIceOptionById(PDO $pdo, int $id) {
    $sql = "
        SELECT 
            i.id, i.ice_code,
            it_zh.ice_option_name AS name_zh, 
            it_es.ice_option_name AS name_es,
            it_zh.sop_description AS sop_zh,
            it_es.sop_description AS sop_es
        FROM kds_ice_options i 
        LEFT JOIN kds_ice_option_translations it_zh ON i.id = it_zh.ice_option_id AND it_zh.language_code = 'zh-CN' 
        LEFT JOIN kds_ice_option_translations it_es ON i.id = it_es.ice_option_id AND it_es.language_code = 'es-ES' 
        WHERE i.id = ? AND i.deleted_at IS NULL";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllCups(PDO $pdo): array {
    // FIX A2: Added sop_description_es to the query
    $stmt = $pdo->query("SELECT id, cup_code, cup_name, sop_description_zh, sop_description_es, volume_ml FROM kds_cups WHERE deleted_at IS NULL ORDER BY cup_code ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCupById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id, cup_code, cup_name, sop_description_zh, sop_description_es, volume_ml FROM kds_cups WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllStatuses(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, status_code, status_name_zh, status_name_es FROM kds_product_statuses WHERE deleted_at IS NULL ORDER BY status_code ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllInvoices(PDO $pdo): array {
    $sql = "
        SELECT 
            pi.id,
            pi.series,
            pi.number,
            pi.issued_at,
            pi.final_total,
            pi.status,
            pi.compliance_system,
            ks.store_name
        FROM pos_invoices pi
        LEFT JOIN kds_stores ks ON pi.store_id = ks.id
        ORDER BY pi.issued_at DESC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getInvoiceDetails(PDO $pdo, int $invoice_id): ?array {
    $sql = "
        SELECT 
            pi.*,
            ks.store_name,
            ks.tax_id AS issuer_tax_id_snapshot,
            cu.display_name AS cashier_name
        FROM pos_invoices pi
        LEFT JOIN kds_stores ks ON pi.store_id = ks.id
        LEFT JOIN cpsys_users cu ON pi.user_id = cu.id
        WHERE pi.id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        return null;
    }

    $sql_items = "SELECT * FROM pos_invoice_items WHERE invoice_id = ?";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([$invoice_id]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $invoice['items'] = $items;
    
    $invoice['compliance_data_decoded'] = json_decode($invoice['compliance_data'] ?? '[]', true);
    $invoice['payment_summary_decoded'] = json_decode($invoice['payment_summary'] ?? '[]', true);

    return $invoice;
}

function getAllPosAddons(PDO $pdo): array {
    try {
        $sql = "
            SELECT 
                pa.*,
                mt.material_name AS material_name_zh
            FROM pos_addons pa
            LEFT JOIN kds_materials m ON pa.material_id = m.id
            LEFT JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
            WHERE pa.deleted_at IS NULL
            ORDER BY pa.sort_order ASC, pa.id ASC
        ";
        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if ($e->getCode() == '42S02') { 
             error_log("Warning: pos_addons table not found. " . $e->getMessage());
             return [];
        }
        throw $e;
    }
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

/**
 * KDS Repo - Ops (stock/warehouse/eod/expiry)
 * Extracted from kds_repository.php (Phase 1 split).
 */

function getWarehouseStock(PDO $pdo): array {
    $sql = "
        SELECT 
            m.id as material_id,
            m.material_type,
            mt.material_name,
            ut.unit_name AS base_unit_name,
            COALESCE(ws.quantity, 0) as quantity
        FROM kds_materials m
        JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
        JOIN kds_unit_translations ut ON m.base_unit_id = ut.unit_id AND ut.language_code = 'zh-CN'
        LEFT JOIN expsys_warehouse_stock ws ON m.id = ws.material_id
        WHERE m.deleted_at IS NULL
        ORDER BY m.material_code ASC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllStoreStock(PDO $pdo): array {
    $sql = "
        SELECT 
            s.store_name,
            mt.material_name,
            ut.unit_name AS base_unit_name,
            ss.quantity
        FROM expsys_store_stock ss
        JOIN kds_stores s ON ss.store_id = s.id
        JOIN kds_materials m ON ss.material_id = m.id
        JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
        JOIN kds_unit_translations ut ON m.base_unit_id = ut.unit_id AND ut.language_code = 'zh-CN'
        WHERE s.deleted_at IS NULL AND m.deleted_at IS NULL AND s.is_active = 1
        ORDER BY s.store_code ASC, mt.material_name ASC
    ";
    $stmt = $pdo->query($sql);
    $flat_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped_results = [];
    foreach ($flat_results as $row) {
        $grouped_results[$row['store_name']][] = [
            'material_name' => $row['material_name'],
            'quantity' => $row['quantity'],
            'base_unit_name' => $row['base_unit_name']
        ];
    }
    return $grouped_results;
}

function getAllExpiryItems(PDO $pdo): array {
    $sql = "
        SELECT 
            e.id, e.opened_at, e.expires_at, e.status, e.handled_at,
            mt.material_name,
            s.store_name,
            u.display_name AS handler_name
        FROM kds_material_expiries e
        JOIN kds_material_translations mt ON e.material_id = mt.material_id AND mt.language_code = 'zh-CN'
        JOIN kds_stores s ON e.store_id = s.id
        LEFT JOIN kds_users u ON e.handler_id = u.id
        ORDER BY e.expires_at DESC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllEodReports(PDO $pdo): array {
    $sql = "
        SELECT 
            per.*,
            ks.store_name,
            cu.display_name AS user_name
        FROM pos_eod_reports per
        LEFT JOIN kds_stores ks ON per.store_id = ks.id
        LEFT JOIN cpsys_users cu ON per.user_id = cu.id
        ORDER BY per.report_date DESC, ks.store_code ASC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getPendingShiftReviewCount(PDO $pdo): int {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM pos_shifts WHERE status = 'FORCE_CLOSED' AND admin_reviewed = 0");
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error in getPendingShiftReviewCount: " . $e->getMessage());
        return 0;
    }
}

function getPendingShiftReviews(PDO $pdo): array {
    try {
        $sql = "
            SELECT 
                s.id, s.start_time, s.end_time, s.expected_cash, s.payment_summary,
                st.store_name,
                u.display_name AS user_name
            FROM pos_shifts s
            LEFT JOIN kds_stores st ON s.store_id = st.id
            LEFT JOIN kds_users u ON s.user_id = u.id
            WHERE s.status = 'FORCE_CLOSED' AND s.admin_reviewed = 0
            ORDER BY s.start_time DESC
        ";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
         error_log("Error in getPendingShiftReviews: " . $e->getMessage());
        return [];
    }
}
