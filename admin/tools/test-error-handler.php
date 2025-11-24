<?php
declare(strict_types=1);

/**
 * Error Handler Test Page
 * Use this to verify the error handler is working correctly
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/db.php';

$test = $_GET['test'] ?? 'none';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error Handler Test - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-flask me-2"></i>Error Handler Test Page</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>Purpose</h5>
                            <p class="mb-0">This page lets you trigger different types of errors to verify the error handler is working correctly. Instead of a frozen page, you should see a beautiful error page with working buttons.</p>
                        </div>

                        <h5 class="mt-4 mb-3">Trigger Test Errors:</h5>
                        
                        <div class="list-group">
                            <a href="?test=missing_column" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-database text-danger me-2"></i>Missing Column Error</h6>
                                    <small class="text-muted">Database</small>
                                </div>
                                <p class="mb-1 small">Simulates querying a column that doesn't exist</p>
                            </a>

                            <a href="?test=missing_table" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-table text-danger me-2"></i>Missing Table Error</h6>
                                    <small class="text-muted">Database</small>
                                </div>
                                <p class="mb-1 small">Simulates querying a table that doesn't exist</p>
                            </a>

                            <a href="?test=syntax_error" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-code text-warning me-2"></i>SQL Syntax Error</h6>
                                    <small class="text-muted">Database</small>
                                </div>
                                <p class="mb-1 small">Simulates a malformed SQL query</p>
                            </a>

                            <a href="?test=php_error" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-bug text-warning me-2"></i>PHP Runtime Error</h6>
                                    <small class="text-muted">Application</small>
                                </div>
                                <p class="mb-1 small">Simulates a PHP runtime error</p>
                            </a>

                            <a href="?test=exception" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><i class="fas fa-exclamation-triangle text-info me-2"></i>Custom Exception</h6>
                                    <small class="text-muted">Application</small>
                                </div>
                                <p class="mb-1 small">Simulates throwing a custom exception</p>
                            </a>
                        </div>

                        <div class="mt-4">
                            <a href="database-health-check.php" class="btn btn-primary">
                                <i class="fas fa-heartbeat me-2"></i>Run Health Check
                            </a>
                            <a href="../dashboard/" class="btn btn-outline-secondary">
                                <i class="fas fa-home me-2"></i>Dashboard
                            </a>
                        </div>

                        <?php if ($test === 'none'): ?>
                        <div class="alert alert-success mt-4">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Error handler is loaded!</strong> Click any test above to verify it's working correctly.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Execute the requested test AFTER the page layout is shown
// This ensures we can see what we're clicking
if ($test !== 'none') {
    $db = db();
    
    switch ($test) {
        case 'missing_column':
            // This should trigger "Unknown column" error
            $db->query("SELECT non_existent_column FROM donors LIMIT 1");
            break;
            
        case 'missing_table':
            // This should trigger "Table doesn't exist" error
            $db->query("SELECT * FROM table_that_does_not_exist LIMIT 1");
            break;
            
        case 'syntax_error':
            // This should trigger SQL syntax error
            $db->query("SELECT * FROM donors WHERE this is not valid SQL");
            break;
            
        case 'php_error':
            // This should trigger PHP error
            $undefined_variable = $this_does_not_exist['key'];
            break;
            
        case 'exception':
            // This should trigger custom exception
            throw new Exception("This is a test exception to verify error handling works correctly!");
            break;
    }
}
?>

