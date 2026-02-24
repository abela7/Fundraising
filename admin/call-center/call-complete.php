<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : (int)($_POST['session_id'] ?? 0);
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : (int)($_POST['donor_id'] ?? 0);
$user_id = (int)($_SESSION['user']['id'] ?? 0);

if (!$session_id) {
    header('Location: index.php');
    exit;
}

$db = db();
$flash_message = null;
$flash_type = 'success';

if (isset($_SESSION['call_complete_flash'])) {
    $flash = $_SESSION['call_complete_flash'];
    $flash_message = is_array($flash) ? ($flash['message'] ?? null) : null;
    $flash_type = is_array($flash) ? ($flash['type'] ?? 'success') : 'success';
    unset($_SESSION['call_complete_flash']);
}

function table_has_column(mysqli $db, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safe_column = $db->real_escape_string($column);
    $safe_table = $db->real_escape_string($table);
    $result = $db->query("SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    $exists = $result && $result->num_rows > 0;
    $cache[$key] = (bool)$exists;
    return (bool)$exists;
}

function fetch_call_session(mysqli $db, int $session_id, int $donor_id = 0, bool $include_claimed_paid = false): ?array {
    $claimed_paid_select = $include_claimed_paid
        ? 's.donor_claimed_already_paid'
        : '0 AS donor_claimed_already_paid';

    $query = "
        SELECT
            s.id,
            s.donor_id,
            s.agent_id,
            s.queue_id,
            s.outcome,
            s.conversation_stage,
            s.notes,
            s.duration_seconds,
            s.call_started_at,
            s.call_ended_at,
            {$claimed_paid_select},
            d.total_pledged,
            d.total_paid,
            d.balance,
            d.payment_status,
            d.name as donor_name,
            d.phone as donor_phone,
            u.name as agent_name
        FROM call_center_sessions s
        JOIN donors d ON s.donor_id = d.id
        LEFT JOIN users u ON s.agent_id = u.id
        WHERE s.id = ?
    ";
    $params = [$session_id];
    if ($donor_id > 0) {
        $query .= " AND s.donor_id = ?";
        $params[] = $donor_id;
    }
    $query .= " LIMIT 1";

    $stmt = $db->prepare($query);
    if (!$stmt) {
        return null;
    }

    $types = str_repeat('i', count($params));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ?: null;
}

$has_claimed_paid_column = table_has_column($db, 'call_center_sessions', 'donor_claimed_already_paid');
$session_record = fetch_call_session($db, $session_id, $donor_id, $has_claimed_paid_column);
if (!$session_record && $donor_id > 0) {
    $session_record = fetch_call_session($db, $session_id, 0, $has_claimed_paid_column);
}

if (!$session_record) {
    header('Location: index.php');
    exit;
}

$donor_id = (int)$session_record['donor_id'];
$session_id = (int)$session_record['id'];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_previously_paid'
) {
    verify_csrf();

    $post_session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $post_donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    if (!$post_donor_id) {
        $post_donor_id = $donor_id;
    }

    if (!$post_session_id || !$post_donor_id) {
        $_SESSION['call_complete_flash'] = [
            'type' => 'danger',
            'message' => 'Missing session or donor ID.'
        ];
        header("Location: call-complete.php?session_id={$session_id}&donor_id={$donor_id}");
        exit;
    }

    $lookup = $db->prepare("
        SELECT id, donor_id, outcome, conversation_stage, queue_id
        FROM call_center_sessions
        WHERE id = ?
        LIMIT 1
    ");
    $lookup->bind_param('i', $post_session_id);
    $lookup->execute();
    $lookup_result = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$lookup_result) {
        $_SESSION['call_complete_flash'] = [
            'type' => 'danger',
            'message' => 'Session not found for this donor.'
        ];
        header("Location: call-complete.php?session_id={$post_session_id}&donor_id={$post_donor_id}");
        exit;
    }

    $db->begin_transaction();

    try {
    $effective_donor_id = (int)($lookup_result['donor_id'] ?? 0);
    if (!$effective_donor_id) {
        $_SESSION['call_complete_flash'] = [
            'type' => 'danger',
            'message' => 'Session lookup returned no donor.'
        ];
        header("Location: call-complete.php?session_id={$post_session_id}");
        exit;
    }

    if ($post_donor_id !== $effective_donor_id) {
        $post_donor_id = $effective_donor_id;
    }

    $notes_append = "\nMarked as previously paid by agent on " . date('Y-m-d H:i:s') . '.';
        $session_update = "
            UPDATE call_center_sessions
            SET outcome = 'agreed_to_pay_full',
                conversation_stage = 'success_pledged',
                notes = CONCAT(COALESCE(notes, ''), ?),
                call_ended_at = NOW()
        ";
        if ($has_claimed_paid_column) {
            $session_update .= ", donor_claimed_already_paid = 1";
        }
        $session_update .= " WHERE id = ? AND donor_id = ?";

        $stmt = $db->prepare($session_update);
        if (!$stmt) {
            throw new Exception('Failed to prepare call session update statement.');
        }
        $stmt->bind_param('sii', $notes_append, $post_session_id, $post_donor_id);
        $stmt->execute();
        if (($stmt->affected_rows ?? 0) < 1) {
            throw new Exception('Session record was not updated.');
        }
        $stmt->close();

        if (!empty($lookup_result['queue_id'])) {
            $queue_id = (int)$lookup_result['queue_id'];
            $queue_update = $db->prepare("
                UPDATE call_center_queues
                SET status = 'completed',
                    completed_at = NOW(),
                    last_attempt_outcome = 'agreed_to_pay_full'
                WHERE id = ?
            ");
            if ($queue_update) {
                $queue_update->bind_param('i', $queue_id);
                $queue_update->execute();
                $queue_update->close();
            }
        }

        log_audit(
            $db,
            'update',
            'call_center_session',
            $post_session_id,
            null,
            [
                'donor_claimed_already_paid' => 1,
                'outcome' => 'agreed_to_pay_full',
                'conversation_stage' => 'success_pledged',
                'notes_append' => trim($notes_append),
                'trigger' => 'manual_mark_previously_paid'
            ],
            get_current_source(),
            $user_id
        );

        $db->commit();

        $_SESSION['call_complete_flash'] = [
            'type' => 'success',
            'message' => $has_claimed_paid_column
                ? 'Donor marked as previously paid successfully.'
                : 'Donor marked as previously paid in notes. Add database migration to enable structured tracking.'
        ];
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['call_complete_flash'] = [
            'type' => 'danger',
            'message' => 'Failed to update donor as previously paid: ' . $e->getMessage()
        ];
    }

    header("Location: call-complete.php?session_id={$post_session_id}&donor_id={$post_donor_id}");
    exit;
}

// Fetch Session and Donor Info
$claimed_paid_select = $has_claimed_paid_column ? 's.donor_claimed_already_paid' : '0 AS donor_claimed_already_paid';

$query = "
    SELECT 
        s.outcome, s.conversation_stage, s.duration_seconds, s.call_started_at, s.call_ended_at, s.notes,
        {$claimed_paid_select},
        d.total_pledged,
        d.total_paid,
        d.balance,
        d.payment_status,
        d.name as donor_name, d.phone as donor_phone,
        u.name as agent_name
    FROM call_center_sessions s
    JOIN donors d ON s.donor_id = d.id
    LEFT JOIN users u ON s.agent_id = u.id
    WHERE s.id = ? AND s.donor_id = ?
";
$stmt = $db->prepare($query);
$stmt->bind_param('ii', $session_id, $donor_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_object();

if (!$result) {
    header('Location: index.php');
    exit;
}

$duration_seconds = (int)($result->duration_seconds ?? 0);
$duration_formatted = gmdate("H:i:s", $duration_seconds);
$notes_text = (string)($result->notes ?? '');
$notes_lower = strtolower($notes_text);

$total_pledged = (float)($result->total_pledged ?? 0);
$total_paid = (float)($result->total_paid ?? 0);
$balance = (float)($result->balance ?? ($total_pledged - $total_paid));
$payment_status = (string)($result->payment_status ?? '');

$financially_paid = ($payment_status === 'completed') || (($total_pledged > 0 && $balance <= 0) || ($total_pledged <= 0 && $total_paid > 0));
$is_already_paid = ((int)($result->donor_claimed_already_paid ?? 0) === 1)
    || $financially_paid
    || str_contains($notes_lower, 'already paid the full pledge')
    || str_contains($notes_lower, 'marked as previously paid')
    || str_contains($notes_lower, 'already_paid_claims');
$outcome_display = !empty($result->outcome) ? ucfirst(str_replace('_', ' ', (string)$result->outcome)) : 'Completed';
if ($is_already_paid) {
    $outcome_display = 'Already Paid (Proof Requested)';
}
$ended_timestamp = false;
if (!empty($result->call_ended_at)) {
    $ended_timestamp = strtotime((string)$result->call_ended_at);
}
$call_ended_at = $ended_timestamp ? date('d M Y, H:i', $ended_timestamp) : 'Not available';
$page_title = 'Call Completed';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Call Completed - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <style>
        .complete-card {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 2rem;
            text-align: center;
        }
        .status-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: #64748b;
        }
        .status-icon.success { color: #22c55e; }
        .status-icon.refused { color: #ef4444; }
        .status-icon.callback { color: #f59e0b; }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: #64748b; font-weight: 600; }
        .detail-value { color: #1e293b; font-weight: 500; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="complete-card">
                <?php 
                $icon = 'fa-check-circle';
                $color = 'text-secondary';
                $title = 'Call Ended';
                
                if ($is_already_paid) {
                    $icon = 'fa-circle-check';
                    $color = 'success';
                    $title = 'Already Paid Confirmed';
                } elseif ($result->conversation_stage === 'success_pledged') {
                    $icon = 'fa-check-circle';
                    $color = 'success';
                    $title = 'Success!';
                } elseif ($result->conversation_stage === 'closed_refused') {
                    $icon = 'fa-times-circle';
                    $color = 'refused';
                    $title = 'Call Closed (Refused)';
                } elseif ($result->conversation_stage === 'callback_scheduled') {
                    $icon = 'fa-calendar-check';
                    $color = 'callback';
                    $title = 'Callback Scheduled';
                }
                ?>
                
                <div class="status-icon <?php echo $color; ?>">
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                
                <h2 class="mb-4"><?php echo $title; ?></h2>

                <?php if ($flash_message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars((string)$flash_type); ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars((string)$flash_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="text-start mb-4">
                    <div class="detail-row">
                        <span class="detail-label">Donor</span>
                        <span class="detail-value"><?php echo htmlspecialchars($result->donor_name); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Outcome</span>
                        <span class="detail-value"><?php echo htmlspecialchars($outcome_display); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Duration</span>
                        <span class="detail-value"><?php echo $duration_formatted; ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Date</span>
                        <span class="detail-value"><?php echo $call_ended_at; ?></span>
                    </div>
                </div>
                
                <div class="d-grid gap-2">
                    <a href="index.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-list me-2"></i>Back to Queue
                    </a>
                    <a href="call-history.php?donor_id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-history me-2"></i>View History
                    </a>

                    <?php if (!$is_already_paid): ?>
                        <form method="post" action="call-complete.php?session_id=<?php echo $session_id; ?>&donor_id=<?php echo $donor_id; ?>" 
                              onsubmit="return confirm('Mark this donor as Previously Paid? This will close this call for follow-up as previously paid.')">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="mark_previously_paid">
                            <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                            <input type="hidden" name="donor_id" value="<?php echo $donor_id; ?>">
                            <button type="submit" class="btn btn-outline-success">
                                <i class="fas fa-check-circle me-2"></i>Mark as Previously Paid
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
if (typeof window.toggleSidebar !== 'function') {
    window.toggleSidebar = function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.querySelector('.sidebar-overlay');
        const body = document.body;

        if (!sidebar || !sidebarOverlay) {
            return;
        }

        if (window.innerWidth <= 991.98) {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        } else {
            body.classList.toggle('sidebar-collapsed');
        }
    };
}
</script>
</body>
</html>
