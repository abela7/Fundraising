<?php
// MINIMAL TEST - NO REQUIREMENTS, JUST BASIC CHECKS
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<!DOCTYPE html><html><head><title>Minimal Deletion Test</title>";
echo "<link rel='icon' type='image/svg+xml' href='../../assets/favicon.svg'>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;} .ok{color:green;} .error{color:red;background:#fff5f5;padding:10px;margin:5px 0;} .info{background:#e7f3ff;padding:10px;margin:5px 0;}</style>";
echo "</head><body>";
echo "<h1>Minimal Deletion Test</h1>";

$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'plan'; // 'plan' or 'session'

echo "<div class='info'><strong>Parameters:</strong><br>";
echo "Type: $type<br>";
echo "ID: " . ($type === 'plan' ? $plan_id : (isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0)) . "<br>";
echo "Donor ID: $donor_id<br></div>";

// Test 1: Check if files exist
echo "<h2>Test 1: File Existence</h2>";
$files = [
    'auth.php' => __DIR__ . '/../../shared/auth.php',
    'db.php' => __DIR__ . '/../../config/db.php',
    'delete-payment-plan.php' => __DIR__ . '/delete-payment-plan.php',
    'delete-call-session.php' => __DIR__ . '/delete-call-session.php',
];

foreach ($files as $name => $path) {
    $exists = file_exists($path);
    $readable = $exists && is_readable($path);
    echo "<div class='" . ($exists && $readable ? 'ok' : 'error') . "'>";
    echo "$name: " . ($exists ? "EXISTS" : "MISSING") . " ";
    echo ($readable ? "(readable)" : "(not readable)");
    echo "<br>Path: $path<br>";
    echo "</div>";
}

// Test 2: Check PHP syntax
echo "<h2>Test 2: PHP Syntax Check</h2>";
$syntax_files = [
    'delete-payment-plan.php' => __DIR__ . '/delete-payment-plan.php',
    'delete-call-session.php' => __DIR__ . '/delete-call-session.php',
];

foreach ($syntax_files as $name => $path) {
    if (file_exists($path)) {
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return_var);
        $syntax_ok = ($return_var === 0);
        echo "<div class='" . ($syntax_ok ? 'ok' : 'error') . "'>";
        echo "$name: " . ($syntax_ok ? "SYNTAX OK" : "SYNTAX ERROR");
        if (!$syntax_ok) {
            echo "<br><pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
        }
        echo "</div>";
    }
}

// Test 3: Try to include auth.php
echo "<h2>Test 3: Include Auth</h2>";
try {
    $auth_path = __DIR__ . '/../../shared/auth.php';
    if (!file_exists($auth_path)) {
        throw new Exception("Auth file not found: $auth_path");
    }
    require_once $auth_path;
    echo "<div class='ok'>✓ Auth file loaded successfully</div>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error loading auth: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
}

// Test 4: Try to include db.php
echo "<h2>Test 4: Include DB Config</h2>";
try {
    $db_path = __DIR__ . '/../../config/db.php';
    if (!file_exists($db_path)) {
        throw new Exception("DB config file not found: $db_path");
    }
    require_once $db_path;
    echo "<div class='ok'>✓ DB config loaded successfully</div>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Error loading DB config: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
}

// Test 5: Try database connection
echo "<h2>Test 5: Database Connection</h2>";
try {
    if (!function_exists('db')) {
        throw new Exception("db() function not found");
    }
    $conn = db();
    echo "<div class='ok'>✓ Database connected</div>";
    echo "<div class='info'>Server: " . htmlspecialchars($conn->server_info) . "<br>";
    echo "Host: " . htmlspecialchars($conn->host_info) . "</div>";
} catch (Throwable $e) {
    echo "<div class='error'>✗ Database connection failed: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    $conn = null;
}

// Test 6: Check if tables exist
if ($conn) {
    echo "<h2>Test 6: Table Existence</h2>";
    $tables = [
        'donor_payment_plans',
        'call_center_sessions',
        'donors',
        'call_center_attempt_log',
        'call_center_sms_log',
        'call_center_workflow_executions',
        'call_center_conversation_steps',
        'call_center_responses',
        'call_center_appointments',
    ];
    
    foreach ($tables as $table) {
        try {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            $exists = $result && $result->num_rows > 0;
            echo "<div class='" . ($exists ? 'ok' : 'error') . "'>";
            echo "$table: " . ($exists ? "EXISTS" : "MISSING");
            echo "</div>";
        } catch (Throwable $e) {
            echo "<div class='error'>$table: ERROR - " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

// Test 7: Check specific record
if ($conn && $plan_id > 0 && $type === 'plan') {
    echo "<h2>Test 7: Check Payment Plan Record</h2>";
    try {
        $query = "SELECT id, donor_id FROM donor_payment_plans WHERE id = ? AND donor_id = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param('ii', $plan_id, $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_object();
        
        if ($plan) {
            echo "<div class='ok'>✓ Payment plan found (ID: $plan->id, Donor: $plan->donor_id)</div>";
        } else {
            echo "<div class='error'>✗ Payment plan not found (ID: $plan_id, Donor: $donor_id)</div>";
        }
    } catch (Throwable $e) {
        echo "<div class='error'>✗ Error checking plan: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

if ($conn && isset($_GET['session_id']) && $type === 'session') {
    $session_id = (int)$_GET['session_id'];
    echo "<h2>Test 7: Check Call Session Record</h2>";
    try {
        $query = "SELECT id, donor_id FROM call_center_sessions WHERE id = ? AND donor_id = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param('ii', $session_id, $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $session = $result->fetch_object();
        
        if ($session) {
            echo "<div class='ok'>✓ Call session found (ID: $session->id, Donor: $session->donor_id)</div>";
        } else {
            echo "<div class='error'>✗ Call session not found (ID: $session_id, Donor: $donor_id)</div>";
        }
    } catch (Throwable $e) {
        echo "<div class='error'>✗ Error checking session: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Test 8: Check foreign key constraints
if ($conn && $type === 'plan' && $plan_id > 0) {
    echo "<h2>Test 8: Foreign Key Constraints (Payment Plan)</h2>";
    try {
        $fk_query = "
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME,
                DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_NAME = 'donor_payment_plans'
            AND TABLE_SCHEMA = DATABASE()
        ";
        $fk_result = $conn->query($fk_query);
        if ($fk_result) {
            echo "<div class='info'><strong>Foreign Keys Referencing donor_payment_plans:</strong><pre>";
            while ($fk = $fk_result->fetch_assoc()) {
                print_r($fk);
            }
            echo "</pre></div>";
        }
    } catch (Throwable $e) {
        echo "<div class='error'>Error checking FK: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Test 9: Check linked records
if ($conn && $type === 'plan' && $plan_id > 0) {
    echo "<h2>Test 9: Linked Records (Payment Plan)</h2>";
    try {
        // Check call_center_sessions
        $check_query = "SELECT COUNT(*) as cnt FROM call_center_sessions WHERE payment_plan_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param('i', $plan_id);
        $check_stmt->execute();
        $sessions_count = $check_stmt->get_result()->fetch_object()->cnt;
        echo "<div class='info'>Linked call_center_sessions: $sessions_count</div>";
        
        // Check donors.active_payment_plan_id
        $check_query = "SELECT COUNT(*) as cnt FROM donors WHERE active_payment_plan_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param('i', $plan_id);
        $check_stmt->execute();
        $donors_count = $check_stmt->get_result()->fetch_object()->cnt;
        echo "<div class='info'>Linked donors (active_payment_plan_id): $donors_count</div>";
        
    } catch (Throwable $e) {
        echo "<div class='error'>Error checking linked records: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

echo "<hr>";
echo "<h2>Quick Links</h2>";
echo "<a href='?type=plan&id=$plan_id&donor_id=$donor_id'>Test Payment Plan (ID: $plan_id)</a><br>";
if (isset($_GET['session_id'])) {
    echo "<a href='?type=session&session_id=" . (int)$_GET['session_id'] . "&donor_id=$donor_id'>Test Call Session (ID: " . (int)$_GET['session_id'] . ")</a><br>";
}
echo "<a href='view-donor.php?id=$donor_id'>Back to Donor</a>";

echo "</body></html>";
?>

