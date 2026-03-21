<?php
declare(strict_types=1);

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private string $host;
    private string $username;
    private string $password;
    private int $port;
    private string $fromName;

    public function __construct(
        string $host = 'mail.abuneteklehaymanot.org',
        string $username = 'stakeholder@abuneteklehaymanot.org',
        string $password = '',
        int $port = 465,
        string $fromName = 'LMKAT EOTC'
    ) {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->port = $port;
        $this->fromName = $fromName;
    }

    public static function fromDatabase($db): ?self
    {
        try {
            $check = $db->query("SHOW TABLES LIKE 'site_settings'");
            if (!$check || $check->num_rows === 0) {
                return null;
            }

            $result = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('smtp_host','smtp_user','smtp_pass','smtp_port','smtp_from_name')");
            if (!$result) return null;

            $settings = [];
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            if (empty($settings['smtp_pass'])) {
                return null;
            }

            return new self(
                $settings['smtp_host'] ?? 'mail.abuneteklehaymanot.org',
                $settings['smtp_user'] ?? 'stakeholder@abuneteklehaymanot.org',
                $settings['smtp_pass'] ?? '',
                (int)($settings['smtp_port'] ?? 465),
                $settings['smtp_from_name'] ?? 'LMKAT EOTC'
            );
        } catch (\Throwable $e) {
            error_log("EmailService: Failed to load from database - " . $e->getMessage());
            return null;
        }
    }

    public function send(string $to, string $subject, string $htmlBody, string $replyTo = ''): array
    {
        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $this->port;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($this->username, $this->fromName);
            $mail->addAddress($to);

            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

            $mail->send();

            return ['success' => true];
        } catch (Exception $e) {
            error_log("EmailService error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
