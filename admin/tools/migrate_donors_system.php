<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

// Admin only - uses proper auth check
require_admin();

$database = db();
$migrationLog = [];
$errors = [];
$warnings = [];
$startTime = microtime(true);
$migrationCompleted = false;

// Check if migration was already run
function checkIfMigrationAlreadyRun($database): bool {
    try {
        $result = $database->query("SHOW TABLES LIKE 'donors'");
        return $result && $result->num_rows > 0;
    } catch (Exception $exception) {
        return false;
    }
}

$alreadyMigrated = checkIfMigrationAlreadyRun($database);

// Run migration if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_migration']) && !$alreadyMigrated) {
    
    $migrationLog[] = ['step' => 'Starting Migration', 'status' => 'info', 'message' => 'Beginning donor system migration...'];
    
    try {
        // Start transaction
        $database->begin_transaction();
        $migrationLog[] = ['step' => 'Transaction Started', 'status' => 'success', 'message' => 'Database transaction initiated'];
        
        // STEP 1: Create donor_payment_plans table
        $migrationLog[] = ['step' => 'Step 1', 'status' => 'info', 'message' => 'Creating donor_payment_plans table...'];
        $sqlCreatePaymentPlans = "CREATE TABLE IF NOT EXISTS donor_payment_plans (
            id INT PRIMARY KEY AUTO_INCREMENT,
            donor_id INT NOT NULL,
            pledge_id INT NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL COMMENT 'Total pledge amount to be paid',
            monthly_amount DECIMAL(10,2) NOT NULL COMMENT 'Amount due each month',
            total_months INT NOT NULL COMMENT 'Number of months in plan',
            start_date DATE NOT NULL COMMENT 'When plan starts',
            end_date DATE GENERATED ALWAYS AS (DATE_ADD(start_date, INTERVAL total_months MONTH)) STORED COMMENT 'Auto-calculated end date',
            status ENUM('active', 'completed', 'paused', 'defaulted', 'cancelled') DEFAULT 'active',
            payments_made INT DEFAULT 0 COMMENT 'Number of installments received',
            amount_paid DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total amount paid so far',
            payment_day INT NOT NULL COMMENT 'Day of month payment is due (1-28)',
            payment_method ENUM('cash', 'bank_transfer', 'card') DEFAULT 'bank_transfer',
            next_payment_due DATE NULL COMMENT 'Next scheduled payment date',
            last_payment_date DATE NULL COMMENT 'Most recent payment received',
            reminder_sent_at DATETIME NULL COMMENT 'When last reminder was sent',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_donor_id (donor_id),
            INDEX idx_pledge_id (pledge_id),
            INDEX idx_status (status),
            INDEX idx_next_payment_due (next_payment_due)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Payment plan instances for each donor pledge'";
        
        if ($database->query($sqlCreatePaymentPlans)) {
            $migrationLog[] = ['step' => 'Step 1', 'status' => 'success', 'message' => 'donor_payment_plans table created successfully'];
        } else {
            throw new Exception('Failed to create donor_payment_plans table: ' . $database->error);
        }
        
        // STEP 2: Create donors table
        $migrationLog[] = ['step' => 'Step 2', 'status' => 'info', 'message' => 'Creating donors table...'];
        $sqlCreateDonors = "CREATE TABLE IF NOT EXISTS donors (
            id INT PRIMARY KEY AUTO_INCREMENT,
            phone VARCHAR(15) UNIQUE NOT NULL COMMENT 'UK mobile format 07XXXXXXXXX',
            name VARCHAR(255) NOT NULL COMMENT 'Donor full name',
            total_pledged DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Sum of all approved pledges',
            total_paid DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Sum of all approved payments',
            balance DECIMAL(10,2) GENERATED ALWAYS AS (total_pledged - total_paid) STORED COMMENT 'Auto-calculated outstanding balance',
            has_active_plan BOOLEAN DEFAULT FALSE COMMENT 'Does donor have active payment plan?',
            active_payment_plan_id INT NULL COMMENT 'Current active payment plan ID',
            plan_monthly_amount DECIMAL(10,2) NULL COMMENT 'Monthly installment amount',
            plan_duration_months INT NULL COMMENT 'Plan duration in months',
            plan_start_date DATE NULL COMMENT 'When payment plan started',
            plan_next_due_date DATE NULL COMMENT 'Next payment due date',
            payment_status ENUM('no_pledge', 'not_started', 'paying', 'overdue', 'completed', 'defaulted') DEFAULT 'no_pledge' COMMENT 'Current payment lifecycle status',
            achievement_badge ENUM('pending', 'started', 'on_track', 'fast_finisher', 'completed', 'champion') DEFAULT 'pending' COMMENT 'UI badge for donor recognition',
            preferred_payment_method ENUM('cash', 'bank_transfer', 'card') DEFAULT 'bank_transfer' COMMENT 'Donor preferred payment method',
            preferred_payment_day INT DEFAULT 1 COMMENT 'Preferred day of month for payments (1-28)',
            preferred_language ENUM('en', 'am', 'ti') DEFAULT 'en' COMMENT 'Portal language: en=English, am=Amharic, ti=Tigrinya',
            sms_opt_in BOOLEAN DEFAULT TRUE COMMENT 'Donor consent for SMS reminders',
            last_sms_sent_at DATETIME NULL COMMENT 'When last SMS was sent',
            last_contacted_at DATETIME NULL COMMENT 'Last admin contact (any method)',
            last_payment_date DATETIME NULL COMMENT 'Most recent payment received',
            portal_token VARCHAR(64) NULL UNIQUE COMMENT 'Secure random token for portal access',
            token_expires_at DATETIME NULL COMMENT 'Token expiration date/time',
            token_generated_at DATETIME NULL COMMENT 'When token was created',
            last_login_at DATETIME NULL COMMENT 'Last portal login timestamp',
            login_count INT DEFAULT 0 COMMENT 'Total number of portal logins',
            admin_notes TEXT NULL COMMENT 'Internal admin notes and follow-up info',
            flagged_for_followup BOOLEAN DEFAULT FALSE COMMENT 'Needs admin attention',
            followup_priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' COMMENT 'Follow-up priority level',
            source ENUM('public_form', 'registrar', 'imported', 'admin') DEFAULT 'public_form' COMMENT 'How donor was registered',
            registered_by_user_id INT NULL COMMENT 'User ID who registered this donor',
            last_pledge_id INT NULL COMMENT 'Most recent pledge ID (optimization)',
            pledge_count INT DEFAULT 0 COMMENT 'Total number of pledges made',
            payment_count INT DEFAULT 0 COMMENT 'Total number of payments made',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Donor registration date',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification date',
            INDEX idx_phone (phone),
            INDEX idx_payment_status (payment_status),
            INDEX idx_achievement_badge (achievement_badge),
            INDEX idx_plan_next_due_date (plan_next_due_date),
            INDEX idx_portal_token (portal_token),
            INDEX idx_preferred_language (preferred_language),
            INDEX idx_followup_flagged (flagged_for_followup, followup_priority),
            INDEX idx_balance (balance),
            INDEX idx_registered_by_user_id (registered_by_user_id),
            FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Central donor registry with payment plans and portal access'";
        
        if ($database->query($sqlCreateDonors)) {
            $migrationLog[] = ['step' => 'Step 2', 'status' => 'success', 'message' => 'donors table created successfully'];
        } else {
            throw new Exception('Failed to create donors table: ' . $database->error);
        }
        
        // STEP 3: Add foreign key constraints
        $migrationLog[] = ['step' => 'Step 3', 'status' => 'info', 'message' => 'Adding foreign key constraints...'];
        
        $sqlForeignKey1 = "ALTER TABLE donors ADD CONSTRAINT fk_donors_active_payment_plan FOREIGN KEY (active_payment_plan_id) REFERENCES donor_payment_plans(id) ON DELETE SET NULL";
        if ($database->query($sqlForeignKey1)) {
            $migrationLog[] = ['step' => 'Step 3a', 'status' => 'success', 'message' => 'Added FK: donors → donor_payment_plans'];
        } else {
            throw new Exception('Failed to add FK constraint on donors: ' . $database->error);
        }
        
        $sqlForeignKey2 = "ALTER TABLE donor_payment_plans ADD CONSTRAINT fk_payment_plans_donor FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE";
        if ($database->query($sqlForeignKey2)) {
            $migrationLog[] = ['step' => 'Step 3b', 'status' => 'success', 'message' => 'Added FK: donor_payment_plans → donors'];
        } else {
            throw new Exception('Failed to add FK constraint on donor_payment_plans (donor): ' . $database->error);
        }
        
        $sqlForeignKey3 = "ALTER TABLE donor_payment_plans ADD CONSTRAINT fk_payment_plans_pledge FOREIGN KEY (pledge_id) REFERENCES pledges(id) ON DELETE CASCADE";
        if ($database->query($sqlForeignKey3)) {
            $migrationLog[] = ['step' => 'Step 3c', 'status' => 'success', 'message' => 'Added FK: donor_payment_plans → pledges'];
        } else {
            throw new Exception('Failed to add FK constraint on donor_payment_plans (pledge): ' . $database->error);
        }
        
        // STEP 4: Enhance pledges table
        $migrationLog[] = ['step' => 'Step 4', 'status' => 'info', 'message' => 'Enhancing pledges table...'];
        $sqlAlterPledges = "ALTER TABLE pledges ADD COLUMN donor_id INT NULL COMMENT 'Link to donors table' AFTER id, ADD INDEX idx_donor_id (donor_id), ADD CONSTRAINT fk_pledges_donor FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL";
        
        if ($database->query($sqlAlterPledges)) {
            $migrationLog[] = ['step' => 'Step 4', 'status' => 'success', 'message' => 'pledges table enhanced with donor_id column'];
        } else {
            throw new Exception('Failed to alter pledges table: ' . $database->error);
        }
        
        // STEP 5: Enhance payments table
        $migrationLog[] = ['step' => 'Step 5', 'status' => 'info', 'message' => 'Enhancing payments table...'];
        $sqlAlterPayments = "ALTER TABLE payments 
            ADD COLUMN donor_id INT NULL COMMENT 'Link to donors table' AFTER id,
            ADD COLUMN pledge_id INT NULL COMMENT 'Link to specific pledge being fulfilled' AFTER donor_id,
            ADD COLUMN installment_number INT NULL COMMENT 'Which installment (1 of 6, 2 of 6, etc.)' AFTER pledge_id,
            ADD INDEX idx_donor_id (donor_id),
            ADD INDEX idx_pledge_id (pledge_id),
            ADD CONSTRAINT fk_payments_donor FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_payments_pledge FOREIGN KEY (pledge_id) REFERENCES pledges(id) ON DELETE SET NULL";
        
        if ($database->query($sqlAlterPayments)) {
            $migrationLog[] = ['step' => 'Step 5', 'status' => 'success', 'message' => 'payments table enhanced with donor_id, pledge_id, installment_number columns'];
        } else {
            throw new Exception('Failed to alter payments table: ' . $database->error);
        }
        
        // STEP 6: Create audit log table
        $migrationLog[] = ['step' => 'Step 6', 'status' => 'info', 'message' => 'Creating donor_audit_log table...'];
        $sqlCreateAuditLog = "CREATE TABLE IF NOT EXISTS donor_audit_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            donor_id INT NOT NULL COMMENT 'Donor this audit entry relates to',
            action ENUM('created', 'updated', 'plan_created', 'plan_updated', 'plan_completed', 'plan_defaulted', 'payment_received', 'status_changed', 'badge_awarded', 'token_generated', 'portal_login', 'sms_sent', 'admin_note_added', 'flagged', 'unflagged') NOT NULL COMMENT 'Type of action performed',
            field_name VARCHAR(50) NULL COMMENT 'Specific field that changed',
            old_value TEXT NULL COMMENT 'Previous value before change',
            new_value TEXT NULL COMMENT 'New value after change',
            before_json JSON NULL COMMENT 'Complete donor state before change',
            after_json JSON NULL COMMENT 'Complete donor state after change',
            changed_by_user_id INT NULL COMMENT 'Admin/registrar who made the change',
            changed_by_system BOOLEAN DEFAULT FALSE COMMENT 'Was this an automated system change?',
            ip_address VARBINARY(16) NULL COMMENT 'IP address of change origin',
            user_agent VARCHAR(255) NULL COMMENT 'Browser/device user agent',
            notes TEXT NULL COMMENT 'Admin reason or notes for this change',
            metadata JSON NULL COMMENT 'Additional context (pledge_id, payment_id, etc.)',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this audit entry was created',
            INDEX idx_donor_id (donor_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            INDEX idx_changed_by_user_id (changed_by_user_id),
            INDEX idx_action_date (action, created_at),
            FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Comprehensive audit trail for all donor activities and changes'";
        
        if ($database->query($sqlCreateAuditLog)) {
            $migrationLog[] = ['step' => 'Step 6', 'status' => 'success', 'message' => 'donor_audit_log table created successfully'];
        } else {
            throw new Exception('Failed to create donor_audit_log table: ' . $database->error);
        }
        
        // STEP 7: Create token security table
        $migrationLog[] = ['step' => 'Step 7', 'status' => 'info', 'message' => 'Creating donor_portal_tokens table...'];
        $sqlCreateTokens = "CREATE TABLE IF NOT EXISTS donor_portal_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            donor_id INT NOT NULL COMMENT 'Donor this token belongs to',
            token VARCHAR(64) NOT NULL UNIQUE COMMENT 'Cryptographically secure random token (64-char hex)',
            token_type ENUM('portal_access', 'sms_verify', 'password_reset') DEFAULT 'portal_access' COMMENT 'Purpose of this token',
            expires_at DATETIME NOT NULL COMMENT 'When this token expires',
            used_at DATETIME NULL COMMENT 'When token was used (NULL = not used yet)',
            revoked_at DATETIME NULL COMMENT 'When token was manually revoked (NULL = still valid)',
            is_active BOOLEAN GENERATED ALWAYS AS (used_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()) STORED COMMENT 'Computed: is this token currently valid?',
            ip_address_generated VARBINARY(16) NULL COMMENT 'IP where token was generated',
            ip_address_used VARBINARY(16) NULL COMMENT 'IP where token was used',
            user_agent VARCHAR(255) NULL COMMENT 'Browser/device that used token',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When token was created',
            INDEX idx_token (token),
            INDEX idx_donor_id (donor_id),
            INDEX idx_is_active (is_active),
            INDEX idx_expires_at (expires_at),
            INDEX idx_token_type (token_type),
            FOREIGN KEY (donor_id) REFERENCES donors(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Secure token management for donor portal access and verification'";
        
        if ($database->query($sqlCreateTokens)) {
            $migrationLog[] = ['step' => 'Step 7', 'status' => 'success', 'message' => 'donor_portal_tokens table created successfully'];
        } else {
            throw new Exception('Failed to create donor_portal_tokens table: ' . $database->error);
        }
        
        // STEP 8: Populate donors from existing pledges
        $migrationLog[] = ['step' => 'Step 8', 'status' => 'info', 'message' => 'Populating donors from existing pledges...'];
        $sqlPopulateDonors = "INSERT INTO donors (phone, name, source, created_at, pledge_count, total_pledged)
            SELECT 
                pledges.donor_phone AS phone,
                MAX(pledges.donor_name) AS name,
                MAX(pledges.source) AS source,
                MIN(pledges.created_at) AS created_at,
                COUNT(*) AS pledge_count,
                SUM(CASE WHEN pledges.status IN ('approved', 'pending') THEN pledges.amount ELSE 0 END) AS total_pledged
            FROM pledges
            WHERE pledges.donor_phone IS NOT NULL AND pledges.donor_phone != ''
            GROUP BY pledges.donor_phone
            ON DUPLICATE KEY UPDATE 
                pledge_count = VALUES(pledge_count),
                total_pledged = VALUES(total_pledged)";
        
        if ($database->query($sqlPopulateDonors)) {
            $donorsCreated = $database->affected_rows;
            $migrationLog[] = ['step' => 'Step 8', 'status' => 'success', 'message' => "{$donorsCreated} donors created from existing pledges"];
        } else {
            throw new Exception('Failed to populate donors: ' . $database->error);
        }
        
        // STEP 9: Link existing pledges to donors
        $migrationLog[] = ['step' => 'Step 9', 'status' => 'info', 'message' => 'Linking existing pledges to donors...'];
        $sqlLinkPledges = "UPDATE pledges
            INNER JOIN donors ON donors.phone = pledges.donor_phone
            SET pledges.donor_id = donors.id
            WHERE pledges.donor_phone IS NOT NULL AND pledges.donor_phone != ''";
        
        if ($database->query($sqlLinkPledges)) {
            $pledgesLinked = $database->affected_rows;
            $migrationLog[] = ['step' => 'Step 9', 'status' => 'success', 'message' => "{$pledgesLinked} pledges linked to donors"];
        } else {
            throw new Exception('Failed to link pledges to donors: ' . $database->error);
        }
        
        // STEP 10: Link existing payments to donors
        $migrationLog[] = ['step' => 'Step 10', 'status' => 'info', 'message' => 'Linking existing payments to donors...'];
        $sqlLinkPayments = "UPDATE payments
            INNER JOIN donors ON donors.phone = payments.donor_phone
            SET payments.donor_id = donors.id
            WHERE payments.donor_phone IS NOT NULL AND payments.donor_phone != ''";
        
        if ($database->query($sqlLinkPayments)) {
            $paymentsLinked = $database->affected_rows;
            $migrationLog[] = ['step' => 'Step 10', 'status' => 'success', 'message' => "{$paymentsLinked} payments linked to donors"];
        } else {
            throw new Exception('Failed to link payments to donors: ' . $database->error);
        }
        
        // STEP 11: Update donor total_paid amounts
        $migrationLog[] = ['step' => 'Step 11', 'status' => 'info', 'message' => 'Calculating total paid amounts for donors...'];
        $sqlUpdateTotalPaid = "UPDATE donors
            SET donors.total_paid = (
                SELECT IFNULL(SUM(payments.amount), 0)
                FROM payments
                WHERE payments.donor_id = donors.id AND payments.status = 'approved'
            )";
        
        if ($database->query($sqlUpdateTotalPaid)) {
            $migrationLog[] = ['step' => 'Step 11', 'status' => 'success', 'message' => 'Donor total_paid amounts calculated'];
        } else {
            throw new Exception('Failed to update total_paid: ' . $database->error);
        }
        
        // STEP 12: Update donor payment_status
        $migrationLog[] = ['step' => 'Step 12', 'status' => 'info', 'message' => 'Updating donor payment statuses...'];
        $sqlUpdatePaymentStatus = "UPDATE donors
            SET donors.payment_status = 
                CASE 
                    WHEN donors.total_pledged = 0 THEN 'no_pledge'
                    WHEN donors.total_paid = 0 THEN 'not_started'
                    WHEN donors.total_paid >= donors.total_pledged THEN 'completed'
                    WHEN donors.total_paid > 0 AND donors.total_paid < donors.total_pledged THEN 'paying'
                    ELSE 'not_started'
                END";
        
        if ($database->query($sqlUpdatePaymentStatus)) {
            $migrationLog[] = ['step' => 'Step 12', 'status' => 'success', 'message' => 'Donor payment statuses updated'];
        } else {
            throw new Exception('Failed to update payment_status: ' . $database->error);
        }
        
        // STEP 13: Update achievement badges
        $migrationLog[] = ['step' => 'Step 13', 'status' => 'info', 'message' => 'Updating donor achievement badges...'];
        $sqlUpdateBadges = "UPDATE donors
            SET donors.achievement_badge = 
                CASE 
                    WHEN donors.total_paid = 0 THEN 'pending'
                    WHEN donors.total_paid > 0 AND donors.total_paid < donors.total_pledged THEN 'started'
                    WHEN donors.total_paid >= donors.total_pledged THEN 'completed'
                    ELSE 'pending'
                END";
        
        if ($database->query($sqlUpdateBadges)) {
            $migrationLog[] = ['step' => 'Step 13', 'status' => 'success', 'message' => 'Donor achievement badges updated'];
        } else {
            throw new Exception('Failed to update achievement_badge: ' . $database->error);
        }
        
        // Commit transaction
        $database->commit();
        $migrationLog[] = ['step' => 'Transaction Committed', 'status' => 'success', 'message' => 'All changes committed to database'];
        
        $migrationCompleted = true;
        $migrationLog[] = ['step' => 'Migration Complete', 'status' => 'success', 'message' => '✅ Migration completed successfully!'];
        
    } catch (Exception $exception) {
        // Rollback on error
        $database->rollback();
        $errors[] = $exception->getMessage();
        $migrationLog[] = ['step' => 'Error', 'status' => 'error', 'message' => 'Migration failed: ' . $exception->getMessage()];
        $migrationLog[] = ['step' => 'Transaction Rolled Back', 'status' => 'warning', 'message' => 'All changes have been rolled back'];
    }
}

// Get verification statistics
$stats = [];
if ($alreadyMigrated || $migrationCompleted) {
    try {
        // Count donors
        $result = $database->query("SELECT COUNT(*) as count FROM donors");
        $stats['donors_count'] = $result->fetch_assoc()['count'];
        
        // Count linked pledges
        $result = $database->query("SELECT COUNT(*) as count FROM pledges WHERE donor_id IS NOT NULL");
        $stats['pledges_linked'] = $result->fetch_assoc()['count'];
        
        // Count linked payments
        $result = $database->query("SELECT COUNT(*) as count FROM payments WHERE donor_id IS NOT NULL");
        $stats['payments_linked'] = $result->fetch_assoc()['count'];
        
        // Total pledged
        $result = $database->query("SELECT IFNULL(SUM(total_pledged), 0) as total FROM donors");
        $stats['total_pledged'] = $result->fetch_assoc()['total'];
        
        // Total paid
        $result = $database->query("SELECT IFNULL(SUM(total_paid), 0) as total FROM donors");
        $stats['total_paid'] = $result->fetch_assoc()['total'];
        
        // Outstanding balance
        $result = $database->query("SELECT IFNULL(SUM(balance), 0) as total FROM donors");
        $stats['outstanding_balance'] = $result->fetch_assoc()['total'];
        
        // Payment status breakdown
        $result = $database->query("SELECT payment_status, COUNT(*) as count FROM donors GROUP BY payment_status");
        $stats['status_breakdown'] = [];
        while ($row = $result->fetch_assoc()) {
            $stats['status_breakdown'][$row['payment_status']] = $row['count'];
        }
        
    } catch (Exception $exception) {
        $warnings[] = 'Could not fetch verification statistics: ' . $exception->getMessage();
    }
}

$executionTime = round((microtime(true) - $startTime) * 1000, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor System Migration | Admin Tools</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .migration-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .migration-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .migration-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .migration-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .migration-body {
            padding: 2rem;
        }
        
        .log-entry {
            padding: 1rem;
            margin-bottom: 0.5rem;
            border-radius: 8px;
            border-left: 4px solid;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        
        .log-entry.success {
            background: #f0fdf4;
            border-color: var(--success);
        }
        
        .log-entry.error {
            background: #fef2f2;
            border-color: var(--danger);
        }
        
        .log-entry.warning {
            background: #fffbeb;
            border-color: var(--warning);
        }
        
        .log-entry.info {
            background: #eff6ff;
            border-color: var(--info);
        }
        
        .log-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-card p {
            margin: 0;
            opacity: 0.9;
        }
        
        .btn-migrate {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
            transition: transform 0.2s;
        }
        
        .btn-migrate:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .badge-no_pledge { background: #e5e7eb; color: #374151; }
        .badge-not_started { background: #fef3c7; color: #92400e; }
        .badge-paying { background: #dbeafe; color: #1e40af; }
        .badge-overdue { background: #fee2e2; color: #991b1b; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-defaulted { background: #fecaca; color: #7f1d1d; }
    </style>
</head>
<body>
    <div class="migration-container">
        <div class="migration-card">
            <div class="migration-header">
                <h1><i class="bi bi-database-gear"></i> Donor System Migration</h1>
                <p>Database Migration v1.0 - Payment Plans & Donor Portal</p>
            </div>
            
            <div class="migration-body">
                <?php if ($alreadyMigrated && !$migrationCompleted): ?>
                    <!-- Already migrated -->
                    <div class="alert alert-info d-flex align-items-center" role="alert">
                        <i class="bi bi-info-circle-fill fs-3 me-3"></i>
                        <div>
                            <h4 class="alert-heading">Migration Already Completed</h4>
                            <p class="mb-0">The donor system has already been migrated. The donors table exists in your database.</p>
                        </div>
                    </div>
                    
                <?php elseif (!$migrationCompleted && empty($migrationLog)): ?>
                    <!-- Not started yet -->
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                        <div>
                            <h4 class="alert-heading">⚠️ Important: Backup Your Database First!</h4>
                            <p class="mb-0">This migration will modify your database structure. Please create a backup before proceeding.</p>
                        </div>
                    </div>
                    
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title"><i class="bi bi-list-check"></i> What This Migration Does:</h5>
                            <ul>
                                <li>✅ Creates <strong>donors</strong> table - Central donor registry</li>
                                <li>✅ Creates <strong>donor_payment_plans</strong> table - Payment plan management</li>
                                <li>✅ Creates <strong>donor_audit_log</strong> table - Comprehensive audit trail</li>
                                <li>✅ Creates <strong>donor_portal_tokens</strong> table - Secure portal access</li>
                                <li>✅ Adds <strong>donor_id</strong> column to pledges table</li>
                                <li>✅ Adds <strong>donor_id, pledge_id, installment_number</strong> columns to payments table</li>
                                <li>✅ Populates donors from existing pledges and payments</li>
                                <li>✅ Links all existing data to new donor records</li>
                                <li>✅ Calculates initial balances and statuses</li>
                            </ul>
                        </div>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to run this migration? Make sure you have backed up your database!');">
                        <div class="text-center">
                            <button type="submit" name="confirm_migration" class="btn btn-migrate">
                                <i class="bi bi-rocket-takeoff"></i> Run Migration
                            </button>
                        </div>
                    </form>
                    
                <?php endif; ?>
                
                <!-- Migration Log -->
                <?php if (!empty($migrationLog)): ?>
                    <div class="mt-4">
                        <h4><i class="bi bi-journal-text"></i> Migration Log</h4>
                        <div class="mt-3">
                            <?php foreach ($migrationLog as $entry): ?>
                                <div class="log-entry <?= htmlspecialchars($entry['status']) ?>">
                                    <div class="log-icon">
                                        <?php if ($entry['status'] === 'success'): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php elseif ($entry['status'] === 'error'): ?>
                                            <i class="bi bi-x-circle-fill text-danger"></i>
                                        <?php elseif ($entry['status'] === 'warning'): ?>
                                            <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                                        <?php else: ?>
                                            <i class="bi bi-info-circle-fill text-info"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <strong><?= htmlspecialchars($entry['step']) ?></strong><br>
                                        <?= htmlspecialchars($entry['message']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-3 text-muted text-center">
                            <small><i class="bi bi-clock"></i> Execution time: <?= $executionTime ?>ms</small>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <?php if (!empty($stats)): ?>
                    <div class="mt-4">
                        <h4><i class="bi bi-bar-chart-fill"></i> Verification Statistics</h4>
                        
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h3><?= number_format($stats['donors_count']) ?></h3>
                                    <p>Donors Created</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h3><?= number_format($stats['pledges_linked']) ?></h3>
                                    <p>Pledges Linked</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h3><?= number_format($stats['payments_linked']) ?></h3>
                                    <p>Payments Linked</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h3>£<?= number_format($stats['total_pledged'], 2) ?></h3>
                                    <p>Total Pledged</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h3>£<?= number_format($stats['total_paid'], 2) ?></h3>
                                    <p>Total Paid</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card">
                                    <h3>£<?= number_format($stats['outstanding_balance'], 2) ?></h3>
                                    <p>Outstanding Balance</p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($stats['status_breakdown'])): ?>
                            <div class="mt-4">
                                <h5>Payment Status Breakdown</h5>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <?php foreach ($stats['status_breakdown'] as $status => $count): ?>
                                        <span class="status-badge badge-<?= htmlspecialchars($status) ?>">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $status))) ?>: <?= $count ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger mt-4" role="alert">
                        <h5 class="alert-heading"><i class="bi bi-exclamation-octagon-fill"></i> Errors:</h5>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Warnings -->
                <?php if (!empty($warnings)): ?>
                    <div class="alert alert-warning mt-4" role="alert">
                        <h5 class="alert-heading"><i class="bi bi-exclamation-triangle-fill"></i> Warnings:</h5>
                        <ul class="mb-0">
                            <?php foreach ($warnings as $warning): ?>
                                <li><?= htmlspecialchars($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Actions -->
                <div class="mt-4 text-center">
                    <a href="/admin/tools/" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Tools
                    </a>
                    <?php if ($migrationCompleted || $alreadyMigrated): ?>
                        <a href="/admin/dashboard/" class="btn btn-primary">
                            <i class="bi bi-speedometer2"></i> Go to Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

