<?php
/**
 * TopTea HQ - 完整运行时诊断程序 (V3)
 * 目标：精确定位 500 错误的根源。
 * * 此程序将模拟 'material_management.js' 在点击“创建”时
 * 调用的 'res=materials&act=get_next_code' 路径。
 */

// 1. 强制开启所有错误报告
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. 设置 JSON 输出，便于阅读
header('Content-Type: application/json; charset=utf-8');

// 3. 准备诊断报告
$report = [
    'test_target' => 'res=materials & act=get_next_code',
    'status' => 'UNKNOWN',
    'php_version' => phpversion(),
    'steps' => [],
    'error' => null,
    'output' => null
];

// 4. 注册一个关闭函数，用于捕获 'run_api()' 内部的 FATA_ERROR
register_shutdown_function(function () use (&$report) {
    $error = error_get_last();
    
    // 检查是否发生了致命错误
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR])) {
        
        $report['status'] = 'FATAL_ERROR';
        $report['error'] = [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line']
        ];
        
        // 确保 JSON 被正确输出
        if (headers_sent() === false) {
             header('Content-Type: application/json; charset=utf-8');
             http_response_code(500); // 模拟500
        }
        
        // 清理所有可能的缓冲区输出，只显示 JSON 错误
        ob_end_clean(); 
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 如果没有致命错误，说明 run_api() 正常执行完毕
    if ($report['status'] === 'UNKNOWN') {
        $report['status'] = 'SUCCESS';
        $report['steps'][] = 'run_api() 执行完毕。';
    }
    
    // 输出最终的 JSON 报告
    ob_end_flush(); // 输出 run_api() 的正常 JSON 响应
    echo "\n\n";
    echo "--- DIAGNOSTIC REPORT ---";
    echo "\n\n";
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
});

// 5. 开启输出缓冲，捕获 run_api() 的正常 JSON 输出
ob_start();

// 6. --- 开始模拟执行 ---

try {
    $report['steps'][] = '开始加载核心文件...';

    // 7. 加载核心文件 (基于 gateway.php)
    require_once realpath(__DIR__ . '/../../../core/config.php');
    $report['steps'][] = 'config.php (PDO) - OK';

    require_once APP_PATH . '/helpers/http_json_helper.php';
    $report['steps'][] = 'http_json_helper.php (json_ok/json_error) - OK';

    require_once APP_PATH . '/core/api_core.php';
    $report['steps'][] = 'api_core.php (run_api) - OK';

    // 8. 加载所有 helpers (模拟 kds_helper.php 的加载顺序)
    $report['steps'][] = '开始加载 helpers...';
    require_once APP_PATH . '/helpers/kds/kds_repo_a.php';
    $report['steps'][] = 'kds_repo_a.php - OK';
    require_once APP_PATH . '/helpers/kds/kds_repo_b.php';
    $report['steps'][] = 'kds_repo_b.php - OK';
    require_once APP_PATH . '/helpers/kds/kds_repo_c.php';
    $report['steps'][] = 'kds_repo_c.php - OK';
    require_once APP_PATH . '/helpers/kds/kds_sop_engine.php';
    $report['steps'][] = 'kds_sop_engine.php - OK';
    require_once APP_PATH . '/helpers/auth_helper.php';
    $report['steps'][] = 'auth_helper.php - OK';
    $report['steps'][] = '所有 helpers 加载完毕。';

    // 9. 加载所有注册表 (基于 gateway.php)
    $report['steps'][] = '开始加载注册表...';
    $registry_base = require_once __DIR__ . '/registries/cpsys_registry_base.php';
    $report['steps'][] = 'registry_base.php - OK';
    $registry_bms = require_once __DIR__ . '/registries/cpsys_registry_bms.php';
    $report['steps'][] = 'registry_bms.php - OK';
    $registry_rms = require_once __DIR__ . '/registries/cpsys_registry_rms.php';
    $report['steps'][] = 'registry_rms.php - OK';
    $registry_ext = require_once __DIR__ . '/registries/cpsys_registry_ext.php';
    $report['steps'][] = 'registry_ext.php - OK';
    $registry_kds = require_once __DIR__ . '/registries/cpsys_registry_kds.php';
    $report['steps'][] = 'registry_kds.php - OK';
    $report['steps'][] = '所有注册表加载完毕。';

    // 10. 合并注册表
    $full_registry = array_merge(
        $registry_base,
        $registry_bms,
        $registry_rms,
        $registry_ext,
        $registry_kds
    );
    $report['steps'][] = '注册表合并完毕。';

    // 11. --- 模拟请求 (与 2.jpg 一致) ---
    $_GET['res'] = 'materials';
    $_GET['act'] = 'get_next_code';

    // 模拟会话
    @session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['role_id'] = ROLE_SUPER_ADMIN; 
    $_SESSION['logged_in'] = true;

    $report['steps'][] = '会话已模拟 (SUPER_ADMIN)。';
    $report['steps'][] = '--- 即将执行 run_api(res=materials, act=get_next_code) ---';

    // 12. 运行引擎
    // 如果 'handle_material_get_next_code' 或其依赖 'getNextAvailableCustomCode'
    // 存在 "Cannot redeclare function" 或 "Call to undefined function"
    // register_shutdown_function 将会捕获它。
    run_api($full_registry, $pdo);

} catch (Throwable $e) {
    // 捕获 Exception (e.g., PDOException)
    $report['status'] = 'EXCEPTION_CAUGHT';
    $report['error'] = [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    // 确保 JSON 被正确输出
    ob_end_clean(); 
    if (headers_sent() === false) {
         header('Content-Type: application/json; charset=utf-8');
         http_response_code(500);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
?>