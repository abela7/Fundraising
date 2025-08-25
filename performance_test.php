<?php
require_once 'config/db.php';
require_once 'shared/csrf.php';

// Get current user counts for baseline
$db = db();
$baseline = [
    'pledges' => $db->query("SELECT COUNT(*) as count FROM pledges")->fetch_assoc()['count'],
    'payments' => $db->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'],
    'users' => $db->query("SELECT COUNT(*) as count FROM users WHERE role='registrar'")->fetch_assoc()['count']
];

$testResults = [];
$error = '';
$success = '';

// Create test registrar users if they don't exist
function createTestRegistrars($db) {
    $registrars = [];
    
    for ($i = 1; $i <= 5; $i++) {
        $name = "Test Registrar $i";
        $phone = "0700000000$i";
        $email = "registrar$i@test.com";
        $code = "12345$i";
        
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
    }
    
    return $registrars;
}

// Generate realistic test data
function generateTestData($registrarId, $batchNumber) {
    $firstNames = ['Ahmed', 'Fatima', 'Mohamed', 'Aisha', 'Omar', 'Khadija', 'Ali', 'Maryam', 'Hassan', 'Zara', 'Ibrahim', 'Layla', 'Yusuf', 'Amina', 'Adam', 'Safiya', 'Khalil', 'Nour', 'Saeed', 'Hiba'];
    $lastNames = ['Abdullah', 'Al-Mahmoud', 'Al-Hassan', 'Al-Zahra', 'Al-Rashid', 'Al-Nouri', 'Al-Faraj', 'Al-Mansouri', 'Al-Khouri', 'Al-Qasemi', 'Al-Shamsi', 'Al-Kaabi', 'Al-Marzouqi', 'Al-Dhaheri', 'Al-Mansoori'];
    
    $data = [];
    
    // Generate 100 registrations per registrar with realistic distribution
    for ($i = 1; $i <= 100; $i++) {
        $firstName = $firstNames[array_rand($firstNames)];
        $lastName = $lastNames[array_rand($lastNames)];
        $name = "$firstName $lastName";
        $phone = "070" . str_pad(rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
        $uuid = uniqid("test_$registrarId" . "_$batchNumber" . "_$i" . "_", true);
        
        // Realistic distribution as requested
        if ($i <= 25) {
            // 25 × £400 (1m²)
            $amount = 400;
            $package = '1';
            $type = 'pledge';
        } elseif ($i <= 50) {
            // 25 × £200 (0.5m²)
            $amount = 200;
            $package = '0.5';
            $type = 'pledge';
        } elseif ($i <= 75) {
            // 25 × £100 (0.25m²)
            $amount = 100;
            $package = '0.25';
            $type = 'pledge';
        } elseif ($i <= 90) {
            // 15 custom amounts under £100
            $amount = rand(25, 99);
            $package = 'custom';
            $type = 'pledge';
        } else {
            // 10 custom amounts between £100-£400
            $amount = rand(100, 400);
            $package = 'custom';
            $type = rand(0, 1) ? 'pledge' : 'paid'; // Mix of pledges and payments
        }
        
        $data[] = [
            'name' => $name,
            'phone' => $phone,
            'amount' => $amount,
            'package' => $package,
            'type' => $type,
            'uuid' => $uuid,
            'registrar_id' => $registrarId,
            'notes' => $type === 'paid' ? 'Paid via Cash.' : 'Performance test pledge'
        ];
    }
    
    return $data;
}

// Handle test execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_test'])) {
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
    
    try {
        // Step 1: Create test registrars
        $timingStart = microtime(true);
        $registrars = createTestRegistrars($db);
        $testResults['registrars_created'] = count($registrars);
        $testResults['timing']['registrar_creation'] = round((microtime(true) - $timingStart) * 1000, 2);
        
        // Step 2: Generate and insert test data for each registrar
        $batchNumber = time(); // Unique batch identifier
        
        foreach ($registrars as $index => $registrarId) {
            $timingStart = microtime(true);
            
            try {
                $testData = generateTestData($registrarId, $batchNumber);
                
                // Insert data in smaller batches to test connection management
                $batchSize = 20; // Insert 20 at a time to simulate concurrent users
                $batches = array_chunk($testData, $batchSize);
                
                foreach ($batches as $batchIndex => $batch) {
                    $db->begin_transaction();
                    
                    try {
                        foreach ($batch as $record) {
                            if ($record['type'] === 'paid') {
                                // Insert as payment
                                $stmt = $db->prepare("
                                    INSERT INTO payments (donor_name, donor_phone, amount, method, status, reference, received_by_user_id, received_at, package_id)
                                    VALUES (?, ?, ?, 'cash', 'pending', ?, ?, NOW(), NULL)
                                ");
                                $stmt->bind_param('ssdsi', $record['name'], $record['phone'], $record['amount'], $record['notes'], $record['registrar_id']);
                                $stmt->execute();
                                $testResults['payments_inserted']++;
                            } else {
                                // Insert as pledge
                                $stmt = $db->prepare("
                                    INSERT INTO pledges (donor_name, donor_phone, source, anonymous, amount, type, status, notes, client_uuid, created_by_user_id, package_id)
                                    VALUES (?, ?, 'volunteer', 0, ?, 'pledge', 'pending', ?, ?, ?, NULL)
                                ");
                                $stmt->bind_param('sdssi', $record['name'], $record['phone'], $record['amount'], $record['notes'], $record['uuid'], $record['registrar_id']);
                                $stmt->execute();
                                $testResults['pledges_inserted']++;
                            }
                            $testResults['total_inserted']++;
                        }
                        
                        $db->commit();
                        
                        // Get connection status after each batch
                        $status = db_status();
                        $testResults['connection_stats'][] = [
                            'registrar' => $index + 1,
                            'batch' => $batchIndex + 1,
                            'thread_id' => $status['thread_id'] ?? 'unknown',
                            'threads_connected' => $status['threads_connected'] ?? 'unknown',
                            'timestamp' => date('H:i:s')
                        ];
                        
                        // Small delay to simulate real-world timing
                        usleep(100000); // 0.1 second
                        
                    } catch (Exception $e) {
                        $db->rollback();
                        $testResults['errors'][] = "Batch error for Registrar " . ($index + 1) . ", Batch " . ($batchIndex + 1) . ": " . $e->getMessage();
                    }
                }
                
                $testResults['timing']["registrar_" . ($index + 1)] = round((microtime(true) - $timingStart) * 1000, 2);
                
            } catch (Exception $e) {
                $testResults['errors'][] = "Registrar " . ($index + 1) . " error: " . $e->getMessage();
            }
        }
        
        $totalTime = microtime(true) - $startTime;
        $testResults['total_time'] = round($totalTime * 1000, 2);
        $testResults['end_time'] = date('Y-m-d H:i:s');
        $testResults['average_per_record'] = round(($totalTime / max(1, $testResults['total_inserted'])) * 1000, 2);
        
        $success = "Performance test completed successfully!";
        
    } catch (Exception $e) {
        $error = "Test failed: " . $e->getMessage();
        $testResults['fatal_error'] = $e->getMessage();
    }
}

// Handle cleanup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_test'])) {
    verify_csrf();
    
    try {
        // Delete test data
        $deleted = [];
        
        // Delete test pledges
        $result = $db->query("DELETE FROM pledges WHERE notes LIKE '%Performance test%' OR client_uuid LIKE 'test_%'");
        $deleted['pledges'] = $db->affected_rows;
        
        // Delete test payments
        $result = $db->query("DELETE FROM payments WHERE reference LIKE '%Paid via Cash.%' AND received_by_user_id IN (SELECT id FROM users WHERE email LIKE '%@test.com')");
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
    <title>Database Performance Test - 500 Registrations</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .test-card { border-left: 4px solid #007bff; }
        .results-card { border-left: 4px solid #28a745; }
        .error-card { border-left: 4px solid #dc3545; }
        .connection-stats { font-family: monospace; font-size: 0.9em; }
        .timing-badge { font-family: monospace; }
    </style>
</head>
<body class="bg-light">
    <div class="container my-5">
        <div class="row">
            <div class="col-12">
                <h1 class="text-center mb-4">
                    <i class="fas fa-database me-2"></i>
                    Database Performance Test
                </h1>
                <p class="text-center text-muted">Test improved connection management with 500 realistic registrations</p>
            </div>
        </div>
        
        <!-- Baseline Stats -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Current Pledges</h5>
                        <h2 class="text-primary"><?php echo $baseline['pledges']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Current Payments</h5>
                        <h2 class="text-success"><?php echo $baseline['payments']; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Registrar Users</h5>
                        <h2 class="text-info"><?php echo $baseline['users']; ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Test Configuration -->
        <div class="card test-card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-cog me-2"></i>
                    Test Configuration
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Test Scenario:</h6>
                        <ul>
                            <li><strong>5 Registrars</strong> each creating 100 registrations</li>
                            <li><strong>Total: 500 registrations</strong></li>
                            <li>Realistic data distribution per registrar</li>
                            <li>Mixed pledges and payments</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Distribution per Registrar (100 records each):</h6>
                        <ul>
                            <li>25 × £400 (1m² pledges)</li>
                            <li>25 × £200 (0.5m² pledges)</li>
                            <li>25 × £100 (0.25m² pledges)</li>
                            <li>15 × Custom amounts under £100</li>
                            <li>10 × Custom amounts £100-£400 (mixed types)</li>
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
                <h5 class="mb-0">
                    <i class="fas fa-chart-line me-2"></i>
                    Test Results
                </h5>
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
                            <small>Pledges Created</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h4 class="text-info"><?php echo $testResults['payments_inserted']; ?></h4>
                            <small>Payments Created</small>
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
                            <span class="badge bg-primary timing-badge fs-6"><?php echo $testResults['total_time']; ?>ms</span><br>
                            <small>Total Time</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <span class="badge bg-success timing-badge fs-6"><?php echo $testResults['average_per_record']; ?>ms</span><br>
                            <small>Average per Record</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <span class="badge bg-info timing-badge fs-6"><?php echo round(1000 / $testResults['average_per_record'], 1); ?></span><br>
                            <small>Records per Second</small>
                        </div>
                    </div>
                </div>
                
                <!-- Timing Breakdown -->
                <h6>Performance by Registrar:</h6>
                <div class="row">
                    <?php foreach ($testResults['timing'] as $key => $time): ?>
                        <?php if (strpos($key, 'registrar_') === 0): ?>
                        <div class="col-md-2 mb-2">
                            <div class="text-center">
                                <span class="badge bg-secondary"><?php echo $time; ?>ms</span><br>
                                <small><?php echo ucfirst(str_replace('_', ' ', $key)); ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <!-- Connection Statistics -->
                <?php if (!empty($testResults['connection_stats'])): ?>
                <h6 class="mt-4">Connection Statistics Sample:</h6>
                <div class="connection-stats">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Registrar</th>
                                <th>Batch</th>
                                <th>Thread ID</th>
                                <th>Active Connections</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($testResults['connection_stats'], 0, 10) as $stat): ?>
                            <tr>
                                <td><?php echo $stat['registrar']; ?></td>
                                <td><?php echo $stat['batch']; ?></td>
                                <td><?php echo $stat['thread_id']; ?></td>
                                <td><?php echo $stat['threads_connected']; ?></td>
                                <td><?php echo $stat['timestamp']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (count($testResults['connection_stats']) > 10): ?>
                    <small class="text-muted">Showing first 10 of <?php echo count($testResults['connection_stats']); ?> connection samples</small>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Errors -->
                <?php if (!empty($testResults['errors'])): ?>
                <h6 class="mt-4 text-danger">Errors:</h6>
                <ul class="list-group">
                    <?php foreach ($testResults['errors'] as $error): ?>
                    <li class="list-group-item list-group-item-danger">
                        <?php echo htmlspecialchars($error); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Performance Analysis -->
        <?php if (!empty($testResults) && !isset($testResults['fatal_error'])): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-analytics me-2"></i>
                    Performance Analysis
                </h5>
            </div>
            <div class="card-body">
                <?php
                $successRate = ($testResults['total_inserted'] / 500) * 100;
                $recordsPerSecond = round(1000 / $testResults['average_per_record'], 1);
                ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6>Success Metrics:</h6>
                        <ul>
                            <li><strong>Success Rate:</strong> <?php echo round($successRate, 1); ?>% (<?php echo $testResults['total_inserted']; ?>/500 records)</li>
                            <li><strong>Processing Speed:</strong> <?php echo $recordsPerSecond; ?> records/second</li>
                            <li><strong>Error Rate:</strong> <?php echo round((count($testResults['errors']) / 500) * 100, 1); ?>%</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Performance Rating:</h6>
                        <?php if ($successRate >= 95): ?>
                            <span class="badge bg-success fs-6">Excellent</span> - System handles load very well
                        <?php elseif ($successRate >= 85): ?>
                            <span class="badge bg-primary fs-6">Good</span> - System handles load adequately
                        <?php elseif ($successRate >= 70): ?>
                            <span class="badge bg-warning fs-6">Fair</span> - System shows some strain
                        <?php else: ?>
                            <span class="badge bg-danger fs-6">Poor</span> - System struggles with load
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-primary">
                <i class="fas fa-home me-2"></i>
                Back to Home
            </a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
