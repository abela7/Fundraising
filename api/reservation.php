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
                $guestLine = $guests > 1 ? "👥 Guests: {$guests} (including yourself)\n" : "";
                $message = "Dear {$fullName},\n\n"
                    . "Thank you for reserving your spot for our Community Engagement event! We're delighted to have you.\n\n"
                    . "📅 Sunday, 29 March 2026\n"
                    . "🕑 2:00 PM\n"
                    . "📍 St Gabriel's Church, 16 Yates St, Liverpool L8 6RD\n"
                    . $guestLine . "\n"
                    . "We look forward to welcoming you with traditional coffee, authentic cuisine, and more.\n\n"
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
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:20px auto;background:#ffffff;">
    <tr><td style="padding:30px 30px 20px;">
        <h1 style="color:#064a66;font-size:22px;margin:0 0 15px;">Reservation Confirmed</h1>
        <p style="font-size:15px;color:#333;margin:0 0 20px;">Dear ' . htmlspecialchars($fullName) . ',</p>
        <p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 25px;">Thank you for reserving your spot for our Community Engagement event! We are delighted to have you.</p>
        <p style="font-size:15px;color:#333;line-height:1.8;margin:0 0 25px;">
            Date: Sunday, 29 March 2026<br>
            Time: 2:00 PM<br>
            Venue: St Gabriel\'s Church, 16 Yates St, Liverpool L8 6RD' . ($guests > 1 ? '<br>Guests: ' . (int)$guests . ' (including yourself)' : '') . '
        </p>
        <p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 25px;">We look forward to welcoming you with traditional coffee, authentic cuisine, and more.</p>
    </td></tr>
    <tr><td style="padding:20px 30px;border-top:1px solid #eee;">
        <p style="color:#999;font-size:13px;margin:0;">God bless you!</p>
        <p style="color:#999;font-size:12px;margin:5px 0 0;">Liverpool Mekane Kiddusan Abune Teklehaymanot EOTC</p>
    </td></tr>
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
        'guests' => $guests,
        'whatsapp_sent' => $whatsappSent,
        'email_sent' => $emailSent
    ]);

} catch (Throwable $e) {
    error_log("Reservation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
