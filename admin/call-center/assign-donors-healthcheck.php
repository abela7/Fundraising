<?php
declare(strict_types=1);

// SIMPLE HEALTHCHECK FOR assign-donors PAGE
// This file does NOT run any complex queries.
// It only steps through includes and echoes markers.

function hc_step(string $label): void {
    echo "<p>{$label}</p>\n";
    @ob_flush();
    @flush();
}

@ob_start();
hc_step('STEP 1: healthcheck.php started');

// 1) Load auth + db
require_once __DIR__ . '/../../shared/auth.php';
hc_step('STEP 2: shared/auth.php loaded');

require_once __DIR__ . '/../../config/db.php';
hc_step('STEP 3: config/db.php loaded (env + db function available)');

// 2) Auth check
try {
    require_admin();
    hc_step('STEP 4: require_admin() OK (you are logged in as admin/registrar)');
} catch (Throwable $e) {
    hc_step('STEP 4 ERROR: require_admin() failed: ' . htmlspecialchars($e->getMessage()));
    exit;
}

// 3) Try DB connection
try {
    $db = db();
    hc_step('STEP 5: db() connection OK');

    // Very small test query
    $res = $db->query('SELECT 1 AS ok');
    $row = $res ? $res->fetch_assoc() : null;
    hc_step('STEP 6: Test query OK, value=' . htmlspecialchars((string)($row['ok'] ?? 'null')));
} catch (Throwable $e) {
    hc_step('STEP 5/6 ERROR: DB connection or query failed: ' . htmlspecialchars($e->getMessage()));
    exit;
}

// 4) Include sidebar + topbar
try {
    hc_step('STEP 7: About to include sidebar.php');
    include __DIR__ . '/../includes/sidebar.php';
    hc_step('STEP 8: sidebar.php included OK');
} catch (Throwable $e) {
    hc_step('STEP 7/8 ERROR: sidebar.php failed: ' . htmlspecialchars($e->getMessage()));
    exit;
}

try {
    hc_step('STEP 9: About to include topbar.php');
    include __DIR__ . '/../includes/topbar.php';
    hc_step('STEP 10: topbar.php included OK');
} catch (Throwable $e) {
    hc_step('STEP 9/10 ERROR: topbar.php failed: ' . htmlspecialchars($e->getMessage()));
    exit;
}

// 5) Finish
hc_step('STEP 11: healthcheck COMPLETE - if you see this, core wiring is OK');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Assign Donors Healthcheck</title>
</head>
<body>
    <h1>Assign Donors Healthcheck</h1>
    <p>If you can see STEP 11 above, then auth, DB, sidebar and topbar are all wired correctly.</p>
    <p>If it stops earlier, tell me the LAST step you see.</p>
</body>
</html>


