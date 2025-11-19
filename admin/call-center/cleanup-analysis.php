<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='utf-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>Call Center Data Cleanup Analysis</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'>
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .analysis-card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stats { display: flex; gap: 15px; flex-wrap: wrap; }
        .stat-box { flex: 1; min-width: 200px; padding: 15px; background: #f8f9fa; border-radius: 6px; border-left: 4px solid #0a6286; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #0a6286; }
        .stat-label { color: #666; font-size: 0.9rem; }
        table { font-size: 0.9rem; }
        .real { background: #d1f4e0 !important; }
        .test { background: #ffe69c !important; }
        .duplicate { background: #f8d7da !important; }
        .sql-code { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; font-family: monospace; font-size: 0.85rem; white-space: pre-wrap; margin: 10px 0; }
    </style>
</head>
<body>
<div class='container-fluid'>
    <h1 class='mb-4'><i class='fas fa-broom me-2'></i>Call Center Data Cleanup Analysis</h1>";

// ===== ANALYSIS 1: Donors with Real Pledges =====
echo "<div class='analysis-card'>
    <h3><i class='fas fa-users text-success me-2'></i>Donors with Real Pledges</h3>
    <p>These donors have actual pledge records in the system.</p>";

$query = "
    SELECT 
        d.id,
        d.name,
        d.phone,
        d.total_pledged,
        d.total_paid,
        d.balance,
        COUNT(p.id) as pledge_count,
        MIN(p.created_at) as first_pledge,
        MAX(p.created_at) as last_pledge
    FROM donors d
    LEFT JOIN pledges p ON d.phone = p.donor_phone AND p.status = 'approved'
    WHERE d.total_pledged > 0
    GROUP BY d.id
    HAVING pledge_count > 0
    ORDER BY d.balance DESC
";

$result = $db->query($query);
$real_donors = [];

echo "<table class='table table-sm table-striped'>
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Phone</th>
            <th>Pledged</th>
            <th>Paid</th>
            <th>Balance</th>
            <th>Pledges</th>
            <th>First Pledge</th>
        </tr>
    </thead>
    <tbody>";

while ($row = $result->fetch_assoc()) {
    $real_donors[] = (int)$row['id'];
    echo "<tr class='real'>
        <td>{$row['id']}</td>
        <td>{$row['name']}</td>
        <td>{$row['phone']}</td>
        <td>£" . number_format((float)$row['total_pledged'], 2) . "</td>
        <td>£" . number_format((float)$row['total_paid'], 2) . "</td>
        <td>£" . number_format((float)$row['balance'], 2) . "</td>
        <td>{$row['pledge_count']}</td>
        <td>" . date('M j, Y', strtotime($row['first_pledge'])) . "</td>
    </tr>";
}

echo "</tbody></table>
    <div class='alert alert-success'><strong>" . count($real_donors) . "</strong> donors have real pledge records.</div>
</div>";

// ===== ANALYSIS 2: Call Records Analysis =====
echo "<div class='analysis-card'>
    <h3><i class='fas fa-phone text-primary me-2'></i>Call Records Analysis</h3>";

// Calls for real donors
$real_donor_ids = implode(',', $real_donors);
$call_query = "
    SELECT 
        cs.id,
        cs.donor_id,
        d.name as donor_name,
        cs.call_started_at,
        cs.outcome,
        cs.duration_seconds,
        cs.payment_plan_id,
        CASE 
            WHEN cs.donor_id IN ($real_donor_ids) THEN 'REAL'
            ELSE 'TEST'
        END as record_type
    FROM call_center_sessions cs
    LEFT JOIN donors d ON cs.donor_id = d.id
    ORDER BY cs.call_started_at DESC
";

$call_result = $db->query($call_query);
$real_calls = [];
$test_calls = [];
$calls_with_plans = [];

echo "<table class='table table-sm table-striped'>
    <thead>
        <tr>
            <th>ID</th>
            <th>Date</th>
            <th>Donor</th>
            <th>Outcome</th>
            <th>Duration</th>
            <th>Has Plan</th>
            <th>Type</th>
        </tr>
    </thead>
    <tbody>";

while ($row = $call_result->fetch_assoc()) {
    $class = ($row['record_type'] === 'REAL') ? 'real' : 'test';
    $duration = ($row['duration_seconds'] > 0) ? floor($row['duration_seconds']/60) . 'm ' . ($row['duration_seconds']%60) . 's' : '-';
    $has_plan = $row['payment_plan_id'] ? '<i class="fas fa-check text-success"></i>' : '-';
    
    echo "<tr class='{$class}'>
        <td>{$row['id']}</td>
        <td>" . date('M j, Y g:i A', strtotime($row['call_started_at'])) . "</td>
        <td>{$row['donor_name']}</td>
        <td>" . ucwords(str_replace('_', ' ', $row['outcome'])) . "</td>
        <td>{$duration}</td>
        <td>{$has_plan}</td>
        <td><span class='badge bg-" . (($row['record_type'] === 'REAL') ? 'success' : 'warning') . "'>{$row['record_type']}</span></td>
    </tr>";
    
    if ($row['record_type'] === 'REAL') {
        $real_calls[] = (int)$row['id'];
        if ($row['payment_plan_id']) {
            $calls_with_plans[] = (int)$row['id'];
        }
    } else {
        $test_calls[] = (int)$row['id'];
    }
}

echo "</tbody></table>

    <div class='stats mt-4'>
        <div class='stat-box'>
            <div class='stat-number'>" . count($real_calls) . "</div>
            <div class='stat-label'>Real Call Records</div>
        </div>
        <div class='stat-box'>
            <div class='stat-number'>" . count($test_calls) . "</div>
            <div class='stat-label'>Test Call Records</div>
        </div>
        <div class='stat-box'>
            <div class='stat-number'>" . count($calls_with_plans) . "</div>
            <div class='stat-label'>Calls with Payment Plans</div>
        </div>
    </div>
</div>";

// ===== ANALYSIS 3: Duplicate Call Records =====
echo "<div class='analysis-card'>
    <h3><i class='fas fa-copy text-warning me-2'></i>Duplicate Call Records</h3>
    <p>Calls made within 2 minutes of each other for the same donor (likely test clicks).</p>";

$dup_query = "
    SELECT 
        cs1.id as call_id,
        cs1.donor_id,
        d.name as donor_name,
        cs1.call_started_at,
        cs1.outcome,
        COUNT(*) as duplicate_count
    FROM call_center_sessions cs1
    INNER JOIN call_center_sessions cs2 ON 
        cs1.donor_id = cs2.donor_id AND
        cs1.id != cs2.id AND
        ABS(TIMESTAMPDIFF(SECOND, cs1.call_started_at, cs2.call_started_at)) < 120
    LEFT JOIN donors d ON cs1.donor_id = d.id
    GROUP BY cs1.id
    HAVING duplicate_count > 0
    ORDER BY cs1.call_started_at DESC
";

$dup_result = $db->query($dup_query);
$duplicate_calls = [];

echo "<table class='table table-sm table-striped'>
    <thead>
        <tr>
            <th>Call ID</th>
            <th>Donor</th>
            <th>Time</th>
            <th>Outcome</th>
            <th>Duplicates Within 2min</th>
        </tr>
    </thead>
    <tbody>";

while ($row = $dup_result->fetch_assoc()) {
    $duplicate_calls[] = (int)$row['call_id'];
    echo "<tr class='duplicate'>
        <td>{$row['call_id']}</td>
        <td>{$row['donor_name']}</td>
        <td>" . date('M j, g:i:s A', strtotime($row['call_started_at'])) . "</td>
        <td>{$row['outcome']}</td>
        <td class='fw-bold text-danger'>{$row['duplicate_count']}</td>
    </tr>";
}

echo "</tbody></table>
    <div class='alert alert-warning'><strong>" . count($duplicate_calls) . "</strong> duplicate/rapid-fire call records found.</div>
</div>";

// ===== CLEANUP SQL =====
echo "<div class='analysis-card'>
    <h3><i class='fas fa-code text-danger me-2'></i>Cleanup SQL Commands</h3>
    <p class='text-danger'><strong>⚠️ WARNING:</strong> These commands will permanently delete test data. Back up your database first!</p>";

if (!empty($test_calls)) {
    $test_call_ids = implode(',', $test_calls);
    echo "<h5 class='mt-4'>Option 1: Delete ALL Test Call Records (NO payment plans attached)</h5>
    <div class='sql-code'>-- Delete " . count($test_calls) . " test call records
DELETE FROM call_center_sessions 
WHERE id IN ($test_call_ids) 
AND payment_plan_id IS NULL;</div>";
}

if (!empty($duplicate_calls)) {
    // Keep only the earliest call for each donor
    echo "<h5 class='mt-4'>Option 2: Delete Duplicate Calls (Keep Only First Call per Donor)</h5>
    <div class='sql-code'>-- Delete duplicate calls, keeping only the first one per donor
DELETE cs1 FROM call_center_sessions cs1
INNER JOIN call_center_sessions cs2 ON 
    cs1.donor_id = cs2.donor_id AND
    cs1.id > cs2.id AND
    ABS(TIMESTAMPDIFF(SECOND, cs1.call_started_at, cs2.call_started_at)) < 120;</div>";
}

echo "<h5 class='mt-4'>Option 3: Delete Specific Call IDs (Manual Selection)</h5>
    <p>Copy specific IDs from the tables above and paste them here:</p>
    <div class='sql-code'>-- Example: Delete specific calls
DELETE FROM call_center_sessions 
WHERE id IN (1, 2, 3, 5, 8, 9, 11, 12, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23);</div>";

echo "</div>";

// ===== RECOMMENDATIONS =====
echo "<div class='analysis-card'>
    <h3><i class='fas fa-lightbulb text-info me-2'></i>Recommendations</h3>
    <div class='alert alert-info'>
        <h6>Based on the analysis:</h6>
        <ol>
            <li><strong>Real Donors:</strong> " . count($real_donors) . " donors have legitimate pledges. Keep all their call records.</li>
            <li><strong>Test Data:</strong> " . count($test_calls) . " call records are for testing (no payment plans). Safe to delete.</li>
            <li><strong>Duplicates:</strong> " . count($duplicate_calls) . " records appear to be rapid-fire test clicks. Can be consolidated.</li>
            <li><strong>Protected:</strong> " . count($calls_with_plans) . " calls have payment plans attached - DO NOT delete these!</li>
        </ol>
        
        <h6 class='mt-3'>Suggested Action:</h6>
        <p>Run the cleanup queries in this order:</p>
        <ol>
            <li>Back up database first!</li>
            <li>Delete duplicate calls (Option 2)</li>
            <li>Delete test calls without payment plans (Option 1)</li>
            <li>Verify call history page looks clean</li>
        </ol>
    </div>
</div>";

echo "</div>
</body>
</html>";
?>

