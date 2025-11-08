<?php
/**
 * Toptea POS - 通用 API 核心引擎
 * (Copied from HQ: api_core.php and Renamed for POS Silo)
 * 职责: 提供 run_api() 函数，驱动基于注册表的 CRUD 操作。
 * Version: 1.0.0
 * Date: 2025-11-08
 */

// 确保核心助手已被加载
if (!function_exists('json_error')) {
    // [MODIFIED 2a]
    require_once realpath(__DIR__ . '/../helpers/pos_json_helper.php');
}

// 确保权限常量已定义 (适应门店端角色)
if (!defined('ROLE_STORE_MANAGER')) {
    define('ROLE_STORE_MANAGER', 'manager');
}
if (!defined('ROLE_STORE_USER')) {
    define('ROLE_STORE_USER', 'staff');
}


/**
 * 运行 API 网关
 *
 * @param array $registry 完整的 API 资源注册表
 * @param PDO $pdo 数据库连接
 */
function run_api(array $registry, PDO $pdo): void {
    
    // 1. 获取资源和动作
    $resource_name = $_GET['res'] ?? null;
    $action_name = $_GET['act'] ?? null;

    if (empty($resource_name) || empty($action_name)) {
        json_error('无效的 API 请求：缺少 res (资源) 或 act (动作) 参数。', 400);
    }

    // 2. 查找资源配置
    $config = $registry[$resource_name] ?? null;
    if ($config === null) {
        json_error("资源 '{$resource_name}' 未在 API 注册表中定义。", 404);
    }

    // 3. 权限检查 (修改为只检查 POS 会话)
    @session_start();
    $required_role = $config['auth_role'] ?? ROLE_STORE_MANAGER; // 默认门店经理

    // 检查 POS 会话
    $is_pos_logged_in = $_SESSION['pos_logged_in'] ?? false;
    
    if (!$is_pos_logged_in) {
        json_error('权限不足：POS 会话未认证。', 401);
    }
    
    // 获取 POS 角色
    $user_role = $_SESSION['pos_user_role'] ?? ROLE_STORE_USER; // 默认 'staff'
    $user_id   = (int)($_SESSION['pos_user_id'] ?? 0);
    
    // 门店经理 (manager) 拥有所有权限
    if ($user_role !== ROLE_STORE_MANAGER && $user_role !== $required_role) {
        json_error("权限不足，禁止访问此资源。需要 '{$required_role}' 权限。", 403);
    }
    
    // 确保会话中有 store_id
    $store_id = (int)($_SESSION['pos_store_id'] ?? 0);
    if ($store_id === 0 || $user_id === 0) {
        json_error('会话无效：缺少 store_id 或 user_id。', 401);
    }

    // 4. 解析输入 (使用 pos_json_helper 中的 get_request_data)
    $input_data = get_request_data();
    
    try {
        // 5. 检查自定义动作
        if (isset($config['custom_actions'][$action_name])) {
            $function_name = $config['custom_actions'][$action_name];
            if (is_callable($function_name)) {
                // 执行自定义函数 (例如: handle_order_submit)
                call_user_func($function_name, $pdo, $config, $input_data);
            } else {
                json_error("配置错误: 资源 '{$resource_name}' 的自定义动作 '{$action_name}' 指向了无效函数 '{$function_name}'", 500);
            }
            exit; // 自定义函数必须自己调用 json_ok/json_error
        }

        // 6. 执行标准动作 (门店端 API 暂不使用标准动作)
        json_error("动作 '{$action_name}' 在资源 '{$resource_name}' 中未定义标准处理器。", 400);
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() == '23000') {
            json_error('数据库操作失败：违反唯一约束（例如：名称或编码重复）。', 409, ['debug' => $e->getMessage()]);
        }
        json_error('数据库错误', 500, ['debug' => $e->getMessage()]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_error('服务器内部错误', 500, ['debug' => $e->getMessage()]);
    }
}