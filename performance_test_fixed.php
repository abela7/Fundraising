<?php
// Fixed performance test with better error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once 'config/db.php';
    require_once 'shared/csrf.php';
} catch (Exception $e) {
    die("Failed to load required files: " . $e->getMessage());
}

// Get current user counts for baseline
try {
    $db = db();
    $baseline = [
        'pledges' => $db->query("SELECT COUNT(*) as count FROM pledges")->fetch_assoc()['count'],
        'payments' => $db->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'],
        'users' => $db->query("SELECT COUNT(*) as count FROM users WHERE role='registrar'")->fetch_assoc()['count']
    ];
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$testResults = [];
$error = '';
$success = '';

// Simple registrar creation
function createTestRegistrars($db) {
    $registrars = [];
    
    for ($i = 1; $i <= 5; $i++) {
        $name = "Test Registrar $i";
        $phone = "0700000000$i";
        $email = "registrar$i@test.com";
        $code = "12345$i";
        
        try {
            // Check if registrar exists
            $check = $db->prepare("SELECT id FROM users WHERE phone = ?");
            $check->bind_param('s', $phone);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            
            if (!$result) {
                // Create registrar
                $hash = password_hash($code, PASSWORD_DEFAULT);
                $insert = $db->prepare("INSERT INTO users (name, phone, email, role, password_hash, active, created_at) VALUES (?, ?, ?, 'registrar', ?, 1, NOW())");
                $insert->bind_param('ssss', $name, $phone, $email, $hash);
                $insert->execute();
                $registrarId = $db->insert_id;
            } else {
                $registrarId = $result['id'];
            }
            
            $registrars[] = $registrarId;
            
        } catch (Exception $e) {
            error_log("Failed to create registrar $i: " . $e->getMessage());
            // Continue with other registrars
        }
    }
    
    return $registrars;
}

// Handle test execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_test'])) {
    try {
        verify_csrf();
        
        $startTime = microtime(true);
        $testResults = [
            'start_time' => date('Y-m-d H:i:s'),
            'registrars_created' => 0,
            'total_inserted' => 0,
            'pledges_inserted' => 0,
            'payments_inserted' => 0,
            'errors' => [],
            'connection_stats' => [],
            'timing' => []
        ];
        
        // Step 1: Create test registrars
        $timingStart = microtime(true);
        $registrars = createTestRegistrars($db);
        $testResults['registrars_created'] = count($registrars);
        $testResults['timing']['registrar_creation'] = round((microtime(true) - $timingStart) * 1000, 2);
        
        // Step 2: Insert test data
        $batchNumber = time();
        
        foreach ($registrars as $index => $registrarId) {
            $timingStart = microtime(true);
            
            try {
                // Generate 100 records per registrar
                for ($i = 1; $i <= 100; $i++) {
                    $name = "Test User " . ($index + 1) . "-$i";
                    $phone = "070" . str_pad(($index * 100 + $i), 7, '0', STR_PAD_LEFT);
                    $uuid = uniqid("test_{$registrarId}_{$batchNumber}_$i", true);
                    
                    // Realistic distribution
                    if ($i <= 25) {
                        $amount = 400; $type = 'pledge';
                    } elseif ($i <= 50) {
                        $amount = 200; $type = 'pledge';
                    } elseif ($i <= 75) {
                        $amount = 100; $type = 'pledge';
                    } elseif ($i <= 90) {
                        $amount = rand(25, 99); $type = 'pledge';
                    } else {
                        $amount = rand(100, 400); $type = rand(0, 1) ? 'pledge' : 'paid';
                    }
                    
                    $db->begin_transaction();
                    
                    try {
                        if ($type === 'paid') {
                            $stmt = $db->prepare("
                                INSERT INTO payments (donor_name, donor_phone, amount, method, status, reference, received_by_user_id, received_at)
                                VALUES (?, ?, ?, 'cash', 'pending', 'Performance test payment', ?, NOW())
                            ");
                            $stmt->bind_param('ssdi', $name, $phone, $amount, $registrarId);
                            $stmt->execute();
                            $testResults['payments_inserted']++;
                        } else {
                            $stmt = $db->prepare("
                                INSERT INTO pledges (donor_name, donor_phone, source, anonymous, amount, type, status, notes, client_uuid, created_by_user_id)
                                VALUES (?, ?, 'volunteer', 0, ?, 'pledge', 'pending', 'Performance test pledge', ?, ?)
                            ");
                            $stmt->bind_param('sdssi', $name, $phone, $amount, $uuid, $registrarId);
                            $stmt->execute();
                            $testResults['pledges_inserted']++;
                        }
                        
                        $db->commit();
                        $testResults['total_inserted']++;
                        
                        // Get connection stats every 50 records
                        if ($i % 50 === 0) {
                            try {
                                $status = db_status();
                                $testResults['connection_stats'][] = [
                                    'registrar' => $index + 1,
                                    'record' => $i,
                                    'thread_id' => $status['thread_id'] ?? 'unknown',
                                    'threads_connected' => $status['threads_connected'] ?? 'unknown',
                                    'timestamp' => date('H:i:s')
                                ];
                            } catch (Exception $statusError) {
                                // Ignore status errors
                            }
                        }
                        
                        // Small delay every 10 records
                        if ($i % 10 === 0) {
                            usleep(50000); // 0.05 second
                        }
                        
                    } catch (Exception $e) {
                        $db->rollback();
                        $testResults['errors'][] = "Registrar " . ($index + 1) . ", Record $i: " . $e->getMessage();
                        
                        // Continue with next record
                        continue;
                    }
                }
                
                $testResults['timing']["registrar_" . ($index + 1)] = round((microtime(true) - $timingStart) * 1000, 2);
                
            } catch (Exception $e) {
                $testResults['errors'][] = "Registrar " . ($index + 1) . " fatal error: " . $e->getMessage();
            }
        }
        
        $totalTime = microtime(true) - $startTime;
        $testResults['total_time'] = round($totalTime * 1000, 2);
        $testResults['end_time'] = date('Y-m-d H:i:s');
        $testResults['average_per_record'] = $testResults['total_inserted'] > 0 ? round(($totalTime / $testResults['total_inserted']) * 1000, 2) : 0;
        
        $success = "Performance test completed! Inserted {$testResults['total_inserted']}/500 records.";
        
    } catch (Exception $e) {
        $error = "Test failed: " . $e->getMessage();
        $testResults['fatal_error'] = $e->getMessage();
    }
}

// Handle cleanup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_test'])) {
    try {
        verify_csrf();
        
        $deleted = [];
        
        // Delete test pledges
        $result = $db->query("DELETE FROM pledges WHERE notes LIKE '%Performance test%' OR client_uuid LIKE 'test_%'");
        $deleted['pledges'] = $db->affected_rows;
        
        // Delete test payments
        $result = $db->query("DELETE FROM payments WHERE reference LIKE '%Performance test%'");
        $deleted['payments'] = $db->affected_rows;
        
        // Delete test registrars
        $result = $db->query("DELETE FROM users WHERE email LIKE '%@test.com' AND role='registrar'");
        $deleted['registrars'] = $db->affected_rows;
        
        $success = "Cleanup completed: Deleted {$deleted['pledges']} pledges, {$deleted['payments']} payments, {$deleted['registrars']} test registrars.";
        $testResults = [];
        
    } catch (Exception $e) {
        $error = "Cleanup failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fixed Performance Test - 500 Registrations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .test-card { border-left: 4px solid #007bff; }
        .results-card { border-left: 4px solid #28a745; }
    </style>
</head>
<body class="bg-light">
    <div class="container my-5">
        <h1 class="text-center mb-4">
            <i class="fas fa-database me-2"></i>
            Fixed Performance Test - 500 Registrations
        </h1>
        
        <!-- Baseline Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5>Current Pledges</h5>
                        <h2 class="text-primary"><?php echo $baseline['pledges']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5>Current Payments</h5>
                        <h2 class="text-success"><?php echo $baseline['payments']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5>Registrars</h5>
                        <h2 class="text-info"><?php echo $baseline['users']; ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Test Configuration -->
        <div class="card test-card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-cog me-2"></i>Test Configuration</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Test Scenario:</h6>
                        <ul>
                            <li><strong>5 Registrars</strong> each creating 100 registrations</li>
                            <li><strong>Total: 500 registrations</strong></li>
                            <li>Realistic data distribution</li>
                            <li>Tests database connection improvements</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Distribution per Registrar (100 records each):</h6>
                        <ul>
                            <li>25 × £400 pledges</li>
                            <li>25 × £200 pledges</li>
                            <li>25 × £100 pledges</li>
                            <li>15 × Custom amounts under £100</li>
                            <li>10 × Custom amounts £100-£400 (mixed)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Test Controls -->
        <div class="card mb-4">
            <div class="card-body text-center">
                <form method="POST" style="display: inline-block; margin-right: 10px;">
                    <?php echo csrf_input(); ?>
                    <button type="submit" name="run_test" class="btn btn-primary btn-lg">
                        <i class="fas fa-play me-2"></i>
                        Run Performance Test (500 Records)
                    </button>
                </form>
                
                <form method="POST" style="display: inline-block;">
                    <?php echo csrf_input(); ?>
                    <button type="submit" name="cleanup_test" class="btn btn-outline-danger">
                        <i class="fas fa-trash me-2"></i>
                        Cleanup Test Data
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>
        
        <!-- Test Results -->
        <?php if (!empty($testResults) && !isset($testResults['fatal_error'])): ?>
        <div class="card results-card">
            <div class="card-header">
                <h5><i class="fas fa-chart-line me-2"></i>Test Results</h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-success"><?php echo $testResults['total_inserted']; ?></h4>
                            <small>Total Records Inserted</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-primary"><?php echo $testResults['pledges_inserted']; ?></h4>
                            <small>Pledges</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-info"><?php echo $testResults['payments_inserted']; ?></h4>
                            <small>Payments</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-warning"><?php echo count($testResults['errors']); ?></h4>
                            <small>Errors</small>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="text-center">
                            <span class="badge bg-primary fs-6"><?php echo $testResults['total_time']; ?>ms</span><br>
                            <small>Total Time</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <span class="badge bg-success fs-6"><?php echo $testResults['average_per_record']; ?>ms</span><br>
                            <small>Average per Record</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <?php $recordsPerSec = $testResults['average_per_record'] > 0 ? round(1000 / $testResults['average_per_record'], 1) : 0; ?>
                            <span class="badge bg-info fs-6"><?php echo $recordsPerSec; ?></span><br>
                            <small>Records per Second</small>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Analysis -->
                <?php 
                $successRate = ($testResults['total_inserted'] / 500) * 100;
                ?>
                <div class="alert alert-info">
                    <h6>Performance Analysis:</h6>
                    <ul class="mb-0">
                        <li><strong>Success Rate:</strong> <?php echo round($successRate, 1); ?>% (<?php echo $testResults['total_inserted']; ?>/500 records)</li>
                        <li><strong>Processing Speed:</strong> <?php echo $recordsPerSec; ?> records/second</li>
                        <li><strong>Error Rate:</strong> <?php echo round((count($testResults['errors']) / 500) * 100, 1); ?>%</li>
                        <li><strong>Performance Rating:</strong> 
                            <?php if ($successRate >= 95): ?>
                                <span class="badge bg-success">Excellent</span>
                            <?php elseif ($successRate >= 85): ?>
                                <span class="badge bg-primary">Good</span>
                            <?php elseif ($successRate >= 70): ?>
                                <span class="badge bg-warning">Fair</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Poor</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                
                <!-- Connection Statistics -->
                <?php if (!empty($testResults['connection_stats'])): ?>
                <h6>Connection Statistics Sample:</h6>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Registrar</th>
                            <th>Record</th>
                            <th>Thread ID</th>
                            <th>Active Connections</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($testResults['connection_stats'], 0, 10) as $stat): ?>
                        <tr>
                            <td><?php echo $stat['registrar']; ?></td>
                            <td><?php echo $stat['record']; ?></td>
                            <td><?php echo $stat['thread_id']; ?></td>
                            <td><?php echo $stat['threads_connected']; ?></td>
                            <td><?php echo $stat['timestamp']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <!-- Errors -->
                <?php if (!empty($testResults['errors'])): ?>
                <h6 class="text-danger">Errors (showing first 10):</h6>
                <ul class="list-group">
                    <?php foreach (array_slice($testResults['errors'], 0, 10) as $errorMsg): ?>
                    <li class="list-group-item list-group-item-danger">
                        <?php echo htmlspecialchars($errorMsg); ?>
                    </li>
                    <?php endforeach; ?>
                    <?php if (count($testResults['errors']) > 10): ?>
                    <li class="list-group-item">... and <?php echo count($testResults['errors']) - 10; ?> more errors</li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-primary">
                <i class="fas fa-home me-2"></i>Back to Home
            </a>
        </div>
    </div>
</body>
</html>
