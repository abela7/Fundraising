<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    $donor_id = (int)$_POST['donor_id'];
    $agent_id = (int)$_POST['agent_id'];
    
    if ($donor_id > 0) {
        try {
            if ($agent_id > 0) {
                $stmt = $db->prepare("UPDATE donors SET agent_id = ? WHERE id = ?");
                $stmt->bind_param('ii', $agent_id, $donor_id);
                $stmt->execute();
                $message = "Donor assigned successfully!";
            } else {
                $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
                $stmt->bind_param('i', $donor_id);
                $stmt->execute();
                $message = "Donor unassigned successfully!";
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// Get agents
$agents_result = $db->query("SELECT id, name FROM users WHERE role IN ('admin', 'registrar') ORDER BY name");
$agents = [];
while ($agent = $agents_result->fetch_assoc()) {
    $agents[] = $agent;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Assign Donors</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-4">
    <h1>Donors</h1>
    
    <?php if (isset($message)): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php
    try {
        $result = $db->query("SELECT d.id, d.name, d.agent_id,
            COALESCE(d.total_pledged, 0) as total_pledged,
            COALESCE(d.total_paid, 0) as total_paid,
            (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance,
            u.name as agent_name
            FROM donors d
            LEFT JOIN users u ON d.agent_id = u.id
            LIMIT 50");
        
        if ($result && $result->num_rows > 0) {
            echo "<table class='table table-striped'>";
            echo "<thead><tr><th>ID</th><th>Name</th><th>Pledge Amount</th><th>Balance</th><th>Assigned To</th><th>Assign</th></tr></thead>";
            echo "<tbody>";
            while ($row = $result->fetch_assoc()) {
                $balance = (float)$row['balance'];
                $pledge = (float)$row['total_pledged'];
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                echo "<td>£" . number_format($pledge, 2) . "</td>";
                echo "<td>£" . number_format($balance, 2) . "</td>";
                echo "<td>" . ($row['agent_name'] ? htmlspecialchars($row['agent_name']) : '<span class="text-muted">Unassigned</span>') . "</td>";
                echo "<td>";
                echo "<form method='POST' style='display: inline;'>";
                echo "<input type='hidden' name='donor_id' value='" . $row['id'] . "'>";
                echo "<select name='agent_id' class='form-select form-select-sm' style='display: inline-block; width: auto;'>";
                echo "<option value='0'>Unassign</option>";
                foreach ($agents as $agent) {
                    $selected = ($row['agent_id'] == $agent['id']) ? 'selected' : '';
                    echo "<option value='" . $agent['id'] . "' $selected>" . htmlspecialchars($agent['name']) . "</option>";
                }
                echo "</select>";
                echo "<button type='submit' name='assign' class='btn btn-sm btn-primary ms-2'>Assign</button>";
                echo "</form>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<p>No donors found</p>";
        }
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
</div>
</body>
</html>
