<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';

/**
 * Background Grid Allocation Processor
 * 
 * This script processes grid allocations for approved pledges/payments
 * without blocking the approval UI. Run this via cron job or manual trigger.
 */

// Prevent browser timeout for long-running operations
set_time_limit(300); // 5 minutes max
ini_set('memory_limit', '256M');

$db = db();
$processed = 0;
$errors = 0;
$results = [];

try {
    echo "<h2>🔄 Background Grid Allocation Processor</h2>";
    echo "<p>Processing approved items that need grid allocation...</p>";
    flush();
    
    // Find approved pledges/payments without grid allocation
    $sql = "
        SELECT 
            p.id, p.amount, p.type, p.donor_name, p.package_id,
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
            pay.id, pay.amount, 'paid' as type, pay.donor_name, pay.package_id,
            'payment' as item_type
        FROM payments pay 
        WHERE pay.status = 'approved' 
        AND pay.id NOT IN (
            SELECT DISTINCT payment_id 
            FROM floor_area_allocations 
            WHERE payment_id IS NOT NULL
        )
        
        ORDER BY amount DESC
        LIMIT 50
    ";
    
    $result = $db->query($sql);
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    
    if (empty($items)) {
        echo "<div class='alert alert-success'>✅ All approved items already have grid allocation!</div>";
        echo "<p>No background processing needed.</p>";
        exit;
    }
    
    echo "<p>Found <strong>" . count($items) . "</strong> items needing allocation...</p>";
    echo "<div id='progress' style='background: #f0f0f0; padding: 10px; margin: 10px 0;'>";
    echo "<div id='progress-bar' style='background: #ddd; height: 20px;'>";
    echo "<div id='progress-fill' style='background: #28a745; height: 100%; width: 0%; transition: width 0.3s;'></div>";
    echo "</div>";
    echo "<p id='progress-text'>Starting...</p>";
    echo "</div>";
    flush();
    
    foreach ($items as $index => $item) {
        $itemId = (int)$item['id'];
        $amount = (float)$item['amount'];
        $donorName = (string)$item['donor_name'];
        $packageId = isset($item['package_id']) ? (int)$item['package_id'] : null;
        $itemType = $item['item_type'];
        $type = $item['type'];
        
        $progress = (($index + 1) / count($items)) * 100;
        echo "<script>
            document.getElementById('progress-fill').style.width = '{$progress}%';
            document.getElementById('progress-text').textContent = 'Processing item " . ($index + 1) . " of " . count($items) . ": {$donorName} (£{$amount})';
        </script>";
        flush();
        
        try {
            $db->begin_transaction();
            
            // Use CustomAmountAllocator for better performance
            $allocator = new CustomAmountAllocator($db);
            
            if ($itemType === 'payment') {
                $result = $allocator->processPaymentCustomAmount(
                    $itemId,
                    $amount,
                    $donorName,
                    'paid'
                );
            } else {
                $status = ($type === 'paid') ? 'paid' : 'pledged';
                $result = $allocator->processCustomAmount(
                    $itemId,
                    $amount,
                    $donorName,
                    $status
                );
            }
            
            if ($result['success']) {
                $processed++;
                $results[] = [
                    'id' => $itemId,
                    'type' => $itemType,
                    'donor' => $donorName,
                    'amount' => $amount,
                    'status' => 'success',
                    'message' => $result['message'] ?? 'Allocated successfully'
                ];
                
                $db->commit();
                echo "<script>console.log('✅ Processed {$itemType} #{$itemId}: {$donorName}');</script>";
            } else {
                $errors++;
                $results[] = [
                    'id' => $itemId,
                    'type' => $itemType,
                    'donor' => $donorName,
                    'amount' => $amount,
                    'status' => 'error',
                    'message' => $result['error'] ?? 'Allocation failed'
                ];
                
                $db->rollback();
                echo "<script>console.log('❌ Failed {$itemType} #{$itemId}: " . ($result['error'] ?? 'Unknown error') . "');</script>";
            }
            
        } catch (Exception $e) {
            $db->rollback();
            $errors++;
            $results[] = [
                'id' => $itemId,
                'type' => $itemType,
                'donor' => $donorName,
                'amount' => $amount,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            
            echo "<script>console.log('❌ Exception for {$itemType} #{$itemId}: " . $e->getMessage() . "');</script>";
        }
        
        // Small delay to prevent overwhelming the system
        usleep(100000); // 0.1 second delay
    }
    
    echo "<script>
        document.getElementById('progress-fill').style.width = '100%';
        document.getElementById('progress-text').textContent = 'Processing complete!';
    </script>";
    
    echo "<h3>📊 Processing Summary</h3>";
    echo "<div class='row'>";
    echo "<div class='col-md-4'><div class='alert alert-success'>✅ <strong>{$processed}</strong> successfully processed</div></div>";
    echo "<div class='col-md-4'><div class='alert alert-danger'>❌ <strong>{$errors}</strong> errors encountered</div></div>";
    echo "<div class='col-md-4'><div class='alert alert-info'>📋 <strong>" . count($items) . "</strong> total items</div></div>";
    echo "</div>";
    
    if (!empty($results)) {
        echo "<h4>📋 Detailed Results</h4>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-sm'>";
        echo "<thead><tr><th>ID</th><th>Type</th><th>Donor</th><th>Amount</th><th>Status</th><th>Message</th></tr></thead>";
        echo "<tbody>";
        
        foreach ($results as $result) {
            $statusClass = $result['status'] === 'success' ? 'success' : 'danger';
            $statusIcon = $result['status'] === 'success' ? '✅' : '❌';
            echo "<tr class='table-{$statusClass}'>";
            echo "<td>#{$result['id']}</td>";
            echo "<td>" . ucfirst($result['type']) . "</td>";
            echo "<td>" . htmlspecialchars($result['donor']) . "</td>";
            echo "<td>£" . number_format($result['amount'], 2) . "</td>";
            echo "<td>{$statusIcon} " . ucfirst($result['status']) . "</td>";
            echo "<td>" . htmlspecialchars($result['message']) . "</td>";
            echo "</tr>";
        }
        
        echo "</tbody></table>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Fatal Error</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Background Grid Allocation Processor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="container mt-4">
    <!-- Content is echoed above -->
    
    <div class="mt-4">
        <a href="index.php" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Back to Approvals
        </a>
        <button class="btn btn-success" onclick="window.location.reload()">
            <i class="fas fa-redo"></i> Run Again
        </button>
    </div>
    
    <div class="mt-4 alert alert-info">
        <h5><i class="fas fa-info-circle"></i> About Background Processing</h5>
        <p>This processor handles the heavy grid allocation operations that were moved out of the approval flow to improve performance. It should be run:</p>
        <ul>
            <li>Manually when needed (like now)</li>
            <li>Via cron job every few minutes: <code>*/5 * * * * php background_processor.php</code></li>
            <li>After bulk approvals to catch up on allocations</li>
        </ul>
    </div>
</body>
</html>
