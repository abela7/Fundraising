<?php
// ENABLE FULL DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

declare(strict_types=1);

echo "<!DOCTYPE html><html><head><title>Call Session Deletion Debug</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;} .step{background:white;padding:15px;margin:10px 0;border-left:4px solid #007bff;} .success{border-color:#28a745;} .error{border-color:#dc3545;background:#fff5f5;} .info{border-color:#17a2b8;} pre{background:#f8f9fa;padding:10px;border-radius:4px;overflow-x:auto;} h2{color:#333;} .query{background:#e7f3ff;padding:10px;margin:5px 0;border-radius:4px;}</style>";
echo "</head><body>";
echo "<h1>🔍 Call Session Deletion - Full Debug</h1>";

$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';
$unlink_plan = isset($_POST['unlink_plan']) ? $_POST['unlink_plan'] : 'unlink';

echo "<div class='step info'><strong>Step 0: Parameters</strong><br>";
echo "Session ID: $session_id<br>Donor ID: $donor_id<br>Confirm: $confirm<br>";
echo "Unlink Plan: $unlink_plan<br>";
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "</div>";

if ($session_id <= 0 || $donor_id <= 0) {
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
    $conn = db();
    echo "✓ Database connected<br>";
    echo "Server: " . $conn->server_info . "<br>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='step error'><strong>ERROR Connecting:</strong> " . htmlspecialchars($e->getMessage()) . "</div></body></html>";
    exit;
}

// Step 3: Fetch Call Session
echo "<div class='step'><strong>Step 3: Fetch Call Session Details</strong><br>";
try {
    $query = "
        SELECT 
            ccs.*,
            d.name as donor_name,
            d.phone as donor_phone,
            u.name as agent_name,
            dpp.id as has_payment_plan
        FROM call_center_sessions ccs
        JOIN donors d ON ccs.donor_id = d.id
        LEFT JOIN users u ON ccs.agent_id = u.id
        LEFT JOIN donor_payment_plans dpp ON ccs.payment_plan_id = dpp.id
        WHERE ccs.id = ? AND ccs.donor_id = ?
    ";
    
    echo "<div class='query'><strong>Query:</strong><pre>" . htmlspecialchars($query) . "</pre></div>";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param('ii', $session_id, $donor_id);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $session = $result->fetch_object();
    
    if (!$session) {
        echo "<div class='step error'><strong>ERROR:</strong> Call session not found</div></body></html>";
        exit;
    }
    
    echo "<strong>Session Found:</strong><pre>";
    print_r($session);
    echo "</pre></div>";
    
} catch (Exception $e) {
    echo "<div class='step error'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</div></body></html>";
    exit;
}

// Step 4: Check Child Records
echo "<div class='step'><strong>Step 4: Check Child Records</strong><br>";
$child_counts = [];

try {
    // Check attempt_log
    $check_query = "SELECT COUNT(*) as cnt FROM call_center_attempt_log WHERE session_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param('i', $session_id);
    $check_stmt->execute();
    $child_counts['attempt_log'] = $check_stmt->get_result()->fetch_object()->cnt;
    
    // Check sms_log
    $check_query = "SELECT COUNT(*) as cnt FROM call_center_sms_log WHERE session_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param('i', $session_id);
    $check_stmt->execute();
    $child_counts['sms_log'] = $check_stmt->get_result()->fetch_object()->cnt;
    
    // Check workflow_executions
    $check_query = "SELECT COUNT(*) as cnt FROM call_center_workflow_executions WHERE session_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param('i', $session_id);
    $check_stmt->execute();
    $child_counts['workflow_executions'] = $check_stmt->get_result()->fetch_object()->cnt;
    
    // Check conversation_steps
    $check_query = "SELECT COUNT(*) as cnt FROM call_center_conversation_steps WHERE session_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param('i', $session_id);
    $check_stmt->execute();
    $child_counts['conversation_steps'] = $check_stmt->get_result()->fetch_object()->cnt;
    
    // Check responses
    $check_query = "SELECT COUNT(*) as cnt FROM call_center_responses WHERE session_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param('i', $session_id);
    $check_stmt->execute();
    $child_counts['responses'] = $check_stmt->get_result()->fetch_object()->cnt;
    
    // Check appointments
    $check_query = "SELECT COUNT(*) as cnt FROM call_center_appointments WHERE session_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param('i', $session_id);
    $check_stmt->execute();
    $child_counts['appointments'] = $check_stmt->get_result()->fetch_object()->cnt;
    
    echo "<strong>Child Record Counts:</strong><pre>";
    print_r($child_counts);
    echo "</pre></div>";
    
} catch (Exception $e) {
    echo "Error checking child records: " . htmlspecialchars($e->getMessage()) . "<br></div>";
}

// Step 5: Check Payment Plan
echo "<div class='step'><strong>Step 5: Check Payment Plan</strong><br>";
$has_payment_plan = !empty($session->payment_plan_id);
echo "Payment Plan ID: " . ($session->payment_plan_id ?? 'NULL') . "<br>";
echo "Has Payment Plan: " . ($has_payment_plan ? 'YES' : 'NO') . "<br>";
if ($has_payment_plan) {
    $plan_id = (int)$session->payment_plan_id;
    echo "Plan ID to handle: $plan_id<br>";
}
echo "</div>";

// Step 6: Test Deletion (Dry Run)
if ($confirm === 'test') {
    echo "<div class='step info'><strong>Step 6: DRY RUN - Testing Deletion Logic</strong><br>";
    try {
        $conn->begin_transaction();
        echo "✓ Transaction started<br>";
        
        // Delete child records
        echo "<br><strong>Test 1: Delete Child Records</strong><br>";
        foreach ($child_counts as $table => $count) {
            if ($count > 0) {
                $delete_query = "DELETE FROM call_center_$table WHERE session_id = ?";
                echo "<div class='query'>Query: " . htmlspecialchars($delete_query) . "</div>";
                
                $delete_stmt = $conn->prepare($delete_query);
                if (!$delete_stmt) {
                    throw new Exception("Prepare failed for $table: " . $conn->error);
                }
                $delete_stmt->bind_param('i', $session_id);
                if (!$delete_stmt->execute()) {
                    throw new Exception("Execute failed for $table: " . $delete_stmt->error);
                }
                echo "✓ $table deleted: " . $delete_stmt->affected_rows . " rows<br>";
            } else {
                echo "✓ $table: No records to delete<br>";
            }
        }
        
        // Handle payment plan
        if ($has_payment_plan) {
            echo "<br><strong>Test 2: Handle Payment Plan</strong><br>";
            if ($unlink_plan === 'delete') {
                // Unlink all sessions
                $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
                echo "<div class='query'>Query: " . htmlspecialchars($unlink_query) . "</div>";
                $unlink_stmt = $conn->prepare($unlink_query);
                $unlink_stmt->bind_param('i', $plan_id);
                $unlink_stmt->execute();
                echo "✓ Sessions unlinked: " . $unlink_stmt->affected_rows . " rows<br>";
                
                // Reset donor
                $reset_query = "UPDATE donors SET active_payment_plan_id = NULL, has_active_plan = 0 WHERE id = ? AND active_payment_plan_id = ?";
                $reset_stmt = $conn->prepare($reset_query);
                $reset_stmt->bind_param('ii', $donor_id, $plan_id);
                $reset_stmt->execute();
                echo "✓ Donor reset: " . $reset_stmt->affected_rows . " rows<br>";
                
                // Delete plan
                $delete_plan_query = "DELETE FROM donor_payment_plans WHERE id = ?";
                echo "<div class='query'>Query: " . htmlspecialchars($delete_plan_query) . "</div>";
                $delete_plan_stmt = $conn->prepare($delete_plan_query);
                $delete_plan_stmt->bind_param('i', $plan_id);
                $delete_plan_stmt->execute();
                echo "✓ Plan deleted: " . $delete_plan_stmt->affected_rows . " rows<br>";
            } else {
                // Just unlink
                $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE id = ?";
                $unlink_stmt = $conn->prepare($unlink_query);
                $unlink_stmt->bind_param('i', $session_id);
                $unlink_stmt->execute();
                echo "✓ Session unlinked from plan: " . $unlink_stmt->affected_rows . " rows<br>";
            }
        }
        
        // Delete session
        echo "<br><strong>Test 3: Delete Call Session</strong><br>";
        $delete_query = "DELETE FROM call_center_sessions WHERE id = ?";
        echo "<div class='query'>Query: " . htmlspecialchars($delete_query) . "</div>";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $session_id);
        $delete_stmt->execute();
        echo "✓ Session deleted: " . $delete_stmt->affected_rows . " rows<br>";
        
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

// Step 7: Actual Deletion
if ($confirm === 'yes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div class='step'><strong>Step 7: ACTUAL DELETION</strong><br>";
    try {
        $conn->begin_transaction();
        echo "✓ Transaction started<br>";
        
        // Delete child records
        echo "<br><strong>Step 7.1: Delete Child Records</strong><br>";
        foreach ($child_counts as $table => $count) {
            if ($count > 0) {
                $delete_query = "DELETE FROM call_center_$table WHERE session_id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param('i', $session_id);
                $delete_stmt->execute();
                echo "✓ $table: " . $delete_stmt->affected_rows . " rows deleted<br>";
            }
        }
        
        // Handle payment plan
        if ($has_payment_plan) {
            echo "<br><strong>Step 7.2: Handle Payment Plan</strong><br>";
            $plan_id = (int)$session->payment_plan_id;
            
            if ($unlink_plan === 'delete') {
                // Unlink all sessions
                $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE payment_plan_id = ?";
                $unlink_stmt = $conn->prepare($unlink_query);
                $unlink_stmt->bind_param('i', $plan_id);
                $unlink_stmt->execute();
                echo "✓ Sessions unlinked: " . $unlink_stmt->affected_rows . " rows<br>";
                
                // Reset donor
                $reset_query = "UPDATE donors SET active_payment_plan_id = NULL, has_active_plan = 0 WHERE id = ? AND active_payment_plan_id = ?";
                $reset_stmt = $conn->prepare($reset_query);
                $reset_stmt->bind_param('ii', $donor_id, $plan_id);
                $reset_stmt->execute();
                echo "✓ Donor reset: " . $reset_stmt->affected_rows . " rows<br>";
                
                // Delete plan
                $delete_plan_query = "DELETE FROM donor_payment_plans WHERE id = ?";
                $delete_plan_stmt = $conn->prepare($delete_plan_query);
                $delete_plan_stmt->bind_param('i', $plan_id);
                $delete_plan_stmt->execute();
                echo "✓ Plan deleted: " . $delete_plan_stmt->affected_rows . " rows<br>";
            } else {
                // Just unlink
                $unlink_query = "UPDATE call_center_sessions SET payment_plan_id = NULL WHERE id = ?";
                $unlink_stmt = $conn->prepare($unlink_query);
                $unlink_stmt->bind_param('i', $session_id);
                $unlink_stmt->execute();
                echo "✓ Session unlinked: " . $unlink_stmt->affected_rows . " rows<br>";
            }
        }
        
        // Delete session
        echo "<br><strong>Step 7.3: Delete Call Session</strong><br>";
        $delete_query = "DELETE FROM call_center_sessions WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param('i', $session_id);
        $delete_stmt->execute();
        echo "✓ Session deleted: " . $delete_stmt->affected_rows . " rows<br>";
        
        $conn->commit();
        echo "<br>✓ Transaction committed successfully!<br>";
        echo "</div>";
        
        echo "<div class='step success'><strong>SUCCESS!</strong> Call session deleted. <a href='view-donor.php?id=$donor_id'>View Donor</a></div>";
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
echo "<a href='?id=$session_id&donor_id=$donor_id&confirm=test' style='display:inline-block;padding:10px 20px;background:#17a2b8;color:white;text-decoration:none;margin:5px;border-radius:4px;'>🧪 Test Deletion (Dry Run)</a>";
echo "<form method='POST' action='?id=$session_id&donor_id=$donor_id&confirm=yes' style='display:inline;'>";
if ($has_payment_plan) {
    echo "<label><input type='radio' name='unlink_plan' value='unlink' checked> Unlink Plan</label> ";
    echo "<label><input type='radio' name='unlink_plan' value='delete'> Delete Plan</label><br>";
}
echo "<button type='submit' style='padding:10px 20px;background:#dc3545;color:white;border:none;border-radius:4px;cursor:pointer;margin:5px;'>🗑️ Actually Delete</button>";
echo "</form>";
echo "<a href='view-donor.php?id=$donor_id' style='display:inline-block;padding:10px 20px;background:#6c757d;color:white;text-decoration:none;margin:5px;border-radius:4px;'>← Back to Donor</a>";
echo "</div>";

echo "</body></html>";
?>

