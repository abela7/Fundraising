<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$db = db();
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
    
    <?php
    try {
        $result = $db->query("SELECT id, name, 
            COALESCE(total_pledged, 0) as total_pledged,
            COALESCE(total_paid, 0) as total_paid,
            (COALESCE(total_pledged, 0) - COALESCE(total_paid, 0)) as balance
            FROM donors LIMIT 50");
        
        if ($result && $result->num_rows > 0) {
            echo "<table class='table table-striped'>";
            echo "<thead><tr><th>ID</th><th>Name</th><th>Balance</th></tr></thead>";
            echo "<tbody>";
            while ($row = $result->fetch_assoc()) {
                $balance = (float)$row['balance'];
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                echo "<td>£" . number_format($balance, 2) . "</td>";
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
