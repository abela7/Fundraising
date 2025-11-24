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
            } else {
                $stmt = $db->prepare("UPDATE donors SET agent_id = NULL WHERE id = ?");
                $stmt->bind_param('i', $donor_id);
                $stmt->execute();
            }
            header("Location: assign-donors.php");
            exit;
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

// Get donors by agent
$donors_by_agent = [];
foreach ($agents as $agent) {
    $donors_result = $db->query("SELECT d.id, d.name,
        COALESCE(d.total_pledged, 0) as total_pledged,
        COALESCE(d.total_paid, 0) as total_paid,
        (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
        FROM donors d
        WHERE d.agent_id = " . (int)$agent['id'] . "
        ORDER BY d.name");
    
    $donors_by_agent[$agent['id']] = [];
    while ($donor = $donors_result->fetch_assoc()) {
        $donors_by_agent[$agent['id']][] = $donor;
    }
}

// Get unassigned donors
$unassigned_result = $db->query("SELECT d.id, d.name,
    COALESCE(d.total_pledged, 0) as total_pledged,
    COALESCE(d.total_paid, 0) as total_paid,
    (COALESCE(d.total_pledged, 0) - COALESCE(d.total_paid, 0)) as balance
    FROM donors d
    WHERE d.agent_id IS NULL
    ORDER BY d.name");
$unassigned_donors = [];
while ($donor = $unassigned_result->fetch_assoc()) {
    $unassigned_donors[] = $donor;
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
    <h1>Assign Donors</h1>
    
    <?php if (isset($message)): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">All Donors</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="agents-tab" data-bs-toggle="tab" data-bs-target="#agents" type="button" role="tab">By Agents</button>
        </li>
    </ul>
    
    <div class="tab-content" id="myTabContent">
        <!-- All Donors Tab -->
        <div class="tab-pane fade show active" id="all" role="tabpanel">
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
        
        <!-- By Agents Tab -->
        <div class="tab-pane fade" id="agents" role="tabpanel">
            <div class="accordion" id="agentsAccordion">
                <?php
                $accordion_index = 0;
                foreach ($agents as $agent):
                    $donors = $donors_by_agent[$agent['id']] ?? [];
                    $count = count($donors);
                    $accordion_index++;
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="heading<?php echo $accordion_index; ?>">
                        <button class="accordion-button <?php echo $accordion_index === 1 ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $accordion_index; ?>">
                            <?php echo htmlspecialchars($agent['name']); ?> 
                            <span class="badge bg-primary ms-2"><?php echo $count; ?> donor<?php echo $count !== 1 ? 's' : ''; ?></span>
                        </button>
                    </h2>
                    <div id="collapse<?php echo $accordion_index; ?>" class="accordion-collapse collapse <?php echo $accordion_index === 1 ? 'show' : ''; ?>" data-bs-parent="#agentsAccordion">
                        <div class="accordion-body">
                            <?php if ($count > 0): ?>
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Name</th>
                                            <th>Pledge Amount</th>
                                            <th>Balance</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($donors as $donor): ?>
                                        <tr>
                                            <td><?php echo $donor['id']; ?></td>
                                            <td><?php echo htmlspecialchars($donor['name']); ?></td>
                                            <td>£<?php echo number_format((float)$donor['total_pledged'], 2); ?></td>
                                            <td>£<?php echo number_format((float)$donor['balance'], 2); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                    <input type="hidden" name="agent_id" value="0">
                                                    <button type="submit" name="assign" class="btn btn-sm btn-outline-danger">Unassign</button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="text-muted">No donors assigned to this agent.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Unassigned Donors -->
                <?php if (count($unassigned_donors) > 0): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="headingUnassigned">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseUnassigned">
                            Unassigned Donors
                            <span class="badge bg-secondary ms-2"><?php echo count($unassigned_donors); ?> donor<?php echo count($unassigned_donors) !== 1 ? 's' : ''; ?></span>
                        </button>
                    </h2>
                    <div id="collapseUnassigned" class="accordion-collapse collapse" data-bs-parent="#agentsAccordion">
                        <div class="accordion-body">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Pledge Amount</th>
                                        <th>Balance</th>
                                        <th>Assign</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($unassigned_donors as $donor): ?>
                                    <tr>
                                        <td><?php echo $donor['id']; ?></td>
                                        <td><?php echo htmlspecialchars($donor['name']); ?></td>
                                        <td>£<?php echo number_format((float)$donor['total_pledged'], 2); ?></td>
                                        <td>£<?php echo number_format((float)$donor['balance'], 2); ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="donor_id" value="<?php echo $donor['id']; ?>">
                                                <select name="agent_id" class="form-select form-select-sm" style="display: inline-block; width: auto;">
                                                    <option value="0">Select...</option>
                                                    <?php foreach ($agents as $agent): ?>
                                                        <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" name="assign" class="btn btn-sm btn-primary ms-2">Assign</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
