<?php
// ENABLE FULL DEBUGGING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo "<div style='background:red;color:white;padding:20px;font-family:monospace;'>";
        echo "<h1>FATAL ERROR DETECTED</h1>";
        echo "<strong>Message:</strong> " . htmlspecialchars($error['message']) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($error['file']) . "<br>";
        echo "<strong>Line:</strong> " . $error['line'] . "<br>";
        echo "</div>";
    }
});

// Don't use strict types to avoid type errors during debugging
// declare(strict_types=1);

echo "<!DOCTYPE html><html><head><title>Payment Plan Deletion Debug</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;} .step{background:white;padding:15px;margin:10px 0;border-left:4px solid #007bff;} .success{border-color:#28a745;} .error{border-color:#dc3545;background:#fff5f5;} .info{border-color:#17a2b8;} pre{background:#f8f9fa;padding:10px;border-radius:4px;overflow-x:auto;} h2{color:#333;} .query{background:#e7f3ff;padding:10px;margin:5px 0;border-radius:4px;}</style>";
echo "</head><body>";
echo "<h1>🔍 Payment Plan Deletion - Full Debug</h1>";

$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

echo "<div class='step info'><strong>Step 0: Parameters</strong><br>";
echo "Plan ID: $plan_id<br>Donor ID: $donor_id<br>Confirm: $confirm<br>";
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "</div>";

if ($plan_id <= 0 || $donor_id <= 0) {
    echo "<div class='step error'><strong>ERROR:</strong> Invalid IDs</div></body></html>";
    exit;
}

// Step 1: Include files
echo "<div class='step'><strong>Step 1: Loading Required Files</strong><br>";
try {
    $auth_path = __DIR__ . '/../../shared/auth.php';
    $db_path = __DIR__ . '/../../config/db.php';
    
    echo "Auth path: $auth_path - " . (file_exists($auth_path) ? "✓ EXISTS" : "✗ MISSING") . "<br>";
    echo "DB path: $db_path - " . (file_exists($db_path) ? "✓ EXISTS" : "✗ MISSING") . "<br>";
    
    require_once $auth_path;
    echo "✓ Auth loaded<br>";
    
    require_once $db_path;
    echo "✓ DB config loaded<br>";
    
    require_login();
    echo "✓ Login verified<br>";
    
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='step error'><strong>ERROR Loading Files:</strong> " . htmlspecialchars($e->getMessage()) . "</div></body></html>";
    exit;
}

// Step 2: Database Connection
echo "<div class='step'><strong>Step 2: Database Connection</strong><br>";
try {
    date_default_timezone_set('Europe/London');
    $conn = get_db_connection();
    echo "✓ Database connected<br>";
    echo "Server: " . $conn->server_info . "<br>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='step error'><strong>ERROR Connecting:</strong> " . htmlspecialchars($e->getMessage()) . "</div></body></html>";
    exit;
}

// Step 3: Fetch Payment Plan
echo "<div class='step'><strong>Step 3: Fetch Payment Plan Details</strong><br>";
try {
    $query = "
        SELECT 
            dpp.*,
            d.name as donor_name,
            d.phone as donor_phone,
            d.active_payment_plan_id,
            d.payment_status,
            p.amount as pledge_amount
        FROM donor_payment_plans dpp
        JOIN donors d ON dpp.donor_id = d.id
        LEFT JOIN pledges p ON dpp.pledge_id = p.id
        WHERE dpp.id = ? AND dpp.donor_id = ?
    ";
    
    echo "<div class='query'><strong>Query:</strong><pre>" . htmlspecialchars($query) . "</pre></div>";
    echo "Parameters: plan_id=$plan_id, donor_id=$donor_id<br>";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error . " (Code: " . $conn->errno . ")");
    }
    echo "✓ Query prepared<br>";
    
    $stmt->bind_param('ii', $plan_id, $donor_id);
    echo "✓ Parameters bound<br>";
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error . " (Code: " . $stmt->errno . ")");
    }
    echo "✓ Query executed<br>";
    
    $result = $stmt->get_result();
    $plan = $result->fetch_object();
    
    if (!$plan) {
        echo "<div class='step error'><strong>ERROR:</strong> Payment plan not found</div></body></html>";
        exit;
    }
    
    echo "<strong>Plan Found:</strong><pre>";
    print_r($plan);
    echo "</pre></div>";
    
} catch (mysqli_sql_exception $e) {
    echo "<div class='step error'><strong>MySQL ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "Error Code: " . $e->getCode() . "<br>";
    echo "SQL State: " . $e->getSqlState() . "</div></body></html>";
    exit;
} catch (Exception $e) {
    echo "<div class='step error'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</div></body></html>";
    exit;
}

// Step 4: Check Linked Sessions
echo "<div class='step'><strong>Step 4: Check Linked Call Sessions</strong><br>";
try {
    $session_query = "SELECT COUNT(*) as count FROM call_center_sessions WHERE payment_plan_id = ?";
    echo "<div class='query'><strong>Query:</strong><pre>" . htmlspecialchars($session_query) . "</pre></div>";
    
    $session_stmt = $conn->prepare($session_query);
    if (!$session_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $session_stmt->bind_param('i', $plan_id);
    $session_stmt->execute();
    $session_result = $session_stmt->get_result();
    $session_row = $session_result->fetch_object();
    $linked_sessions = $session_row ? (int)$session_row->count : 0;
    
    echo "✓ Linked sessions: $linked_sessions<br>";
    
    // Get actual session IDs
    if ($linked_sessions > 0) {
        $get_sessions_query = "SELECT id, donor_id, agent_id, call_started_at FROM call_center_sessions WHERE payment_plan_id = ? LIMIT 10";
        $get_sessions_stmt = $conn->prepare($get_sessions_query);
        $get_sessions_stmt->bind_param('i', $plan_id);
        $get_sessions_stmt->execute();
        $sessions_result = $get_sessions_stmt->get_result();
        echo "<strong>Sample Sessions:</strong><pre>";
        while ($s = $sessions_result->fetch_assoc()) {
            print_r($s);
        }
        echo "</pre>";
    }
    
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='step error'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</div></body></html>";
    exit;
}

// Step 5: Check Active Plan Status
echo "<div class='step'><strong>Step 5: Check Active Plan Status</strong><br>";
$is_active_plan = ($plan->active_payment_plan_id == $plan_id);
echo "Donor's active_payment_plan_id: " . ($plan->active_payment_plan_id ?? 'NULL') . "<br>";
echo "Is Active Plan: " . ($is_active_plan ? 'YES' : 'NO') . "<br>";
echo "</div>";

// Step 6: Check Foreign Key Constraints
echo "<div class='step'><strong>Step 6: Check Foreign Key Constraints</strong><br>";
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
    echo "<strong>Foreign Keys Referencing donor_payment_plans:</strong><pre>";
    while ($fk = $fk_result->fetch_assoc()) {
        print_r($fk);
    }
    echo "</pre></div>";
} catch (Exception $e) {
    echo "Could not check FK constraints: " . htmlspecialchars($e->getMessage()) . "<br></div>";
}

// Step 7: Test Deletion (Dry Run)
if ($confirm === 'test') {
    echo "<div class='step info'><strong>Step 7: DRY RUN - Testing Deletion Logic</strong><br>";
    try {
        $conn->begin_transaction();
        echo "✓ Transaction started<br>";
        
        // Test 1: Unlink sessions
        echo "<br><strong>Test 1: Unlink Sessions</strong><br>";
        if ($linked_sessions > 0) {
            $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
            echo "<div class='query'>Query: " . htmlspecialchars($unlink_query) . "</div>";
            
            $unlink_stmt = $conn->prepare($unlink_query);
            if (!$unlink_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $unlink_stmt->bind_param('i', $plan_id);
            if (!$unlink_stmt->execute()) {
                throw new Exception("Execute failed: " . $unlink_stmt->error);
            }
            echo "✓ Sessions unlinked (affected rows: " . $unlink_stmt->affected_rows . ")<br>";
        } else {
            echo "✓ No sessions to unlink<br>";
        }
        
        // Test 2: Reset donor
        echo "<br><strong>Test 2: Reset Donor Status</strong><br>";
        if ($is_active_plan) {
            $reset_query = "
                UPDATE donors 
                SET active_payment_plan_id = NULL,
                    has_active_plan = 0,
                    payment_status = CASE 
                        WHEN balance > 0 THEN 'not_started'
                        WHEN balance = 0 THEN 'completed'
                        ELSE 'no_pledge'
                    END
                WHERE id = ?
            ";
            echo "<div class='query'>Query: " . htmlspecialchars($reset_query) . "</div>";
            
            $reset_stmt = $conn->prepare($reset_query);
            if (!$reset_stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $reset_stmt->bind_param('i', $donor_id);
            if (!$reset_stmt->execute()) {
                throw new Exception("Execute failed: " . $reset_stmt->error);
            }
            echo "✓ Donor reset (affected rows: " . $reset_stmt->affected_rows . ")<br>";
        } else {
            echo "✓ Not active plan, skipping donor reset<br>";
        }
        
        // Test 3: Delete plan
        echo "<br><strong>Test 3: Delete Payment Plan</strong><br>";
        $delete_query = "DELETE FROM donor_payment_plans WHERE id = ?";
        echo "<div class='query'>Query: " . htmlspecialchars($delete_query) . "</div>";
        
        $delete_stmt = $conn->prepare($delete_query);
        if (!$delete_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $delete_stmt->bind_param('i', $plan_id);
        if (!$delete_stmt->execute()) {
            throw new Exception("Execute failed: " . $delete_stmt->error);
        }
        echo "✓ Plan deleted (affected rows: " . $delete_stmt->affected_rows . ")<br>";
        
        // Rollback (dry run)
        $conn->rollback();
        echo "<br>✓ Transaction rolled back (DRY RUN)<br>";
        echo "</div>";
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        echo "<div class='step error'><strong>MySQL ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "Error Code: " . $e->getCode() . "<br>";
        echo "SQL State: " . $e->getSqlState() . "</div>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<div class='step error'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Step 8: Actual Deletion (if confirmed)
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div class='step'><strong>Step 8: ACTUAL DELETION</strong><br>";
    try {
        $conn->begin_transaction();
        echo "✓ Transaction started<br>";
        
        // Step 1: Unlink sessions
        echo "<br><strong>Step 8.1: Unlink Sessions</strong><br>";
        if ($linked_sessions > 0) {
            $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
            $unlink_stmt = $conn->prepare($unlink_query);
            $unlink_stmt->bind_param('i', $plan_id);
            $unlink_stmt->execute();
            echo "✓ Sessions unlinked: " . $unlink_stmt->affected_rows . " rows<br>";
        }
        
        // Step 2: Reset donor
        echo "<br><strong>Step 8.2: Reset Donor</strong><br>";
        if ($is_active_plan) {
            $reset_query = "
                UPDATE donors 
                SET active_payment_plan_id = NULL,
                    has_active_plan = 0,
                    payment_status = CASE 
                        WHEN balance > 0 THEN 'not_started'
                        WHEN balance = 0 THEN 'completed'
                        ELSE 'no_pledge'
                    END
                WHERE id = ?
            ";
            $reset_stmt = $conn->prepare($reset_query);
            $reset_stmt->bind_param('i', $donor_id);
            $reset_stmt->execute();
            echo "✓ Donor reset: " . $reset_stmt->affected_rows . " rows<br>";
        }
        
        // Step 3: Delete plan
        echo "<br><strong>Step 8.3: Delete Plan</strong><br>";
        $delete_query = "DELETE FROM donor_payment_plans WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $plan_id);
        $delete_stmt->execute();
        echo "✓ Plan deleted: " . $delete_stmt->affected_rows . " rows<br>";
        
        $conn->commit();
        echo "<br>✓ Transaction committed successfully!<br>";
        echo "</div>";
        
        echo "<div class='step success'><strong>SUCCESS!</strong> Payment plan deleted. <a href='view-donor.php?id=$donor_id'>View Donor</a></div>";
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        echo "<div class='step error'><strong>MySQL ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "Error Code: " . $e->getCode() . "<br>";
        echo "SQL State: " . $e->getSqlState() . "<br>";
        echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    } catch (Exception $e) {
        $conn->rollback();
        echo "<div class='step error'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "</div>";
    }
}

// Action Buttons
echo "<div class='step info'>";
echo "<h2>Actions</h2>";
echo "<a href='?id=$plan_id&donor_id=$donor_id&confirm=test' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:white;text-decoration:none;margin:5px;border-radius:4px;'>🧪 Test Deletion (Dry Run)</a>";
echo "<form method='POST' action='?id=$plan_id&donor_id=$donor_id&confirm=yes' style='display:inline;'>";
echo "<button type='submit' style='padding:10px 20px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;margin:5px;'>🗑️ Actually Delete</button>";
echo "</form>";
echo "<a href='view-donor.php?id=$donor_id' style='display:inline-block;padding:10px 20px;background:#6c757d;color:white;text-decoration:none;margin:5px;border-radius:4px;'>← Back to Donor</a>";
echo "</div>";

echo "</body></html>";
?>

