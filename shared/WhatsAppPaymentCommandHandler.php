<?php
/**
 * WhatsApp PAY command handler (4-message confirm flow)
 *
 * 1) Registrar: PAY 0335 50
 * 2) Bot: preview + ask YES/NO
 * 3) Registrar: YES
 * 4) Bot: payment created + approved confirmation
 *
 * Operators and bot message text are configurable via
 * admin/donations/whatsapp-pay-setup.php
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

    /** @var array<string,string> */
    private array $templateCache = [];

    public function __construct($db)
    {
        $this->db = $db;
        $this->ensureTables();
        $this->seedDefaultTemplates();
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
            return false;
        }

        $normalizedFrom = $this->normalizePhoneDigits($fromPhone);
        $pending = $this->getPendingSession($normalizedFrom);

        if ($pending && $this->isConfirmMessage($body)) {
            $this->completePayment($operator, $pending, $fromPhone, $conversationId);
            return true;
        }

        if ($pending && $this->isCancelMessage($body)) {
            $this->cancelSession((int)$pending['id']);
            $this->reply(
                $fromPhone,
                $this->renderTemplate('cancelled', []),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        $parsed = $this->parsePayCommand($body);
        if ($parsed === null) {
            if ($pending) {
                $this->reply(
                    $fromPhone,
                    $this->renderTemplate('pending_reminder', [
                        'preview' => $this->formatPreview($pending),
                        'expires_minutes' => (string)self::SESSION_TTL_MINUTES,
                    ]),
                    $conversationId,
                    (int)$operator['id']
                );
                return true;
            }

            if (preg_match('/^(?:PAY|APPROVE|ክፍያ|HELP|እገዛ)\b/iu', $body)) {
                $this->reply(
                    $fromPhone,
                    $this->renderTemplate('help', [
                        'expires_minutes' => (string)self::SESSION_TTL_MINUTES,
                    ]),
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
     * Normalize phone input from admin UI (07... or +44...) to digits for matching.
     */
    public static function normalizeOperatorPhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '44' . substr($digits, 1);
        }
        return $digits;
    }

    /**
     * @return array{reference:string,amount:float,method:string,payment_date:string}|null
     */
    private function parsePayCommand(string $body): ?array
    {
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
                $this->renderTemplate('not_found', ['reference' => $parsed['reference']]),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        if (count($matches) > 1) {
            $lines = [];
            foreach ($matches as $row) {
                $lines[] = '• ' . ($row['name'] ?? '') . ' (' . ($row['phone'] ?? '') . ')';
            }
            $this->reply(
                $fromPhone,
                $this->renderTemplate('multiple_matches', [
                    'reference' => $parsed['reference'],
                    'matches_list' => implode("\n", $lines),
                ]),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        $donor = $matches[0];
        if (empty($donor['pledge_id'])) {
            $this->reply(
                $fromPhone,
                $this->renderTemplate('no_pledge', [
                    'donor_name' => (string)($donor['name'] ?? ''),
                    'reference' => $parsed['reference'],
                ]),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

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
        $msg = $this->renderTemplate('confirm_request', [
            'preview' => $preview,
            'donor_name' => $payload['donor_name'],
            'donor_phone' => $payload['donor_phone'],
            'reference' => $payload['reference'],
            'amount' => number_format((float)$payload['amount'], 2),
            'method' => $payload['method'],
            'payment_date' => $payload['payment_date'],
            'balance' => number_format((float)$payload['balance'], 2),
            'expires_minutes' => (string)self::SESSION_TTL_MINUTES,
        ]);

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
            $this->reply(
                $fromPhone,
                $this->renderTemplate('session_invalid', []),
                $conversationId,
                (int)$operator['id']
            );
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

            $updLast = $this->db->prepare("UPDATE donors SET last_payment_date = ? WHERE id = ?");
            $updLast->bind_param('si', $paymentDate, $donorId);
            $updLast->execute();
            $updLast->close();

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

            $done = $this->db->prepare("
                UPDATE whatsapp_payment_command_sessions
                SET status = 'completed', completed_payment_id = ?, completed_at = NOW()
                WHERE id = ?
            ");
            $done->bind_param('ii', $paymentId, $sessionId);
            $done->execute();
            $done->close();

            $this->db->commit();

            $donorStmt = $this->db->prepare("SELECT name, total_pledged, total_paid, balance FROM donors WHERE id = ? LIMIT 1");
            $donorStmt->bind_param('i', $donorId);
            $donorStmt->execute();
            $donor = $donorStmt->get_result()->fetch_assoc() ?: [];
            $donorStmt->close();

            $msg = $this->renderTemplate('success', [
                'donor_name' => (string)($donor['name'] ?? $payload['donor_name']),
                'reference' => $reference,
                'amount' => number_format($amount, 2),
                'payment_date' => $paymentDate,
                'method' => $method,
                'payment_id' => (string)$paymentId,
                'total_pledged' => number_format((float)($donor['total_pledged'] ?? 0), 2),
                'total_paid' => number_format((float)($donor['total_paid'] ?? 0), 2),
                'remaining' => number_format((float)($donor['balance'] ?? 0), 2),
            ]);

            $this->reply($fromPhone, $msg, $conversationId, $operatorId);
        } catch (Throwable $e) {
            try {
                $this->db->rollback();
            } catch (Throwable $ignored) {
            }
            error_log('WhatsApp PAY complete error: ' . $e->getMessage());
            $this->reply(
                $fromPhone,
                $this->renderTemplate('error', ['error' => $e->getMessage()]),
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
            $byDonor = [];
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                if (!isset($byDonor[$id])) {
                    $byDonor[$id] = $row;
                }
            }
            return array_values($byDonor);
        }

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
     * Authorized operators come from whatsapp_pay_operators (setup page),
     * with fallback to active admin/registrar users by phone.
     *
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

        $sqlOps = "
            SELECT o.id AS operator_row_id, o.name AS operator_name, o.phone_raw, o.phone_digits,
                   o.linked_user_id, u.id AS user_id, u.name AS user_name, u.phone AS user_phone, u.role
            FROM whatsapp_pay_operators o
            LEFT JOIN users u ON u.id = o.linked_user_id
            WHERE o.active = 1
              AND o.phone_digits IN ($placeholders)
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sqlOps);
        if ($stmt) {
            $types = str_repeat('s', count($variants));
            $stmt->bind_param($types, ...$variants);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $userId = (int)($row['linked_user_id'] ?? $row['user_id'] ?? 0);
                if ($userId <= 0) {
                    return null;
                }
                return [
                    'id' => $userId,
                    'name' => (string)($row['operator_name'] ?: ($row['user_name'] ?? 'Operator')),
                    'phone' => (string)($row['phone_raw'] ?? $fromPhone),
                    'role' => (string)($row['role'] ?? 'registrar'),
                ];
            }
        }

        $sql = "
            SELECT id, name, phone, role
            FROM users
            WHERE active = 1
              AND role IN ('admin', 'registrar')
              AND REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') IN ($placeholders)
            LIMIT 1
        ";
        $stmt2 = $this->db->prepare($sql);
        if (!$stmt2) {
            return null;
        }
        $types = str_repeat('s', count($variants));
        $stmt2->bind_param($types, ...$variants);
        $stmt2->execute();
        $user = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();

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
        if (isset($payload['payload_json'])) {
            $decoded = json_decode((string)$payload['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return $this->renderTemplate('preview_block', [
            'donor_name' => (string)($payload['donor_name'] ?? 'Donor'),
            'donor_phone' => (string)($payload['donor_phone'] ?? ''),
            'reference' => (string)($payload['reference'] ?? ''),
            'amount' => number_format((float)($payload['amount'] ?? 0), 2),
            'method' => (string)($payload['method'] ?? 'cash'),
            'payment_date' => (string)($payload['payment_date'] ?? date('Y-m-d')),
            'balance' => number_format((float)($payload['balance'] ?? 0), 2),
        ]);
    }

    /**
     * @param array<string,string> $vars
     */
    private function renderTemplate(string $key, array $vars): string
    {
        $body = $this->getTemplateBody($key);
        foreach ($vars as $name => $value) {
            $body = str_replace('{' . $name . '}', (string)$value, $body);
        }
        return $body;
    }

    private function getTemplateBody(string $key): string
    {
        if (isset($this->templateCache[$key])) {
            return $this->templateCache[$key];
        }

        $stmt = $this->db->prepare("SELECT body FROM whatsapp_pay_message_templates WHERE template_key = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && trim((string)$row['body']) !== '') {
                $this->templateCache[$key] = (string)$row['body'];
                return $this->templateCache[$key];
            }
        }

        $defaults = $this->defaultTemplates();
        $this->templateCache[$key] = $defaults[$key]['body'] ?? ('[' . $key . ']');
        return $this->templateCache[$key];
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
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '44' . substr($digits, 1);
        }
        return $digits;
    }

    /**
     * @return list<string>
     */
    private function phoneVariants(string $digits): array
    {
        $variants = [$digits];

        if (str_starts_with($digits, '44') && strlen($digits) >= 12) {
            $variants[] = '0' . substr($digits, 2);
            $variants[] = substr($digits, 2);
            $variants[] = $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $variants[] = '44' . substr($digits, 1);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * @return array<string,array{label:string,description:string,placeholders:string,body:string}>
     */
    public function defaultTemplates(): array
    {
        return [
            'help' => [
                'label' => 'Help / Usage',
                'description' => 'Sent when staff type PAY/HELP incorrectly',
                'placeholders' => '{expires_minutes}',
                'body' => "📌 *የWhatsApp ክፍያ ትእዛዝ*\n\nይላኩ:\n*PAY 0335 50*\n\nአማራጭ:\n*PAY 0335 50 cash*\n*PAY 0335 50 cash 2025-06-08*\n\nከዚያ *አዎ* ይበሉ ለማረጋገጥ፣ ወይም *አይ* ለመሰረዝ።",
            ],
            'confirm_request' => [
                'label' => 'Confirm Request (message 2)',
                'description' => 'Ask staff to confirm before recording payment',
                'placeholders' => '{preview} {donor_name} {donor_phone} {reference} {amount} {method} {payment_date} {balance} {expires_minutes}',
                'body' => "✅ ለጋሽ ተገኝቷል። እባክዎ ያረጋግጡ:\n\n{preview}\n\n*አዎ* ይበሉ ክፍያውን ለመመዝገብና ለማጽደቅ\n*አይ* ይበሉ ለመሰረዝ\n(በ{expires_minutes} ደቂቃ ውስጥ ይጠፋል)",
            ],
            'preview_block' => [
                'label' => 'Preview Block',
                'description' => 'Donor/payment details block used inside confirm messages',
                'placeholders' => '{donor_name} {donor_phone} {reference} {amount} {method} {payment_date} {balance}',
                'body' => "👤 {donor_name}\n📞 {donor_phone}\n🔖 መከታተያ: {reference}\n💷 መጠን: £{amount}\n📅 ቀን: {payment_date}\n💳 ዘዴ: {method}\n📊 አሁን ያለ ቀሪ: £{balance}",
            ],
            'pending_reminder' => [
                'label' => 'Pending Reminder',
                'description' => 'Reminder when a confirmation is still waiting',
                'placeholders' => '{preview} {expires_minutes}',
                'body' => "⏳ ገና ያልተረጋገጠ ክፍያ አለዎት።\n*አዎ* ይበሉ ለማረጋገጥ ወይም *አይ* ለመሰረዝ።\n\n{preview}",
            ],
            'cancelled' => [
                'label' => 'Cancelled',
                'description' => 'Sent after NO/cancel',
                'placeholders' => '',
                'body' => "❌ ተሰርዟል። ምንም ክፍያ አልተመዘገበም።\n\nእንደገና ለመጀመር: PAY 0335 50",
            ],
            'success' => [
                'label' => 'Success (message 4)',
                'description' => 'Sent after payment is recorded and approved',
                'placeholders' => '{donor_name} {reference} {amount} {payment_date} {method} {payment_id} {total_pledged} {total_paid} {remaining}',
                'body' => "✅ *ክፍያ ተጽድቋል*\n\nለጋሽ: {donor_name}\nመከታተያ: {reference}\nመጠን: £{amount}\nቀን: {payment_date}\nዘዴ: {method}\nየክፍያ መለያ: #{payment_id}\n\nማጠቃለያ:\n→ ጠቅላላ ቃል ኪዳን: £{total_pledged}\n→ እስካሁን የከፈሉት: £{total_paid}\n→ ቀሪ: £{remaining}\n\nሌላ ለመጀመር: PAY 0335 50",
            ],
            'not_found' => [
                'label' => 'Reference Not Found',
                'description' => 'No donor for the 4-digit reference',
                'placeholders' => '{reference}',
                'body' => "❌ ለመከታተያ *{reference}* ለጋሽ አልተገኘም።\nባለ 4 አሃዝ ቁጥሩን ያረጋግጡ።\n\nቅርጸት: PAY 0335 50",
            ],
            'multiple_matches' => [
                'label' => 'Multiple Matches',
                'description' => 'More than one donor shares the reference',
                'placeholders' => '{reference} {matches_list}',
                'body' => "⚠️ መከታተያ *{reference}* ለብዙ ለጋሾች ተመሳሳይ ነው። በራስ መስራት አይቻልም:\n{matches_list}\n\nእባክዎ ከአስተዳዳሪ ገጽ ይጠቀሙ።",
            ],
            'no_pledge' => [
                'label' => 'No Active Pledge',
                'description' => 'Donor found but no payable pledge',
                'placeholders' => '{donor_name} {reference}',
                'body' => "❌ ለጋሽ *{donor_name}* ተገኝቷል፣ ግን የሚከፈልበት ቃል ኪዳን የለም።",
            ],
            'session_invalid' => [
                'label' => 'Invalid Session',
                'description' => 'Pending session data is broken',
                'placeholders' => '',
                'body' => "❌ የማረጋገጫ መረጃ ተበላሽቷል። እባክዎ PAY እንደገና ይላኩ።",
            ],
            'error' => [
                'label' => 'Processing Error',
                'description' => 'Generic failure while saving/approving',
                'placeholders' => '{error}',
                'body' => "❌ ክፍያውን ማስኬድ አልተሳካም: {error}\nእባክዎ እንደገና ይሞክሩ ወይም የአስተዳዳሪ ገጹን ይጠቀሙ።",
            ],
        ];
    }

    private function seedDefaultTemplates(): void
    {
        $defaults = $this->defaultTemplates();
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO whatsapp_pay_message_templates
                (template_key, label, description, placeholders_help, body, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt) {
            return;
        }
        foreach ($defaults as $key => $tpl) {
            $label = $tpl['label'];
            $desc = $tpl['description'];
            $ph = $tpl['placeholders'];
            $body = $tpl['body'];
            $stmt->bind_param('sssss', $key, $label, $desc, $ph, $body);
            $stmt->execute();
        }
        $stmt->close();
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

        $this->db->query("
            CREATE TABLE IF NOT EXISTS whatsapp_pay_operators (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                phone_raw VARCHAR(40) NOT NULL,
                phone_digits VARCHAR(30) NOT NULL,
                linked_user_id INT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                notes VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_phone_digits (phone_digits),
                INDEX idx_active (active),
                INDEX idx_linked_user (linked_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS whatsapp_pay_message_templates (
                template_key VARCHAR(50) NOT NULL PRIMARY KEY,
                label VARCHAR(120) NOT NULL,
                description VARCHAR(255) NULL,
                placeholders_help VARCHAR(500) NULL,
                body TEXT NOT NULL,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
