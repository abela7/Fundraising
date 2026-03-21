<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/UltraMsgService.php';
require_once __DIR__ . '/../services/EmailService.php';

try {
    $db = db();

    // Parse input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $fullName   = trim($input['fullName'] ?? '');
    $email      = trim($input['email'] ?? '');
    $phone      = trim($input['phone'] ?? '');
    $attendance = trim($input['attendance'] ?? 'yes');
    $guests     = max(1, min(20, (int)($input['guests'] ?? 1)));
    $dietary    = trim($input['dietary'] ?? '');

    // Validation
    if ($fullName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Full name is required']);
        exit;
    }

    if ($email === '' && $phone === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email or phone number is required']);
        exit;
    }

    if (!in_array($attendance, ['yes', 'maybe', 'no'], true)) {
        $attendance = 'yes';
    }

    // Insert reservation
    $stmt = $db->prepare("
        INSERT INTO event_reservations (full_name, email, phone, attendance, guests, dietary, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $emailVal = $email !== '' ? $email : null;
    $phoneVal = $phone !== '' ? $phone : null;
    $dietaryVal = $dietary !== '' ? $dietary : null;
    $stmt->bind_param('ssssis', $fullName, $emailVal, $phoneVal, $attendance, $guests, $dietaryVal);
    $stmt->execute();
    $reservationId = $stmt->insert_id;
    $stmt->close();

    // Send WhatsApp confirmation if phone provided and attending
    $whatsappSent = false;
    if ($phone !== '' && $attendance !== 'no') {
        try {
            $whatsapp = UltraMsgService::fromDatabase($db);
            if ($whatsapp) {
                $message = "Dear {$fullName},\n\n"
                    . "Thank you for reserving your spot for our Community Engagement event! We're delighted to have you.\n\n"
                    . "📅 Sunday, 29 March 2026\n"
                    . "🕑 2:00 PM\n"
                    . "📍 St Gabriel's Church, 16 Yates St, Liverpool L8 6RD\n\n"
                    . "We look forward to welcoming you with traditional coffee, authentic cuisine, and more.\n\n"
                    . "For more details:\n"
                    . "https://donate.abuneteklehaymanot.org/invitation\n\n"
                    . "God bless you! 🙏\n\n"
                    . "- Liverpool Mekane Kiddusan Abune Teklehaymanot EOTC";

                $result = $whatsapp->send($phone, $message, ['log' => true]);
                $whatsappSent = !empty($result['success']);

                if ($whatsappSent) {
                    $updateStmt = $db->prepare("UPDATE event_reservations SET whatsapp_sent = 1 WHERE id = ?");
                    $updateStmt->bind_param('i', $reservationId);
                    $updateStmt->execute();
                    $updateStmt->close();
                }
            }
        } catch (Throwable $e) {
            error_log("Reservation WhatsApp error: " . $e->getMessage());
        }
    }

    // Send email confirmation if email provided
    $emailSent = false;
    if ($email !== '') {
        try {
            $emailService = EmailService::fromDatabase($db);
            if ($emailService) {
                $subject = 'Reservation Confirmed - LMKAT EOTC Community Engagement';
                $htmlBody = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:20px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
    <tr><td style="background:#064a66;padding:4px 0;text-align:center;"><img src="https://donate.abuneteklehaymanot.org/invitation/Pattern_Two!.jpeg" style="width:100%;height:12px;object-fit:cover;" alt=""></td></tr>
    <tr><td style="background:#064a66;padding:30px 30px 20px;text-align:center;">
        <h1 style="color:#e2ca18;font-size:24px;margin:0 0 8px;">You\'re All Set!</h1>
        <p style="color:rgba(255,255,255,0.8);font-size:15px;margin:0;">Your reservation has been confirmed</p>
    </td></tr>
    <tr><td style="padding:30px;">
        <p style="font-size:15px;color:#333;margin:0 0 20px;">Dear <strong>' . htmlspecialchars($fullName) . '</strong>,</p>
        <p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 25px;">Thank you for reserving your spot for our Community Engagement event! We\'re delighted to have you.</p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fa;border-radius:10px;padding:5px;">
            <tr><td style="padding:12px 18px;border-bottom:1px solid #e9ecef;">
                <span style="color:#064a66;font-weight:bold;">📅</span>
                <span style="color:#333;font-size:15px;margin-left:8px;">Sunday, 29 March 2026</span>
            </td></tr>
            <tr><td style="padding:12px 18px;border-bottom:1px solid #e9ecef;">
                <span style="color:#064a66;font-weight:bold;">🕑</span>
                <span style="color:#333;font-size:15px;margin-left:8px;">2:00 PM</span>
            </td></tr>
            <tr><td style="padding:12px 18px;">
                <span style="color:#064a66;font-weight:bold;">📍</span>
                <span style="color:#333;font-size:15px;margin-left:8px;">St Gabriel\'s Church, 16 Yates St, Liverpool L8 6RD</span>
            </td></tr>
        </table>
        <p style="font-size:15px;color:#333;line-height:1.6;margin:25px 0 20px;">We look forward to welcoming you with traditional coffee, authentic cuisine, and more.</p>
        <p style="text-align:center;margin:25px 0 0;">
            <a href="https://donate.abuneteklehaymanot.org/invitation" style="display:inline-block;background:#064a66;color:#ffffff;text-decoration:none;padding:12px 30px;border-radius:8px;font-weight:bold;font-size:14px;">View Event Details</a>
        </p>
    </td></tr>
    <tr><td style="background:#064a66;padding:20px 30px;text-align:center;">
        <p style="color:rgba(255,255,255,0.7);font-size:13px;margin:0;">God bless you! 🙏</p>
        <p style="color:rgba(255,255,255,0.5);font-size:12px;margin:8px 0 0;">Liverpool Mekane Kiddusan Abune Teklehaymanot EOTC</p>
    </td></tr>
    <tr><td style="background:#064a66;padding:0 0 4px;text-align:center;"><img src="https://donate.abuneteklehaymanot.org/invitation/Pattern_Two!.jpeg" style="width:100%;height:12px;object-fit:cover;" alt=""></td></tr>
</table>
</body>
</html>';

                $result = $emailService->send($email, $subject, $htmlBody);
                $emailSent = !empty($result['success']);
            }
        } catch (Throwable $e) {
            error_log("Reservation email error: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your reservation! We look forward to welcoming you.',
        'reservation_id' => $reservationId,
        'whatsapp_sent' => $whatsappSent,
        'email_sent' => $emailSent
    ]);

} catch (Throwable $e) {
    error_log("Reservation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
