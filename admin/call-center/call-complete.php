<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$user_id = (int)($_SESSION['user']['id'] ?? 0);

if (!$session_id || !$donor_id) {
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

$has_claimed_paid_column = false;
$claimed_paid_column_check = $db->query("SHOW COLUMNS FROM call_center_sessions LIKE 'donor_claimed_already_paid'");
if ($claimed_paid_column_check && $claimed_paid_column_check->num_rows > 0) {
    $has_claimed_paid_column = true;
}
$claimed_paid_select = $has_claimed_paid_column ? ", s.donor_claimed_already_paid" : ", 0 AS donor_claimed_already_paid";

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'mark_previously_paid'
) {
    verify_csrf();

    $post_session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $post_donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;

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
        WHERE id = ? AND donor_id = ?
        LIMIT 1
    ");
    $lookup->bind_param('ii', $post_session_id, $post_donor_id);
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
        $session_update .= " WHERE id = ?";

        $stmt = $db->prepare($session_update);
        if (!$stmt) {
            throw new Exception('Failed to prepare call session update statement.');
        }
        $stmt->bind_param('si', $notes_append, $post_session_id);
        $stmt->execute();
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
$query = "
    SELECT 
        s.outcome, s.conversation_stage, s.duration_seconds, s.call_started_at, s.call_ended_at, s.notes
        {$claimed_paid_select},
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

$duration_formatted = gmdate("H:i:s", (int)$result->duration_seconds);
$notes_text = (string)($result->notes ?? '');
$is_already_paid = ((int)($result->donor_claimed_already_paid ?? 0) === 1) ||
    (stripos($notes_text, 'already paid the full pledge') !== false) ||
    (stripos($notes_text, 'marked as previously paid') !== false);
$outcome_display = !empty($result->outcome) ? ucfirst(str_replace('_', ' ', (string)$result->outcome)) : 'Completed';
if ($is_already_paid) {
    $outcome_display = 'Already Paid (Proof Requested)';
}
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
                        <span class="detail-value"><?php echo date('d M Y, H:i', strtotime($result->call_ended_at)); ?></span>
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
</body>
</html>
