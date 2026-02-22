<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/audit_helper.php';
require_login();

$db = db();
$user_id = (int)$_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? 'registrar';
$is_admin = ($user_role === 'admin');

$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$session_id) {
    header('Location: call-history.php');
    exit;
}

// Fetch Session
$query = "SELECT * FROM call_center_sessions WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->bind_param('i', $session_id);
$stmt->execute();
$call = $stmt->get_result()->fetch_object();

if (!$call) {
    die("Call record not found.");
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_agent_id = (int)$_POST['agent_id'];
    $minutes = (int)$_POST['duration_minutes'];
    $seconds = (int)$_POST['duration_seconds'];
    $new_notes = $_POST['notes'];
    
    $total_seconds = ($minutes * 60) + $seconds;
    
    $update_query = "UPDATE call_center_sessions SET agent_id = ?, duration_seconds = ?, notes = ? WHERE id = ?";
    $stmt = $db->prepare($update_query);
    $stmt->bind_param('iisi', $new_agent_id, $total_seconds, $new_notes, $session_id);
    
    if ($stmt->execute()) {
        // Audit log the edit
        log_audit(
            $db,
            'update',
            'call_session',
            $session_id,
            [
                'agent_id' => $call->agent_id,
                'duration_seconds' => $call->duration_seconds,
                'notes' => $call->notes
            ],
            [
                'agent_id' => $new_agent_id,
                'duration_seconds' => $total_seconds,
                'notes' => $new_notes
            ],
            'admin_portal',
            $user_id
        );
        
        header("Location: call-details.php?id={$session_id}");
        exit;
    } else {
        $error = "Failed to update record.";
    }
}

// Get Agents
$agents = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') AND active = 1 ORDER BY name");

// Calculate current duration parts
$curr_min = floor(($call->duration_seconds ?? 0) / 60);
$curr_sec = ($call->duration_seconds ?? 0) % 60;

$page_title = 'Edit Call Record';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Call Record - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="container mt-5" style="max-width: 600px;">
    <div class="card">
        <div class="card-header">
            <h3>Edit Call Record #<?php echo $call->id; ?></h3>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Agent</label>
                    <select name="agent_id" class="form-select" required>
                        <?php while ($agent = $agents->fetch_object()): ?>
                            <option value="<?php echo $agent->id; ?>" <?php echo $agent->id == $call->agent_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($agent->name); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Duration</label>
                    <div class="input-group">
                        <input type="number" name="duration_minutes" class="form-control" value="<?php echo $curr_min; ?>" min="0" placeholder="Min">
                        <span class="input-group-text">min</span>
                        <input type="number" name="duration_seconds" class="form-control" value="<?php echo $curr_sec; ?>" min="0" max="59" placeholder="Sec">
                        <span class="input-group-text">sec</span>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="5"><?php echo htmlspecialchars($call->notes ?? ''); ?></textarea>
                </div>
                
                <div class="d-flex justify-content-between">
                    <a href="call-details.php?id=<?php echo $session_id; ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>

