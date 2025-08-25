<?php
declare(strict_types=1);

// Enable error reporting for debugging
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once 'config/db.php';

// Performance test for 500 simultaneous registrations
// Distribution: 5 registrars × 100 registrations each

$testResults = [];
$errors = [];
$success = '';

// Add connection test at startup
try {
    $db = db();
    $dbStatus = "✅ Database connection successful (" . $db->server_info . ")";
} catch (Exception $e) {
    $dbStatus = "❌ Database connection failed: " . $e->getMessage();
    $errors[] = $dbStatus;
}

// Get registrar users for realistic assignment
function getRegistrarUsers(): array {
    $db = db();
    $result = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY id LIMIT 5");
    $registrars = [];
    while ($row = $result->fetch_assoc()) {
        $registrars[] = $row;
    }
    return $registrars;
}

// Generate realistic Ethiopian names
function generateRealisticName(): string {
    $firstNames = [
        'Abel', 'Abebe', 'Almaz', 'Aster', 'Bekele', 'Berhan', 'Birhan', 'Dagmawi',
        'Daniel', 'Dawit', 'Desta', 'Getachew', 'Girma', 'Haile', 'Hanna', 'Helen',
        'Kedir', 'Kebede', 'Lemma', 'Marta', 'Meron', 'Michael', 'Naod', 'Rahel',
        'Samuel', 'Sara', 'Selamawit', 'Solomon', 'Teshome', 'Tigist', 'Tsegaye',
        'Wondwossen', 'Yohannes', 'Yonas', 'Zara', 'Zelalem', 'Zenebe', 'Zewdu',
        'Birhane', 'Girmay', 'Tekle', 'Mulugeta', 'Gebre', 'Tadesse', 'Worku',
        'Legese', 'Gemechu', 'Seboka', 'Bartley', 'Mader', 'Nasir', 'Gebru'
    ];
    
    $lastNames = [
        'Abebe', 'Alemayehu', 'Alemu', 'Bekele', 'Desta', 'Getachew', 'Girma',
        'Hailu', 'Kebede', 'Lemma', 'Mengistu', 'Tadesse', 'Tekle', 'Tesfaye',
        'Teshome', 'Tsegaye', 'Wolde', 'Worku', 'Yohannes', 'Legese', 'Gemechu',
        'Seboka', 'Bartley', 'Mader', 'Nasir', 'Gebru', 'Demsie', 'Goytom'
    ];
    
    return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
}

// Generate realistic UK phone number
function generateUkPhone(): string {
    // UK mobile numbers start with 07 and have 11 digits total
    return '074' . str_pad((string)rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
}

// Generate UUID for client tracking
function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Test data generation based on your requirements
function generateTestData(): array {
    $registrars = getRegistrarUsers();
    if (count($registrars) < 5) {
        throw new Exception("Need at least 5 registrar users. Current count: " . count($registrars));
    }
    
    $testData = [];
    $phoneNumbers = []; // Track to avoid duplicates
    
    // Each of 5 registrars creates 100 registrations
    for ($registrarIndex = 0; $registrarIndex < 5; $registrarIndex++) {
        $registrar = $registrars[$registrarIndex];
        
        for ($i = 0; $i < 100; $i++) {
            // Distribution per registrar (100 total):
            // 25 × £400 (package 1)
            // 25 × £200 (package 2) 
            // 25 × £100 (package 3)
            // 15 × custom under £100
            // 10 × custom £100-£400
            
            $donor_name = generateRealisticName();
            
            // Generate unique phone number
            do {
                $phone = generateUkPhone();
            } while (in_array($phone, $phoneNumbers));
            $phoneNumbers[] = $phone;
            
            $anonymous = rand(0, 100) < 15 ? 1 : 0; // 15% anonymous
            $type = rand(0, 100) < 30 ? 'paid' : 'pledge'; // 30% paid, 70% pledge
            
            // Determine package and amount based on distribution
            if ($i < 25) {
                // £400 (1 m²)
                $package_id = 1;
                $amount = 400.00;
            } elseif ($i < 50) {
                // £200 (1/2 m²)
                $package_id = 2;
                $amount = 200.00;
            } elseif ($i < 75) {
                // £100 (1/4 m²)
                $package_id = 3;
                $amount = 100.00;
            } elseif ($i < 90) {
                // Custom under £100
                $package_id = 4; // Custom package
                $amount = (float)rand(25, 99);
            } else {
                // Custom £100-£400
                $package_id = 4; // Custom package
                $amount = (float)rand(100, 400);
            }
            
            $notes = $type === 'paid' ? 'Paid via ' . ['cash', 'card', 'bank'][rand(0, 2)] . '.' : '';
            
            $testData[] = [
                'donor_name' => $anonymous ? 'Anonymous' : $donor_name,
                'donor_phone' => $anonymous ? null : $phone,
                'donor_email' => null,
                'package_id' => $package_id === 4 ? null : $package_id,
                'source' => 'volunteer',
                'anonymous' => $anonymous,
                'amount' => $amount,
                'type' => $type,
                'status' => 'pending',
                'notes' => $notes,
                'client_uuid' => generateUUID(),
                'created_by_user_id' => $registrar['id'],
                'registrar_name' => $registrar['name']
            ];
        }
    }
    
    return $testData;
}

// Insert test data with performance monitoring
function insertTestData(array $testData): array {
    $results = [
        'total_records' => count($testData),
        'successful_inserts' => 0,
        'failed_inserts' => 0,
        'start_time' => microtime(true),
        'end_time' => 0,
        'duration' => 0,
        'connection_info' => [],
        'errors' => [],
        'performance_metrics' => []
    ];
    
    $db = db();
    $batchSize = 50; // Insert in batches to avoid timeout
    $batches = array_chunk($testData, $batchSize);
    
    foreach ($batches as $batchIndex => $batch) {
        $batchStart = microtime(true);
        
        try {
            $db->begin_transaction();
            
            // Use EXACT same query structure as working registrar page
            $stmt = $db->prepare("
                INSERT INTO pledges (
                  donor_name, donor_phone, donor_email, source, anonymous,
                  amount, type, status, notes, client_uuid, created_by_user_id, package_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($batch as $record) {
                $stmt->bind_param(
                    'sssidsssii',
                    $record['donor_name'],
                    $record['donor_phone'],
                    $record['donor_email'],
                    $record['source'],
                    $record['anonymous'],
                    $record['amount'],
                    $record['type'],
                    $record['status'],
                    $record['notes'],
                    $record['client_uuid'],
                    $record['created_by_user_id'],
                    $record['package_id']
                );
                
                if ($stmt->execute()) {
                    $results['successful_inserts']++;
                } else {
                    $results['failed_inserts']++;
                    $results['errors'][] = "Record failed: " . $stmt->error;
                }
            }
            
            $db->commit();
            
            $batchDuration = (microtime(true) - $batchStart) * 1000;
            $results['performance_metrics'][] = [
                'batch' => $batchIndex + 1,
                'records' => count($batch),
                'duration_ms' => round($batchDuration, 2),
                'records_per_second' => round(count($batch) / ($batchDuration / 1000), 2)
            ];
            
        } catch (Exception $e) {
            $db->rollback();
            $results['failed_inserts'] += count($batch);
            $results['errors'][] = "Batch " . ($batchIndex + 1) . " failed: " . $e->getMessage();
        }
        
        // Add small delay between batches to test connection management
        usleep(100000); // 0.1 second
    }
    
    $results['end_time'] = microtime(true);
    $results['duration'] = round(($results['end_time'] - $results['start_time']) * 1000, 2);
    
    // Get connection status
    $results['connection_info'] = db_status();
    
    return $results;
}

// Clean up test data
function cleanupTestData(): bool {
    try {
        $db = db();
        $result = $db->query("DELETE FROM pledges WHERE notes LIKE '%Paid via%' OR client_uuid LIKE '%-%-%-%'");
        return $result !== false;
    } catch (Exception $e) {
        return false;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'generate_test') {
        try {
            $testData = generateTestData();
            $testResults = insertTestData($testData);
            $success = "Performance test completed successfully!";
        } catch (Exception $e) {
            $errors[] = "Test failed: " . $e->getMessage();
        }
    } elseif ($action === 'cleanup') {
        if (cleanupTestData()) {
            $success = "Test data cleaned up successfully!";
        } else {
            $errors[] = "Failed to clean up test data.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500-User Performance Test - Database Connection Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .metric-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .performance-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        .success { background: #28a745; color: white; }
        .warning { background: #ffc107; color: black; }
        .danger { background: #dc3545; color: white; }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h1 class="display-4 text-center mb-4">
                    <i class="fas fa-database me-3"></i>
                    500-User Performance Test
                </h1>
                <p class="lead text-center text-muted mb-5">
                    Test improved database connection management under high load
                </p>
            </div>
        </div>

        <!-- Test Controls -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-play me-2"></i>Test Controls</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="action" value="generate_test">
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <i class="fas fa-rocket me-2"></i>
                                Run 500-User Performance Test
                            </button>
                        </form>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="cleanup">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="fas fa-trash me-2"></i>
                                Clean Up Test Data
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-info-circle me-2"></i>Test Specification</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li><strong>5 Registrars</strong> × 100 records each</li>
                            <li><strong>Distribution per registrar:</strong></li>
                            <li>• 25 × £400 (1 m²)</li>
                            <li>• 25 × £200 (½ m²)</li>
                            <li>• 25 × £100 (¼ m²)</li>
                            <li>• 15 × Custom under £100</li>
                            <li>• 10 × Custom £100-£400</li>
                            <li><strong>Mix:</strong> 70% pledges, 30% payments</li>
                            <li><strong>Anonymous:</strong> 15% of records</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Status -->
        <div class="alert <?php echo strpos($dbStatus, '✅') !== false ? 'alert-success' : 'alert-danger'; ?>" role="alert">
            <?php echo $dbStatus; ?>
        </div>

        <!-- Messages -->
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Test Results -->
        <?php if (!empty($testResults)): ?>
        <div class="row">
            <!-- Performance Metrics -->
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $testResults['successful_inserts']; ?></h3>
                    <p class="mb-0">Successful Inserts</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $testResults['failed_inserts']; ?></h3>
                    <p class="mb-0">Failed Inserts</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo $testResults['duration']; ?>ms</h3>
                    <p class="mb-0">Total Duration</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="metric-card">
                    <h3><?php echo round($testResults['successful_inserts'] / ($testResults['duration'] / 1000), 1); ?></h3>
                    <p class="mb-0">Records/Second</p>
                </div>
            </div>
        </div>

        <!-- Success Rate -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line me-2"></i>Performance Summary</h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $successRate = ($testResults['successful_inserts'] / $testResults['total_records']) * 100;
                        $statusClass = $successRate >= 95 ? 'success' : ($successRate >= 80 ? 'warning' : 'danger');
                        ?>
                        <div class="row">
                            <div class="col-md-4">
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    Success Rate: <?php echo round($successRate, 1); ?>%
                                </span>
                            </div>
                            <div class="col-md-4">
                                <strong>Total Records:</strong> <?php echo $testResults['total_records']; ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Average Speed:</strong> <?php echo round($testResults['successful_inserts'] / ($testResults['duration'] / 1000), 2); ?> records/sec
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Batch Performance -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card performance-table">
                    <div class="card-header">
                        <h5><i class="fas fa-stopwatch me-2"></i>Batch Performance Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Records</th>
                                        <th>Duration (ms)</th>
                                        <th>Records/Second</th>
                                        <th>Performance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testResults['performance_metrics'] as $metric): ?>
                                    <tr>
                                        <td>#<?php echo $metric['batch']; ?></td>
                                        <td><?php echo $metric['records']; ?></td>
                                        <td><?php echo $metric['duration_ms']; ?></td>
                                        <td><?php echo $metric['records_per_second']; ?></td>
                                        <td>
                                            <?php if ($metric['records_per_second'] >= 20): ?>
                                                <span class="badge bg-success">Excellent</span>
                                            <?php elseif ($metric['records_per_second'] >= 10): ?>
                                                <span class="badge bg-warning">Good</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Slow</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connection Info -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5><i class="fas fa-server me-2"></i>Connection Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($testResults['connection_info'])): ?>
                        <ul class="list-unstyled">
                            <li><strong>Connected:</strong> <?php echo $testResults['connection_info']['connected'] ? 'Yes' : 'No'; ?></li>
                            <li><strong>Thread ID:</strong> <?php echo $testResults['connection_info']['thread_id'] ?? 'N/A'; ?></li>
                            <li><strong>Server Info:</strong> <?php echo $testResults['connection_info']['server_info'] ?? 'N/A'; ?></li>
                            <li><strong>Active Connections:</strong> <?php echo $testResults['connection_info']['threads_connected'] ?? 'N/A'; ?></li>
                            <li><strong>Charset:</strong> <?php echo $testResults['connection_info']['charset'] ?? 'N/A'; ?></li>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>Errors & Issues</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($testResults['errors'])): ?>
                        <ul class="list-unstyled text-danger">
                            <?php foreach ($testResults['errors'] as $error): ?>
                            <li><i class="fas fa-times me-2"></i><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php else: ?>
                        <p class="text-success mb-0">
                            <i class="fas fa-check me-2"></i>No errors encountered!
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Current Database Status -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-database me-2"></i>Current Database Status</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        try {
                            $currentStatus = db_status();
                            $db = db();
                            $pledgeCount = $db->query("SELECT COUNT(*) as count FROM pledges")->fetch_assoc();
                            $userCount = $db->query("SELECT COUNT(*) as count FROM users WHERE role='registrar'")->fetch_assoc();
                        ?>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Total Pledges:</strong> <?php echo $pledgeCount['count']; ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Registrars:</strong> <?php echo $userCount['count']; ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Connection Status:</strong> <?php echo $currentStatus['connected'] ? '✅ Connected' : '❌ Disconnected'; ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Active Connections:</strong> <?php echo $currentStatus['threads_connected'] ?? 'Unknown'; ?>
                            </div>
                        </div>
                        <?php } catch (Exception $e): ?>
                        <p class="text-danger">Error checking database status: <?php echo htmlspecialchars($e->getMessage()); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Instructions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle me-2"></i>Instructions</h5>
                    <ol>
                        <li><strong>Run Test:</strong> Click "Run 500-User Performance Test" to simulate 500 simultaneous registrations</li>
                        <li><strong>Monitor Results:</strong> Check success rate, duration, and batch performance</li>
                        <li><strong>Analyze Performance:</strong> Look for any failed batches or slow performance</li>
                        <li><strong>Clean Up:</strong> Use "Clean Up Test Data" to remove test records when done</li>
                    </ol>
                    <p class="mb-0"><strong>Expected Results:</strong> With improved connection management, you should see 95%+ success rate and consistent performance across all batches.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
