<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';

require_admin();

$database = db();
$executionLog = [];
$hasErrors = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_fix'])) {
    verify_csrf();
    
    try {
        $database->begin_transaction();
        
        // Helper function to check table existence
        $tableExists = function(string $tableName) use ($database): bool {
            $result = $database->query("SHOW TABLES LIKE '{$tableName}'");
            return $result && $result->num_rows > 0;
        };
        
        // Helper function to check column existence
        $columnExists = function(string $tableName, string $columnName) use ($database): bool {
            if (!$database->query("SHOW TABLES LIKE '{$tableName}'")->num_rows) {
                return false;
            }
            $result = $database->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
            return $result && $result->num_rows > 0;
        };
        
        // STEP 1: Create missing tables
        if (!$tableExists('donor_payment_plans')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Creating donor_payment_plans table...'];
            $sql = "CREATE TABLE donor_payment_plans (
                id INT PRIMARY KEY AUTO_INCREMENT,
                donor_id INT NOT NULL,
                pledge_id INT NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL,
                monthly_amount DECIMAL(10,2) NOT NULL,
                total_months INT NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NULL,
                status ENUM('active', 'completed', 'paused', 'defaulted', 'cancelled') DEFAULT 'active',
                payments_made INT DEFAULT 0,
                amount_paid DECIMAL(10,2) DEFAULT 0.00,
                payment_day INT NOT NULL,
                payment_method ENUM('cash', 'bank_transfer', 'card') DEFAULT 'bank_transfer',
                next_payment_due DATE NULL,
                last_payment_date DATE NULL,
                reminder_sent_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_donor_id (donor_id),
                INDEX idx_pledge_id (pledge_id),
                INDEX idx_status (status),
                INDEX idx_next_payment_due (next_payment_due)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $database->query($sql);
            $executionLog[] = ['status' => 'success', 'message' => '✓ donor_payment_plans created'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ donor_payment_plans already exists'];
        }
        
        if (!$tableExists('donors')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Creating donors table...'];
            $sql = "CREATE TABLE donors (
                id INT PRIMARY KEY AUTO_INCREMENT,
                phone VARCHAR(15) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                total_pledged DECIMAL(10,2) DEFAULT 0.00,
                total_paid DECIMAL(10,2) DEFAULT 0.00,
                balance DECIMAL(10,2) DEFAULT 0.00,
                has_active_plan BOOLEAN DEFAULT FALSE,
                active_payment_plan_id INT NULL,
                plan_monthly_amount DECIMAL(10,2) NULL,
                plan_duration_months INT NULL,
                plan_start_date DATE NULL,
                plan_next_due_date DATE NULL,
                payment_status ENUM('no_pledge', 'not_started', 'paying', 'overdue', 'completed', 'defaulted') DEFAULT 'no_pledge',
                achievement_badge ENUM('pending', 'started', 'on_track', 'fast_finisher', 'completed', 'champion') DEFAULT 'pending',
                preferred_payment_method ENUM('cash', 'bank_transfer', 'card') DEFAULT 'bank_transfer',
                preferred_payment_day INT DEFAULT 1,
                preferred_language ENUM('en', 'am', 'ti') DEFAULT 'en',
                sms_opt_in BOOLEAN DEFAULT TRUE,
                last_sms_sent_at DATETIME NULL,
                last_contacted_at DATETIME NULL,
                last_payment_date DATETIME NULL,
                portal_token VARCHAR(64) NULL UNIQUE,
                token_expires_at DATETIME NULL,
                token_generated_at DATETIME NULL,
                last_login_at DATETIME NULL,
                login_count INT DEFAULT 0,
                admin_notes TEXT NULL,
                flagged_for_followup BOOLEAN DEFAULT FALSE,
                followup_priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
                source ENUM('public_form', 'registrar', 'imported', 'admin') DEFAULT 'public_form',
                registered_by_user_id INT NULL,
                last_pledge_id INT NULL,
                pledge_count INT DEFAULT 0,
                payment_count INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_phone (phone),
                INDEX idx_payment_status (payment_status),
                INDEX idx_achievement_badge (achievement_badge),
                INDEX idx_plan_next_due_date (plan_next_due_date),
                INDEX idx_portal_token (portal_token),
                INDEX idx_preferred_language (preferred_language),
                INDEX idx_followup_flagged (flagged_for_followup, followup_priority),
                INDEX idx_balance (balance),
                INDEX idx_registered_by_user_id (registered_by_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $database->query($sql);
            $executionLog[] = ['status' => 'success', 'message' => '✓ donors table created'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ donors table already exists'];
        }
        
        if (!$tableExists('donor_audit_log')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Creating donor_audit_log table...'];
            $sql = "CREATE TABLE donor_audit_log (
                id BIGINT PRIMARY KEY AUTO_INCREMENT,
                donor_id INT NOT NULL,
                action ENUM('created', 'updated', 'plan_created', 'plan_updated', 'plan_completed', 'plan_defaulted', 'payment_received', 'status_changed', 'badge_awarded', 'token_generated', 'portal_login', 'sms_sent', 'admin_note_added', 'flagged', 'unflagged') NOT NULL,
                field_name VARCHAR(50) NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                before_json JSON NULL,
                after_json JSON NULL,
                changed_by_user_id INT NULL,
                changed_by_system BOOLEAN DEFAULT FALSE,
                ip_address VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                notes TEXT NULL,
                metadata JSON NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_donor_id (donor_id),
                INDEX idx_action (action),
                INDEX idx_created_at (created_at),
                INDEX idx_changed_by_user_id (changed_by_user_id),
                INDEX idx_action_date (action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $database->query($sql);
            $executionLog[] = ['status' => 'success', 'message' => '✓ donor_audit_log table created'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ donor_audit_log table already exists'];
        }
        
        if (!$tableExists('donor_portal_tokens')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Creating donor_portal_tokens table...'];
            $sql = "CREATE TABLE donor_portal_tokens (
                id INT PRIMARY KEY AUTO_INCREMENT,
                donor_id INT NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                token_type ENUM('portal_access', 'sms_verify', 'password_reset') DEFAULT 'portal_access',
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                is_active BOOLEAN DEFAULT TRUE,
                ip_address_generated VARBINARY(16) NULL,
                ip_address_used VARBINARY(16) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_token (token),
                INDEX idx_donor_id (donor_id),
                INDEX idx_is_active (is_active),
                INDEX idx_expires_at (expires_at),
                INDEX idx_token_type (token_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $database->query($sql);
            $executionLog[] = ['status' => 'success', 'message' => '✓ donor_portal_tokens table created'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ donor_portal_tokens table already exists'];
        }
        
        // STEP 2: Add missing columns
        if ($tableExists('pledges') && !$columnExists('pledges', 'donor_id')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Adding donor_id to pledges...'];
            $database->query("ALTER TABLE pledges ADD COLUMN donor_id INT NULL AFTER id, ADD INDEX idx_donor_id (donor_id)");
            $executionLog[] = ['status' => 'success', 'message' => '✓ pledges.donor_id added'];
        } else {
            $executionLog[] = ['status' => 'success', 'message' => '✓ pledges.donor_id already exists'];
        }
        
        if ($tableExists('payments')) {
            if (!$columnExists('payments', 'donor_id')) {
                $executionLog[] = ['status' => 'info', 'message' => 'Adding donor_id to payments...'];
                $database->query("ALTER TABLE payments ADD COLUMN donor_id INT NULL AFTER id, ADD INDEX idx_donor_id_payments (donor_id)");
                $executionLog[] = ['status' => 'success', 'message' => '✓ payments.donor_id added'];
            }
            if (!$columnExists('payments', 'pledge_id')) {
                $executionLog[] = ['status' => 'info', 'message' => 'Adding pledge_id to payments...'];
                $database->query("ALTER TABLE payments ADD COLUMN pledge_id INT NULL AFTER donor_id, ADD INDEX idx_pledge_id_payments (pledge_id)");
                $executionLog[] = ['status' => 'success', 'message' => '✓ payments.pledge_id added'];
            }
            if (!$columnExists('payments', 'installment_number')) {
                $executionLog[] = ['status' => 'info', 'message' => 'Adding installment_number to payments...'];
                $database->query("ALTER TABLE payments ADD COLUMN installment_number INT NULL AFTER pledge_id");
                $executionLog[] = ['status' => 'success', 'message' => '✓ payments.installment_number added'];
            }
        }
        
        // STEP 3: Populate donors if table is empty
        if ($tableExists('donors')) {
            $count = $database->query("SELECT COUNT(*) as c FROM donors")->fetch_assoc()['c'];
            if ($count == 0) {
                $executionLog[] = ['status' => 'info', 'message' => 'Populating donors from pledges...'];
                $database->query("INSERT INTO donors (phone, name, source, created_at, pledge_count, total_pledged)
                    SELECT pledges.donor_phone, MAX(pledges.donor_name), MAX(pledges.source), MIN(pledges.created_at),
                           COUNT(*), SUM(CASE WHEN pledges.status IN ('approved', 'pending') THEN pledges.amount ELSE 0 END)
                    FROM pledges
                    WHERE pledges.donor_phone IS NOT NULL AND pledges.donor_phone != ''
                    GROUP BY pledges.donor_phone");
                $executionLog[] = ['status' => 'success', 'message' => '✓ Donors populated (' . $database->affected_rows . ' donors)'];
            }
        }
        
        // STEP 4: Link pledges to donors
        if ($columnExists('pledges', 'donor_id')) {
            $unlinked = $database->query("SELECT COUNT(*) as c FROM pledges WHERE donor_id IS NULL")->fetch_assoc()['c'];
            if ($unlinked > 0) {
                $executionLog[] = ['status' => 'info', 'message' => "Linking {$unlinked} pledges to donors..."];
                $database->query("UPDATE pledges
                    INNER JOIN donors ON donors.phone = pledges.donor_phone
                    SET pledges.donor_id = donors.id
                    WHERE pledges.donor_phone IS NOT NULL");
                $executionLog[] = ['status' => 'success', 'message' => '✓ Pledges linked (' . $database->affected_rows . ' linked)'];
            }
        }
        
        // STEP 5: Link payments to donors
        if ($columnExists('payments', 'donor_id')) {
            $unlinked = $database->query("SELECT COUNT(*) as c FROM payments WHERE donor_id IS NULL")->fetch_assoc()['c'];
            if ($unlinked > 0) {
                $executionLog[] = ['status' => 'info', 'message' => "Linking {$unlinked} payments to donors..."];
                $database->query("UPDATE payments
                    INNER JOIN donors ON donors.phone = payments.donor_phone
                    SET payments.donor_id = donors.id
                    WHERE payments.donor_phone IS NOT NULL");
                $executionLog[] = ['status' => 'success', 'message' => '✓ Payments linked (' . $database->affected_rows . ' linked)'];
            }
        }
        
        // STEP 6: Update donor totals
        if ($tableExists('donors')) {
            $executionLog[] = ['status' => 'info', 'message' => 'Updating donor financial totals...'];
            $database->query("UPDATE donors SET total_paid = (
                SELECT IFNULL(SUM(payments.amount), 0) FROM payments 
                WHERE payments.donor_id = donors.id AND payments.status = 'approved'
            )");
            $database->query("UPDATE donors SET balance = total_pledged - total_paid");
            $database->query("UPDATE donors SET payment_status = 
                CASE 
                    WHEN total_pledged = 0 THEN 'no_pledge'
                    WHEN total_paid = 0 THEN 'not_started'
                    WHEN total_paid >= total_pledged THEN 'completed'
                    WHEN total_paid > 0 AND total_paid < total_pledged THEN 'paying'
                    ELSE 'not_started'
                END");
            $database->query("UPDATE donors SET achievement_badge = 
                CASE 
                    WHEN total_paid = 0 THEN 'pending'
                    WHEN total_paid > 0 AND total_paid < total_pledged THEN 'started'
                    WHEN total_paid >= total_pledged THEN 'completed'
                    ELSE 'pending'
                END");
            $executionLog[] = ['status' => 'success', 'message' => '✓ Donor totals and statuses updated'];
        }
        
        $database->commit();
        $executionLog[] = ['status' => 'success', 'message' => '🎉 Migration fix completed successfully!'];
        
    } catch (Exception $exception) {
        $database->rollback();
        $hasErrors = true;
        $executionLog[] = ['status' => 'error', 'message' => '❌ Error: ' . $exception->getMessage()];
        $executionLog[] = ['status' => 'warning', 'message' => 'Changes rolled back'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Partial Migration | Admin Tools</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 2rem 0; }
        .fix-container { max-width: 900px; margin: 0 auto; }
        .fix-card { background: white; border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); overflow: hidden; }
        .fix-header { background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); color: white; padding: 2rem; text-align: center; }
        .fix-body { padding: 2rem; }
        .log-entry { padding: 1rem; margin-bottom: 0.5rem; border-radius: 8px; border-left: 4px solid; }
        .log-entry.success { background: #f0fdf4; border-color: #10b981; }
        .log-entry.error { background: #fef2f2; border-color: #ef4444; }
        .log-entry.warning { background: #fffbeb; border-color: #f59e0b; }
        .log-entry.info { background: #eff6ff; border-color: #3b82f6; }
    </style>
</head>
<body>
    <div class="fix-container">
        <div class="fix-card">
            <div class="fix-header">
                <h1><i class="bi bi-tools"></i> Fix Partial Migration</h1>
                <p class="mb-0">Complete the donor system migration safely</p>
            </div>
            
            <div class="fix-body">
                <?php if (empty($executionLog)): ?>
                    <div class="alert alert-warning">
                        <h5><i class="bi bi-exclamation-triangle-fill"></i> About This Tool</h5>
                        <p>This tool will:</p>
                        <ul>
                            <li>✅ Create any missing tables</li>
                            <li>✅ Add any missing columns</li>
                            <li>✅ Link existing pledges and payments to donors</li>
                            <li>✅ Calculate all financial totals</li>
                            <li>✅ Skip foreign key constraints (they're not critical)</li>
                        </ul>
                        <p class="mb-0"><strong>Note:</strong> This is safe to run. It uses transactions and will rollback on any error.</p>
                    </div>
                    
                    <form method="POST">
                        <?= csrf_input() ?>
                        <div class="text-center">
                            <button type="submit" name="run_fix" class="btn btn-warning btn-lg">
                                <i class="bi bi-tools"></i> Run Migration Fix
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <h5><i class="bi bi-journal-text"></i> Execution Log</h5>
                    <?php foreach ($executionLog as $entry): ?>
                        <div class="log-entry <?= $entry['status'] ?>">
                            <?= htmlspecialchars($entry['message']) ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="mt-4 text-center">
                        <a href="check_migration_status.php" class="btn btn-primary">
                            <i class="bi bi-clipboard-check"></i> Check Status Again
                        </a>
                        <?php if (!$hasErrors): ?>
                            <a href="/admin/dashboard/" class="btn btn-success">
                                <i class="bi bi-speedometer2"></i> Go to Dashboard
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

