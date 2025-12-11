<?php
/**
 * Messaging System Migration
 * Adds unified messaging support (SMS + WhatsApp)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';

require_admin();

$db = db();
$executionLog = [];
$hasErrors = false;

// Helper functions
$tableExists = function(string $tableName) use ($db): bool {
    $result = $db->query("SHOW TABLES LIKE '{$tableName}'");
    return $result && $result->num_rows > 0;
};

$columnExists = function(string $tableName, string $columnName) use ($db): bool {
    if (!$tableExists($tableName)) {
        return false;
    }
    $result = $db->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
    return $result && $result->num_rows > 0;
};

$indexExists = function(string $tableName, string $indexName) use ($db): bool {
    if (!$tableExists($tableName)) {
        return false;
    }
    $result = $db->query("SHOW INDEX FROM `{$tableName}` WHERE Key_name = '{$indexName}'");
    return $result && $result->num_rows > 0;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    verify_csrf();
    
    try {
        $db->begin_transaction();
        
        // Step 1: Add preferred_message_channel column
        if (!$columnExists('donors', 'preferred_message_channel')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Adding preferred_message_channel column to donors table...'];
            $db->query("
                ALTER TABLE `donors`
                ADD COLUMN `preferred_message_channel` ENUM('auto', 'sms', 'whatsapp', 'both') 
                    DEFAULT 'auto' 
                    COMMENT 'Preferred messaging channel: auto=smart selection, sms=SMS only, whatsapp=WhatsApp only, both=both channels'
                    AFTER `sms_opt_in`
            ");
            $executionLog[] = ['status' => 'success', 'message' => '✓ Column preferred_message_channel added'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ Column preferred_message_channel already exists'];
        }
        
        // Step 2: Add index
        if (!$indexExists('donors', 'idx_message_channel')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Adding idx_message_channel index...'];
            $db->query("ALTER TABLE `donors` ADD INDEX `idx_message_channel` (`preferred_message_channel`)");
            $executionLog[] = ['status' => 'success', 'message' => '✓ Index idx_message_channel added'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ Index idx_message_channel already exists'];
        }
        
        // Step 3: Create whatsapp_number_cache table
        if (!$tableExists('whatsapp_number_cache')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Creating whatsapp_number_cache table...'];
            $db->query("
                CREATE TABLE `whatsapp_number_cache` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `phone_number` VARCHAR(20) NOT NULL COMMENT 'Phone number in international format',
                    `has_whatsapp` TINYINT(1) NOT NULL COMMENT 'True if number has WhatsApp',
                    `checked_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this check was performed',
                    UNIQUE KEY `uk_phone` (`phone_number`),
                    KEY `idx_checked` (`checked_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Cache for WhatsApp number availability checks (24h TTL)'
            ");
            $executionLog[] = ['status' => 'success', 'message' => '✓ Table whatsapp_number_cache created'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ Table whatsapp_number_cache already exists'];
        }
        
        $db->commit();
        $executionLog[] = ['status' => 'success', 'message' => '🎉 Migration completed successfully!'];
        
    } catch (Exception $e) {
        $db->rollback();
        $hasErrors = true;
        $executionLog[] = ['status' => 'error', 'message' => '❌ Error: ' . $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messaging System Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .log-entry { padding: 8px; margin: 4px 0; border-radius: 4px; }
        .log-info { background: #e7f3ff; color: #0066cc; }
        .log-success { background: #d4edda; color: #155724; }
        .log-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-10 offset-md-1">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h3>Messaging System Migration</h3>
                        <p class="mb-0">Adds unified messaging support (SMS + WhatsApp)</p>
                    </div>
                    <div class="card-body">
                        <h5>What This Migration Does:</h5>
                        <ul>
                            <li>Adds <code>preferred_message_channel</code> column to <code>donors</code> table</li>
                            <li>Adds index for efficient queries</li>
                            <li>Creates <code>whatsapp_number_cache</code> table for performance</li>
                        </ul>
                        
                        <?php if (!empty($executionLog)): ?>
                            <hr>
                            <h5>Migration Log:</h5>
                            <div class="mb-3">
                                <?php foreach ($executionLog as $entry): ?>
                                    <div class="log-entry log-<?= $entry['status'] ?>">
                                        <?= htmlspecialchars($entry['message']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$hasErrors && empty($executionLog)): ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <button type="submit" name="run_migration" class="btn btn-primary btn-lg">
                                    Run Migration
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if ($hasErrors): ?>
                            <div class="alert alert-danger mt-3">
                                <strong>Migration failed!</strong> Please check the errors above and try again.
                            </div>
                        <?php elseif (!empty($executionLog) && !$hasErrors): ?>
                            <div class="alert alert-success mt-3">
                                <strong>Success!</strong> Migration completed. You can now use the unified messaging system.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

