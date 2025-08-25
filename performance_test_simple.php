<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/db.php';
require_once 'shared/csrf.php';

// Simple performance test without advanced features
$db = db();
$baseline = [
    'pledges' => $db->query("SELECT COUNT(*) as count FROM pledges")->fetch_assoc()['count'],
    'payments' => $db->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'],
    'users' => $db->query("SELECT COUNT(*) as count FROM users WHERE role='registrar'")->fetch_assoc()['count']
];

$testResults = [];
$error = '';
$success = '';

// Simple test function
function runSimpleTest($db) {
    $startTime = microtime(true);
    $results = [
        'inserted' => 0,
        'errors' => []
    ];
    
    try {
        // Insert 50 test records (smaller test first)
        for ($i = 1; $i <= 50; $i++) {
            $name = "Test User $i";
            $phone = "070" . str_pad($i, 7, '0', STR_PAD_LEFT);
            $amount = 100;
            $uuid = uniqid("simple_test_$i", true);
            
            $db->begin_transaction();
            
            try {
                $stmt = $db->prepare("
                    INSERT INTO pledges (donor_name, donor_phone, source, anonymous, amount, type, status, notes, client_uuid, created_by_user_id, package_id)
                    VALUES (?, ?, 'volunteer', 0, ?, 'pledge', 'pending', 'Simple performance test', ?, NULL, NULL)
                ");
                $stmt->bind_param('ssds', $name, $phone, $amount, $uuid);
                $stmt->execute();
                
                $db->commit();
                $results['inserted']++;
                
            } catch (Exception $e) {
                $db->rollback();
                $results['errors'][] = "Record $i: " . $e->getMessage();
            }
            
            // Small delay
            usleep(10000); // 0.01 second
        }
        
    } catch (Exception $e) {
        $results['errors'][] = "Fatal error: " . $e->getMessage();
    }
    
    $results['time'] = round((microtime(true) - $startTime) * 1000, 2);
    return $results;
}

// Handle test execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_simple_test'])) {
    verify_csrf();
    $testResults = runSimpleTest($db);
    $success = "Simple test completed: {$testResults['inserted']} records inserted in {$testResults['time']}ms";
}

// Handle cleanup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_test'])) {
    verify_csrf();
    
    try {
        $result = $db->query("DELETE FROM pledges WHERE notes LIKE '%performance test%' OR client_uuid LIKE 'simple_test_%'");
        $deleted = $db->affected_rows;
        $success = "Cleanup completed: Deleted $deleted test records.";
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
    <title>Simple Performance Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container my-5">
        <h1 class="text-center mb-4">Simple Database Performance Test</h1>
        
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
        
        <!-- Test Controls -->
        <div class="card mb-4">
            <div class="card-body text-center">
                <form method="POST" style="display: inline-block; margin-right: 10px;">
                    <?php echo csrf_input(); ?>
                    <button type="submit" name="run_simple_test" class="btn btn-primary btn-lg">
                        Run Simple Test (50 Records)
                    </button>
                </form>
                
                <form method="POST" style="display: inline-block;">
                    <?php echo csrf_input(); ?>
                    <button type="submit" name="cleanup_test" class="btn btn-outline-danger">
                        Cleanup Test Data
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Messages -->
        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Test Results -->
        <?php if (!empty($testResults)): ?>
        <div class="card">
            <div class="card-header">
                <h5>Test Results</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center">
                            <h4 class="text-success"><?php echo $testResults['inserted']; ?></h4>
                            <small>Records Inserted</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <h4 class="text-primary"><?php echo $testResults['time']; ?>ms</h4>
                            <small>Total Time</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <h4 class="text-warning"><?php echo count($testResults['errors']); ?></h4>
                            <small>Errors</small>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($testResults['errors'])): ?>
                <h6 class="mt-4 text-danger">Errors:</h6>
                <ul>
                    <?php foreach (array_slice($testResults['errors'], 0, 5) as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($testResults['errors']) > 5): ?>
                    <li>... and <?php echo count($testResults['errors']) - 5; ?> more errors</li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="text-center mt-4">
            <a href="index.php" class="btn btn-outline-primary">Back to Home</a>
        </div>
    </div>
</body>
</html>
