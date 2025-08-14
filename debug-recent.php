<?php
require_once 'config/db.php';

// Simple debug to see what recent.php returns
echo "<h1>Debug Recent API</h1>";

echo "<h2>Database Check:</h2>";
$pledges = db()->query("SELECT id, donor_name, amount, status, approved_at FROM pledges WHERE status='approved' ORDER BY approved_at DESC LIMIT 5");
echo "<h3>Approved Pledges:</h3>";
while ($row = $pledges->fetch_assoc()) {
    echo "<p>ID: {$row['id']}, Name: {$row['donor_name']}, Amount: {$row['amount']}, Approved: {$row['approved_at']}</p>";
}

$payments = db()->query("SELECT id, donor_name, amount, status, received_at FROM payments WHERE status='approved' ORDER BY received_at DESC LIMIT 5");
echo "<h3>Approved Payments:</h3>";
while ($row = $payments->fetch_assoc()) {
    echo "<p>ID: {$row['id']}, Name: {$row['donor_name']}, Amount: {$row['amount']}, Received: {$row['received_at']}</p>";
}

echo "<h2>API Response:</h2>";
$recent = file_get_contents('http://localhost/fundraising/api/recent.php');
echo "<pre>$recent</pre>";
?>
