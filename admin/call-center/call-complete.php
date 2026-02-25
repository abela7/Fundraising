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

// ── Handle POST: Mark as Previously Paid ──
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
            $db->rollback();
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
                call_ended_at = COALESCE(call_ended_at, NOW())
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
            throw new Exception('Session record was not updated — check session_id and donor_id match.');
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

// ── Fetch Session + Donor for display ──
$claimed_paid_select = $has_claimed_paid_column ? 's.donor_claimed_already_paid' : '0 AS donor_claimed_already_paid';

$query = "
    SELECT
        s.donor_id,
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

// Determine "already paid" status — only flag as paid if there's genuine evidence:
// 1. The donor_claimed_already_paid column is explicitly set, OR
// 2. The donor's payment_status is 'completed', OR
// 3. Pledged > 0 and balance <= 0 (fully paid off), OR
// 4. Notes contain paid-claim keywords from the conversation flow.
$financially_paid = ($payment_status === 'completed')
    || ($total_pledged > 0 && $balance <= 0 && $total_paid > 0);

$is_already_paid = ((int)($result->donor_claimed_already_paid ?? 0) === 1)
    || $financially_paid
    || str_contains($notes_lower, 'already paid the full pledge')
    || str_contains($notes_lower, 'marked as previously paid')
    || str_contains($notes_lower, 'already_paid_claims');

$proof_request_sent = str_contains($notes_lower, 'proof request sent via whatsapp: yes');
$proof_request_skipped = str_contains($notes_lower, 'proof request sent via whatsapp: no');
$already_paid_claim_only = ((int)($result->donor_claimed_already_paid ?? 0) === 1) && !$financially_paid;

$outcome_display = !empty($result->outcome) ? ucfirst(str_replace('_', ' ', (string)$result->outcome)) : 'Completed';
if ($is_already_paid) {
    if ($already_paid_claim_only) {
        $outcome_display = $proof_request_sent
            ? 'Already Paid Claim (Proof Requested)'
            : 'Already Paid Claim (Pending Verification)';
    } else {
        $outcome_display = 'Already Paid (Verified)';
    }
}

$donor_name_raw = trim((string)($result->donor_name ?? ''));
$donor_display_name = $donor_name_raw;
if ($donor_display_name === '' || strtolower($donor_display_name) === 'unknown') {
    $donor_phone_fallback = trim((string)($result->donor_phone ?? ''));
    if ($donor_phone_fallback !== '') {
        $donor_display_name = 'Donor (' . $donor_phone_fallback . ')';
    } else {
        $donor_display_name = 'Donor #' . (int)($result->donor_id ?? $donor_id);
    }
}

$ended_timestamp = false;
if (!empty($result->call_ended_at)) {
    $ended_timestamp = strtotime((string)$result->call_ended_at);
}
$call_ended_at = $ended_timestamp ? date('d M Y, H:i', $ended_timestamp) : 'Just now';

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
        /* === Call Complete Card === */
        .cc-complete-wrapper {
            max-width: 640px;
            margin: 30px auto;
            padding: 0 1rem;
        }

        .cc-complete-card {
            background: var(--white, #fff);
            border: 1px solid var(--gray-200, #e5e7eb);
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* Status banner */
        .cc-status-banner {
            padding: 2rem 2rem 1.75rem;
            text-align: center;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
            border-bottom: 1px solid var(--gray-100, #f3f4f6);
        }

        .cc-status-banner.refused {
            background: linear-gradient(135deg, #fef2f2 0%, #fff1f2 100%);
        }

        .cc-status-banner.callback {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }

        .cc-status-banner.neutral {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .cc-status-icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }

        .cc-status-icon.success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--success, #10b981);
        }

        .cc-status-icon.refused {
            background: rgba(239, 68, 68, 0.12);
            color: var(--danger, #ef4444);
        }

        .cc-status-icon.callback {
            background: rgba(245, 158, 11, 0.12);
            color: var(--warning, #f59e0b);
        }

        .cc-status-icon.neutral {
            background: rgba(100, 116, 139, 0.1);
            color: #64748b;
        }

        .cc-status-title {
            font-size: 1.375rem;
            font-weight: 700;
            color: var(--gray-900, #111827);
            margin: 0;
        }

        .cc-status-subtitle {
            font-size: 0.875rem;
            color: var(--gray-500, #6b7280);
            margin-top: 0.375rem;
        }

        /* Details section */
        .cc-details {
            padding: 1.5rem 2rem;
        }

        .cc-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100, #f3f4f6);
        }

        .cc-detail-row:last-child {
            border-bottom: none;
        }

        .cc-detail-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--gray-500, #6b7280);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .cc-detail-label i {
            width: 16px;
            text-align: center;
            font-size: 0.75rem;
            color: var(--gray-400, #9ca3af);
        }

        .cc-detail-value {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-800, #1f2937);
            text-align: right;
        }

        .cc-outcome-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .cc-outcome-pill.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success, #10b981);
        }

        .cc-outcome-pill.refused {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger, #ef4444);
        }

        .cc-outcome-pill.callback {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning, #f59e0b);
        }

        .cc-outcome-pill.neutral {
            background: var(--gray-100, #f3f4f6);
            color: var(--gray-600, #4b5563);
        }

        /* Financial summary (for already-paid) */
        .cc-finance-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 1rem 2rem;
            background: var(--gray-50, #f9fafb);
            border-top: 1px solid var(--gray-100, #f3f4f6);
            border-bottom: 1px solid var(--gray-100, #f3f4f6);
        }

        .cc-finance-item {
            text-align: center;
        }

        .cc-finance-amount {
            font-size: 1rem;
            font-weight: 700;
            color: var(--gray-800, #1f2937);
        }

        .cc-finance-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--gray-400, #9ca3af);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }

        /* Actions */
        .cc-actions {
            padding: 1.5rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
        }

        .cc-actions .btn {
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9375rem;
            padding: 0.75rem 1rem;
        }

        .cc-actions .btn-primary {
            background: var(--primary, #0a6286);
            border-color: var(--primary, #0a6286);
        }

        .cc-actions .btn-primary:hover {
            background: var(--primary-light, #0ea5e9);
            border-color: var(--primary-light, #0ea5e9);
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.3);
        }

        .cc-actions .btn-outline-secondary {
            border-color: var(--gray-300, #d1d5db);
            color: var(--gray-600, #4b5563);
        }

        .cc-actions .btn-outline-secondary:hover {
            background: var(--gray-50, #f9fafb);
            border-color: var(--gray-400, #9ca3af);
        }

        .cc-actions .btn-outline-success {
            border-color: var(--success, #10b981);
            color: var(--success, #10b981);
        }

        .cc-actions .btn-outline-success:hover {
            background: var(--success, #10b981);
            color: #fff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .cc-mark-paid-form .btn {
            width: 100%;
        }

        /* Notes preview */
        .cc-notes-preview {
            padding: 1rem 2rem;
            border-top: 1px solid var(--gray-100, #f3f4f6);
        }

        .cc-notes-toggle {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--gray-500, #6b7280);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            border: none;
            background: none;
            padding: 0;
        }

        .cc-notes-toggle:hover {
            color: var(--primary, #0a6286);
        }

        .cc-notes-body {
            margin-top: 0.75rem;
            font-size: 0.8125rem;
            color: var(--gray-600, #4b5563);
            line-height: 1.6;
            background: var(--gray-50, #f9fafb);
            padding: 0.75rem 1rem;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Flash alert */
        .cc-flash {
            margin: 0 2rem 0;
            padding-top: 1rem;
        }

        /* Responsive */
        @media (max-width: 576px) {
            .cc-complete-wrapper {
                margin: 16px auto;
            }

            .cc-status-banner {
                padding: 1.5rem 1.25rem 1.25rem;
            }

            .cc-details,
            .cc-actions,
            .cc-notes-preview {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .cc-finance-row {
                padding-left: 1.25rem;
                padding-right: 1.25rem;
            }

            .cc-flash {
                margin: 0 1.25rem 0;
            }

            .cc-detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .cc-detail-value {
                text-align: left;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="cc-complete-wrapper">
                <div class="cc-complete-card">
                    <?php
                    // Determine visual state
                    $icon = 'fa-check-circle';
                    $banner_class = 'neutral';
                    $icon_class = 'neutral';
                    $title = 'Call Ended';
                    $subtitle = 'The call session has been recorded.';
                    $pill_class = 'neutral';

                    if ($is_already_paid) {
                        $icon = 'fa-circle-check';
                        $banner_class = '';
                        $icon_class = 'success';
                        if ($already_paid_claim_only) {
                            $title = 'Already Paid Claim Recorded';
                            if ($proof_request_sent) {
                                $subtitle = 'Proof request was sent to the donor.';
                            } elseif ($proof_request_skipped) {
                                $subtitle = 'Proof request was skipped. Verification is pending.';
                            } else {
                                $subtitle = 'Verification is pending for this already-paid claim.';
                            }
                        } else {
                            $title = 'Already Paid Confirmed';
                            $subtitle = 'This donor is already completed in financial records.';
                        }
                        $pill_class = 'success';
                    } elseif (($result->conversation_stage ?? '') === 'success_pledged') {
                        $icon = 'fa-check-circle';
                        $banner_class = '';
                        $icon_class = 'success';
                        $title = 'Payment Plan Created';
                        $subtitle = 'The donor has agreed to a payment plan.';
                        $pill_class = 'success';
                    } elseif (($result->conversation_stage ?? '') === 'closed_refused') {
                        $icon = 'fa-times-circle';
                        $banner_class = 'refused';
                        $icon_class = 'refused';
                        $title = 'Call Closed — Refused';
                        $subtitle = 'The donor declined or the call could not proceed.';
                        $pill_class = 'refused';
                    } elseif (($result->conversation_stage ?? '') === 'callback_scheduled') {
                        $icon = 'fa-calendar-check';
                        $banner_class = 'callback';
                        $icon_class = 'callback';
                        $title = 'Callback Scheduled';
                        $subtitle = 'A follow-up call has been arranged.';
                        $pill_class = 'callback';
                    }
                    ?>

                    <!-- Status Banner -->
                    <div class="cc-status-banner <?php echo $banner_class; ?>">
                        <div class="cc-status-icon <?php echo $icon_class; ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <h2 class="cc-status-title"><?php echo $title; ?></h2>
                        <p class="cc-status-subtitle"><?php echo $subtitle; ?></p>
                    </div>

                    <?php if ($flash_message): ?>
                        <div class="cc-flash">
                            <div class="alert alert-<?php echo htmlspecialchars((string)$flash_type); ?> alert-dismissible fade show mb-0" role="alert">
                                <i class="fas <?php echo $flash_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> me-2"></i>
                                <?php echo htmlspecialchars((string)$flash_message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Call Details -->
                    <div class="cc-details">
                        <div class="cc-detail-row">
                            <span class="cc-detail-label"><i class="fas fa-user"></i>Donor</span>
                            <span class="cc-detail-value"><?php echo htmlspecialchars($donor_display_name); ?></span>
                        </div>
                        <div class="cc-detail-row">
                            <span class="cc-detail-label"><i class="fas fa-phone"></i>Phone</span>
                            <span class="cc-detail-value"><?php echo htmlspecialchars((string)($result->donor_phone ?? '')); ?></span>
                        </div>
                        <div class="cc-detail-row">
                            <span class="cc-detail-label"><i class="fas fa-flag"></i>Outcome</span>
                            <span class="cc-detail-value">
                                <span class="cc-outcome-pill <?php echo $pill_class; ?>"><?php echo htmlspecialchars($outcome_display); ?></span>
                            </span>
                        </div>
                        <div class="cc-detail-row">
                            <span class="cc-detail-label"><i class="fas fa-stopwatch"></i>Duration</span>
                            <span class="cc-detail-value"><?php echo $duration_formatted; ?></span>
                        </div>
                        <div class="cc-detail-row">
                            <span class="cc-detail-label"><i class="fas fa-calendar"></i>Ended</span>
                            <span class="cc-detail-value"><?php echo htmlspecialchars($call_ended_at); ?></span>
                        </div>
                        <?php if (!empty($result->agent_name)): ?>
                            <div class="cc-detail-row">
                                <span class="cc-detail-label"><i class="fas fa-headset"></i>Agent</span>
                                <span class="cc-detail-value"><?php echo htmlspecialchars((string)$result->agent_name); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($total_pledged > 0 || $total_paid > 0): ?>
                        <!-- Financial Summary -->
                        <div class="cc-finance-row">
                            <div class="cc-finance-item">
                                <div class="cc-finance-amount">&pound;<?php echo number_format($total_pledged, 2); ?></div>
                                <div class="cc-finance-label">Pledged</div>
                            </div>
                            <div class="cc-finance-item">
                                <div class="cc-finance-amount">&pound;<?php echo number_format($total_paid, 2); ?></div>
                                <div class="cc-finance-label">Paid</div>
                            </div>
                            <div class="cc-finance-item">
                                <div class="cc-finance-amount" style="color: <?php echo $balance > 0 ? 'var(--warning, #f59e0b)' : 'var(--success, #10b981)'; ?>;">
                                    &pound;<?php echo number_format($balance, 2); ?>
                                </div>
                                <div class="cc-finance-label">Balance</div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($notes_text)): ?>
                        <!-- Notes Preview -->
                        <div class="cc-notes-preview">
                            <button class="cc-notes-toggle" onclick="document.getElementById('notesBody').classList.toggle('d-none'); this.querySelector('.chevron').classList.toggle('fa-chevron-down'); this.querySelector('.chevron').classList.toggle('fa-chevron-up');">
                                <i class="fas fa-sticky-note"></i>
                                Call Notes
                                <i class="fas fa-chevron-down chevron" style="font-size: 0.625rem; margin-left: auto;"></i>
                            </button>
                            <div id="notesBody" class="cc-notes-body d-none"><?php echo htmlspecialchars($notes_text); ?></div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="cc-actions">
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-list me-2"></i>Back to Queue
                        </a>
                        <a href="call-history.php?donor_id=<?php echo $donor_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-history me-2"></i>View Call History
                        </a>

                        <?php if (!$is_already_paid): ?>
                            <form method="post" class="cc-mark-paid-form"
                                  action="call-complete.php?session_id=<?php echo $session_id; ?>&donor_id=<?php echo $donor_id; ?>"
                                  onsubmit="return confirm('Mark this donor as Previously Paid?\n\nThis will update the call outcome to show the donor has already paid. Use this if the donor confirmed payment during the call.')">
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
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
