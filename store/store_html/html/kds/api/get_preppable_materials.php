<?php
/**
 * Toptea Store - KDS API
 * API Endpoint for KDS to fetch preppable materials
 * Engineer: Gemini | Date: 2025-10-26 | Revision: 8.3 (SQL JOIN and Catch Fix)
 */
require_once realpath(__DIR__ . '/../../../kds/core/config.php');

header('Content-Type: application/json; charset=utf-8');
@session_start();

if (!isset($_SESSION['kds_store_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: No store session found.']);
    exit;
}

// --- START: 500 ERROR FIX (Revision 8.3) ---
try {
    // --- 1. SQL FIX: Changed JOIN to LEFT JOIN ---
    // 这可以确保即使物料缺少 'zh-CN' 或 'es-ES' 翻译，也不会导致查询失败
    $sql = "
        SELECT 
            m.id,
            m.material_type,
            mt.material_name AS name_zh,
            mt_es.material_name AS name_es
        FROM kds_materials m
        LEFT JOIN kds_material_translations mt ON m.id = mt.material_id AND mt.language_code = 'zh-CN'
        LEFT JOIN kds_material_translations mt_es ON m.id = mt_es.material_id AND mt_es.language_code = 'es-ES'
        WHERE m.deleted_at IS NULL
          AND m.expiry_rule_type IS NOT NULL -- Crucial filter: only items needing tracking appear
        ORDER BY m.material_code ASC
    ";
    
    $stmt = $pdo->query($sql);
    
    // 如果查询失败，$stmt 将为 false。
    if ($stmt === false) {
        throw new Exception("SQL query failed to execute.");
    }
    
    $all_materials = $stmt->fetchAll(PDO::FETCH_ASSOC); // 使用 FETCH_ASSOC

    $response_data = [
        'packaged_goods' => [], // '开封物料'
        'in_store_preps' => []  // '门店现制'
    ];

    // (User Logic Fix)
    foreach ($all_materials as $material) {
        if ($material['material_type'] === 'PRODUCT' || $material['material_type'] === 'RAW') {
            $response_data['packaged_goods'][] = $material;
        } 
        elseif ($material['material_type'] === 'SEMI_FINISHED') {
            $response_data['in_store_preps'][] = $material;
        }
    }

    echo json_encode(['status' => 'success', 'data' => $response_data]);

// --- 2. CATCH FIX: Changed Exception to Throwable ---
// 这将捕获所有致命错误 (如 call to member function on bool)
} catch (Throwable $e) { 
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Failed to fetch materials.', 'debug' => $e->getMessage()]);
}
// --- END: 500 ERROR FIX ---
?>