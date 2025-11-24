<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

if (!$session_id || !$donor_id) {
    header('Location: index.php');
    exit;
}

$db = db();

// Fetch Session and Donor Info
$query = "
    SELECT 
        s.outcome, s.conversation_stage, s.duration_seconds, s.call_started_at, s.call_ended_at, s.notes,
        d.name as donor_name, d.phone as donor_phone,
        u.name as agent_name
    FROM call_center_sessions s
    JOIN donors d ON s.donor_id = d.id
    LEFT JOIN users u ON s.agent_id = u.id
    WHERE s.id = ?
";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $session_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_object();

if (!$result) {
    header('Location: index.php');
    exit;
}

$duration_formatted = gmdate("H:i:s", (int)$result->duration_seconds);
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
                
                if ($result->conversation_stage === 'success_pledged') {
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
                
                <div class="text-start mb-4">
                    <div class="detail-row">
                        <span class="detail-label">Donor</span>
                        <span class="detail-value"><?php echo htmlspecialchars($result->donor_name); ?></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Outcome</span>
                        <span class="detail-value"><?php echo ucfirst(str_replace('_', ' ', $result->outcome)); ?></span>
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
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
