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
require_once __DIR__ . '/../services/MessagingHelper.php';

class WhatsAppPaymentCommandHandler
{
    private const SESSION_TTL_MINUTES = 10;
    private const MAX_AMOUNT = 100000.0;
    private const KESIS_BIRHANU_PHONE = '07473822244';
    private const KESIS_BIRHANU_NAME = 'Kesis Birhanu';

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
     * Flows:
     * - PAY 0335 50 → show donor → Yes/አይደለም → approve
     * - PAY 0335 → show donor → ask amount or ይቅር → then Yes/አይደለም → approve
     * - CHECK 0335 → show donor status without starting a session
     *
     * @return bool True if this message was handled as a supported command
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

        $statusReference = $this->parseStatusCheckCommand($body);
        if ($statusReference !== null) {
            $this->sendDonorStatus(
                $operator,
                $statusReference,
                $fromPhone,
                $conversationId
            );
            return true;
        }

        $pending = $this->getPendingSession($normalizedFrom);
        $pendingStatus = (string)($pending['status'] ?? '');

        // Abort / leave flow (ይቅር) — works for amount or confirm stages
        if ($pending && $this->isAbortMessage($body)) {
            $this->cancelSession((int)$pending['id']);
            $this->reply(
                $fromPhone,
                $this->renderTemplate('cancelled_abort', []),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        // Wrong person (አይደለም) during identity confirm
        if ($pending && $pendingStatus === 'pending_confirm' && $this->isWrongPersonMessage($body)) {
            $this->cancelSession((int)$pending['id']);
            $this->reply(
                $fromPhone,
                $this->renderTemplate('cancelled', []),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        // Waiting for amount after PAY 0335
        if ($pending && $pendingStatus === 'pending_amount') {
            $amountOnly = $this->parseAmountOnly($body);
            if ($amountOnly !== null) {
                $this->promoteAmountSession($operator, $pending, $amountOnly, $fromPhone, $conversationId);
                return true;
            }

            $this->reply(
                $fromPhone,
                $this->renderTemplate('ask_amount_reminder', [
                    'preview' => $this->formatPreview($pending),
                ]),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        // Identity confirm stage
        if ($pending && $pendingStatus === 'pending_confirm' && $this->isConfirmMessage($body)) {
            $this->completePayment($operator, $pending, $fromPhone, $conversationId);
            return true;
        }

        if ($pending && $pendingStatus === 'pending_confirm') {
            $this->reply(
                $fromPhone,
                $this->renderTemplate('pending_reminder', [
                    'preview' => $this->formatPreview($pending),
                    'amount' => number_format((float)($this->payloadFromSession($pending)['amount'] ?? 0), 2),
                    'reference' => (string)($this->payloadFromSession($pending)['reference'] ?? ''),
                ]),
                $conversationId,
                (int)$operator['id']
            );
            return true;
        }

        $parsed = $this->parsePayCommand($body);
        if ($parsed === null) {
            if (preg_match('/^(?:PAY|APPROVE|CHECK|ክፍያ|HELP|እገዛ)\b/iu', $body)) {
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

        // PAY 0335 (no amount) → ask for amount
        if ($parsed['amount'] === null) {
            return $this->startAmountPrompt($operator, $parsed, $fromPhone, $conversationId);
        }

        // PAY 0335 50 → identity confirm
        return $this->startConfirmation($operator, $parsed, $fromPhone, $conversationId);
    }

    private function parseStatusCheckCommand(string $body): ?string
    {
        if (!preg_match('/^CHECK\s+(\d{4})\s*$/iu', $body, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Send a read-only donor status response. This flow creates no session and
     * does not expect a follow-up message.
     *
     * @param array<string,mixed> $operator
     */
    private function sendDonorStatus(
        array $operator,
        string $reference,
        string $fromPhone,
        int $conversationId
    ): void {
        $matches = $this->findDonorsForStatusByReference($reference);

        if (count($matches) === 0) {
            $message = "❌ በመከታተያ ቁጥር *{$reference}* የተመዘገበ ለጋሽ አልተገኘም።";
            $this->reply($fromPhone, $message, $conversationId, (int)$operator['id']);
            return;
        }

        if (count($matches) > 1) {
            $lines = [];
            foreach ($matches as $donor) {
                $lines[] = '• ' . ($donor['name'] ?? '') . ' — ' . ($donor['phone'] ?? '');
            }
            $message = "⚠️ መከታተያ ቁጥር *{$reference}* ለአንድ በላይ ለጋሾች ተመዝግቧል፦\n"
                . implode("\n", $lines)
                . "\n\nእባክዎ መረጃውን ከአስተዳዳሪው ገጽ ያረጋግጡ።";
            $this->reply($fromPhone, $message, $conversationId, (int)$operator['id']);
            return;
        }

        $donor = $matches[0];
        $donorId = (int)$donor['id'];
        $agentName = $this->findAssignedAgentName($donorId);
        $history = $this->buildCompletePaymentHistoryText($donorId);
        $pledgeAmount = (float)($donor['pledge_amount'] ?? $donor['total_pledged'] ?? 0);

        $message = $this->renderTemplate('status_check', [
            'donor_name' => (string)($donor['name'] ?? ''),
            'donor_phone' => (string)($donor['phone'] ?? ''),
            'reference' => $reference,
            'pledge_amount' => number_format($pledgeAmount, 2),
            'total_paid' => number_format((float)($donor['total_paid'] ?? 0), 2),
            'balance' => number_format((float)($donor['balance'] ?? 0), 2),
            'assigned_agent' => $agentName ?? 'ወኪል አልተመደበም',
            'payment_history' => $history,
        ]);

        $this->logStatusCheck((int)$operator['id'], $donorId, $reference);
        $this->reply($fromPhone, $message, $conversationId, (int)$operator['id']);
    }

    /**
     * Find donors from pledge, pledge-payment, or immediate-payment references.
     *
     * @return list<array<string,mixed>>
     */
    private function findDonorsForStatusByReference(string $reference): array
    {
        $stmt = $this->db->prepare("
            SELECT d.id, d.name, d.phone, d.balance, d.total_paid,
                   d.total_pledged, d.total_pledged AS pledge_amount
            FROM donors d
            WHERE d.id IN (
                SELECT donor_id FROM pledges WHERE notes = ?
                UNION
                SELECT donor_id FROM pledge_payments
                WHERE reference_number = ?
                UNION
                SELECT donor_id FROM payments
                WHERE reference = ? AND donor_id IS NOT NULL
            )
            ORDER BY d.name ASC
        ");
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param(
            'sss',
            $reference,
            $reference,
            $reference
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function logStatusCheck(
        int $operatorId,
        int $donorId,
        string $reference
    ): void {
        try {
            $details = json_encode(
                ['reference' => $reference, 'read_only' => true],
                JSON_UNESCAPED_UNICODE
            );
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs
                    (user_id, entity_type, entity_id, action, after_json, source)
                VALUES (?, 'donor', ?, 'whatsapp_status_check', ?, 'whatsapp')
            ");
            if (!$stmt) {
                return;
            }

            $stmt->bind_param('iis', $operatorId, $donorId, $details);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('WhatsApp CHECK audit log failed: ' . $e->getMessage());
        }
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
     * @return array{reference:string,amount:?float,method:string,payment_date:string}|null
     */
    private function parsePayCommand(string $body): ?array
    {
        // With amount: PAY 0335 50 [method] [date]
        if (preg_match('/^(?:PAY|APPROVE|ክፍያ)\s+(\d{4})\s+([0-9]+(?:\.[0-9]{1,2})?)(?:\s+(cash|card|bank|other))?(?:\s+(\d{4}-\d{2}-\d{2}))?\s*$/iu', $body, $m)) {
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

        // Without amount: PAY 0335
        if (preg_match('/^(?:PAY|APPROVE|ክፍያ)\s+(\d{4})\s*$/iu', $body, $m)) {
            return [
                'reference' => $m[1],
                'amount' => null,
                'method' => 'cash',
                'payment_date' => date('Y-m-d'),
            ];
        }

        return null;
    }

    private function parseAmountOnly(string $body): ?float
    {
        if (!preg_match('/^£?\s*([0-9]+(?:\.[0-9]{1,2})?)\s*$/u', trim($body), $m)) {
            return null;
        }
        $amount = (float)$m[1];
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            return null;
        }
        return $amount;
    }

    private function isConfirmMessage(string $body): bool
    {
        $t = mb_strtolower(trim($body));
        return in_array($t, ['yes', 'y', 'ok', 'confirm', 'አዎ', 'እሺ', 'እሳምማለሁ'], true);
    }

    private function isWrongPersonMessage(string $body): bool
    {
        $t = mb_strtolower(trim($body));
        return in_array($t, ['no', 'n', 'አይደለም', 'አይደለም።'], true);
    }

    private function isAbortMessage(string $body): bool
    {
        $t = mb_strtolower(trim($body));
        return in_array($t, ['ይቅር', 'ይቅር።', 'cancel', 'abort', 'stop'], true);
    }

    /**
     * Resolve donor by reference and build shared payload fields.
     *
     * @param array{reference:string,amount:?float,method:string,payment_date:string} $parsed
     * @return array<string,mixed>|null payload on success; null if reply already sent
     */
    private function resolveDonorPayload(array $operator, array $parsed, string $fromPhone, int $conversationId): ?array
    {
        $matches = $this->findDonorsByReference($parsed['reference']);

        if (count($matches) === 0) {
            $this->reply(
                $fromPhone,
                $this->renderTemplate('not_found', ['reference' => $parsed['reference']]),
                $conversationId,
                (int)$operator['id']
            );
            return null;
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
            return null;
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
            return null;
        }

        $totalPledged = (float)($donor['total_pledged'] ?? 0);
        $totalPaid = (float)($donor['total_paid'] ?? 0);
        $remaining = (float)($donor['balance'] ?? max(0, $totalPledged - $totalPaid));
        $paymentHistory = $this->buildPaymentHistoryText((int)$donor['id']);

        return [
            'donor_id' => (int)$donor['id'],
            'donor_name' => (string)$donor['name'],
            'donor_phone' => (string)$donor['phone'],
            'pledge_id' => (int)$donor['pledge_id'],
            'reference' => $parsed['reference'],
            'amount' => $parsed['amount'],
            'method' => $parsed['method'],
            'payment_date' => $parsed['payment_date'],
            'balance' => $remaining,
            'total_paid' => $totalPaid,
            'total_pledged' => $totalPledged,
            'payment_history' => $paymentHistory,
        ];
    }

    /**
     * @param array{reference:string,amount:?float,method:string,payment_date:string} $parsed
     */
    private function startAmountPrompt(array $operator, array $parsed, string $fromPhone, int $conversationId): bool
    {
        $payload = $this->resolveDonorPayload($operator, $parsed, $fromPhone, $conversationId);
        if ($payload === null) {
            return true;
        }

        $this->cancelOpenSessions($this->normalizePhoneDigits($fromPhone));
        $this->createSession($operator, $payload, 'pending_amount', $fromPhone);

        $preview = $this->formatPreview($payload);
        $msg = $this->renderTemplate('ask_amount', [
            'preview' => $preview,
            'donor_name' => $payload['donor_name'],
            'reference' => $payload['reference'],
            'remaining' => number_format((float)$payload['balance'], 2),
            'total_pledged' => number_format((float)$payload['total_pledged'], 2),
            'payment_history' => (string)$payload['payment_history'],
        ]);
        $this->reply($fromPhone, $msg, $conversationId, (int)$operator['id']);
        return true;
    }

    /**
     * @param array{reference:string,amount:?float,method:string,payment_date:string} $parsed
     */
    private function startConfirmation(array $operator, array $parsed, string $fromPhone, int $conversationId): bool
    {
        $payload = $this->resolveDonorPayload($operator, $parsed, $fromPhone, $conversationId);
        if ($payload === null) {
            return true;
        }

        $this->cancelOpenSessions($this->normalizePhoneDigits($fromPhone));
        $this->createSession($operator, $payload, 'pending_confirm', $fromPhone);
        $this->sendIdentityConfirm($operator, $payload, $fromPhone, $conversationId);
        return true;
    }

    /**
     * @param array<string,mixed> $pending
     */
    private function promoteAmountSession(array $operator, array $pending, float $amount, string $fromPhone, int $conversationId): void
    {
        $payload = $this->payloadFromSession($pending);
        $payload['amount'] = $amount;
        if (empty($payload['method'])) {
            $payload['method'] = 'cash';
        }
        if (empty($payload['payment_date'])) {
            $payload['payment_date'] = date('Y-m-d');
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $sessionId = (int)$pending['id'];
        $expiresAt = date('Y-m-d H:i:s', time() + (self::SESSION_TTL_MINUTES * 60));
        $stmt = $this->db->prepare("
            UPDATE whatsapp_payment_command_sessions
            SET status = 'pending_confirm', payload_json = ?, expires_at = ?
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $payloadJson, $expiresAt, $sessionId);
        $stmt->execute();
        $stmt->close();

        $this->sendIdentityConfirm($operator, $payload, $fromPhone, $conversationId);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sendIdentityConfirm(array $operator, array $payload, string $fromPhone, int $conversationId): void
    {
        $preview = $this->formatPreview($payload);
        $msg = $this->renderTemplate('confirm_request', [
            'preview' => $preview,
            'donor_name' => (string)$payload['donor_name'],
            'donor_phone' => (string)$payload['donor_phone'],
            'reference' => (string)$payload['reference'],
            'amount' => number_format((float)$payload['amount'], 2),
            'method' => (string)$payload['method'],
            'payment_date' => (string)$payload['payment_date'],
            'balance' => number_format((float)$payload['balance'], 2),
            'remaining' => number_format((float)$payload['balance'], 2),
            'total_pledged' => number_format((float)$payload['total_pledged'], 2),
            'total_paid' => number_format((float)$payload['total_paid'], 2),
            'payment_history' => (string)$payload['payment_history'],
            'expires_minutes' => (string)self::SESSION_TTL_MINUTES,
        ]);
        $this->reply($fromPhone, $msg, $conversationId, (int)$operator['id']);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function createSession(array $operator, array $payload, string $status, string $fromPhone): void
    {
        $expiresAt = date('Y-m-d H:i:s', time() + (self::SESSION_TTL_MINUTES * 60));
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $operatorPhone = $this->normalizePhoneDigits($fromPhone);
        $operatorId = (int)$operator['id'];

        $stmt = $this->db->prepare("
            INSERT INTO whatsapp_payment_command_sessions
                (operator_user_id, operator_phone, status, payload_json, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('issss', $operatorId, $operatorPhone, $status, $payloadJson, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * @param array<string,mixed> $pending
     * @return array<string,mixed>
     */
    private function payloadFromSession(array $pending): array
    {
        if (isset($pending['payload_json'])) {
            $decoded = json_decode((string)$pending['payload_json'], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $pending;
    }

    /**
     * Build Amharic payment history lines for a donor.
     */
    private function buildPaymentHistoryText(int $donorId): string
    {
        $lines = [];

        $stmt = $this->db->prepare("
            SELECT payment_date, amount, status
            FROM pledge_payments
            WHERE donor_id = ? AND status = 'confirmed'
            ORDER BY payment_date DESC, id DESC
            LIMIT 12
        ");
        if ($stmt) {
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $dateRaw = (string)($row['payment_date'] ?? '');
                $dateLabel = $dateRaw !== '' ? date('d/m/Y', strtotime($dateRaw)) : '—';
                $amount = number_format((float)($row['amount'] ?? 0), 2);
                $lines[] = "በቀን {$dateLabel} - £{$amount}";
            }
            $stmt->close();
        }

        // Also include approved immediate payments if any
        $stmt2 = $this->db->prepare("
            SELECT COALESCE(DATE(received_at), DATE(created_at)) AS payment_date, amount
            FROM payments
            WHERE donor_id = ? AND status = 'approved'
            ORDER BY COALESCE(received_at, created_at) DESC, id DESC
            LIMIT 8
        ");
        if ($stmt2) {
            $stmt2->bind_param('i', $donorId);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $dateRaw = (string)($row['payment_date'] ?? '');
                $dateLabel = $dateRaw !== '' ? date('d/m/Y', strtotime($dateRaw)) : '—';
                $amount = number_format((float)($row['amount'] ?? 0), 2);
                $line = "በቀን {$dateLabel} - £{$amount}";
                if (!in_array($line, $lines, true)) {
                    $lines[] = $line;
                }
            }
            $stmt2->close();
        }

        if (empty($lines)) {
            return 'ምንም የክፍያ ታሪክ የለም።';
        }

        return implode("\n", $lines);
    }

    private function findAssignedAgentName(int $donorId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT u.name
            FROM donors d
            LEFT JOIN users u ON u.id = d.agent_id
            WHERE d.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $agentName = trim((string)($row['name'] ?? ''));
        return $agentName !== '' ? $agentName : null;
    }

    /**
     * Return every confirmed pledge payment and approved immediate payment.
     */
    private function buildCompletePaymentHistoryText(int $donorId): string
    {
        $stmt = $this->db->prepare("
            SELECT paid_on, amount
            FROM (
                SELECT payment_date AS paid_on, amount, id, 1 AS source_order
                FROM pledge_payments
                WHERE donor_id = ? AND status = 'confirmed'

                UNION ALL

                SELECT COALESCE(DATE(received_at), DATE(created_at)) AS paid_on,
                       amount, id, 2 AS source_order
                FROM payments
                WHERE donor_id = ? AND status = 'approved'
            ) AS donor_payments
            ORDER BY paid_on DESC, source_order ASC, id DESC
        ");
        if (!$stmt) {
            return 'ምንም የክፍያ ታሪክ አልተገኘም።';
        }

        $stmt->bind_param('ii', $donorId, $donorId);
        $stmt->execute();
        $result = $stmt->get_result();
        $lines = [];

        while ($row = $result->fetch_assoc()) {
            $paidOn = (string)($row['paid_on'] ?? '');
            $timestamp = $paidOn !== '' ? strtotime($paidOn) : false;
            $amount = number_format((float)($row['amount'] ?? 0), 2);
            if ($timestamp === false) {
                $lines[] = "• ቀኑ አልተመዘገበም፣ £{$amount} ተከፍሏል።";
                continue;
            }

            $date = date('d/m/Y', $timestamp);
            $lines[] = "• በቀን {$date}፣ £{$amount} ተከፍሏል።";
        }
        $stmt->close();

        if (empty($lines)) {
            return 'ምንም የክፍያ ታሪክ አልተገኘም።';
        }

        return implode("\n", $lines);
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

            $donorStmt = $this->db->prepare("SELECT name, phone, total_pledged, total_paid, balance FROM donors WHERE id = ? LIMIT 1");
            $donorStmt->bind_param('i', $donorId);
            $donorStmt->execute();
            $donor = $donorStmt->get_result()->fetch_assoc() ?: [];
            $donorStmt->close();

            // Same confirmation logic as review-pledge-payments (templates + Kesis routing)
            $confirmResult = $this->sendDonorConfirmation(
                $operator,
                $donorId,
                $amount,
                $paymentDate,
                $donor
            );

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
                'confirmation_status' => (string)($confirmResult['operator_note'] ?? ''),
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
     * Send donor confirmation using the same templates + routing as review-pledge-payments.
     *
     * @param array<string,mixed> $operator
     * @param array<string,mixed> $donor
     * @return array{success:bool,operator_note:string}
     */
    private function sendDonorConfirmation(array $operator, int $donorId, float $amount, string $paymentDate, array $donor): array
    {
        try {
            $hasUserPhoneNumberCol = $this->db->query("SHOW COLUMNS FROM users LIKE 'phone_number'")->num_rows > 0;
            $assignedAgentPhoneExpr = $hasUserPhoneNumberCol
                ? "COALESCE(NULLIF(agent.phone_number, ''), NULLIF(agent.phone, ''))"
                : "NULLIF(agent.phone, '')";

            $stmt = $this->db->prepare("
                SELECT d.name, d.phone, d.preferred_language,
                       d.total_pledged, d.total_paid, d.balance,
                       p.amount AS pledge_amount,
                       dpp.next_payment_due AS plan_next_payment,
                       dpp.monthly_amount AS plan_amount,
                       dpp.status AS plan_status,
                       agent.id AS assigned_agent_id,
                       agent.name AS assigned_agent_name,
                       {$assignedAgentPhoneExpr} AS assigned_agent_phone
                FROM donors d
                LEFT JOIN pledges p ON d.id = p.donor_id AND p.status IN ('approved','pending')
                LEFT JOIN donor_payment_plans dpp ON d.active_payment_plan_id = dpp.id
                LEFT JOIN users agent ON d.agent_id = agent.id
                WHERE d.id = ?
                ORDER BY (p.status = 'approved') DESC, p.id DESC
                LIMIT 1
            ");
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            $totalPledged = (float)($info['total_pledged'] ?? $donor['total_pledged'] ?? 0);
            $totalPaid = (float)($info['total_paid'] ?? $donor['total_paid'] ?? 0);
            $balance = (float)($info['balance'] ?? $donor['balance'] ?? 0);
            $isFullyPaid = $totalPledged > 0 && $balance <= 0;
            $hasPlan = !empty($info['plan_next_payment']) && ($info['plan_status'] ?? '') === 'active';

            $formattedPaymentDate = date('l, j F Y', strtotime($paymentDate));
            $donorName = (string)($info['name'] ?? $donor['name'] ?? 'Donor');
            $donorPhone = (string)($info['phone'] ?? $donor['phone'] ?? '');

            $templateKey = $isFullyPaid ? 'fully_paid_confirmation' : 'payment_confirmed';
            $tpl = $this->loadSmsTemplate($templateKey);
            $templateMode = $this->resolveTemplateMode($tpl);

            $message = '';
            if ($tpl) {
                $message = (string)($tpl['message_am'] ?: ($tpl['message_en'] ?? ''));
            }

            if ($message === '') {
                if ($isFullyPaid) {
                    $message = "ሰላም ጤና ይስጥልን ወድ {donor_name}፣\n\nሙሉ ቃል ኪዳን ክፍያዎን ስለጨረሱ እናመሰግናለን።\n\nበቀን ({date}) የተቀበልነው ክፍያ: £{payment_amount}\n\nየቃል ኪዳንዎ ማጠቃለያ፡\n→ ጠቅላላ ቃል ኪዳን: £{total_pledged}\n→ ጠቅላላ የከፈሉት: £{total_paid}\n→ ቀሪ: £{remaining}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን።\n\n- ሊቨርፑል አቡነ ተክለሃይማኖት ቤተ ክርስቲያን";
                } else {
                    $message = "ሰላም ጤና ይስጥልን የተከበሩ {name}፣\n\nበቀን {payment_date} የ {amount} ፓውንድ ክፍያዎን ተቀብለናል።\n\nየቃል ኪዳንዎ ማጠቃለያ፡\n→ ጠቅላላ ቃል ኪዳን የገቡት፡ £{total_pledge}\n→ እስካሁን የከፈሉት: £{total_paid}\n→ ቀሪ ሂሳብ፡ £{outstanding_balance}\n\n{next_payment_info}\n\nአምላከ ተክለሃይማኖት በሰጡት አብዝቶ ይስጥልን🙏\n\n- ሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተ ክርስቲያን";
                }
            }

            $nextWithPlan = 'ቀጣዩ የ£{next_payment_amount} ክፍያዎ በ{next_payment_date} ነው።';
            $nextWithoutPlan = 'ቀሪ ሂሳብዎን በቀላሉ ማስተካከል እንዲሁም የክፍያ እቅድ ማዘጋጀት ይችላሉ።';
            $nextInfo = $hasPlan ? $nextWithPlan : $nextWithoutPlan;

            $message = str_replace('{next_payment_info}', $nextInfo, $message);
            $replacements = [
                '{name}' => $donorName,
                '{donor_name}' => $donorName,
                '{amount}' => number_format($amount, 2),
                '{payment_amount}' => number_format($amount, 2),
                '{payment_date}' => $formattedPaymentDate,
                '{date}' => $formattedPaymentDate,
                '{total_pledge}' => number_format((float)($info['pledge_amount'] ?? $totalPledged), 2),
                '{total_pledged}' => number_format($totalPledged, 2),
                '{total_paid}' => number_format($totalPaid, 2),
                '{outstanding_balance}' => number_format($balance, 2),
                '{remaining}' => number_format($balance, 2),
                '{total_pledged_sqm}' => (string)round(max($totalPledged, $totalPaid) / 400, 2),
                '{next_payment_amount}' => $hasPlan ? number_format((float)($info['plan_amount'] ?? 0), 2) : '',
                '{next_payment_date}' => $hasPlan && !empty($info['plan_next_payment'])
                    ? date('l, j F Y', strtotime((string)$info['plan_next_payment']))
                    : '',
            ];
            $message = str_replace(array_keys($replacements), array_values($replacements), $message);
            $message = str_replace(['\\n', "\r\n"], "\n", $message);

            $routedToKesis = $this->phonesMatch((string)($info['assigned_agent_phone'] ?? ''), self::KESIS_BIRHANU_PHONE);
            $destinationPhone = $routedToKesis ? self::KESIS_BIRHANU_PHONE : $donorPhone;
            $agentName = $routedToKesis
                ? ((string)($info['assigned_agent_name'] ?: self::KESIS_BIRHANU_NAME))
                : null;

            if (trim($destinationPhone) === '') {
                return [
                    'success' => false,
                    'operator_note' => "\n⚠️ ክፍያ ተጽድቋል፣ ግን የለጋሽ/ወኪል ስልክ አልተገኘም — ማረጋገጫ አልተላከም።",
                ];
            }

            $channel = MessagingHelper::CHANNEL_AUTO;
            $allowSmsFallback = true;
            if ($templateMode === 'sms') {
                $channel = MessagingHelper::CHANNEL_SMS;
            } elseif ($templateMode === 'whatsapp') {
                $channel = MessagingHelper::CHANNEL_WHATSAPP;
                $allowSmsFallback = false;
            }

            $messaging = new MessagingHelper($this->db);
            $messaging->setCurrentUser((int)$operator['id'], $operator);
            $result = $messaging->sendDirect(
                $destinationPhone,
                $message,
                $channel,
                $donorId,
                $templateKey,
                $allowSmsFallback
            );

            if (!empty($result['success'])) {
                $this->logPaymentNotification(
                    $donorId,
                    $templateKey,
                    (string)($result['channel'] ?? 'whatsapp'),
                    $destinationPhone,
                    $message,
                    (int)$operator['id'],
                    $routedToKesis,
                    $agentName
                );

                if ($routedToKesis) {
                    return [
                        'success' => true,
                        'operator_note' => "\n📨 ማረጋገጫ ወደ ወኪል ተልኳል: " . ($agentName ?: self::KESIS_BIRHANU_NAME),
                    ];
                }
                return [
                    'success' => true,
                    'operator_note' => "\n📨 ማረጋገጫ ለለጋሹ ተልኳል።",
                ];
            }

            return [
                'success' => false,
                'operator_note' => "\n⚠️ ክፍያ ተጽድቋል፣ ግን ማረጋገጫ መላክ አልተሳካም: " . (string)($result['error'] ?? 'unknown'),
            ];
        } catch (Throwable $e) {
            error_log('WhatsApp PAY donor confirmation failed: ' . $e->getMessage());
            return [
                'success' => false,
                'operator_note' => "\n⚠️ ክፍያ ተጽድቋል፣ ግን ማረጋገጫ መላክ አልተሳካም።",
            ];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadSmsTemplate(string $templateKey): ?array
    {
        $check = $this->db->query("SHOW TABLES LIKE 'sms_templates'");
        if (!$check || $check->num_rows === 0) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM sms_templates WHERE template_key = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $templateKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * @param array<string,mixed>|null $template
     */
    private function resolveTemplateMode(?array $template): string
    {
        if (!$template) {
            return 'auto';
        }
        $preferred = strtolower(trim((string)($template['preferred_channel'] ?? '')));
        if (in_array($preferred, ['auto', 'sms', 'whatsapp'], true)) {
            return $preferred;
        }
        $platform = strtolower(trim((string)($template['platform'] ?? '')));
        if (in_array($platform, ['sms', 'whatsapp'], true)) {
            return $platform;
        }
        return 'auto';
    }

    private function phonesMatch(string $a, string $b): bool
    {
        $na = $this->normalizePhoneDigits($a);
        $nb = $this->normalizePhoneDigits($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        $va = $this->phoneVariants($na);
        $vb = $this->phoneVariants($nb);
        return count(array_intersect($va, $vb)) > 0;
    }

    private function logPaymentNotification(
        int $donorId,
        string $templateKey,
        string $channel,
        string $phone,
        string $message,
        int $userId,
        bool $routedViaAgent,
        ?string $agentName
    ): void {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'payment_notifications_log'");
            if (!$tableCheck || $tableCheck->num_rows === 0) {
                return;
            }
            $preview = mb_substr($message, 0, 500);
            $routedFlag = $routedViaAgent ? 1 : 0;
            $stmt = $this->db->prepare("
                INSERT INTO payment_notifications_log
                (donor_id, notification_type, channel, phone_number, message_preview, sent_by_user_id, routed_via_agent, agent_name)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param('issssiis', $donorId, $templateKey, $channel, $phone, $preview, $userId, $routedFlag, $agentName);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('WhatsApp PAY notification log failed: ' . $e->getMessage());
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
              AND status IN ('pending_confirm', 'pending_amount')
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
            WHERE operator_phone = ? AND status IN ('pending_confirm', 'pending_amount')
        ");
        $stmt->bind_param('s', $operatorPhoneDigits);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Build donor preview. Uses identity-only template when amount is unknown,
     * or identity + payment amount/date when amount is already provided.
     *
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

        $rawAmount = $payload['amount'] ?? null;
        $hasAmount = $rawAmount !== null && $rawAmount !== '' && (float)$rawAmount > 0;

        $paymentDateRaw = (string)($payload['payment_date'] ?? date('Y-m-d'));
        $dayLabel = $paymentDateRaw;
        $ts = strtotime($paymentDateRaw);
        if ($ts !== false) {
            $dayLabel = date('d/m/Y', $ts);
        }

        $vars = [
            'donor_name' => (string)($payload['donor_name'] ?? 'Donor'),
            'donor_phone' => (string)($payload['donor_phone'] ?? ''),
            'reference' => (string)($payload['reference'] ?? ''),
            'amount' => $hasAmount ? number_format((float)$rawAmount, 2) : '',
            'method' => (string)($payload['method'] ?? 'cash'),
            'payment_date' => $dayLabel,
            'day' => $dayLabel,
            'balance' => number_format((float)($payload['balance'] ?? 0), 2),
            'remaining' => number_format((float)($payload['balance'] ?? 0), 2),
            'total_pledged' => number_format((float)($payload['total_pledged'] ?? 0), 2),
            'total_paid' => number_format((float)($payload['total_paid'] ?? 0), 2),
            'payment_history' => (string)($payload['payment_history'] ?? $this->buildPaymentHistoryText((int)($payload['donor_id'] ?? 0))),
        ];

        $templateKey = $hasAmount ? 'preview_block_with_amount' : 'preview_block';
        return $this->renderTemplate($templateKey, $vars);
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
                'body' => "📌 *የWhatsApp ክፍያ ትእዛዝ*\n\nክፍያ ለመመዝገብ፦\n*PAY 0335*\nወይም\n*PAY 0335 50*\n\nየለጋሽ መረጃን ብቻ ለማየት፦\n*CHECK 0335*\n\nከPAY ትእዛዝ በኋላ፦\n• መጠን ካልላኩ — የክፍያ መጠን ይላኩ ወይም *ይቅር*\n• መጠን ካላኩ — *አዎ* ለማረጋገጥ ወይም *አይደለም* ትክክለኛው ሰው ካልሆነ",
            ],
            'status_check' => [
                'label' => 'Donor Status Check',
                'description' => 'Read-only Amharic donor summary sent for CHECK 0335',
                'placeholders' => '{donor_name} {donor_phone} {reference} {pledge_amount} {total_paid} {balance} {assigned_agent} {payment_history}',
                'body' => "👤 ስም: {donor_name}\n📞 ስልክ: {donor_phone}\n🔖 መከታተያ ቁጥር: {reference}\n\n💷 ቃል የገቡት መጠን: £{pledge_amount}\n✅ እስካሁን የከፈሉት: £{total_paid}\n📊 ቀሪ ክፍያ: £{balance}\n👤 የተመደበ ወኪል: {assigned_agent}\n\n--------------------------------\n📜 *የክፍያ ታሪክ*\n{payment_history}",
            ],
            'ask_amount' => [
                'label' => 'Ask Amount (after PAY ref only)',
                'description' => 'Show donor identity (no payment amount yet), then ask for amount or ይቅር',
                'placeholders' => '{preview}',
                'body' => "{preview}\n\nአዲስ ክፍያ ለመመዝገብ የሚከፍለውን መጠን ይላኩ።\nለመተው *ይቅር* ብለው ይላኩ።",
            ],
            'ask_amount_reminder' => [
                'label' => 'Ask Amount Reminder',
                'description' => 'Reminder while waiting for amount (reference-only flow)',
                'placeholders' => '{preview}',
                'body' => "⏳ የክፍያ መጠን እየጠበቁ ነው።\n\n{preview}\n\nአዲስ ክፍያ ለመመዝገብ የሚከፍለውን መጠን ይላኩ።\nለመተው *ይቅር* ብለው ይላኩ።",
            ],
            'confirm_request' => [
                'label' => 'Confirm Request (identity)',
                'description' => 'Ask if this is the correct donor. Preview already includes payment amount when provided.',
                'placeholders' => '{preview}',
                'body' => "{preview}\n\nይህ ትክክለኛው ሰው ነው?\nከሆነ *አዎ* ብለው ይላኩ።\nካልሆነ *አይደለም* ብለው ይላኩ።",
            ],
            'preview_block' => [
                'label' => 'Preview (reference only)',
                'description' => 'Shown after PAY 0335 (no amount yet). ቃል የገቡት = pledge total, not payment.',
                'placeholders' => '{donor_name} {donor_phone} {reference} {total_pledged} {balance}',
                'body' => "👤 {donor_name}\n📞 {donor_phone}\n🔖 መከታተያ: {reference}\n💷 ቃል የገቡት መጠን: £{total_pledged}\n📊 ቀሪ ክፍያ: £{balance}",
            ],
            'preview_block_with_amount' => [
                'label' => 'Preview (reference + amount)',
                'description' => 'Shown after PAY 0335 50. Separates pledge total from the amount being paid now.',
                'placeholders' => '{donor_name} {donor_phone} {reference} {total_pledged} {balance} {amount} {day}',
                'body' => "👤 {donor_name}\n📞 {donor_phone}\n🔖 መከታተያ: {reference}\n💷 ቃል የገቡት መጠን: £{total_pledged}\n📊 ቀሪ ክፍያ: £{balance}\n---------------------------\nአሁን የሚከፍለው መጠን: £{amount}\nቀን: {day}",
            ],
            'pending_reminder' => [
                'label' => 'Pending Reminder',
                'description' => 'Reminder when identity confirmation is still waiting',
                'placeholders' => '{preview}',
                'body' => "⏳ ገና ማረጋገጫ እየጠበቁ ነው።\n\n{preview}\n\nይህ ትክክለኛው ሰው ነው?\nከሆነ *አዎ* ብለው ይላኩ።\nካልሆነ *አይደለም* ብለው ይላኩ።",
            ],
            'cancelled' => [
                'label' => 'Wrong Person (አይደለም)',
                'description' => 'Sent after አይደለም — ask them to send reference again',
                'placeholders' => '',
                'body' => "❌ ትክክለኛው ሰው አይደለም።\n\nእባክዎ መከታተያ ቁጥሩን እንደገና ይላኩ።\nምሳሌ: PAY 0335",
            ],
            'cancelled_abort' => [
                'label' => 'Aborted (ይቅር)',
                'description' => 'Sent after ይቅር — leave without recording',
                'placeholders' => '',
                'body' => "❌ ተቋርጧል። ምንም ክፍያ አልተመዘገበም።\n\nእንደገና ለመጀመር: PAY 0335",
            ],
            'success' => [
                'label' => 'Success (message 4)',
                'description' => 'Sent after payment is recorded and approved',
                'placeholders' => '{donor_name} {reference} {amount} {payment_date} {method} {payment_id} {total_pledged} {total_paid} {remaining} {confirmation_status}',
                'body' => "✅ *ክፍያ ተጽድቋል*\n\nለጋሽ: {donor_name}\nመከታተያ: {reference}\nመጠን: £{amount}\nቀን: {payment_date}\nዘዴ: {method}\nየክፍያ መለያ: #{payment_id}\n\nማጠቃለያ:\n→ ጠቅላላ ቃል ኪዳን: £{total_pledged}\n→ እስካሁን የከፈሉት: £{total_paid}\n→ ቀሪ: £{remaining}{confirmation_status}\n\nሌላ ለመጀመር: PAY 0335 50",
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
        if ($stmt) {
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

        // One-time upgrade of identity-confirm flow templates (versioned)
        $flowVersion = 6;
        $currentVersion = 0;
        $verRes = $this->db->query("SELECT body FROM whatsapp_pay_message_templates WHERE template_key = '_flow_version' LIMIT 1");
        if ($verRes && ($verRow = $verRes->fetch_assoc())) {
            $currentVersion = (int)$verRow['body'];
        } else {
            $this->db->query("
                INSERT IGNORE INTO whatsapp_pay_message_templates
                    (template_key, label, description, placeholders_help, body, updated_at)
                VALUES ('_flow_version', 'Internal', 'Flow template version marker', '', '0', NOW())
            ");
        }

        if ($currentVersion < $flowVersion) {
            $flowKeys = [
                'help',
                'status_check',
                'ask_amount',
                'ask_amount_reminder',
                'confirm_request',
                'preview_block',
                'preview_block_with_amount',
                'pending_reminder',
                'cancelled',
                'cancelled_abort',
                'success',
            ];
            $upd = $this->db->prepare("
                UPDATE whatsapp_pay_message_templates
                SET body = ?, label = ?, description = ?, placeholders_help = ?, updated_at = NOW()
                WHERE template_key = ?
            ");
            $ins = $this->db->prepare("
                INSERT INTO whatsapp_pay_message_templates
                    (template_key, label, description, placeholders_help, body, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    body = VALUES(body),
                    label = VALUES(label),
                    description = VALUES(description),
                    placeholders_help = VALUES(placeholders_help),
                    updated_at = NOW()
            ");
            if ($upd && $ins) {
                foreach ($flowKeys as $key) {
                    if (!isset($defaults[$key])) {
                        continue;
                    }
                    $tpl = $defaults[$key];
                    $body = $tpl['body'];
                    $label = $tpl['label'];
                    $desc = $tpl['description'];
                    $ph = $tpl['placeholders'];
                    $ins->bind_param('sssss', $key, $label, $desc, $ph, $body);
                    $ins->execute();
                }
                $upd->close();
                $ins->close();
            }

            $ver = (string)$flowVersion;
            $verKey = '_flow_version';
            $setVer = $this->db->prepare("UPDATE whatsapp_pay_message_templates SET body = ?, updated_at = NOW() WHERE template_key = ?");
            if ($setVer) {
                $setVer->bind_param('ss', $ver, $verKey);
                $setVer->execute();
                $setVer->close();
            }
        }
    }

    private function ensureTables(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS whatsapp_payment_command_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                operator_user_id INT NOT NULL,
                operator_phone VARCHAR(30) NOT NULL,
                status ENUM('pending_amount','pending_confirm','completed','cancelled','expired') NOT NULL DEFAULT 'pending_confirm',
                payload_json TEXT NOT NULL,
                completed_payment_id INT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                INDEX idx_operator_pending (operator_phone, status),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Add pending_amount for existing installs
        @$this->db->query("
            ALTER TABLE whatsapp_payment_command_sessions
            MODIFY COLUMN status ENUM('pending_amount','pending_confirm','completed','cancelled','expired')
            NOT NULL DEFAULT 'pending_confirm'
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
