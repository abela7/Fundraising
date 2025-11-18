<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();

// Search for Arsema
$search_name = "Arsema";

echo "<!DOCTYPE html><html><head><title>Debug Duration</title>";
echo "<style>table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ccc; padding: 8px; text-align: left; } th { background: #f0f0f0; }</style>";
echo "</head><body>";
echo "<h1>Debug Duration for '{$search_name}'</h1>";

// 1. Find Donor ID
$donor_query = "SELECT id, name, phone FROM donors WHERE name LIKE ?";
$stmt = $db->prepare($donor_query);
$like_name = "%{$search_name}%";
$stmt->bind_param('s', $like_name);
$stmt->execute();
$donors = $stmt->get_result();

if ($donors->num_rows === 0) {
    echo "<p style='color:red'>No donor found with name like '{$search_name}'</p>";
} else {
    echo "<h2>Donors Found:</h2>";
    echo "<table><tr><th>ID</th><th>Name</th><th>Phone</th></tr>";
    
    $donor_ids = [];
    while ($d = $donors->fetch_assoc()) {
        echo "<tr><td>{$d['id']}</td><td>{$d['name']}</td><td>{$d['phone']}</td></tr>";
        $donor_ids[] = $d['id'];
    }
    echo "</table>";
    
    // 2. Check Sessions for these donors
    if (!empty($donor_ids)) {
        $ids_str = implode(',', $donor_ids);
        echo "<h2>Call Sessions (Raw Data)</h2>";
        
        $session_query = "
            SELECT id, donor_id, call_started_at, call_ended_at, duration_seconds, outcome, created_at 
            FROM call_center_sessions 
            WHERE donor_id IN ({$ids_str}) 
            ORDER BY id DESC
        ";
        
        $res = $db->query($session_query);
        
        if ($res->num_rows > 0) {
            echo "<table>";
            echo "<tr><th>Session ID</th><th>Donor ID</th><th>Start Time</th><th>End Time</th><th>Duration (Sec)</th><th>Outcome</th><th>Created At</th></tr>";
            while ($row = $res->fetch_assoc()) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>{$row['donor_id']}</td>";
                echo "<td>{$row['call_started_at']}</td>";
                echo "<td>{$row['call_ended_at']}</td>";
                echo "<td style='background: " . ($row['duration_seconds'] > 0 ? '#d1fae5' : '#fee2e2') . "'>{$row['duration_seconds']}</td>";
                echo "<td>{$row['outcome']}</td>";
                echo "<td>{$row['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No sessions found for these donors.</p>";
        }
    }
}

echo "<br><a href='index.php'>Back to Dashboard</a>";
echo "</body></html>";

