<?php
/**
 * WhatsApp PAY command handler (4-message confirm flow)
 *
 * 1) Registrar: PAY 0335 50
 * 2) Bot: preview + ask YES/NO
 * 3) Registrar: YES
 * 4) Bot: payment created + approved confirmation
 */

declare(strict_types=1);

require_once __DIR__ . '/FinancialCalculator.php';
require_once __DIR__ . '/../services/UltraMsgService.php';

class WhatsAppPaymentCommandHandler
{
    private const SESSION_TTL_MINUTES = 10;
    private const MAX_AMOUNT = 100000.0;

    /** @var mysqli */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
        $this->ensureTables();
    }

    /**
     * Process an inbound WhatsApp text from a possible registrar/admin.
     *
     * @return bool True if this message was handled as a PAY command flow
     */
    public function handleIncoming(string $fromPhone, string $body, int $conversationId = 0): bool
    {
        $body = trim($body);
        if ($body === '') {
            return false;
        }

        $operator = $this->findAuthorizedOperator($fromPhone);
        if (!$operator) {
            // Only authorized staff can use commands; ignore silently for donors
            return false;
        }

        $normalizedFrom = $this->normalizePhoneDigits($fromPhone);
        $pending = $this->getPendingSession($normalizedFrom);

        // Confirm / cancel pending session first
        if ($pending && $this->isConfirmMessage($body)) {
            $this->completePayment($operator, $pending, $fromPhone, $conversationId);
            return true;
        }

        if ($pending && $this->isCancelMessage($body)) {
            $this->cancelSession((int)$pending['id']);
            $this->reply($fromPhone, "❌ Cancelled. No payment was recorded.\n\nSend PAY <ref> <amount> to start again.", $conversationId, (int)$operator['id']);
            return true;
        }

        // New PAY command
        $parsed = $this->parsePayCommand($body);
        if ($parsed === null) {
            // If they have a pending session, remind them
            if ($pending) {
                $this->reply(
                    $fromPhone,
                    "⏳ You still have a pending confirmation.\nReply *YES* to confirm or *NO* to cancel.\n\n"
                    . $this->formatPreview($pending),
                    $conversationId,
                    (int)$operator['id']
                );
                return true;
            }

            // Help / usage for staff who typed PAY incorrectly
            if (preg_match('/^(?:PAY|APPROVE|ክፍያ|HELP|እገዛ)\b/iu', $body)) {
                $this->reply(
                    $fromPhone,
                    "📌 *WhatsApp Payment Command*\n\n"
                    . "Send:\n*PAY 0335 50*\n\n"
                    . "Optional:\n*PAY 0335 50 cash*\n*PAY 0335 50 cash 2025-06-08*\n\n"
                    . "Then reply *YES* to approve, or *NO* to cancel.",
                    $conversationId,
                    (int)$operator['id']
                );
                return true;
            }

            return false;
        }

        return $this->startConfirmation($operator, $parsed, $fromPhone, $conversationId);
    }

    /**
     * @return array{reference:string,amount:float,method:string,payment_date:string}|null
     */
    private function parsePayCommand(string $body): ?array
    {
        // PAY 0335 50
        // PAY 0335 50 cash
        // PAY 0335 50 cash 2025-06-08
        // Also accept: pay / APPROVE / ክፍያ
        if (!preg_match('/^(?:PAY|APPROVE|ክፍያ)\s+(\d{4})\s+([0-9]+(?:\.[0-9]{1,2})?)(?:\s+(cash|card|bank|other))?(?:\s+(\d{4}-\d{2}-\d{2}))?\s*$/iu', $body, $m)) {
            return null;
        }

        $amount = (float)$m[2];
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            return null;
        }

        $method = strtolower($m[3] ?? 'cash');
        if (!in_array($method, ['cash', 'card', 'bank', 'other'], true)) {
            $method = 'cash';
        }

        $paymentDate = $m[4] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            $paymentDate = date('Y-m-d');
        }

        return [
            'reference' => $m[1],
            'amount' => $amount,
            'method' => $method,
            'payment_date' => $paymentDate,
        ];
    }

    private function isConfirmMessage(string $body): bool
    {
        $t = mb_strtolower(trim($body));
        return in_array($t, ['yes', 'y', 'ok', 'confirm', 'አዎ', 'እሺ', 'እሳምማለሁ'], true);
    }

    private function isCancelMessage(string $body): bool
    {
        $t = mb_strtolower(trim($body));
        return in_array($t, ['no', 'n', 'cancel', 'አይ', 'ሰርዝ'], true);
    }

    /**
     * @param array{reference:string,amount:float,method:string,payment_date:string} $parsed
     */
    private function startConfirmation(array $operator, array $parsed, string $fromPhone, int $conversationId): bool
    {
        $matches = $this->findDonorsByReference($parsed['reference']);

        if (count($matches) === 0) {
            $this->reply(
                $fromPhone,
                "❌ No donor found for reference *{$parsed['reference']}*.\nCheck the 4-digit reference and try again.\n\nFormat: PAY 0335 50",
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        if (count($matches) > 1) {
            $lines = ["⚠️ Multiple donors share reference *{$parsed['reference']}*. Cannot auto-process:"];
            foreach ($matches as $row) {
                $lines[] = "• {$row['name']} ({$row['phone']})";
            }
            $lines[] = "\nPlease use the admin page for this one.";
            $this->reply($fromPhone, implode("\n", $lines), $conversationId, (int)$operator['id']);
            return true;
        }

        $donor = $matches[0];
        if (empty($donor['pledge_id'])) {
            $this->reply(
                $fromPhone,
                "❌ Donor *{$donor['name']}* found, but no active pledge to pay against.",
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        // Replace any existing pending session for this operator
        $this->cancelOpenSessions($this->normalizePhoneDigits($fromPhone));

        $payload = [
            'donor_id' => (int)$donor['id'],
            'donor_name' => (string)$donor['name'],
            'donor_phone' => (string)$donor['phone'],
            'pledge_id' => (int)$donor['pledge_id'],
            'reference' => $parsed['reference'],
            'amount' => $parsed['amount'],
            'method' => $parsed['method'],
            'payment_date' => $parsed['payment_date'],
            'balance' => (float)($donor['balance'] ?? 0),
            'total_paid' => (float)($donor['total_paid'] ?? 0),
            'total_pledged' => (float)($donor['total_pledged'] ?? 0),
        ];

        $expiresAt = date('Y-m-d H:i:s', time() + (self::SESSION_TTL_MINUTES * 60));
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $operatorPhone = $this->normalizePhoneDigits($fromPhone);
        $operatorId = (int)$operator['id'];

        $stmt = $this->db->prepare("
            INSERT INTO whatsapp_payment_command_sessions
                (operator_user_id, operator_phone, status, payload_json, expires_at, created_at)
            VALUES (?, ?, 'pending_confirm', ?, ?, NOW())
        ");
        $stmt->bind_param('isss', $operatorId, $operatorPhone, $payloadJson, $expiresAt);
        $stmt->execute();
        $stmt->close();

        $preview = $this->formatPreview($payload);
        $msg = "✅ Found donor. Please confirm:\n\n{$preview}\n\n"
            . "Reply *YES* to record & approve\n"
            . "Reply *NO* to cancel\n"
            . "(expires in " . self::SESSION_TTL_MINUTES . " minutes)";

        $this->reply($fromPhone, $msg, $conversationId, $operatorId);
        return true;
    }

    /**
     * @param array<string,mixed> $pending Session row
     */
    private function completePayment(array $operator, array $pending, string $fromPhone, int $conversationId): void
    {
        $sessionId = (int)$pending['id'];
        $payload = json_decode((string)($pending['payload_json'] ?? '{}'), true);
        if (!is_array($payload) || empty($payload['donor_id']) || empty($payload['pledge_id'])) {
            $this->cancelSession($sessionId);
            $this->reply($fromPhone, "❌ Session data invalid. Please send PAY again.", $conversationId, (int)$operator['id']);
            return;
        }

        $donorId = (int)$payload['donor_id'];
        $pledgeId = (int)$payload['pledge_id'];
        $amount = (float)$payload['amount'];
        $method = (string)($payload['method'] ?? 'cash');
        $paymentDate = (string)($payload['payment_date'] ?? date('Y-m-d'));
        $reference = (string)($payload['reference'] ?? '');
        $operatorId = (int)$operator['id'];
        $notes = 'WhatsApp PAY command by ' . ($operator['name'] ?? 'staff');

        try {
            $this->db->begin_transaction();

            // Re-validate pledge
            $pledgeStmt = $this->db->prepare("SELECT id, donor_id, status FROM pledges WHERE id = ? LIMIT 1");
            $pledgeStmt->bind_param('i', $pledgeId);
            $pledgeStmt->execute();
            $pledge = $pledgeStmt->get_result()->fetch_assoc();
            $pledgeStmt->close();

            if (!$pledge || (int)$pledge['donor_id'] !== $donorId) {
                throw new RuntimeException('Pledge not found for this donor.');
            }
            if (($pledge['status'] ?? '') === 'cancelled') {
                throw new RuntimeException('Cannot pay towards a cancelled pledge.');
            }

            $proof = '';
            $insert = $this->db->prepare("
                INSERT INTO pledge_payments
                    (pledge_id, donor_id, amount, payment_method, payment_date, reference_number, payment_proof, notes, processed_by_user_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $insert->bind_param(
                'iidsssssi',
                $pledgeId,
                $donorId,
                $amount,
                $method,
                $paymentDate,
                $reference,
                $proof,
                $notes,
                $operatorId
            );
            $insert->execute();
            $paymentId = (int)$this->db->insert_id;
            $insert->close();

            if ($paymentId <= 0) {
                throw new RuntimeException('Failed to create payment.');
            }

            // Approve immediately
            $approve = $this->db->prepare("
                UPDATE pledge_payments
                SET status = 'confirmed', approved_by_user_id = ?, approved_at = NOW()
                WHERE id = ?
            ");
            $approve->bind_param('ii', $operatorId, $paymentId);
            $approve->execute();
            $approve->close();

            $calculator = new FinancialCalculator();
            if (!$calculator->recalculateDonorTotalsAfterApprove($donorId)) {
                throw new RuntimeException('Failed to update donor totals.');
            }

            // Use recorded payment date for last_payment_date
            $updLast = $this->db->prepare("UPDATE donors SET last_payment_date = ? WHERE id = ?");
            $updLast->bind_param('si', $paymentDate, $donorId);
            $updLast->execute();
            $updLast->close();

            // Audit
            $after = json_encode([
                'action' => 'whatsapp_pay_command',
                'payment_id' => $paymentId,
                'donor_id' => $donorId,
                'pledge_id' => $pledgeId,
                'amount' => $amount,
                'method' => $method,
                'payment_date' => $paymentDate,
                'reference' => $reference,
                'status' => 'confirmed',
            ], JSON_UNESCAPED_UNICODE);
            $log = $this->db->prepare("
                INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source)
                VALUES (?, 'pledge_payment', ?, 'create_and_approve', ?, 'whatsapp')
            ");
            $log->bind_param('iis', $operatorId, $paymentId, $after);
            $log->execute();
            $log->close();

            // Mark session completed
            $done = $this->db->prepare("
                UPDATE whatsapp_payment_command_sessions
                SET status = 'completed', completed_payment_id = ?, completed_at = NOW()
                WHERE id = ?
            ");
            $done->bind_param('ii', $paymentId, $sessionId);
            $done->execute();
            $done->close();

            $this->db->commit();

            // Fresh totals for reply
            $donorStmt = $this->db->prepare("SELECT name, total_pledged, total_paid, balance FROM donors WHERE id = ? LIMIT 1");
            $donorStmt->bind_param('i', $donorId);
            $donorStmt->execute();
            $donor = $donorStmt->get_result()->fetch_assoc() ?: [];
            $donorStmt->close();

            $msg = "✅ *Payment approved*\n\n"
                . "Donor: " . ($donor['name'] ?? $payload['donor_name']) . "\n"
                . "Reference: {$reference}\n"
                . "Amount: £" . number_format($amount, 2) . "\n"
                . "Date: {$paymentDate}\n"
                . "Method: {$method}\n"
                . "Payment ID: #{$paymentId}\n\n"
                . "Summary:\n"
                . "→ Total pledged: £" . number_format((float)($donor['total_pledged'] ?? 0), 2) . "\n"
                . "→ Total paid: £" . number_format((float)($donor['total_paid'] ?? 0), 2) . "\n"
                . "→ Remaining: £" . number_format((float)($donor['balance'] ?? 0), 2) . "\n\n"
                . "Send another: PAY 0335 50";

            $this->reply($fromPhone, $msg, $conversationId, $operatorId);
        } catch (Throwable $e) {
            try {
                $this->db->rollback();
            } catch (Throwable $ignored) {
                // no active transaction
            }
            error_log('WhatsApp PAY complete error: ' . $e->getMessage());
            $this->reply(
                $fromPhone,
                "❌ Failed to process payment: " . $e->getMessage() . "\nPlease try again or use the admin page.",
                $conversationId,
                (int)$operator['id']
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findDonorsByReference(string $reference): array
    {
        $rows = [];

        // Prefer pledges.notes exact 4-digit reference
        $sql = "
            SELECT d.id, d.name, d.phone, d.balance, d.total_paid, d.total_pledged,
                   p.id AS pledge_id, p.notes AS reference
            FROM pledges p
            INNER JOIN donors d ON d.id = p.donor_id
            WHERE p.notes = ?
              AND p.status IN ('approved', 'pending')
            ORDER BY (p.status = 'approved') DESC, p.id DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        if (!empty($rows)) {
            // Collapse to unique donors, keep best pledge per donor
            $byDonor = [];
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if (!isset($byDonor[$id])) {
                    $byDonor[$id] = $row;
                }
            }
            return array_values($byDonor);
        }

        // Fallback: payments.reference exact match, then find latest pledge for that donor
        $sql2 = "
            SELECT DISTINCT d.id, d.name, d.phone, d.balance, d.total_paid, d.total_pledged
            FROM payments pay
            INNER JOIN donors d ON d.id = pay.donor_id
            WHERE pay.reference = ?
        ";
        $stmt2 = $this->db->prepare($sql2);
        $stmt2->bind_param('s', $reference);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
            $donorId = (int)$row['id'];
            $pledgeStmt = $this->db->prepare("
                SELECT id FROM pledges
                WHERE donor_id = ? AND status IN ('approved', 'pending')
                ORDER BY (status = 'approved') DESC, id DESC
                LIMIT 1
            ");
            $pledgeStmt->bind_param('i', $donorId);
            $pledgeStmt->execute();
            $pledge = $pledgeStmt->get_result()->fetch_assoc();
            $pledgeStmt->close();
            $row['pledge_id'] = $pledge['id'] ?? null;
            $row['reference'] = $reference;
            $rows[] = $row;
        }
        $stmt2->close();

        return $rows;
    }

    /**
     * @return array{id:int,name:string,phone:string,role:string}|null
     */
    private function findAuthorizedOperator(string $fromPhone): ?array
    {
        $digits = $this->normalizePhoneDigits($fromPhone);
        if ($digits === '') {
            return null;
        }

        $variants = $this->phoneVariants($digits);
        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $sql = "
            SELECT id, name, phone, role
            FROM users
            WHERE active = 1
              AND role IN ('admin', 'registrar')
              AND REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') IN ($placeholders)
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $types = str_repeat('s', count($variants));
        $stmt->bind_param($types, ...$variants);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getPendingSession(string $operatorPhoneDigits): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM whatsapp_payment_command_sessions
            WHERE operator_phone = ?
              AND status = 'pending_confirm'
              AND expires_at > NOW()
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('s', $operatorPhoneDigits);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function cancelSession(int $sessionId): void
    {
        $stmt = $this->db->prepare("
            UPDATE whatsapp_payment_command_sessions
            SET status = 'cancelled', completed_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $stmt->close();
    }

    private function cancelOpenSessions(string $operatorPhoneDigits): void
    {
        $stmt = $this->db->prepare("
            UPDATE whatsapp_payment_command_sessions
            SET status = 'cancelled', completed_at = NOW()
            WHERE operator_phone = ? AND status = 'pending_confirm'
        ");
        $stmt->bind_param('s', $operatorPhoneDigits);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function formatPreview(array $payload): string
    {
        // Support either session row or payload array
        if (isset($payload['payload_json'])) {
            $decoded = json_decode((string)$payload['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $name = (string)($payload['donor_name'] ?? 'Donor');
        $phone = (string)($payload['donor_phone'] ?? '');
        $ref = (string)($payload['reference'] ?? '');
        $amount = number_format((float)($payload['amount'] ?? 0), 2);
        $method = (string)($payload['method'] ?? 'cash');
        $date = (string)($payload['payment_date'] ?? date('Y-m-d'));
        $balance = number_format((float)($payload['balance'] ?? 0), 2);

        return "👤 {$name}\n"
            . "📞 {$phone}\n"
            . "🔖 Ref: {$ref}\n"
            . "💷 Amount: £{$amount}\n"
            . "📅 Date: {$date}\n"
            . "💳 Method: {$method}\n"
            . "📊 Current balance: £{$balance}";
    }

    private function reply(string $toPhone, string $message, int $conversationId, int $operatorUserId): void
    {
        $service = UltraMsgService::fromDatabase($this->db);
        if (!$service) {
            error_log('WhatsAppPaymentCommandHandler: UltraMsg not configured');
            return;
        }

        $result = $service->send($toPhone, $message, [
            'log' => true,
            'source_type' => 'whatsapp_pay_command',
            'user_id' => $operatorUserId,
        ]);

        // Also store outbound in conversation if possible
        if ($conversationId > 0) {
            try {
                $ultramsgId = (string)($result['message_id'] ?? ('local_' . uniqid()));
                $status = !empty($result['success']) ? 'sent' : 'failed';
                $stmt = $this->db->prepare("
                    INSERT INTO whatsapp_messages
                        (conversation_id, ultramsg_id, direction, message_type, body, status, sender_id, is_from_donor, sent_at, created_at)
                    VALUES (?, ?, 'outgoing', 'text', ?, ?, ?, 0, NOW(), NOW())
                ");
                if ($stmt) {
                    $stmt->bind_param('isssi', $conversationId, $ultramsgId, $message, $status, $operatorUserId);
                    $stmt->execute();
                    $stmt->close();
                }

                $preview = mb_substr($message, 0, 100);
                $upd = $this->db->prepare("
                    UPDATE whatsapp_conversations
                    SET last_message_at = NOW(), last_message_preview = ?
                    WHERE id = ?
                ");
                if ($upd) {
                    $upd->bind_param('si', $preview, $conversationId);
                    $upd->execute();
                    $upd->close();
                }
            } catch (Throwable $e) {
                error_log('WhatsAppPaymentCommandHandler store reply failed: ' . $e->getMessage());
            }
        }
    }

    private function normalizePhoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // Strip leading 00
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    /**
     * @return list<string>
     */
    private function phoneVariants(string $digits): array
    {
        $variants = [$digits];

        // UK local from +44...
        if (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            $variants[] = '0' . substr($digits, 2);
            $variants[] = substr($digits, 2);
        }
        // Local 07... to international
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $variants[] = '44' . substr($digits, 1);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function ensureTables(): void
    {
        $this->db->query("
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
        ");
    }
}
