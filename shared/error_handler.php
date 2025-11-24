<?php
declare(strict_types=1);

/**
 * Global Error Handler for Graceful Error Display
 * 
 * This prevents the "frozen page" issue when database queries fail
 * by catching errors and displaying a user-friendly error page
 * with proper HTML structure and working JavaScript.
 */

// Custom exception handler
function handleException(Throwable $exception): void
{
    // Log the error
    error_log(sprintf(
        "[%s] %s in %s:%d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    ));
    
    // Check if we're in an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        // Return JSON error for AJAX requests
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => ENVIRONMENT === 'local' ? $exception->getMessage() : 'An error occurred. Please try again.',
            'trace' => ENVIRONMENT === 'local' ? $exception->getTraceAsString() : null
        ]);
        exit;
    }
    
    // For regular page loads, show a proper error page
    showErrorPage($exception);
}

function showErrorPage(Throwable $exception): void
{
    // Determine if it's a database error
    $isDatabaseError = $exception instanceof mysqli_sql_exception || 
                       $exception instanceof RuntimeException ||
                       strpos($exception->getMessage(), 'DB connection') !== false ||
                       strpos($exception->getMessage(), 'Unknown column') !== false ||
                       strpos($exception->getMessage(), "doesn't exist") !== false;
    
    $title = $isDatabaseError ? 'Database Error' : 'Application Error';
    $icon = $isDatabaseError ? 'fa-database' : 'fa-exclamation-triangle';
    $color = $isDatabaseError ? '#dc3545' : '#ffc107';
    
    // Check for specific schema issues
    $isSchemaMissing = strpos($exception->getMessage(), 'Unknown column') !== false ||
                       strpos($exception->getMessage(), "doesn't exist") !== false;
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo htmlspecialchars($title); ?> - Fundraising System</title>
        <link rel="icon" type="image/svg+xml" href="/Fundraising/assets/favicon.svg">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .error-container {
                max-width: 800px;
                width: 90%;
                margin: 20px;
            }
            .error-card {
                background: white;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                overflow: hidden;
            }
            .error-header {
                background: <?php echo $color; ?>;
                color: white;
                padding: 30px;
                text-align: center;
            }
            .error-icon {
                font-size: 4rem;
                margin-bottom: 15px;
                animation: pulse 2s infinite;
            }
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.6; }
            }
            .error-body {
                padding: 30px;
            }
            .error-message {
                background: #f8f9fa;
                border-left: 4px solid <?php echo $color; ?>;
                padding: 15px;
                margin: 20px 0;
                border-radius: 4px;
                font-family: 'Courier New', monospace;
                font-size: 0.9rem;
                word-break: break-word;
            }
            .btn-custom {
                background: <?php echo $color; ?>;
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 8px;
                font-weight: 600;
                transition: all 0.3s;
            }
            .btn-custom:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                color: white;
            }
            .action-buttons {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                margin-top: 25px;
            }
            .stack-trace {
                background: #282c34;
                color: #abb2bf;
                padding: 15px;
                border-radius: 8px;
                font-family: 'Courier New', monospace;
                font-size: 0.85rem;
                max-height: 300px;
                overflow-y: auto;
                margin-top: 15px;
            }
            .alert-info-custom {
                background: #e7f3ff;
                border: 1px solid #b3d9ff;
                border-radius: 8px;
                padding: 15px;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-card">
                <div class="error-header">
                    <i class="fas <?php echo $icon; ?> error-icon"></i>
                    <h1 class="mb-0"><?php echo htmlspecialchars($title); ?></h1>
                </div>
                
                <div class="error-body">
                    <?php if ($isSchemaMissing): ?>
                        <div class="alert alert-warning">
                            <h5><i class="fas fa-tools me-2"></i>Database Schema Issue Detected</h5>
                            <p class="mb-0">The database is missing required columns or tables. This usually happens after a fresh installation or when a migration hasn't been run.</p>
                        </div>
                    <?php elseif ($isDatabaseError): ?>
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-circle me-2"></i>Database Connection or Query Failed</h5>
                            <p class="mb-0">The application could not communicate with the database. This might be due to connection issues or a malformed query.</p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <h5><i class="fas fa-bug me-2"></i>Application Error</h5>
                            <p class="mb-0">An unexpected error occurred while processing your request.</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (defined('ENVIRONMENT') && ENVIRONMENT === 'local'): ?>
                        <div class="error-message">
                            <strong>Error Details:</strong><br>
                            <?php echo htmlspecialchars($exception->getMessage()); ?>
                        </div>
                        
                        <details>
                            <summary style="cursor: pointer; color: #0d6efd; font-weight: 600;">
                                <i class="fas fa-code me-1"></i>View Stack Trace
                            </summary>
                            <div class="stack-trace">
                                <?php echo nl2br(htmlspecialchars($exception->getTraceAsString())); ?>
                            </div>
                        </details>
                    <?php else: ?>
                        <p class="text-muted">An error occurred. Please contact the system administrator if this persists.</p>
                    <?php endif; ?>
                    
                    <div class="alert-info-custom">
                        <h6><i class="fas fa-lightbulb me-2"></i>Common Solutions:</h6>
                        <ul class="mb-0">
                            <?php if ($isSchemaMissing): ?>
                                <li>Run the <strong>Database Readiness Check</strong> tool</li>
                                <li>Check if all SQL migration scripts have been executed</li>
                                <li>Verify the database schema matches the application requirements</li>
                            <?php elseif ($isDatabaseError): ?>
                                <li>Ensure <strong>XAMPP MySQL/MariaDB is running</strong></li>
                                <li>Check database credentials in <code>config/env.php</code></li>
                                <li>Verify the database exists and is accessible</li>
                                <li>Run pending migration scripts in phpMyAdmin</li>
                            <?php else: ?>
                                <li>Check the error log for more details</li>
                                <li>Clear your browser cache and try again</li>
                                <li>Contact the system administrator</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <div class="action-buttons">
                        <button onclick="history.back()" class="btn btn-custom">
                            <i class="fas fa-arrow-left me-2"></i>Go Back
                        </button>
                        <a href="/Fundraising/admin/dashboard/" class="btn btn-outline-primary">
                            <i class="fas fa-home me-2"></i>Dashboard
                        </a>
                        <?php if ($isSchemaMissing || $isDatabaseError): ?>
                            <a href="/Fundraising/admin/call-center/check-database.php" class="btn btn-outline-success">
                                <i class="fas fa-wrench me-2"></i>Database Check
                            </a>
                        <?php endif; ?>
                        <button onclick="location.reload()" class="btn btn-outline-secondary">
                            <i class="fas fa-sync-alt me-2"></i>Retry
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-3">
                <small class="text-white">
                    Error occurred at <?php echo date('Y-m-d H:i:s'); ?> | 
                    Environment: <?php echo defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown'; ?>
                </small>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Custom error handler for PHP errors
function handleError(int $severity, string $message, string $file, int $line): bool
{
    // Don't throw exceptions for suppressed errors (@operator)
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    throw new ErrorException($message, 0, $severity, $file, $line);
}

// Register handlers
set_exception_handler('handleException');
set_error_handler('handleError');

// Handle fatal errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        handleException(new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }
});

