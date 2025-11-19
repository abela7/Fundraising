<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
echo "<h3>Payments Table Structure</h3>";
echo "<pre>";
$cols = $db->query("SHOW COLUMNS FROM payments");
while ($col = $cols->fetch_assoc()) {
    echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "</pre>";
?>

