<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$agent_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($agent_id <= 0) {
    header('Location: index.php');
    exit;
}

$db = db();

$schema_cache = [
    'tables' => [],
    'columns' => [],
];

$table_exists = static function (string $table) use (&$schema_cache, $db): bool {
    if (!array_key_exists($table, $schema_cache['tables'])) {
        $safeTable = $db->real_escape_string($table);
        $check = $db->query("SHOW TABLES LIKE '{$safeTable}'");
        $schema_cache['tables'][$table] = $check !== false && $check->num_rows > 0;
    }
    return (bool)$schema_cache['tables'][$table];
};

$column_exists = static function (string $table, string $column) use (&$schema_cache, $db, $table_exists): bool {
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $schema_cache['columns'])) {
        if (!$table_exists($table)) {
            $schema_cache['columns'][$key] = false;
        } else {
            $safeColumn = $db->real_escape_string($column);
            $safeTable = $db->real_escape_string($table);
            $check = $db->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
            $schema_cache['columns'][$key] = $check !== false && $check->num_rows > 0;
        }
    }
    return (bool)$schema_cache['columns'][$key];
};

$formatMoney = static function (mixed $value): string {
    $amount = (float)($value ?? 0);
    return number_format($amount, 2, '.', ',');
};

$formatDateTime = static function (?string $value): string {
    if (!$value) {
        return 'N/A';
    }

    $ts = strtotime($value);
    if (!$ts) {
        return 'Invalid date';
    }

    return date('M j, Y H:i', $ts);
};

$formatDuration = static function (?float $seconds): string {
    $secondsInt = max(0, (int)floor((float)($seconds ?? 0)));

    if ($secondsInt === 0) {
        return '0m 0s';
    }

    $hours = intdiv($secondsInt, 3600);
    $minutes = intdiv($secondsInt % 3600, 60);
    $remain = $secondsInt % 60;

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm ' . $remain . 's';
    }

    return $minutes . 'm ' . $remain . 's';
};

$agent = null;
$error_message = '';
$warning_messages = [];

$portfolio = [
    'total_donors' => 0,
    'total_pledged' => 0,
    'total_paid' => 0,
    'total_balance' => 0,
    'assigned_only_donors' => 0,
    'registered_only_donors' => 0,
    'overlapping_donors' => 0,
    'completed_donors' => 0,
    'paying_donors' => 0,
    'overdue_donors' => 0,
    'not_started_donors' => 0,
    'pledge_donors' => 0,
];

$call_stats = [
    'total_calls' => 0,
    'successful_calls' => 0,
    'callbacks_scheduled' => 0,
    'total_talk_seconds' => 0,
    'first_call_at' => null,
    'last_call_at' => null,
];

$message_stats = [
    'total_messages' => 0,
    'whatsapp_messages' => 0,
    'sms_messages' => 0,
    'failed_messages' => 0,
    'last_message_at' => null,
];

$whatsapp_stats = [
    'total_conversations' => 0,
    'unread_conversations' => 0,
    'unknown_conversations' => 0,
    'direct_assignments' => 0,
    'last_message_at' => null,
];

$pledge_stats = [
    'total_pledges' => 0,
    'approved_pledges' => 0,
    'total_pledge_amount' => 0,
    'approved_pledge_amount' => 0,
];

$recent_calls = [];
$call_outcomes = [];
$recent_donors = [];
$recent_messages = [];
$recent_conversations = [];

$page_title = 'Agent Profile';

try {
    $agent_stmt = $db->prepare(
        "
        SELECT id, name, phone, phone_number, email, role, active, created_at, last_login_at
        FROM users
        WHERE id = ? AND role IN ('admin', 'registrar')
        LIMIT 1
    "
    );
    $agent_stmt->bind_param('i', $agent_id);
    $agent_stmt->execute();
    $agent = $agent_stmt->get_result()->fetch_assoc();
    $agent_stmt->close();

    if (!$agent) {
        header('Location: index.php?not_found=1');
        exit;
    }

    if (!$table_exists('donors')) {
        $warning_messages[] = 'donors table not found. Portfolio and donor history could not be loaded.';
    } else {
        $portfolio_stmt = $db->prepare(
            "
            SELECT
                COUNT(*) AS total_donors,
                COALESCE(SUM(d.total_pledged), 0) AS total_pledged,
                COALESCE(SUM(d.total_paid), 0) AS total_paid,
                COALESCE(SUM(d.balance), 0) AS total_balance,
                SUM(CASE WHEN d.agent_id = ? AND (d.registered_by_user_id IS NULL OR d.registered_by_user_id <> ?) THEN 1 ELSE 0 END) AS assigned_only_donors,
                SUM(CASE WHEN d.registered_by_user_id = ? AND (d.agent_id IS NULL OR d.agent_id <> ?) THEN 1 ELSE 0 END) AS registered_only_donors,
                SUM(CASE WHEN d.agent_id = ? AND d.registered_by_user_id = ? THEN 1 ELSE 0 END) AS overlapping_donors,
                SUM(CASE WHEN d.payment_status = 'completed' THEN 1 ELSE 0 END) AS completed_donors,
                SUM(CASE WHEN d.payment_status = 'paying' THEN 1 ELSE 0 END) AS paying_donors,
                SUM(CASE WHEN d.payment_status = 'overdue' THEN 1 ELSE 0 END) AS overdue_donors,
                SUM(CASE WHEN d.payment_status = 'not_started' THEN 1 ELSE 0 END) AS not_started_donors,
                SUM(CASE WHEN d.donor_type = 'pledge' THEN 1 ELSE 0 END) AS pledge_donors
            FROM donors d
            WHERE d.agent_id = ? OR d.registered_by_user_id = ?
        "
        );
        $portfolio_stmt->bind_param(
            'iiiiiiii',
            $agent_id,
            $agent_id,
            $agent_id,
            $agent_id,
            $agent_id,
            $agent_id,
            $agent_id,
            $agent_id
        );
        $portfolio_stmt->execute();
        $portfolio_data = $portfolio_stmt->get_result()->fetch_assoc();
        $portfolio_stmt->close();

        if ($portfolio_data) {
            foreach ($portfolio_data as $key => $value) {
                $portfolio[$key] = $value;
            }
        }

        $donors_stmt = $db->prepare(
            "
            SELECT
                d.id,
                d.name,
                d.phone,
                COALESCE(d.total_pledged, 0) AS total_pledged,
                COALESCE(d.total_paid, 0) AS total_paid,
                COALESCE(d.balance, 0) AS balance,
                COALESCE(d.payment_status, 'unknown') AS payment_status,
                COALESCE(d.created_at, '') AS created_at,
                d.agent_id,
                d.registered_by_user_id,
                d.last_contacted_at
            FROM donors d
            WHERE d.agent_id = ? OR d.registered_by_user_id = ?
            ORDER BY d.created_at DESC
            LIMIT 50
        "
        );
        $donors_stmt->bind_param('ii', $agent_id, $agent_id);
        $donors_stmt->execute();
        $recent_donors_result = $donors_stmt->get_result();
        while ($row = $recent_donors_result->fetch_assoc()) {
            $recent_donors[] = $row;
        }
        $donors_stmt->close();
    }

    if ($table_exists('call_center_sessions')) {
        $has_call_started_at = $column_exists('call_center_sessions', 'call_started_at');
        $has_call_ended_at = $column_exists('call_center_sessions', 'call_ended_at');
        $has_duration = $column_exists('call_center_sessions', 'duration_seconds');
        $has_conversation_stage = $column_exists('call_center_sessions', 'conversation_stage');
        $has_outcome = $column_exists('call_center_sessions', 'outcome');
        $has_callback_scheduled_for = $column_exists('call_center_sessions', 'callback_scheduled_for');

        if ($has_duration) {
            $duration_expression = "CASE WHEN cs.duration_seconds IS NOT NULL AND cs.duration_seconds > 0 THEN cs.duration_seconds ELSE 0 END";
        } elseif ($has_call_started_at && $has_call_ended_at) {
            $duration_expression = "CASE WHEN cs.call_started_at IS NOT NULL AND cs.call_ended_at IS NOT NULL THEN GREATEST(0, TIMESTAMPDIFF(SECOND, cs.call_started_at, cs.call_ended_at)) ELSE 0 END";
        } else {
            $duration_expression = '0';
        }

        $call_order_col = $has_call_started_at
            ? 'cs.call_started_at'
            : ($column_exists('call_center_sessions', 'created_at') ? 'cs.created_at' : 'cs.id');

        $successful_expr = $has_conversation_stage
            ? "SUM(CASE WHEN cs.conversation_stage NOT IN ('pending', 'attempt_failed', 'invalid_data') THEN 1 ELSE 0 END) AS successful_calls"
            : ($has_outcome
                ? "SUM(CASE WHEN cs.outcome IN ('agreed_to_pay_full', 'payment_plan_created', 'agreed_reduced_amount', 'payment_made_during_call', 'contact_made', 'interested_follow_up', 'success_pledged', 'callback_scheduled') THEN 1 ELSE 0 END) AS successful_calls"
                : '0 AS successful_calls');

        $callbacks_expr = '0 AS callbacks_scheduled';
        if ($has_callback_scheduled_for && $has_outcome) {
            $callbacks_expr = "SUM(CASE WHEN cs.callback_scheduled_for IS NOT NULL OR cs.outcome = 'callback_scheduled' THEN 1 ELSE 0 END) AS callbacks_scheduled";
        } elseif ($has_callback_scheduled_for) {
            $callbacks_expr = "SUM(CASE WHEN cs.callback_scheduled_for IS NOT NULL THEN 1 ELSE 0 END) AS callbacks_scheduled";
        } elseif ($has_outcome) {
            $callbacks_expr = "SUM(CASE WHEN cs.outcome = 'callback_scheduled' THEN 1 ELSE 0 END) AS callbacks_scheduled";
        }

        $call_stats_stmt = $db->prepare(
            "
            SELECT
                COUNT(*) AS total_calls,
                {$successful_expr},
                {$callbacks_expr},
                SUM({$duration_expression}) AS total_talk_seconds,
                MIN({$call_order_col}) AS first_call_at,
                MAX({$call_order_col}) AS last_call_at
            FROM call_center_sessions cs
            WHERE cs.agent_id = ?
        "
        );
        $call_stats_stmt->bind_param('i', $agent_id);
        $call_stats_stmt->execute();
        $call_data = $call_stats_stmt->get_result()->fetch_assoc();
        $call_stats_stmt->close();
        if ($call_data) {
            foreach ($call_data as $key => $value) {
                $call_stats[$key] = $value;
            }
        }

        $recent_calls_stmt = $db->prepare(
            "
            SELECT
                cs.*, d.id AS donor_id, d.name AS donor_name, d.phone AS donor_phone
            FROM call_center_sessions cs
            LEFT JOIN donors d ON cs.donor_id = d.id
            WHERE cs.agent_id = ?
            ORDER BY {$call_order_col} DESC
            LIMIT 20
        "
        );
        $recent_calls_stmt->bind_param('i', $agent_id);
        $recent_calls_stmt->execute();
        $recent_calls_result = $recent_calls_stmt->get_result();
        while ($row = $recent_calls_result->fetch_assoc()) {
            $recent_calls[] = $row;
        }
        $recent_calls_stmt->close();

        if ($has_outcome) {
            $call_outcome_stmt = $db->prepare(
                "
                SELECT cs.outcome, COUNT(*) AS total_count
                FROM call_center_sessions cs
                WHERE cs.agent_id = ? AND cs.outcome IS NOT NULL AND cs.outcome <> ''
                GROUP BY cs.outcome
                ORDER BY total_count DESC
                LIMIT 10
            "
            );
            $call_outcome_stmt->bind_param('i', $agent_id);
            $call_outcome_stmt->execute();
            $call_outcome_result = $call_outcome_stmt->get_result();
            while ($row = $call_outcome_result->fetch_assoc()) {
                $call_outcomes[] = $row;
            }
            $call_outcome_stmt->close();
        }
    } else {
        $warning_messages[] = 'call_center_sessions table not found. Call metrics unavailable.';
    }

    if ($table_exists('message_log')) {
        $has_msg_sent_at = $column_exists('message_log', 'sent_at');
        $has_msg_channel = $column_exists('message_log', 'channel');
        $has_msg_status = $column_exists('message_log', 'status');
        $has_msg_sent_by = $column_exists('message_log', 'sent_by_user_id');

        if ($has_msg_sent_by) {
            $message_query = "
                SELECT
                    COUNT(*) AS total_messages,
                    " . ($has_msg_channel ? "SUM(CASE WHEN LOWER(COALESCE(ml.channel, '')) = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapp_messages," : '0 AS whatsapp_messages,') . "
                    " . ($has_msg_channel ? "SUM(CASE WHEN LOWER(COALESCE(ml.channel, '')) = 'sms' THEN 1 ELSE 0 END) AS sms_messages," : '0 AS sms_messages,') . "
                    " . ($has_msg_status ? "SUM(CASE WHEN LOWER(COALESCE(ml.status, '')) IN ('failed', 'error') THEN 1 ELSE 0 END) AS failed_messages," : '0 AS failed_messages,') . "
                    " . ($has_msg_sent_at ? 'MAX(ml.sent_at) AS last_message_at' : 'MAX(ml.id) AS last_message_at') . "
                FROM message_log ml
                WHERE ml.sent_by_user_id = ?
            ";
            $message_stats_stmt = $db->prepare($message_query);
            $message_stats_stmt->bind_param('i', $agent_id);
            $message_stats_stmt->execute();
            $message_data = $message_stats_stmt->get_result()->fetch_assoc();
            $message_stats_stmt->close();

            if ($message_data) {
                foreach ($message_data as $key => $value) {
                    $message_stats[$key] = $value;
                }
            }

            $recent_messages_order = $has_msg_sent_at ? 'COALESCE(ml.sent_at, NOW())' : 'ml.id';
            $recent_messages_stmt = $db->prepare(
                "
                SELECT ml.*, d.name AS donor_name, d.phone AS donor_phone
                FROM message_log ml
                LEFT JOIN donors d ON ml.donor_id = d.id
                WHERE ml.sent_by_user_id = ?
                ORDER BY {$recent_messages_order} DESC
                LIMIT 25
            "
            );
            $recent_messages_stmt->bind_param('i', $agent_id);
            $recent_messages_stmt->execute();
            $recent_messages_result = $recent_messages_stmt->get_result();
            while ($row = $recent_messages_result->fetch_assoc()) {
                $recent_messages[] = $row;
            }
            $recent_messages_stmt->close();
        } else {
            $warning_messages[] = 'message_log table exists but sent_by_user_id column is missing. Message metrics unavailable.';
        }
    } else {
        $warning_messages[] = 'message_log table not found. Message metrics unavailable.';
    }

    if ($table_exists('whatsapp_conversations')) {
        $has_wc_status = $column_exists('whatsapp_conversations', 'status');
        $has_wc_last_message = $column_exists('whatsapp_conversations', 'last_message_at');
        $has_wc_created_at = $column_exists('whatsapp_conversations', 'created_at');
        $has_wc_assigned = $column_exists('whatsapp_conversations', 'assigned_agent_id');
        $has_wc_unread = $column_exists('whatsapp_conversations', 'unread_count');
        $has_wc_unknown = $column_exists('whatsapp_conversations', 'is_unknown');
        $has_wc_contact_name = $column_exists('whatsapp_conversations', 'contact_name');

        $wa_order_col = $has_wc_last_message ? 'wc.last_message_at' : ($has_wc_created_at ? 'wc.created_at' : 'wc.id');

        $where_parts = [];
        $where_params = [];
        $where_types = '';

        if ($has_wc_assigned) {
            $where_parts[] = '(wc.assigned_agent_id = ? OR d.agent_id = ? OR d.registered_by_user_id = ?)';
            $where_params[] = $agent_id;
            $where_params[] = $agent_id;
            $where_params[] = $agent_id;
            $where_types .= 'iii';
        } else {
            $where_parts[] = '(d.agent_id = ? OR d.registered_by_user_id = ?)';
            $where_params[] = $agent_id;
            $where_params[] = $agent_id;
            $where_types .= 'ii';
        }

        if ($has_wc_status) {
            $where_parts[] = "wc.status = 'active'";
        }

        $where_clause = implode(' AND ', $where_parts);

        $stats_select = [
            'COUNT(*) AS total_conversations',
            $has_wc_unread ? "SUM(CASE WHEN wc.unread_count > 0 THEN 1 ELSE 0 END) AS unread_conversations" : '0 AS unread_conversations',
            $has_wc_unknown ? "SUM(CASE WHEN wc.is_unknown = 1 THEN 1 ELSE 0 END) AS unknown_conversations" : '0 AS unknown_conversations',
            $has_wc_assigned ? 'SUM(CASE WHEN wc.assigned_agent_id = ? THEN 1 ELSE 0 END) AS direct_assignments' : '0 AS direct_assignments',
            "MAX({$wa_order_col}) AS last_message_at",
        ];

        $wa_stats_query = "SELECT " . implode(', ', $stats_select) . "
            FROM whatsapp_conversations wc
            LEFT JOIN donors d ON wc.donor_id = d.id
            WHERE {$where_clause}";

        $wa_stats_stmt = $db->prepare($wa_stats_query);
        if (!empty($where_params)) {
            if ($has_wc_assigned) {
                $where_types_with_direct = $where_types . 'i';
                $wa_stats_stmt->bind_param($where_types_with_direct, ...array_merge($where_params, [$agent_id]));
            } else {
                $wa_stats_stmt->bind_param($where_types, ...$where_params);
            }
        }

        $wa_stats_stmt->execute();
        $wa_stats_data = $wa_stats_stmt->get_result()->fetch_assoc();
        $wa_stats_stmt->close();
        if ($wa_stats_data) {
            foreach ($wa_stats_data as $key => $value) {
                $whatsapp_stats[$key] = $value;
            }
        }

        $recent_select = [
            'wc.id',
            'wc.phone_number',
            'wc.created_at',
            'wc.last_message_at',
            'wc.unread_count',
            'wc.is_unknown',
            'wc.status',
            'wc.assigned_agent_id',
            'wc.contact_name',
            'd.id AS donor_id',
            'd.name AS donor_name',
            'd.phone AS donor_phone',
        ];

        if (!$has_wc_unread) {
            $recent_select = array_filter($recent_select, static fn($item) => str_contains($item, 'wc.unread_count') === false);
        }
        if (!$has_wc_unknown) {
            $recent_select = array_filter($recent_select, static fn($item) => str_contains($item, 'wc.is_unknown') === false);
        }
        if (!$has_wc_status) {
            $recent_select = array_filter($recent_select, static fn($item) => str_contains($item, 'wc.status') === false);
        }
        if (!$has_wc_assigned) {
            $recent_select = array_filter($recent_select, static fn($item) => str_contains($item, 'wc.assigned_agent_id') === false);
        }
        if (!$has_wc_contact_name) {
            $recent_select = array_filter($recent_select, static fn($item) => str_contains($item, 'wc.contact_name') === false);
        }

        $recent_select = array_values($recent_select);
        $recent_query = "SELECT " . implode(', ', $recent_select) . "
            FROM whatsapp_conversations wc
            LEFT JOIN donors d ON wc.donor_id = d.id
            WHERE {$where_clause}
            ORDER BY {$wa_order_col} DESC
            LIMIT 20";

        $wa_recent_stmt = $db->prepare($recent_query);
        if (!empty($where_params)) {
            if ($has_wc_assigned) {
                $wa_recent_stmt->bind_param($where_types, ...$where_params);
            } else {
                $wa_recent_stmt->bind_param($where_types, ...$where_params);
            }
        }

        $wa_recent_stmt->execute();
        $wa_recent_result = $wa_recent_stmt->get_result();
        while ($row = $wa_recent_result->fetch_assoc()) {
            $recent_conversations[] = $row;
        }
        $wa_recent_stmt->close();
    } else {
        $warning_messages[] = 'whatsapp_conversations table not found. WhatsApp stats unavailable.';
    }

    if ($table_exists('pledges')) {
        $pledge_stmt = $db->prepare(
            "
            SELECT
                COUNT(*) AS total_pledges,
                SUM(COALESCE(p.amount, 0)) AS total_pledge_amount,
                SUM(CASE WHEN p.status = 'approved' THEN 1 ELSE 0 END) AS approved_pledges,
                SUM(CASE WHEN p.status = 'approved' THEN COALESCE(p.amount, 0) ELSE 0 END) AS approved_pledge_amount
            FROM pledges p
            LEFT JOIN donors d ON p.donor_id = d.id
            WHERE p.created_by_user_id = ? OR d.agent_id = ? OR d.registered_by_user_id = ?
        "
        );
        $pledge_stmt->bind_param('iii', $agent_id, $agent_id, $agent_id);
        $pledge_stmt->execute();
        $pledge_data = $pledge_stmt->get_result()->fetch_assoc();
        $pledge_stmt->close();
        if ($pledge_data) {
            foreach ($pledge_data as $key => $value) {
                $pledge_stats[$key] = $value;
            }
        }
    }

} catch (Throwable $e) {
    $error_message = $e->getMessage();
}

$avg_talk_time = 0;
if ((int)($call_stats['total_calls'] ?? 0) > 0) {
    $avg_talk_time = (float)($call_stats['total_talk_seconds'] ?? 0) / (float)($call_stats['total_calls']);
}

$call_success_rate = 0;
if ((int)($call_stats['total_calls'] ?? 0) > 0) {
    $call_success_rate = round(((int)($call_stats['successful_calls'] ?? 0) / (int)($call_stats['total_calls'])) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        /* === Agent Profile Header === */
        .vp-profile-header {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }

        .vp-profile-top {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            flex-wrap: wrap;
        }

        .vp-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.5rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.3);
        }

        .vp-profile-info { flex: 1; min-width: 200px; }

        .vp-profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 6px;
        }

        .vp-profile-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .vp-badge {
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .vp-badge-admin { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
        .vp-badge-registrar { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .vp-badge-active { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .vp-badge-inactive { background: rgba(107, 114, 128, 0.1); color: var(--gray-500); }
        .vp-badge-id { background: var(--gray-100); color: var(--gray-600); }

        .vp-contact-row {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            font-size: 0.875rem;
            color: var(--gray-600);
        }

        .vp-contact-row i {
            width: 16px;
            text-align: center;
            margin-right: 6px;
            color: var(--gray-400);
        }

        .vp-profile-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-self: flex-start;
        }

        .vp-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.8125rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid var(--gray-200);
            color: var(--gray-700);
            background: var(--white);
        }

        .vp-action-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        .vp-profile-meta {
            display: flex;
            gap: 32px;
            flex-wrap: wrap;
            padding-top: 16px;
            margin-top: 16px;
            border-top: 1px solid var(--gray-100);
            font-size: 0.8125rem;
        }

        .vp-meta-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .vp-meta-label {
            color: var(--gray-400);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .vp-meta-value {
            color: var(--gray-700);
            font-weight: 500;
        }

        /* === Stat Cards Grid === */
        .vp-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }

        .vp-stat-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 10px;
            padding: 16px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary);
        }

        .vp-stat-card:hover {
            box-shadow: var(--shadow-md);
        }

        .vp-stat-card.accent  { border-left-color: var(--accent-dark); }
        .vp-stat-card.success { border-left-color: var(--success); }
        .vp-stat-card.info    { border-left-color: var(--info); }
        .vp-stat-card.warning { border-left-color: var(--warning); }
        .vp-stat-card.danger  { border-left-color: var(--danger); }

        .vp-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 2px;
        }

        .vp-stat-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 6px;
        }

        .vp-stat-detail {
            font-size: 0.75rem;
            color: var(--gray-400);
            line-height: 1.5;
        }

        /* === Section Headers === */
        .vp-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vp-section-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--gray-200);
        }

        /* === Table Cards === */
        .vp-table-card {
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 16px;
        }

        .vp-table-card:hover {
            box-shadow: var(--shadow-md);
        }

        .vp-table-header {
            padding: 14px 20px;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .vp-table-header h6 {
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
            font-size: 0.9375rem;
        }

        .vp-table-header small {
            color: var(--gray-400);
            font-size: 0.75rem;
        }

        .vp-table-card .table {
            margin: 0;
        }

        .vp-table-card .table thead th {
            background: var(--white);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 1px solid var(--gray-100);
            padding: 10px 16px;
            white-space: nowrap;
        }

        .vp-table-card .table tbody td {
            padding: 10px 16px;
            vertical-align: middle;
            font-size: 0.875rem;
            border-bottom: 1px solid var(--gray-50);
        }

        .vp-table-card .table tbody tr:hover {
            background: var(--gray-50);
        }

        .vp-table-card .table tbody tr:last-child td {
            border-bottom: none;
        }

        .vp-table-empty {
            text-align: center;
            padding: 32px 16px;
            color: var(--gray-400);
            font-size: 0.875rem;
        }

        .vp-table-empty i {
            font-size: 1.5rem;
            display: block;
            margin-bottom: 8px;
            opacity: 0.4;
        }

        /* Status pills in tables */
        .vp-pill {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .vp-pill-primary { background: rgba(10, 98, 134, 0.1); color: var(--primary); }
        .vp-pill-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .vp-pill-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .vp-pill-danger  { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .vp-pill-info    { background: rgba(59, 130, 246, 0.1); color: var(--info); }
        .vp-pill-muted   { background: var(--gray-100); color: var(--gray-500); }

        /* Responsive */
        @media (max-width: 991px) {
            .vp-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .vp-profile-top {
                flex-direction: column;
            }

            .vp-profile-actions {
                align-self: stretch;
            }

            .vp-action-btn {
                flex: 1;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .vp-stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .vp-profile-header {
                padding: 16px;
            }

            .vp-stat-card {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <main class="main-content">
            <?php if ($error_message): ?>
                <div class="alert alert-danger mb-3" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Could not load full profile: <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($warning_messages)): ?>
                <div class="alert alert-warning mb-3" role="alert">
                    <i class="fas fa-triangle-exclamation me-2"></i>
                    <strong>Data warnings:</strong>
                    <ul class="mb-0 mt-1">
                        <?php foreach ($warning_messages as $warning): ?>
                            <li class="small"><?php echo htmlspecialchars($warning); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Back link -->
            <div class="mb-3">
                <a href="index.php" class="vp-action-btn" style="display: inline-flex;">
                    <i class="fas fa-arrow-left"></i>Back to Agents
                </a>
            </div>

            <!-- Profile Header Card -->
            <?php
            $is_active = (int)($agent['active'] ?? 0) === 1;
            $initials = strtoupper(substr((string)($agent['name'] ?? 'A'), 0, 1));
            ?>
            <div class="vp-profile-header">
                <div class="vp-profile-top">
                    <div class="vp-avatar"><?php echo $initials; ?></div>
                    <div class="vp-profile-info">
                        <div class="vp-profile-name"><?php echo htmlspecialchars((string)($agent['name'] ?? 'Agent')); ?></div>
                        <div class="vp-profile-badges">
                            <span class="vp-badge <?php echo ($agent['role'] ?? '') === 'admin' ? 'vp-badge-admin' : 'vp-badge-registrar'; ?>">
                                <?php echo htmlspecialchars((string)($agent['role'] ?? 'registrar')); ?>
                            </span>
                            <span class="vp-badge <?php echo $is_active ? 'vp-badge-active' : 'vp-badge-inactive'; ?>">
                                <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                            </span>
                            <span class="vp-badge vp-badge-id">#<?php echo (int)$agent['id']; ?></span>
                        </div>
                        <div class="vp-contact-row">
                            <span><i class="fas fa-envelope"></i><?php echo htmlspecialchars((string)($agent['email'] ?: 'No email')); ?></span>
                            <span><i class="fas fa-phone"></i><?php echo htmlspecialchars((string)($agent['phone'] ?: $agent['phone_number'] ?: 'No phone')); ?></span>
                        </div>
                    </div>
                    <div class="vp-profile-actions">
                        <a href="../donor-management/donors.php?filter_agent=<?php echo (int)$agent['id']; ?>" class="vp-action-btn">
                            <i class="fas fa-users"></i>Donors
                        </a>
                        <a href="../call-center/call-history.php?agent=<?php echo (int)$agent['id']; ?>" class="vp-action-btn">
                            <i class="fas fa-phone"></i>Calls
                        </a>
                        <a href="../messaging/whatsapp/inbox.php" class="vp-action-btn">
                            <i class="fab fa-whatsapp"></i>WhatsApp
                        </a>
                    </div>
                </div>
                <div class="vp-profile-meta">
                    <div class="vp-meta-item">
                        <span class="vp-meta-label">Last Login</span>
                        <span class="vp-meta-value"><?php echo htmlspecialchars($formatDateTime($agent['last_login_at'] ?? null)); ?></span>
                    </div>
                    <div class="vp-meta-item">
                        <span class="vp-meta-label">Joined</span>
                        <span class="vp-meta-value"><?php echo htmlspecialchars($formatDateTime($agent['created_at'] ?? null)); ?></span>
                    </div>
                    <div class="vp-meta-item">
                        <span class="vp-meta-label">First Call</span>
                        <span class="vp-meta-value"><?php echo htmlspecialchars($formatDateTime($call_stats['first_call_at'] ?? null)); ?></span>
                    </div>
                    <div class="vp-meta-item">
                        <span class="vp-meta-label">Last Call</span>
                        <span class="vp-meta-value"><?php echo htmlspecialchars($formatDateTime($call_stats['last_call_at'] ?? null)); ?></span>
                    </div>
                </div>
            </div>

            <!-- Portfolio Stats -->
            <div class="vp-section-title"><i class="fas fa-wallet"></i> Portfolio</div>
            <div class="vp-stats-grid">
                <div class="vp-stat-card">
                    <div class="vp-stat-value"><?php echo (int)$portfolio['total_donors']; ?></div>
                    <div class="vp-stat-label">Total Donors</div>
                    <div class="vp-stat-detail">
                        Assigned <?php echo (int)$portfolio['assigned_only_donors']; ?> &middot;
                        Registered <?php echo (int)$portfolio['registered_only_donors']; ?> &middot;
                        Overlap <?php echo (int)$portfolio['overlapping_donors']; ?>
                    </div>
                </div>
                <div class="vp-stat-card success">
                    <div class="vp-stat-value"><?php echo $formatMoney($portfolio['total_pledged']); ?></div>
                    <div class="vp-stat-label">Total Pledged</div>
                    <div class="vp-stat-detail">Completed: <?php echo (int)$portfolio['completed_donors']; ?> donors</div>
                </div>
                <div class="vp-stat-card info">
                    <div class="vp-stat-value"><?php echo $formatMoney($portfolio['total_paid']); ?></div>
                    <div class="vp-stat-label">Total Paid</div>
                    <div class="vp-stat-detail">
                        Paying: <?php echo (int)$portfolio['paying_donors']; ?> &middot;
                        Overdue: <?php echo (int)$portfolio['overdue_donors']; ?>
                    </div>
                </div>
                <div class="vp-stat-card warning">
                    <div class="vp-stat-value"><?php echo $formatMoney($portfolio['total_balance']); ?></div>
                    <div class="vp-stat-label">Outstanding</div>
                    <div class="vp-stat-detail">Not started: <?php echo (int)$portfolio['not_started_donors']; ?></div>
                </div>
            </div>

            <!-- Call & Communication Stats -->
            <div class="vp-section-title"><i class="fas fa-headset"></i> Calls & Communication</div>
            <div class="vp-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="vp-stat-card">
                    <div class="vp-stat-value"><?php echo (int)$call_stats['total_calls']; ?></div>
                    <div class="vp-stat-label">Calls Logged</div>
                    <div class="vp-stat-detail">
                        Successful: <?php echo (int)$call_stats['successful_calls']; ?> (<?php echo $call_success_rate; ?>%)<br>
                        Callbacks: <?php echo (int)$call_stats['callbacks_scheduled']; ?>
                    </div>
                </div>
                <div class="vp-stat-card accent">
                    <div class="vp-stat-value"><?php echo $formatDuration((float)$call_stats['total_talk_seconds']); ?></div>
                    <div class="vp-stat-label">Total Talk Time</div>
                    <div class="vp-stat-detail">Avg/Call: <?php echo $formatDuration($avg_talk_time); ?></div>
                </div>
                <div class="vp-stat-card success">
                    <div class="vp-stat-value"><?php echo (int)$whatsapp_stats['total_conversations']; ?></div>
                    <div class="vp-stat-label">WhatsApp Conversations</div>
                    <div class="vp-stat-detail">
                        Unread: <?php echo (int)$whatsapp_stats['unread_conversations']; ?> &middot;
                        Unknown: <?php echo (int)$whatsapp_stats['unknown_conversations']; ?>
                    </div>
                </div>
            </div>

            <!-- Messages & Pledges Stats -->
            <div class="vp-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="vp-stat-card info">
                    <div class="vp-stat-value"><?php echo (int)$message_stats['total_messages']; ?></div>
                    <div class="vp-stat-label">Messages Sent</div>
                    <div class="vp-stat-detail">
                        WhatsApp: <?php echo (int)$message_stats['whatsapp_messages']; ?> &middot;
                        SMS: <?php echo (int)$message_stats['sms_messages']; ?>
                    </div>
                </div>
                <div class="vp-stat-card">
                    <div class="vp-stat-value"><?php echo (int)$pledge_stats['total_pledges']; ?></div>
                    <div class="vp-stat-label">Pledges</div>
                    <div class="vp-stat-detail">
                        Approved: <?php echo (int)$pledge_stats['approved_pledges']; ?><br>
                        Amount: <?php echo $formatMoney($pledge_stats['approved_pledge_amount']); ?>
                    </div>
                </div>
                <div class="vp-stat-card accent">
                    <div class="vp-stat-value"><?php echo $formatMoney($pledge_stats['total_pledge_amount']); ?></div>
                    <div class="vp-stat-label">Total Pledge Amount</div>
                    <div class="vp-stat-detail">
                        Last msg: <?php echo htmlspecialchars($formatDateTime($message_stats['last_message_at'])); ?><br>
                        Last WA: <?php echo htmlspecialchars($formatDateTime($whatsapp_stats['last_message_at'])); ?>
                    </div>
                </div>
            </div>

            <!-- Tables Section -->
            <div class="vp-section-title"><i class="fas fa-table"></i> Detailed Activity</div>

            <div class="row g-3">
                <!-- Recent Donor Portfolio -->
                <div class="col-12 col-xl-6">
                    <div class="vp-table-card">
                        <div class="vp-table-header">
                            <div>
                                <h6><i class="fas fa-users me-2" style="color: var(--primary);"></i>Donor Portfolio</h6>
                                <small>Latest 50 donors assigned or registered</small>
                            </div>
                        </div>
                        <?php if (!$recent_donors): ?>
                            <div class="vp-table-empty">
                                <i class="fas fa-inbox"></i>
                                No donors matched this agent.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead>
                                    <tr>
                                        <th>Donor</th>
                                        <th>Pledged</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                        <th>Source</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recent_donors as $donor): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)$donor['name']); ?></div>
                                                <div class="small" style="color: var(--gray-400);"><?php echo htmlspecialchars((string)($donor['phone'] ?: 'No phone')); ?></div>
                                            </td>
                                            <td><?php echo $formatMoney((float)$donor['total_pledged']); ?></td>
                                            <td>
                                                <?php
                                                $bal = (float)$donor['balance'];
                                                $bal_class = $bal > 0 ? 'vp-pill-warning' : 'vp-pill-success';
                                                ?>
                                                <span class="vp-pill <?php echo $bal_class; ?>"><?php echo $formatMoney($bal); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $ps = (string)$donor['payment_status'];
                                                $ps_map = ['completed' => 'vp-pill-success', 'paying' => 'vp-pill-info', 'overdue' => 'vp-pill-danger', 'not_started' => 'vp-pill-muted'];
                                                $ps_class = $ps_map[$ps] ?? 'vp-pill-muted';
                                                ?>
                                                <span class="vp-pill <?php echo $ps_class; ?>"><?php echo htmlspecialchars($ps); ?></span>
                                            </td>
                                            <td>
                                                <?php if ((int)$donor['agent_id'] === (int)$agent['id'] && (int)$donor['registered_by_user_id'] === (int)$agent['id']): ?>
                                                    <span class="vp-pill vp-pill-primary">Both</span>
                                                <?php elseif ((int)$donor['agent_id'] === (int)$agent['id']): ?>
                                                    <span class="vp-pill vp-pill-info">Assigned</span>
                                                <?php else: ?>
                                                    <span class="vp-pill vp-pill-muted">Registered</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Call Sessions -->
                <div class="col-12 col-xl-6">
                    <div class="vp-table-card">
                        <div class="vp-table-header">
                            <div>
                                <h6><i class="fas fa-phone me-2" style="color: var(--success);"></i>Recent Calls</h6>
                                <small>Last 20 calls handled</small>
                            </div>
                        </div>
                        <?php if (!$recent_calls): ?>
                            <div class="vp-table-empty">
                                <i class="fas fa-phone-slash"></i>
                                No calls found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead>
                                    <tr>
                                        <th>Donor</th>
                                        <th>Started</th>
                                        <th>Duration</th>
                                        <th>Outcome</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recent_calls as $call): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($call['donor_name'] ?? 'Unknown')); ?></div>
                                                <div class="small" style="color: var(--gray-400);"><?php echo htmlspecialchars((string)($call['donor_phone'] ?? '')); ?></div>
                                            </td>
                                            <td class="small"><?php echo htmlspecialchars($formatDateTime($call['call_started_at'] ?? null)); ?></td>
                                            <td class="small"><?php echo $formatDuration((float)($call['duration_seconds'] ?? 0)); ?></td>
                                            <td><span class="vp-pill vp-pill-muted"><?php echo htmlspecialchars((string)($call['outcome'] ?? '-')); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- Outcome Breakdown -->
                <div class="col-12 col-xl-6">
                    <div class="vp-table-card">
                        <div class="vp-table-header">
                            <div>
                                <h6><i class="fas fa-chart-pie me-2" style="color: var(--accent-dark);"></i>Outcome Breakdown</h6>
                            </div>
                        </div>
                        <?php if (!$call_outcomes): ?>
                            <div class="vp-table-empty">
                                <i class="fas fa-chart-bar"></i>
                                No outcome data yet.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table mb-0">
                                    <thead>
                                    <tr>
                                        <th>Outcome</th>
                                        <th>Count</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($call_outcomes as $outcome): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$outcome['outcome']); ?></td>
                                            <td><span class="fw-semibold"><?php echo (int)$outcome['total_count']; ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent WhatsApp Conversations -->
                <div class="col-12 col-xl-6">
                    <div class="vp-table-card">
                        <div class="vp-table-header">
                            <div>
                                <h6><i class="fab fa-whatsapp me-2" style="color: var(--success);"></i>WhatsApp Conversations</h6>
                            </div>
                        </div>
                        <?php if (!$recent_conversations): ?>
                            <div class="vp-table-empty">
                                <i class="fab fa-whatsapp"></i>
                                No conversations found.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead>
                                    <tr>
                                        <th>Contact</th>
                                        <th>Last Message</th>
                                        <th>Status</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($recent_conversations as $conversation): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($conversation['donor_name'] ?: ($conversation['contact_name'] ?? $conversation['phone_number'] ?? 'Unknown'))); ?></div>
                                                <div class="small" style="color: var(--gray-400);"><?php echo htmlspecialchars((string)($conversation['donor_phone'] ?: 'Unknown')); ?></div>
                                            </td>
                                            <td class="small"><?php echo htmlspecialchars($formatDateTime($conversation['last_message_at'] ?? null)); ?></td>
                                            <td>
                                                <?php if (array_key_exists('status', $conversation)): ?>
                                                    <span class="vp-pill vp-pill-muted"><?php echo htmlspecialchars((string)($conversation['status'])); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($conversation['unread_count'])): ?>
                                                    <span class="vp-pill vp-pill-danger">Unread: <?php echo (int)$conversation['unread_count']; ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($conversation['is_unknown'])): ?>
                                                    <span class="vp-pill vp-pill-warning">Unknown</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Messages -->
            <div class="vp-table-card">
                <div class="vp-table-header">
                    <div>
                        <h6><i class="fas fa-paper-plane me-2" style="color: var(--info);"></i>Recent Messages Sent</h6>
                        <small>Last 25 messages sent by this agent</small>
                    </div>
                </div>
                <?php if (!$recent_messages): ?>
                    <div class="vp-table-empty">
                        <i class="fas fa-envelope-open"></i>
                        No message logs found.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead>
                            <tr>
                                <th>Recipient</th>
                                <th>Channel</th>
                                <th>Status</th>
                                <th>Sent At</th>
                                <th>Message</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recent_messages as $message): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($message['donor_name'] ?: ($message['recipient_name'] ?? ($message['phone_number'] ?? 'Unknown')))); ?></div>
                                        <div class="small" style="color: var(--gray-400);"><?php echo htmlspecialchars((string)($message['donor_phone'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <?php
                                        $ch = strtolower((string)($message['channel'] ?? 'unknown'));
                                        $ch_class = $ch === 'whatsapp' ? 'vp-pill-success' : ($ch === 'sms' ? 'vp-pill-info' : 'vp-pill-muted');
                                        ?>
                                        <span class="vp-pill <?php echo $ch_class; ?>"><?php echo htmlspecialchars($ch); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $st = strtolower((string)($message['status'] ?? '-'));
                                        $st_class = in_array($st, ['failed', 'error']) ? 'vp-pill-danger' : ($st === 'delivered' || $st === 'sent' ? 'vp-pill-success' : 'vp-pill-muted');
                                        ?>
                                        <span class="vp-pill <?php echo $st_class; ?>"><?php echo htmlspecialchars($st); ?></span>
                                    </td>
                                    <td class="small"><?php echo htmlspecialchars($formatDateTime($message['sent_at'] ?? null)); ?></td>
                                    <td class="small" style="max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo htmlspecialchars(mb_substr((string)($message['message_content'] ?? ''), 0, 90)); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
