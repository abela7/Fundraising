<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$db = db();
$keys = ['payment_confirmed', 'fully_paid_confirmation'];

foreach ($keys as $key) {
    $stmt = $db->prepare(
        "SELECT template_key, message_en, message_am
         FROM sms_templates
         WHERE template_key = ?
         LIMIT 1"
    );
    if (!$stmt) {
        echo "prepare failed for {$key}\n";
        continue;
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo "--- {$key} ---\n";
    if (!$row) {
        echo "not found\n";
        continue;
    }

    $en = preg_replace('/\s+/', ' ', (string)$row['message_en']);
    $am = preg_replace('/\s+/', ' ', (string)$row['message_am']);

    echo "EN: " . mb_substr($en, 0, 220) . "\n";
    echo "AM: " . mb_substr($am, 0, 220) . "\n\n";
}
