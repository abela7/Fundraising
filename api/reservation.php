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

    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your reservation! We look forward to welcoming you.',
        'reservation_id' => $reservationId,
        'whatsapp_sent' => $whatsappSent
    ]);

} catch (Throwable $e) {
    error_log("Reservation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
