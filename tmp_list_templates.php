<?php
declare(strict_types=1);

require __DIR__ . '/config/db.php';

$db = db();
$res = $db->query("
    SELECT id, template_key, name, message_en, message_am, preferred_channel, platform, is_active
    FROM sms_templates
    ORDER BY id
");

if (!$res) {
    fwrite(STDERR, "Query failed: " . $db->error . PHP_EOL);
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    $am = trim((string)($row['message_am'] ?? '')) !== '' ? 'yes' : 'no';
    echo $row['id']
        . '|' . $row['template_key']
        . '|' . $row['name']
        . '|am:' . $am
        . '|pref:' . ($row['preferred_channel'] ?? 'n/a')
        . '|platform:' . ($row['platform'] ?? 'n/a')
        . '|active:' . $row['is_active']
        . PHP_EOL;
}
