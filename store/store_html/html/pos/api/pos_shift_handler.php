<?php
/**
 * TopTea POS · Shift Management API
 * v2.1.1 (Ghost Shift Guardian - DB Schema FIX)
 *
 * Actions: status | start | end | force_start
 * - `force_start` action: Removed 'notes' column from the INSERT statement
 * for 'pos_eod_records' to match the database schema.
 */
require_once realpath(__DIR__ . '/../../../pos_backend/core/config.php');
require_once realpath(__DIR__ . '/../../../pos_backend/core/api_auth_core.php');

header('Content-Type: application/json; charset=utf-8');

/* ---------- helpers ---------- */
function send_json(string $status, string $message, $data = null, int $http = 200): void {
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
function col_exists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}

/**
 * 计算 expected_cash
 * 口径：starting_float + cash_sales + cash_in - cash_out - cash_refunds
 * 时间窗：本班次 start_time ~ end_time(UTC)
 */
function compute_expected_cash(PDO $pdo, int $store_id, string $start_iso, string $end_iso, float $starting_float): array {
    $cash_sales   = 0.0;
    $cash_in      = 0.0;
    $cash_out     = 0.0;
    $cash_refunds = 0.0;

    // 1) 优先用 pos_payments(method='CASH')
    if (table_exists($pdo, 'pos_payments')) {
        $ts_col  = col_exists($pdo,'pos_payments','paid_at')    ? 'paid_at'
                : (col_exists($pdo,'pos_payments','created_at') ? 'created_at' : null);
        $amt_col = col_exists($pdo,'pos_payments','amount_eur') ? 'amount_eur'
                : (col_exists($pdo,'pos_payments','amount')     ? 'amount'     : null);
        if ($ts_col && $amt_col && col_exists($pdo,'pos_payments','method')) {
            // 现金销售（正额，排除退款）
            $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_payments
                    WHERE store_id=? AND method='CASH' AND $ts_col BETWEEN ? AND ?
                      AND (NOT (COALESCE(is_refund,0)=1))";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$store_id, $start_iso, $end_iso]);
            $cash_sales = (float)$stmt->fetchColumn();

            // 现金退款
            if (col_exists($pdo, 'pos_payments', 'is_refund')) {
                $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_payments
                        WHERE store_id=? AND method='CASH' AND COALESCE(is_refund,0)=1
                          AND $ts_col BETWEEN ? AND ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$store_id, $start_iso, $end_iso]);
                $cash_refunds = (float)$stmt->fetchColumn();
            } else {
                // 无 is_refund 字段时：负金额视作退款
                $sql = "SELECT COALESCE(SUM(CASE WHEN $amt_col<0 THEN -$amt_col ELSE 0 END),0)
                        FROM pos_payments
                        WHERE store_id=? AND method='CASH' AND $ts_col BETWEEN ? AND ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$store_id, $start_iso, $end_iso]);
                $cash_refunds = (float)$stmt->fetchColumn();
            }
        }
    }
    // 2) 回退：pos_orders（payment_method='CASH'）
    elseif (table_exists($pdo, 'pos_orders')) {
        $ts_col  = col_exists($pdo,'pos_orders','paid_at')     ? 'paid_at'
                : (col_exists($pdo,'pos_orders','created_at')  ? 'created_at' : null);
        $amt_col = col_exists($pdo,'pos_orders','grand_total_eur')   ? 'grand_total_eur'
                : (col_exists($pdo,'pos_orders','total_amount_eur') ? 'total_amount_eur'
                : (col_exists($pdo,'pos_orders','total')           ? 'total' : null));
        if ($ts_col && $amt_col && col_exists($pdo,'pos_orders','payment_method')) {
            $where_paid = col_exists($pdo,'pos_orders','status') ? "AND status IN ('PAID','COMPLETED','CLOSED')" : '';
            $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_orders
                    WHERE store_id=? AND payment_method='CASH' $where_paid
                      AND $ts_col BETWEEN ? AND ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$store_id, $start_iso, $end_iso]);
            $cash_sales = (float)$stmt->fetchColumn();
        }
    }

    // 3) 现金出入：pos_cash_movements
    if (table_exists($pdo, 'pos_cash_movements')) {
        $ts_col  = col_exists($pdo,'pos_cash_movements','created_at') ? 'created_at'
                : (col_exists($pdo,'pos_cash_movements','occurred_at') ? 'occurred_at' : null);
        $amt_col = col_exists($pdo,'pos_cash_movements','amount_eur') ? 'amount_eur'
                : (col_exists($pdo,'pos_cash_movements','amount')     ? 'amount'     : null);
        $type_col= col_exists($pdo,'pos_cash_movements','movement_type') ? 'movement_type'
                : (col_exists($pdo,'pos_cash_movements','type')          ? 'type'          : null);
        if ($ts_col && $amt_col && $type_col) {
            // IN
            $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_cash_movements
                    WHERE store_id=? AND $type_col IN ('CASH_IN','SAFE_IN','ADJUST_IN')
                      AND $ts_col BETWEEN ? AND ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$store_id, $start_iso, $end_iso]);
            $cash_in = (float)$stmt->fetchColumn();

            // OUT
            $sql = "SELECT COALESCE(SUM($amt_col),0) FROM pos_cash_movements
                    WHERE store_id=? AND $type_col IN ('CASH_OUT','PAYOUT','SAFE_OUT','ADJUST_OUT')
                      AND $ts_col BETWEEN ? AND ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$store_id, $start_iso, $end_iso]);
            $cash_out = (float)$stmt->fetchColumn();
        }
    }

    $expected_cash = (float)$starting_float + (float)$cash_sales + (float)$cash_in - (float)$cash_out - (float)$cash_refunds;

    return [
        'starting_float' => round((float)$starting_float, 2),
        'cash_sales'     => round((float)$cash_sales, 2),
        'cash_in'        => round((float)$cash_in, 2),
        'cash_out'       => round((float)$cash_out, 2),
        'cash_refunds'   => round((float)$cash_refunds, 2),
        'expected_cash'  => round((float)$expected_cash, 2),
    ];
}
/* ---------- helpers end ---------- */

$user_id  = (int)($_SESSION['pos_user_id']  ?? 0);
$store_id = (int)($_SESSION['pos_store_id'] ?? 0);
if ($user_id === 0 || $store_id === 0) {
    send_json('error', 'Unauthorized: invalid session.', null, 401);
}

$action = $_GET['action'] ?? null;
$body   = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            send_json('error', 'Bad JSON payload.', ['debug' => json_last_error_msg()], 400);
        }
        $action = $body['action'] ?? $action;
    }
}

// 统一获取关账时间 (EOD cutoff hour)
$stmt_store = $pdo->prepare("SELECT eod_cutoff_hour FROM kds_stores WHERE id = ?");
$stmt_store->execute([$store_id]);
$eod_cutoff_hour = (int)($stmt_store->fetchColumn() ?: 3); // 默认为 3 (凌晨3点)

// 统一“现在”时间
$tzMadrid = new DateTimeZone(APP_TZ);
$now_madrid = new DateTime('now', $tzMadrid);
$now_utc_str = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');


try {
    switch ($action) {
        case 'status': {
            // [GHOST SHIFT FIX v2.1.0]
            
            // 1. 定义“今天”的关账时间点 (马德里时区)
            $today_cutoff_dt_madrid = (clone $now_madrid)->setTime($eod_cutoff_hour, 0, 0);
            
            // 2. 将其转换为 UTC
            $cutoff_dt_utc_str = (clone $today_cutoff_dt_madrid)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            // 3. 查找本店 *任何* 处于 ACTIVE 的班次
            $stmt_any = $pdo->prepare(
                "SELECT s.id, s.user_id, s.start_time, u.display_name
                 FROM pos_shifts s
                 LEFT JOIN kds_users u ON s.user_id = u.id AND s.store_id = u.store_id
                 WHERE s.store_id=? AND s.status='ACTIVE'
                 ORDER BY s.id ASC LIMIT 1"
            );
            $stmt_any->execute([$store_id]);
            $active_shift = $stmt_any->fetch(PDO::FETCH_ASSOC);

            // 4. 如果 *没有* 任何 ACTIVE 班次
            if (!$active_shift) {
                unset($_SESSION['pos_shift_id']);
                send_json('success', 'No active shift.', ['has_active_shift'=>false, 'ghost_shift_detected'=>false]);
            }

            // 5. 如果 *有* ACTIVE 班次，检查它是否为幽灵
            // 幽灵班次 = start_time 早于 "今天" 的关账时间点
            $is_ghost = ($active_shift['start_time'] < $cutoff_dt_utc_str);

            if ($is_ghost) {
                // 5a. 这是一个幽灵班次
                if ((int)$active_shift['user_id'] === $user_id) {
                    unset($_SESSION['pos_shift_id']); // 清理 session
                }
                $ghost_start_dt_utc = new DateTime($active_shift['start_time'], new DateTimeZone('UTC'));
                send_json('success', 'Ghost shift detected.', [
                    'has_active_shift' => false,
                    'ghost_shift_detected' => true,
                    'ghost_shift_user_name' => $active_shift['display_name'] ?? '未知员工',
                    // 转换为马德里时间显示
                    'ghost_shift_start_time' => $ghost_start_dt_utc->setTimezone($tzMadrid)->format('Y-m-d H:i') 
                ]);
            } else {
                // 5b. 这是一个 *有效* 的 ACTIVE 班次 (今天刚开的)
                if ((int)$active_shift['user_id'] === $user_id) {
                    // 是我的班次
                    $_SESSION['pos_shift_id'] = (int)$active_shift['id'];
                    send_json('success', 'Active shift found for current user.', ['has_active_shift'=>true, 'shift'=>$active_shift]);
                } else {
                    // 是别人的有效班次
                    unset($_SESSION['pos_shift_id']);
                    send_json('success', 'Another user shift is active.', ['has_active_shift'=>false, 'ghost_shift_detected'=>false]);
                }
            }
        }
        
        case 'start': {
            $starting_float = null;
            if (is_array($body) && array_key_exists('starting_float', $body)) {
                $starting_float = (float)$body['starting_float'];
            }
            if (!is_numeric($starting_float) || $starting_float < 0) {
                send_json('error', 'Invalid starting_float.', null, 422);
            }

            $tx_started = false;
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

            // 幂等：已有ACTIVE则复用
            $chk = $pdo->prepare("SELECT id FROM pos_shifts WHERE user_id=? AND store_id=? AND status='ACTIVE' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $chk->execute([$user_id, $store_id]);
            if ($existing_id = $chk->fetchColumn()) {
                $_SESSION['pos_shift_id'] = (int)$existing_id;
                if ($tx_started && $pdo->inTransaction()) $pdo->commit();
                send_json('success', 'Shift already active (reused).', ['shift_id' => $existing_id]);
            }

            // 安全检查：确保没有其他幽灵班次（如果 status action 失败了）
            $chk_ghost = $pdo->prepare("SELECT id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' LIMIT 1 FOR UPDATE");
            $chk_ghost->execute([$store_id]);
            if ($chk_ghost->fetchColumn()) {
                 if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
                 send_json('error', 'Cannot start shift, another shift is still active.', null, 409);
            }

            // 新建
            $uuid = bin2hex(random_bytes(16));
            $ins = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
            $ins->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
            $shift_id = (int)$pdo->lastInsertId();

            if ($tx_started && $pdo->inTransaction()) $pdo->commit();
            $_SESSION['pos_shift_id'] = $shift_id;

            send_json('success','Shift started.',[
                'shift'=>[ 'id'=>$shift_id, 'start_time'=>$now_utc_str, 'starting_float'=>(float)$starting_float ]
            ]);
        }

        case 'end': {
            $counted_cash = null;
            $shift_id = (int)($_SESSION['pos_shift_id'] ?? 0);
            if (is_array($body) && isset($body['counted_cash'])) $counted_cash = (float)$body['counted_cash'];
            if ($shift_id <= 0) send_json('error', 'No active shift in session.', null, 400);
            if (!is_numeric($counted_cash) || $counted_cash < 0) send_json('error', 'Invalid counted_cash.', null, 422);

            $tx_started = false;
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

            $lock = $pdo->prepare("SELECT id, start_time, starting_float FROM pos_shifts WHERE id=? AND user_id=? AND store_id=? AND status='ACTIVE' FOR UPDATE");
            $lock->execute([$shift_id, $user_id, $store_id]);
            $shift = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$shift) {
                if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
                send_json('error', 'Active shift not found or already ended.', null, 404);
            }

            $totals = compute_expected_cash($pdo, $store_id, $shift['start_time'], $now_utc_str, (float)$shift['starting_float']);
            $expected_cash = (float)$totals['expected_cash'];
            $cash_diff     = round((float)$counted_cash - $expected_cash, 2);

            $upd = $pdo->prepare("UPDATE pos_shifts SET end_time=?, status='ENDED', counted_cash=? WHERE id=?");
            $upd->execute([$now_utc_str, $counted_cash, $shift_id]);

            // 写交接班记录（如果表存在）
            if (table_exists($pdo, 'pos_eod_records')) {
                $ins = $pdo->prepare("INSERT INTO pos_eod_records
                  (shift_id, store_id, user_id, started_at, ended_at, starting_float,
                   cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $shift_id, $store_id, $user_id, $shift['start_time'], $now_utc_str, (float)$totals['starting_float'],
                    (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
                    (float)$totals['cash_refunds'], $expected_cash, (float)$counted_cash, (float)$cash_diff
                ]);
                $eod_id = (int)$pdo->lastInsertId();
            } else {
                $eod_id = null; // 表不存在
            }

            if ($tx_started && $pdo->inTransaction()) $pdo->commit();
            unset($_SESSION['pos_shift_id']);

            send_json('success','Shift ended.',[
                'eod_id' => $eod_id,
                'eod' => [ 'shift_id' => $shift_id, 'started_at' => $shift['start_time'], 'ended_at' => $now_utc_str,
                           'starting_float' => $totals['starting_float'], 'cash_sales' => $totals['cash_sales'],
                           'cash_in' => $totals['cash_in'], 'cash_out' => $totals['cash_out'],
                           'cash_refunds' => $totals['cash_refunds'], 'expected_cash' => $totals['expected_cash'],
                           'counted_cash' => (float)$counted_cash, 'cash_diff' => $cash_diff ]
            ]);
        }
        
        case 'force_start': {
            $starting_float = (float)($body['starting_float'] ?? -1);
            if ($starting_float < 0) {
                send_json('error', 'Invalid starting_float for new shift.', null, 422);
            }
            
            $tx_started = false;
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $tx_started = true; }

            // 1. 查找所有本门店的幽灵班次
            $stmt_ghosts = $pdo->prepare("SELECT id, start_time, starting_float, user_id FROM pos_shifts WHERE store_id=? AND status='ACTIVE' FOR UPDATE");
            $stmt_ghosts->execute([$store_id]);
            $ghosts = $stmt_ghosts->fetchAll(PDO::FETCH_ASSOC);

            if (empty($ghosts)) {
                 if ($tx_started && $pdo->inTransaction()) $pdo->rollBack();
                 send_json('error', 'No ghost shifts found. Please try starting a normal shift.', ['redirect_action' => 'start'], 404);
            }

            $closer_name = $_SESSION['pos_display_name'] ?? ('User #' . $user_id);

            foreach ($ghosts as $ghost) {
                $ghost_id = (int)$ghost['id'];
                
                // 2. 为每个幽灵班次计算理论金额
                $totals = compute_expected_cash($pdo, $store_id, $ghost['start_time'], $now_utc_str, (float)$ghost['starting_float']);
                
                // 3. 更新幽灵班次
                $upd = $pdo->prepare(
                    "UPDATE pos_shifts SET 
                        end_time = ?, 
                        status = 'FORCE_CLOSED', 
                        counted_cash = NULL, 
                        expected_cash = ?, 
                        cash_variance = NULL, 
                        payment_summary = ?, 
                        admin_reviewed = 0 
                     WHERE id = ?"
                );
                $upd->execute([
                    $now_utc_str,
                    (float)$totals['expected_cash'],
                    json_encode(['note' => 'Forcibly closed by ' . $closer_name]),
                    $ghost_id
                ]);
                
                // 4. [可选] 为被关闭的班次也写入 eod_records 记录
                // [GHOST SHIFT FIX v2.1.1] 移除 'notes' 字段
                if (table_exists($pdo, 'pos_eod_records')) {
                    $ins = $pdo->prepare("INSERT INTO pos_eod_records
                      (shift_id, store_id, user_id, started_at, ended_at, starting_float,
                       cash_sales, cash_in, cash_out, cash_refunds, expected_cash, counted_cash, cash_diff)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $ins->execute([
                        $ghost_id, $store_id, $ghost['user_id'], $ghost['start_time'], $now_utc_str, (float)$totals['starting_float'],
                        (float)$totals['cash_sales'], (float)$totals['cash_in'], (float)$totals['cash_out'],
                        (float)$totals['cash_refunds'], (float)$totals['expected_cash'], 0.00, 0.00 // Counted 和 Diff 设为 0
                    ]);
                }
            }
            
            // 5. 开启员工 B 的新班次
            $uuid = bin2hex(random_bytes(16));
            $ins_new = $pdo->prepare("INSERT INTO pos_shifts (shift_uuid, store_id, user_id, start_time, status, starting_float) VALUES (?, ?, ?, ?, 'ACTIVE', ?)");
            $ins_new->execute([$uuid, $store_id, $user_id, $now_utc_str, $starting_float]);
            $new_shift_id = (int)$pdo->lastInsertId();

            if ($tx_started && $pdo->inTransaction()) $pdo->commit();
            
            // 6. 将新班次ID存入会话
            $_SESSION['pos_shift_id'] = $new_shift_id;
            
            send_json('success', 'Ghost shifts closed and new shift started.', [
                'shift' => [ 'id' => $new_shift_id, 'start_time' => $now_utc_str, 'starting_float' => $starting_float ]
            ]);
        }

        default:
            send_json('error', 'Invalid action.', null, 400);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $_) {} }
    error_log('Shift Handler Error: ' . $e->getMessage() . ' on line ' . $e->getLine());
    send_json('error', 'Internal server error.', ['debug' => $e->getMessage()], 500);
}