<?php
/**
 * Optional one-time migration helper for WhatsApp PAY command sessions.
 * Table is also auto-created by WhatsAppPaymentCommandHandler.
 *
 * Run once in browser (admin) or via CLI if needed:
 * php admin/tools/create_whatsapp_pay_command_table.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

$db = db();

$sql = "
CREATE TABLE IF NOT EXISTS whatsapp_payment_command_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operator_user_id INT NOT NULL,
    operator_phone VARCHAR(30) NOT NULL,
    status ENUM('pending_confirm','completed','cancelled','expired') NOT NULL DEFAULT 'pending_confirm',
    payload_json TEXT NOT NULL,
    completed_payment_id INT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_operator_pending (operator_phone, status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

if ($db->query($sql)) {
    echo "OK: whatsapp_payment_command_sessions ready\n";
} else {
    echo "ERROR: " . $db->error . "\n";
    exit(1);
}
