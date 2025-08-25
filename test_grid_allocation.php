<?php
require_once 'config/db.php';

echo "<h1>🔍 Grid Allocation Test</h1>";
echo "<p>Testing if grid allocation is working after approvals...</p>";

try {
    $db = db();
    
    // Check recent approved pledges/payments
    echo "<h2>📊 Recent Approved Items (Last 10)</h2>";
    
    $sql = "
        SELECT 
            p.id, p.amount, p.type, p.donor_name, p.approved_at,
            'pledge' as item_type,
            COUNT(faa.id) as grid_allocations
        FROM pledges p
        LEFT JOIN floor_area_allocations faa ON faa.pledge_id = p.id
        WHERE p.status = 'approved'
        GROUP BY p.id
        
        UNION ALL
        
        SELECT 
            pay.id, pay.amount, 'paid' as type, pay.donor_name, pay.received_at as approved_at,
            'payment' as item_type,
            COUNT(faa.id) as grid_allocations
        FROM payments pay
        LEFT JOIN floor_area_allocations faa ON faa.payment_id = pay.id
        WHERE pay.status = 'approved'
        GROUP BY pay.id
        
        ORDER BY approved_at DESC
        LIMIT 10
    ";
    
    $result = $db->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<table class='table table-striped'>";
        echo "<thead><tr><th>Type</th><th>ID</th><th>Donor</th><th>Amount</th><th>Approved</th><th>Grid Allocations</th><th>Status</th></tr></thead>";
        echo "<tbody>";
        
        while ($row = $result->fetch_assoc()) {
            $hasGrid = (int)$row['grid_allocations'] > 0;
            $statusIcon = $hasGrid ? '✅' : '❌';
            $statusText = $hasGrid ? 'Has Grid' : 'No Grid';
            $statusClass = $hasGrid ? 'success' : 'danger';
            
            echo "<tr class='table-{$statusClass}'>";
            echo "<td>" . ucfirst($row['item_type']) . "</td>";
            echo "<td>#{$row['id']}</td>";
            echo "<td>" . htmlspecialchars($row['donor_name']) . "</td>";
            echo "<td>£" . number_format($row['amount'], 2) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['approved_at'])) . "</td>";
            echo "<td>{$row['grid_allocations']} cells</td>";
            echo "<td>{$statusIcon} {$statusText}</td>";
            echo "</tr>";
        }
        
        echo "</tbody></table>";
        
        // Summary
        $total = $result->num_rows;
        $withGrid = 0;
        $result->data_seek(0); // Reset result pointer
        while ($row = $result->fetch_assoc()) {
            if ((int)$row['grid_allocations'] > 0) $withGrid++;
        }
        
        echo "<div class='alert alert-info'>";
        echo "<h4>📈 Summary</h4>";
        echo "<p><strong>{$withGrid}</strong> out of <strong>{$total}</strong> approved items have grid allocation.</p>";
        
        if ($withGrid === $total) {
            echo "<p class='text-success'>✅ <strong>Perfect!</strong> All approved items have grid allocation.</p>";
        } else {
            echo "<p class='text-warning'>⚠️ <strong>{($total - $withGrid)}</strong> items are missing grid allocation.</p>";
        }
        echo "</div>";
        
    } else {
        echo "<div class='alert alert-warning'>";
        echo "<p>No approved items found in the system.</p>";
        echo "</div>";
    }
    
    // Check for any pending without grid
    echo "<h2>🔄 Items Needing Grid Allocation</h2>";
    
    $pending_sql = "
        SELECT 
            p.id, p.amount, p.type, p.donor_name, p.approved_at,
            'pledge' as item_type
        FROM pledges p
        WHERE p.status = 'approved'
        AND p.id NOT IN (
            SELECT DISTINCT pledge_id 
            FROM floor_area_allocations 
            WHERE pledge_id IS NOT NULL
        )
        
        UNION ALL
        
        SELECT 
            pay.id, pay.amount, 'paid' as type, pay.donor_name, pay.received_at as approved_at,
            'payment' as item_type
        FROM payments pay
        WHERE pay.status = 'approved'
        AND pay.id NOT IN (
            SELECT DISTINCT payment_id 
            FROM floor_area_allocations 
            WHERE payment_id IS NOT NULL
        )
        
        ORDER BY approved_at DESC
        LIMIT 20
    ";
    
    $pending_result = $db->query($pending_sql);
    
    if ($pending_result && $pending_result->num_rows > 0) {
        echo "<div class='alert alert-warning'>";
        echo "<h4>⚠️ Found {$pending_result->num_rows} approved items without grid allocation!</h4>";
        echo "</div>";
        
        echo "<table class='table table-warning'>";
        echo "<thead><tr><th>Type</th><th>ID</th><th>Donor</th><th>Amount</th><th>Approved</th></tr></thead>";
        echo "<tbody>";
        
        while ($row = $pending_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . ucfirst($row['item_type']) . "</td>";
            echo "<td>#{$row['id']}</td>";
            echo "<td>" . htmlspecialchars($row['donor_name']) . "</td>";
            echo "<td>£" . number_format($row['amount'], 2) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['approved_at'])) . "</td>";
            echo "</tr>";
        }
        
        echo "</tbody></table>";
        
        echo "<div class='mt-3'>";
        echo "<a href='admin/approvals/background_processor.php' class='btn btn-warning'>";
        echo "<i class='fas fa-cogs'></i> Run Background Processor to Fix These";
        echo "</a>";
        echo "</div>";
        
    } else {
        echo "<div class='alert alert-success'>";
        echo "<p>✅ <strong>Excellent!</strong> All approved items have grid allocation.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Error</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grid Allocation Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="container mt-4">
    <!-- Content is echoed above -->
    
    <div class="mt-4">
        <a href="admin/approvals/" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Back to Approvals
        </a>
        <button class="btn btn-success" onclick="window.location.reload()">
            <i class="fas fa-redo"></i> Refresh Test
        </button>
    </div>
    
    <div class="mt-4 alert alert-info">
        <h5><i class="fas fa-info-circle"></i> How to Test</h5>
        <ol>
            <li>Go to the approval page and approve some test donations</li>
            <li>Come back here and refresh to see if they have grid allocation</li>
            <li>Check the projector floor map to see if the cells are filled</li>
            <li>If any items are missing grid allocation, run the background processor</li>
        </ol>
    </div>
</body>
</html>
