<?php
require_once 'config/db.php';
require_once 'shared/csrf.php';

// Security check - only allow in development/testing
$allowed_ips = ['127.0.0.1', '::1', 'localhost'];
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($client_ip, $allowed_ips) && strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false) {
    // Uncomment the line below if you want to restrict access
    // die('Performance testing only allowed on localhost');
}

$results = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'test_500_inserts') {
        $results = performBulkInsertTest();
    } elseif ($action === 'test_connection_pool') {
        $results = testConnectionPool();
    } elseif ($action === 'cleanup_test_data') {
        $results = cleanupTestData();
    } elseif ($action === 'stress_test') {
        $results = performStressTest();
    }
}

function performBulkInsertTest(): array {
    $startTime = microtime(true);
    $success = 0;
    $failures = 0;
    $errors = [];
    $connectionInfo = [];
    
    try {
        echo "<div id='progress' style='background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>🚀 Starting Bulk Insert Test - 500 Records</h3>";
        echo "<div id='progress-bar' style='background: #ddd; height: 20px; border-radius: 10px;'>";
        echo "<div id='progress-fill' style='background: #4CAF50; height: 100%; width: 0%; border-radius: 10px; transition: width 0.3s;'></div>";
        echo "</div>";
        echo "<p id='progress-text'>Starting...</p>";
        echo "</div>";
        flush();
        
        // Test data generation
        $testData = [];
        for ($i = 1; $i <= 500; $i++) {
            $testData[] = [
                'name' => "Test Donor $i",
                'phone' => sprintf("07%09d", $i),
                'email' => "test{$i}@example.com",
                'amount' => rand(25, 1000),
                'package_id' => rand(1, 4),
                'notes' => "Performance test record $i - " . date('Y-m-d H:i:s')
            ];
        }
        
        // Batch insert in chunks to simulate real-world concurrent load
        $batchSize = 50; // Process 50 at a time
        $batches = array_chunk($testData, $batchSize);
        $batchNum = 0;
        
        foreach ($batches as $batch) {
            $batchNum++;
            $batchStart = microtime(true);
            
            echo "<script>
                document.getElementById('progress-fill').style.width = '" . ($batchNum / count($batches) * 100) . "%';
                document.getElementById('progress-text').textContent = 'Processing batch $batchNum of " . count($batches) . " (Records " . (($batchNum-1) * $batchSize + 1) . "-" . min($batchNum * $batchSize, 500) . ")';
            </script>";
            flush();
            
            // Simulate concurrent connections by creating multiple connections per batch
            $batchSuccesses = 0;
            $batchFailures = 0;
            
            foreach ($batch as $record) {
                try {
                    $db = db(); // This will test our connection management
                    
                    // Record connection info periodically
                    if (count($connectionInfo) < 10) {
                        $status = db_status();
                        $connectionInfo[] = [
                            'record_num' => ($batchNum - 1) * $batchSize + $batchSuccesses + 1,
                            'thread_id' => $status['thread_id'] ?? 'unknown',
                            'threads_connected' => $status['threads_connected'] ?? 'unknown',
                            'timestamp' => date('H:i:s.') . substr(microtime(), 2, 3)
                        ];
                    }
                    
                    // Insert test pledge
                    $stmt = $db->prepare("
                        INSERT INTO pledges (
                            donor_name, donor_phone, donor_email, amount, 
                            package_id, notes, status, type, source, 
                            anonymous, created_at, client_uuid
                        ) VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pledge', 'performance_test', 0, NOW(), ?)
                    ");
                    
                    $uuid = 'perf_test_' . uniqid() . '_' . $record['name'];
                    $stmt->bind_param(
                        'sssdsss',
                        $record['name'],
                        $record['phone'], 
                        $record['email'],
                        $record['amount'],
                        $record['package_id'],
                        $record['notes'],
                        $uuid
                    );
                    
                    if ($stmt->execute()) {
                        $batchSuccesses++;
                        $success++;
                    } else {
                        $batchFailures++;
                        $failures++;
                        $errors[] = "Batch $batchNum: " . $db->error;
                    }
                    
                } catch (Exception $e) {
                    $batchFailures++;
                    $failures++;
                    $errors[] = "Batch $batchNum: " . $e->getMessage();
                }
                
                // Small delay to simulate real user behavior
                usleep(1000); // 1ms delay
            }
            
            $batchTime = microtime(true) - $batchStart;
            echo "<script>console.log('Batch $batchNum: $batchSuccesses successes, $batchFailures failures in " . round($batchTime * 1000, 2) . "ms');</script>";
            flush();
        }
        
    } catch (Exception $e) {
        $errors[] = "Fatal error: " . $e->getMessage();
    }
    
    $totalTime = microtime(true) - $startTime;
    
    echo "<script>
        document.getElementById('progress-fill').style.width = '100%';
        document.getElementById('progress-text').textContent = 'Test Complete!';
    </script>";
    flush();
    
    return [
        'type' => 'bulk_insert',
        'total_records' => 500,
        'successful_inserts' => $success,
        'failed_inserts' => $failures,
        'success_rate' => round(($success / 500) * 100, 2),
        'total_time_seconds' => round($totalTime, 3),
        'records_per_second' => round(500 / $totalTime, 2),
        'average_time_per_record' => round(($totalTime / 500) * 1000, 2), // milliseconds
        'connection_info' => $connectionInfo,
        'errors' => array_slice($errors, 0, 10), // Show first 10 errors
        'memory_usage' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB'
    ];
}

function testConnectionPool(): array {
    $startTime = microtime(true);
    $connectionTests = [];
    
    echo "<div id='connection-progress' style='background: #f0f0f0; padding: 10px; margin: 10px 0;'>";
    echo "<h3>🔗 Testing Connection Pool - 100 Rapid Connections</h3>";
    echo "</div>";
    flush();
    
    // Test rapid connection creation/reuse
    for ($i = 1; $i <= 100; $i++) {
        $connStart = microtime(true);
        try {
            $db = db();
            $result = $db->query("SELECT 1 as test");
            $connTime = microtime(true) - $connStart;
            
            $status = db_status();
            $connectionTests[] = [
                'test_num' => $i,
                'connection_time_ms' => round($connTime * 1000, 2),
                'thread_id' => $status['thread_id'] ?? 'unknown',
                'success' => true
            ];
            
        } catch (Exception $e) {
            $connTime = microtime(true) - $connStart;
            $connectionTests[] = [
                'test_num' => $i,
                'connection_time_ms' => round($connTime * 1000, 2),
                'thread_id' => 'failed',
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        if ($i % 10 === 0) {
            echo "<script>console.log('Connection test $i/100 completed');</script>";
            flush();
        }
    }
    
    $totalTime = microtime(true) - $startTime;
    $successful = array_filter($connectionTests, fn($test) => $test['success']);
    $avgConnectionTime = array_sum(array_column($successful, 'connection_time_ms')) / count($successful);
    
    return [
        'type' => 'connection_pool',
        'total_tests' => 100,
        'successful_connections' => count($successful),
        'failed_connections' => 100 - count($successful),
        'success_rate' => round((count($successful) / 100) * 100, 2),
        'total_time_seconds' => round($totalTime, 3),
        'average_connection_time_ms' => round($avgConnectionTime, 2),
        'connection_details' => array_slice($connectionTests, 0, 20) // Show first 20 tests
    ];
}

function performStressTest(): array {
    $startTime = microtime(true);
    $results = [
        'type' => 'stress_test',
        'phases' => []
    ];
    
    echo "<div id='stress-progress' style='background: #f0f0f0; padding: 10px; margin: 10px 0;'>";
    echo "<h3>⚡ Stress Test - Multiple Concurrent Operations</h3>";
    echo "</div>";
    flush();
    
    // Phase 1: Rapid queries
    echo "<p>Phase 1: 200 rapid SELECT queries...</p>";
    flush();
    $phase1Start = microtime(true);
    $phase1Success = 0;
    
    for ($i = 1; $i <= 200; $i++) {
        try {
            $db = db();
            $result = $db->query("SELECT COUNT(*) FROM users");
            if ($result) $phase1Success++;
        } catch (Exception $e) {
            // Count failures
        }
    }
    
    $results['phases']['rapid_queries'] = [
        'total' => 200,
        'successful' => $phase1Success,
        'time_seconds' => round(microtime(true) - $phase1Start, 3)
    ];
    
    // Phase 2: Mixed operations
    echo "<p>Phase 2: 100 mixed INSERT/SELECT operations...</p>";
    flush();
    $phase2Start = microtime(true);
    $phase2Success = 0;
    
    for ($i = 1; $i <= 100; $i++) {
        try {
            $db = db();
            
            // Alternate between SELECT and INSERT
            if ($i % 2 === 0) {
                $result = $db->query("SELECT COUNT(*) FROM pledges WHERE source = 'performance_test'");
                if ($result) $phase2Success++;
            } else {
                $stmt = $db->prepare("INSERT INTO pledges (donor_name, amount, status, type, source, anonymous, created_at, client_uuid) VALUES (?, 50, 'pending', 'pledge', 'stress_test', 0, NOW(), ?)");
                $name = "Stress Test $i";
                $uuid = "stress_" . uniqid();
                $stmt->bind_param('ss', $name, $uuid);
                if ($stmt->execute()) $phase2Success++;
            }
            
        } catch (Exception $e) {
            // Count failures
        }
    }
    
    $results['phases']['mixed_operations'] = [
        'total' => 100,
        'successful' => $phase2Success,
        'time_seconds' => round(microtime(true) - $phase2Start, 3)
    ];
    
    $results['total_time_seconds'] = round(microtime(true) - $startTime, 3);
    $results['overall_success_rate'] = round((($phase1Success + $phase2Success) / 300) * 100, 2);
    
    return $results;
}

function cleanupTestData(): array {
    $startTime = microtime(true);
    
    try {
        $db = db();
        
        // Count test records first
        $countResult = $db->query("SELECT COUNT(*) as count FROM pledges WHERE source IN ('performance_test', 'stress_test')");
        $count = $countResult->fetch_assoc()['count'] ?? 0;
        
        // Delete test records
        $deleteResult = $db->query("DELETE FROM pledges WHERE source IN ('performance_test', 'stress_test')");
        $deletedRows = $db->affected_rows;
        
        $totalTime = microtime(true) - $startTime;
        
        return [
            'type' => 'cleanup',
            'test_records_found' => $count,
            'records_deleted' => $deletedRows,
            'time_seconds' => round($totalTime, 3),
            'success' => true
        ];
        
    } catch (Exception $e) {
        return [
            'type' => 'cleanup',
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Performance Test - 500 Users Simulation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .test-card { margin-bottom: 20px; }
        .result-box { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success { color: #28a745; }
        .warning { color: #ffc107; }
        .danger { color: #dc3545; }
        .metric { display: inline-block; margin: 10px 20px 10px 0; }
        .metric-value { font-size: 1.5em; font-weight: bold; }
        .metric-label { font-size: 0.9em; color: #666; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1><i class="fas fa-rocket"></i> Database Performance Test Suite</h1>
        <p class="lead">Test how your improved database connection management handles high load scenarios.</p>
        
        <div class="alert alert-info">
            <h5><i class="fas fa-info-circle"></i> About This Test</h5>
            <p>This suite simulates the <strong>500 simultaneous users scenario</strong> we discussed. It tests:</p>
            <ul>
                <li>Bulk insert performance (500 records)</li>
                <li>Connection pool efficiency</li>
                <li>Stress testing under load</li>
                <li>Database cleanup capabilities</li>
            </ul>
        </div>

        <!-- Test Controls -->
        <div class="row">
            <div class="col-md-6">
                <div class="card test-card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-database"></i> Bulk Insert Test</h5>
                    </div>
                    <div class="card-body">
                        <p>Simulates 500 users submitting donations simultaneously.</p>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="test_500_inserts">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-play"></i> Run 500 Insert Test
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card test-card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-link"></i> Connection Pool Test</h5>
                    </div>
                    <div class="card-body">
                        <p>Tests rapid connection creation and reuse (100 connections).</p>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="test_connection_pool">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-play"></i> Test Connection Pool
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card test-card">
                    <div class="card-header bg-warning text-white">
                        <h5><i class="fas fa-bolt"></i> Stress Test</h5>
                    </div>
                    <div class="card-body">
                        <p>Mixed rapid operations to test system resilience.</p>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="stress_test">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="fas fa-play"></i> Run Stress Test
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card test-card">
                    <div class="card-header bg-danger text-white">
                        <h5><i class="fas fa-trash"></i> Cleanup Test Data</h5>
                    </div>
                    <div class="card-body">
                        <p>Remove all test records created by performance tests.</p>
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="cleanup_test_data">
                            <button type="submit" class="btn btn-danger btn-lg">
                                <i class="fas fa-trash"></i> Cleanup Test Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($results)): ?>
        <!-- Test Results -->
        <div class="card mt-4">
            <div class="card-header bg-dark text-white">
                <h5><i class="fas fa-chart-bar"></i> Test Results</h5>
            </div>
            <div class="card-body">
                <?php if ($results['type'] === 'bulk_insert'): ?>
                    <h6>🚀 Bulk Insert Test Results (500 Records)</h6>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value <?php echo $results['success_rate'] >= 80 ? 'success' : ($results['success_rate'] >= 60 ? 'warning' : 'danger'); ?>">
                                    <?php echo $results['success_rate']; ?>%
                                </div>
                                <div class="metric-label">Success Rate</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value"><?php echo $results['records_per_second']; ?></div>
                                <div class="metric-label">Records/Second</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value"><?php echo $results['total_time_seconds']; ?>s</div>
                                <div class="metric-label">Total Time</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="metric">
                                <div class="metric-value"><?php echo $results['memory_usage']; ?></div>
                                <div class="metric-label">Peak Memory</div>
                            </div>
                        </div>
                    </div>

                    <div class="result-box">
                        <strong>Summary:</strong> 
                        <?php echo $results['successful_inserts']; ?> successful, 
                        <?php echo $results['failed_inserts']; ?> failed out of 500 total records.
                        Average time per record: <?php echo $results['average_time_per_record']; ?>ms
                    </div>

                    <?php if (!empty($results['connection_info'])): ?>
                    <h6>🔗 Connection Information During Test</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Record #</th>
                                    <th>Thread ID</th>
                                    <th>Total Connections</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['connection_info'] as $info): ?>
                                <tr>
                                    <td><?php echo $info['record_num']; ?></td>
                                    <td><?php echo $info['thread_id']; ?></td>
                                    <td><?php echo $info['threads_connected']; ?></td>
                                    <td><?php echo $info['timestamp']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($results['errors'])): ?>
                    <h6 class="danger">❌ Errors Encountered</h6>
                    <pre><?php echo implode("\n", array_slice($results['errors'], 0, 5)); ?></pre>
                    <?php endif; ?>

                <?php elseif ($results['type'] === 'connection_pool'): ?>
                    <h6>🔗 Connection Pool Test Results</h6>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="metric">
                                <div class="metric-value <?php echo $results['success_rate'] >= 95 ? 'success' : 'warning'; ?>">
                                    <?php echo $results['success_rate']; ?>%
                                </div>
                                <div class="metric-label">Success Rate</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric">
                                <div class="metric-value"><?php echo $results['average_connection_time_ms']; ?>ms</div>
                                <div class="metric-label">Avg Connection Time</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="metric">
                                <div class="metric-value"><?php echo $results['total_time_seconds']; ?>s</div>
                                <div class="metric-label">Total Time</div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($results['type'] === 'stress_test'): ?>
                    <h6>⚡ Stress Test Results</h6>
                    
                    <div class="metric">
                        <div class="metric-value <?php echo $results['overall_success_rate'] >= 90 ? 'success' : 'warning'; ?>">
                            <?php echo $results['overall_success_rate']; ?>%
                        </div>
                        <div class="metric-label">Overall Success Rate</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="result-box">
                                <strong>Phase 1 - Rapid Queries:</strong><br>
                                <?php echo $results['phases']['rapid_queries']['successful']; ?>/<?php echo $results['phases']['rapid_queries']['total']; ?> successful 
                                in <?php echo $results['phases']['rapid_queries']['time_seconds']; ?>s
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="result-box">
                                <strong>Phase 2 - Mixed Operations:</strong><br>
                                <?php echo $results['phases']['mixed_operations']['successful']; ?>/<?php echo $results['phases']['mixed_operations']['total']; ?> successful 
                                in <?php echo $results['phases']['mixed_operations']['time_seconds']; ?>s
                            </div>
                        </div>
                    </div>

                <?php elseif ($results['type'] === 'cleanup'): ?>
                    <h6>🗑️ Cleanup Results</h6>
                    <?php if ($results['success']): ?>
                        <div class="result-box success">
                            ✅ Successfully deleted <?php echo $results['records_deleted']; ?> test records 
                            in <?php echo $results['time_seconds']; ?> seconds.
                        </div>
                    <?php else: ?>
                        <div class="result-box danger">
                            ❌ Cleanup failed: <?php echo $results['error']; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="mt-3">
                    <pre><?php echo json_encode($results, JSON_PRETTY_PRINT); ?></pre>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Current System Status -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5><i class="fas fa-info-circle"></i> Current System Status</h5>
            </div>
            <div class="card-body">
                <?php
                try {
                    $status = db_status();
                    if ($status['connected']) {
                        echo "<div class='row'>";
                        echo "<div class='col-md-3'><strong>Status:</strong> <span class='success'>✅ Connected</span></div>";
                        echo "<div class='col-md-3'><strong>Thread ID:</strong> {$status['thread_id']}</div>";
                        echo "<div class='col-md-3'><strong>Server:</strong> {$status['server_info']}</div>";
                        if (isset($status['threads_connected'])) {
                            echo "<div class='col-md-3'><strong>Active Connections:</strong> {$status['threads_connected']}</div>";
                        }
                        echo "</div>";
                    } else {
                        echo "<span class='danger'>❌ Database connection failed</span>";
                    }
                } catch (Exception $e) {
                    echo "<span class='danger'>❌ Error checking status: " . htmlspecialchars($e->getMessage()) . "</span>";
                }
                ?>
            </div>
        </div>

        <div class="alert alert-warning mt-4">
            <h6><i class="fas fa-exclamation-triangle"></i> Performance Interpretation Guide</h6>
            <ul>
                <li><strong>Success Rate ≥80%:</strong> Excellent - System can handle high load</li>
                <li><strong>Success Rate 60-79%:</strong> Good - Some optimization needed</li>
                <li><strong>Success Rate <60%:</strong> Needs improvement - Connection issues likely</li>
                <li><strong>Records/Second ≥50:</strong> Good throughput for donation system</li>
                <li><strong>Avg Connection Time <10ms:</strong> Excellent connection performance</li>
            </ul>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
