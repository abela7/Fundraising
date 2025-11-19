<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$action = $_GET['action'] ?? 'preview';

// CSS and HTML Header
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>Reset Call History</title>
    <link rel='icon' type='image/svg+xml' href='../../assets/favicon.svg'>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'>
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .reset-card { background: white; border-radius: 8px; padding: 25px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .danger-zone { border: 2px solid #dc3545; background: #fff5f5; }
        .safe-zone { border: 2px solid #198754; background: #f0f8f5; }
        .btn-reset { background: #dc3545; color: white; padding: 12px 30px; font-size: 1.1rem; }
        .btn-reset:hover { background: #bb2d3b; color: white; }
        table { font-size: 0.9rem; }
        .keep { background: #d1f4e0 !important; }
        .delete { background: #ffe69c !important; }
    </style>
</head>
<body>
<div class='container'>
    <div class='d-flex justify-content-between align-items-center mb-4'>
        <h1><i class='fas fa-redo me-2'></i>Reset Call History</h1>
        <a href='index.php' class='btn btn-outline-secondary'><i class='fas fa-arrow-left me-2'></i>Back to Call Center</a>
    </div>";

if ($action === 'preview') {
    // PREVIEW MODE - Show what will be kept/deleted
    
    // Get calls WITH payment plans (KEEP)
    $keep_query = "
        SELECT 
            cs.id,
            cs.call_started_at,
            d.name as donor_name,
            cs.outcome,
            cs.duration_seconds,
            cs.payment_plan_id,
            pp.total_amount as plan_amount
        FROM call_center_sessions cs
        LEFT JOIN donors d ON cs.donor_id = d.id
        LEFT JOIN donor_payment_plans pp ON cs.payment_plan_id = pp.id
        WHERE cs.payment_plan_id IS NOT NULL
        ORDER BY cs.id
    ";
    
    $keep_result = $db->query($keep_query);
    $keep_ids = [];
    
    echo "<div class='reset-card safe-zone'>
        <h3 class='text-success'><i class='fas fa-shield-check me-2'></i>Calls to KEEP (Have Payment Plans)</h3>
        <p>These calls created payment plans and will be preserved.</p>
        <table class='table table-sm'>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Donor</th>
                    <th>Outcome</th>
                    <th>Plan Amount</th>
                </tr>
            </thead>
            <tbody>";
    
    while ($row = $keep_result->fetch_assoc()) {
        $keep_ids[] = (int)$row['id'];
        echo "<tr class='keep'>
            <td><strong>{$row['id']}</strong></td>
            <td>" . date('M j, Y g:i A', strtotime($row['call_started_at'])) . "</td>
            <td>{$row['donor_name']}</td>
            <td>" . ucwords(str_replace('_', ' ', $row['outcome'])) . "</td>
            <td class='text-success fw-bold'>£" . number_format((float)$row['plan_amount'], 2) . "</td>
        </tr>";
    }
    
    echo "</tbody></table>
        <div class='alert alert-success mb-0'>
            <i class='fas fa-check-circle me-2'></i><strong>" . count($keep_ids) . " calls</strong> will be kept (they created payment plans).
        </div>
    </div>";
    
    // Get calls WITHOUT payment plans (DELETE)
    $delete_query = "
        SELECT 
            cs.id,
            cs.call_started_at,
            d.name as donor_name,
            cs.outcome,
            cs.duration_seconds
        FROM call_center_sessions cs
        LEFT JOIN donors d ON cs.donor_id = d.id
        WHERE cs.payment_plan_id IS NULL
        ORDER BY cs.id
    ";
    
    $delete_result = $db->query($delete_query);
    $delete_ids = [];
    
    echo "<div class='reset-card danger-zone'>
        <h3 class='text-danger'><i class='fas fa-trash-alt me-2'></i>Calls to DELETE (Test Data)</h3>
        <p>These are test calls without payment plans. They will be permanently removed.</p>
        <table class='table table-sm'>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Donor</th>
                    <th>Outcome</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>";
    
    while ($row = $delete_result->fetch_assoc()) {
        $delete_ids[] = (int)$row['id'];
        
        // Determine reason
        $reason = 'Test call';
        if ($row['outcome'] === 'no_answer') {
            $reason = 'No answer (likely test)';
        } elseif ($row['outcome'] === 'number_not_in_service') {
            $reason = 'Invalid number test';
        }
        
        echo "<tr class='delete'>
            <td>{$row['id']}</td>
            <td>" . date('M j, Y g:i A', strtotime($row['call_started_at'])) . "</td>
            <td>{$row['donor_name']}</td>
            <td>" . ucwords(str_replace('_', ' ', $row['outcome'])) . "</td>
            <td class='text-muted'>{$reason}</td>
        </tr>";
    }
    
    echo "</tbody></table>
        <div class='alert alert-danger mb-0'>
            <i class='fas fa-exclamation-triangle me-2'></i><strong>" . count($delete_ids) . " calls</strong> will be deleted.
        </div>
    </div>";
    
    // Summary and Action Button
    echo "<div class='reset-card'>
        <h3><i class='fas fa-clipboard-check me-2'></i>Reset Summary</h3>
        <div class='row'>
            <div class='col-md-6'>
                <div class='p-3 border border-success rounded bg-light'>
                    <div class='display-6 text-success fw-bold'>" . count($keep_ids) . "</div>
                    <div class='text-muted'>Calls to Keep</div>
                    <small>IDs: " . implode(', ', $keep_ids) . "</small>
                </div>
            </div>
            <div class='col-md-6'>
                <div class='p-3 border border-danger rounded bg-light'>
                    <div class='display-6 text-danger fw-bold'>" . count($delete_ids) . "</div>
                    <div class='text-muted'>Calls to Delete</div>
                    <small>IDs: " . implode(', ', array_slice($delete_ids, 0, 10)) . (count($delete_ids) > 10 ? '...' : '') . "</small>
                </div>
            </div>
        </div>
        
        <div class='alert alert-warning mt-4'>
            <h5><i class='fas fa-info-circle me-2'></i>What Will Happen:</h5>
            <ol>
                <li>Delete <strong>" . count($delete_ids) . " test calls</strong> (no payment plans)</li>
                <li>Keep <strong>" . count($keep_ids) . " important calls</strong> (with payment plans)</li>
                <li>Also delete any related <strong>appointments</strong> for test calls</li>
                <li>Call history will be clean, showing only real interactions</li>
            </ol>
        </div>
        
        <div class='d-grid gap-3 mt-4'>
            <button onclick='confirmReset()' class='btn btn-reset btn-lg'>
                <i class='fas fa-broom me-2'></i>Clean Up Now
            </button>
            <a href='index.php' class='btn btn-outline-secondary btn-lg'>
                <i class='fas fa-times me-2'></i>Cancel
            </a>
        </div>
    </div>";
    
    echo "<script>
    function confirmReset() {
        const deleteCount = " . count($delete_ids) . ";
        const keepCount = " . count($keep_ids) . ";
        
        const message = `⚠️ CONFIRM CLEANUP\\n\\n` +
            `This will:\\n` +
            `✓ DELETE ${deleteCount} test call records\\n` +
            `✓ KEEP ${keepCount} calls with payment plans\\n\\n` +
            `This action cannot be undone!\\n\\n` +
            `Are you sure you want to proceed?`;
        
        if (confirm(message)) {
            window.location.href = '?action=execute';
        }
    }
    </script>";
    
} elseif ($action === 'execute') {
    // EXECUTE MODE - Actually delete the test data
    
    echo "<div class='reset-card'>
        <h3><i class='fas fa-spinner fa-spin me-2'></i>Cleaning Up Call History...</h3>
        <div class='progress mb-3' style='height: 30px;'>
            <div class='progress-bar progress-bar-striped progress-bar-animated' role='progressbar' style='width: 100%'>
                Processing...
            </div>
        </div>
    </div>";
    
    try {
        $db->begin_transaction();
        
        $results = [];
        
        // Step 1: Delete appointments for test calls (calls without payment plans)
        $delete_appointments = "
            DELETE FROM call_center_appointments 
            WHERE session_id IN (
                SELECT id FROM call_center_sessions WHERE payment_plan_id IS NULL
            )
        ";
        $db->query($delete_appointments);
        $results['appointments_deleted'] = $db->affected_rows;
        
        // Step 2: Delete test calls (no payment plans)
        $delete_calls = "DELETE FROM call_center_sessions WHERE payment_plan_id IS NULL";
        $db->query($delete_calls);
        $results['calls_deleted'] = $db->affected_rows;
        
        // Step 3: Get remaining calls count
        $remaining = $db->query("SELECT COUNT(*) as count FROM call_center_sessions")->fetch_assoc();
        $results['calls_remaining'] = (int)$remaining['count'];
        
        // Step 4: Update queue attempts count (reset for donors whose test calls were deleted)
        $reset_queue = "
            UPDATE call_center_queues q
            LEFT JOIN (
                SELECT donor_id, COUNT(*) as real_attempts 
                FROM call_center_sessions 
                GROUP BY donor_id
            ) cs ON q.donor_id = cs.donor_id
            SET q.attempts_count = COALESCE(cs.real_attempts, 0)
        ";
        $db->query($reset_queue);
        $results['queues_updated'] = $db->affected_rows;
        
        $db->commit();
        
        // Success message
        echo "<div class='reset-card safe-zone'>
            <h3 class='text-success'><i class='fas fa-check-circle me-2'></i>Cleanup Complete!</h3>
            
            <div class='row g-3 mt-3'>
                <div class='col-md-3'>
                    <div class='p-3 bg-white rounded border'>
                        <div class='display-6 text-danger fw-bold'>{$results['calls_deleted']}</div>
                        <div class='text-muted small'>Test Calls Deleted</div>
                    </div>
                </div>
                <div class='col-md-3'>
                    <div class='p-3 bg-white rounded border'>
                        <div class='display-6 text-warning fw-bold'>{$results['appointments_deleted']}</div>
                        <div class='text-muted small'>Appointments Removed</div>
                    </div>
                </div>
                <div class='col-md-3'>
                    <div class='p-3 bg-white rounded border'>
                        <div class='display-6 text-success fw-bold'>{$results['calls_remaining']}</div>
                        <div class='text-muted small'>Calls Remaining</div>
                    </div>
                </div>
                <div class='col-md-3'>
                    <div class='p-3 bg-white rounded border'>
                        <div class='display-6 text-info fw-bold'>{$results['queues_updated']}</div>
                        <div class='text-muted small'>Queues Updated</div>
                    </div>
                </div>
            </div>
            
            <div class='alert alert-success mt-4'>
                <h5><i class='fas fa-check-circle me-2'></i>What Was Done:</h5>
                <ul class='mb-0'>
                    <li>Deleted <strong>{$results['calls_deleted']}</strong> test call records (no payment plans)</li>
                    <li>Removed <strong>{$results['appointments_deleted']}</strong> test appointments</li>
                    <li>Kept <strong>{$results['calls_remaining']}</strong> important calls (with payment plans)</li>
                    <li>Updated <strong>{$results['queues_updated']}</strong> donor queue records</li>
                    <li>Call history is now clean and shows only real interactions!</li>
                </ul>
            </div>
            
            <div class='d-grid gap-2 mt-4'>
                <a href='call-history.php' class='btn btn-success btn-lg'>
                    <i class='fas fa-history me-2'></i>View Clean Call History
                </a>
                <a href='index.php' class='btn btn-outline-primary btn-lg'>
                    <i class='fas fa-home me-2'></i>Back to Call Center
                </a>
            </div>
        </div>";
        
    } catch (Exception $e) {
        $db->rollback();
        
        echo "<div class='reset-card danger-zone'>
            <h3 class='text-danger'><i class='fas fa-exclamation-triangle me-2'></i>Cleanup Failed</h3>
            <div class='alert alert-danger'>
                <strong>Error:</strong> {$e->getMessage()}
            </div>
            <a href='?action=preview' class='btn btn-secondary'>
                <i class='fas fa-arrow-left me-2'></i>Go Back
            </a>
        </div>";
    }
}

echo "</div>
</body>
</html>";
?>

