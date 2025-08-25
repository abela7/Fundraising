<?php
declare(strict_types=1);

// Simple, working performance test
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'config/db.php';

$message = '';
$results = null;

// Generate test data
function createTestRecords($count = 100) {
    $db = db();
    
    // Get registrars
    $registrars = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY id LIMIT 5")->fetch_all(MYSQLI_ASSOC);
    
    if (count($registrars) < 1) {
        throw new Exception("No registrars found");
    }
    
    $names = ['Abel Tadesse', 'Sara Bekele', 'David Haile', 'Meron Desta', 'Samuel Girma'];
    $records = [];
    
    for ($i = 0; $i < $count; $i++) {
        $records[] = [
            'name' => $names[array_rand($names)],
            'phone' => '074' . str_pad((string)rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'amount' => [400.00, 200.00, 100.00][array_rand([400.00, 200.00, 100.00])],
            'registrar_id' => $registrars[array_rand($registrars)]['id']
        ];
    }
    
    return $records;
}

// Insert records with performance tracking
function insertRecords($records) {
    $db = db();
    $startTime = microtime(true);
    $successful = 0;
    $failed = 0;
    $errors = [];
    
    foreach ($records as $record) {
        try {
            $stmt = $db->prepare("
                INSERT INTO pledges (
                    donor_name, donor_phone, donor_email, source, anonymous,
                    amount, type, status, notes, client_uuid, created_by_user_id, package_id
                ) VALUES (?, ?, NULL, 'volunteer', 0, ?, 'pledge', 'pending', 'Performance test', ?, ?, 1)
            ");
            
            $uuid = uniqid('test_', true);
            $stmt->bind_param('sdssi', 
                $record['name'], 
                $record['phone'], 
                $record['amount'], 
                $uuid, 
                $record['registrar_id']
            );
            
            if ($stmt->execute()) {
                $successful++;
            } else {
                $failed++;
                $errors[] = $stmt->error;
            }
            
        } catch (Exception $e) {
            $failed++;
            $errors[] = $e->getMessage();
        }
    }
    
    $endTime = microtime(true);
    $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds
    
    return [
        'total' => count($records),
        'successful' => $successful,
        'failed' => $failed,
        'duration_ms' => round($duration, 2),
        'records_per_second' => round($successful / ($duration / 1000), 2),
        'errors' => array_slice($errors, 0, 5) // Show only first 5 errors
    ];
}

// Clean up test records
function cleanupTest() {
    $db = db();
    $result = $db->query("DELETE FROM pledges WHERE notes = 'Performance test'");
    return $result ? $db->affected_rows : 0;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_100') {
        try {
            $records = createTestRecords(100);
            $results = insertRecords($records);
            $message = "100-record test completed!";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'test_500') {
        try {
            $records = createTestRecords(500);
            $results = insertRecords($records);
            $message = "500-record test completed!";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    } elseif ($action === 'cleanup') {
        $deleted = cleanupTest();
        $message = "Cleaned up $deleted test records";
    }
}

// Get current database status
try {
    $db = db();
    $status = db_status();
    $pledgeCount = $db->query("SELECT COUNT(*) as count FROM pledges")->fetch_assoc()['count'];
} catch (Exception $e) {
    $status = ['connected' => false, 'error' => $e->getMessage()];
    $pledgeCount = 'Error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Performance Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container py-4">
        <h1 class="text-center mb-4">Simple Performance Test</h1>
        
        <!-- Database Status -->
        <div class="alert <?php echo $status['connected'] ? 'alert-success' : 'alert-danger'; ?>">
            <strong>Database:</strong> 
            <?php echo $status['connected'] ? '✅ Connected' : '❌ ' . ($status['error'] ?? 'Disconnected'); ?>
            <?php if ($status['connected']): ?>
                | Server: <?php echo $status['server_info'] ?? 'Unknown'; ?>
                | Active Connections: <?php echo $status['threads_connected'] ?? 'Unknown'; ?>
                | Total Pledges: <?php echo $pledgeCount; ?>
            <?php endif; ?>
        </div>
        
        <!-- Message -->
        <?php if ($message): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <!-- Test Controls -->
        <div class="row mb-4">
            <div class="col-md-4">
                <form method="POST" class="mb-2">
                    <input type="hidden" name="action" value="test_100">
                    <button type="submit" class="btn btn-primary w-100">Test 100 Records</button>
                </form>
            </div>
            <div class="col-md-4">
                <form method="POST" class="mb-2">
                    <input type="hidden" name="action" value="test_500">
                    <button type="submit" class="btn btn-success w-100">Test 500 Records</button>
                </form>
            </div>
            <div class="col-md-4">
                <form method="POST">
                    <input type="hidden" name="action" value="cleanup">
                    <button type="submit" class="btn btn-warning w-100">Clean Up Test Data</button>
                </form>
            </div>
        </div>
        
        <!-- Results -->
        <?php if ($results): ?>
        <div class="row">
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $results['successful']; ?></h3>
                    <p class="mb-0">Successful</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $results['failed']; ?></h3>
                    <p class="mb-0">Failed</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $results['duration_ms']; ?>ms</h3>
                    <p class="mb-0">Duration</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $results['records_per_second']; ?></h3>
                    <p class="mb-0">Records/Sec</p>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h5>Test Results Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Success Rate:</strong> 
                        <?php 
                        $successRate = ($results['successful'] / $results['total']) * 100;
                        $badgeClass = $successRate >= 95 ? 'success' : ($successRate >= 80 ? 'warning' : 'danger');
                        ?>
                        <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo round($successRate, 1); ?>%</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Performance:</strong>
                        <?php if ($results['records_per_second'] >= 20): ?>
                            <span class="badge bg-success">Excellent</span>
                        <?php elseif ($results['records_per_second'] >= 10): ?>
                            <span class="badge bg-warning">Good</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Slow</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($results['errors'])): ?>
                <div class="mt-3">
                    <strong>Errors:</strong>
                    <ul class="mt-2">
                        <?php foreach ($results['errors'] as $error): ?>
                        <li class="text-danger"><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="alert alert-info mt-4">
            <h5>How to Interpret Results:</h5>
            <ul class="mb-0">
                <li><strong>Success Rate ≥95%:</strong> Excellent - System handles load well</li>
                <li><strong>Success Rate 80-94%:</strong> Good - Minor issues under load</li>
                <li><strong>Success Rate &lt;80%:</strong> Poor - System struggling with load</li>
                <li><strong>Speed ≥20 records/sec:</strong> Excellent performance</li>
                <li><strong>Speed 10-19 records/sec:</strong> Good performance</li>
                <li><strong>Speed &lt;10 records/sec:</strong> Slow - needs optimization</li>
            </ul>
        </div>
    </div>
</body>
</html>
