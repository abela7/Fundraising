<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_admin();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Page</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-5">
    <h1>✅ Test Page Working!</h1>
    <p>If you see this, the page loads fine.</p>
    
    <button class="btn btn-primary" onclick="alert('JavaScript Working!')">Test Button</button>
    
    <hr>
    
    <h3>Checking Database...</h3>
    <?php
        require_once __DIR__ . '/../../config/db.php';
        $db = db();
        
        if ($db) {
            echo '<p class="alert alert-success">✅ Database Connected!</p>';
            
            // Simple count
            $result = $db->query("SELECT COUNT(*) as count FROM donors");
            $row = $result->fetch_assoc();
            echo '<p>Total Donors: <strong>' . $row['count'] . '</strong></p>';
        } else {
            echo '<p class="alert alert-danger">❌ Database Failed!</p>';
        }
    ?>
</div>
</body>
</html>
