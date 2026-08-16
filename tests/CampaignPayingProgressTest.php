<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/CampaignPayingProgress.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n"
        );
        exit(1);
    }
}

assertSameValue('a1b2c3d4e5f67890', CampaignPayingProgress::normalizeToken('A1B2C3D4E5F67890'), 'normalizes paying tokens');
assertSameValue(null, CampaignPayingProgress::normalizeToken('not-a-token'), 'rejects invalid tokens');
assertSameValue('status', CampaignPayingProgress::sanitizeStep('info'), 'maps the old info step to status');
assertSameValue('status', CampaignPayingProgress::sanitizeStep('status'), 'allows the status step');
assertSameValue('contact', CampaignPayingProgress::sanitizeStep('contact'), 'allows the contact step');
assertSameValue('welcome', CampaignPayingProgress::sanitizeStep('../admin'), 'unknown steps fall back to welcome');
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('contact', ['status_correct' => 'yes']),
    'contact is allowed after a yes on status'
);
assertSameValue(
    'status',
    CampaignPayingProgress::resolveStep('contact', ['status_correct' => 'no']),
    'contact is blocked when status is not yes'
);
assertSameValue(
    'status',
    CampaignPayingProgress::resolveStep('contact', []),
    'contact is blocked before a status answer'
);
assertSameValue(
    '{}',
    json_encode(CampaignPayingProgress::answersForClient([])),
    'empty answers encode as a JSON object so JS does not treat them as an array'
);
assertSameValue(
    '{"status_correct":"yes"}',
    json_encode(CampaignPayingProgress::answersForClient(['status_correct' => 'yes'])),
    'status answers keep their keys when encoded for the paying page'
);

$clean = CampaignPayingProgress::sanitizeAnswers([
    'confirm_name' => 'Abeba',
    'donor_id' => 99,
    'token' => 'secret',
    '<script>' => 'x',
    'note' => '<b>hi</b>',
    'flag' => true,
]);
assertSameValue('Abeba', $clean['confirm_name'] ?? null, 'keeps a normal answer');
assertSameValue(true, $clean['flag'] ?? null, 'keeps a boolean answer');
assertSameValue(false, array_key_exists('donor_id', $clean), 'strips donor_id');
assertSameValue(false, array_key_exists('token', $clean), 'strips token');
assertSameValue('hi', $clean['note'] ?? null, 'strips HTML from answers');

$booking = CampaignPayingProgress::sanitizeAnswers([
    'contact_date' => '2026-08-20',
    'contact_time' => '14:30:00',
    'contact_method' => 'whatsapp',
    'status_correct' => 'yes',
]);
assertSameValue('2026-08-20', $booking['contact_date'] ?? null, 'keeps a booking date');
assertSameValue('14:30', $booking['contact_time'] ?? null, 'normalizes booking time to HH:MM');
assertSameValue('whatsapp', $booking['contact_method'] ?? null, 'keeps WhatsApp as a contact method');
assertSameValue(
    false,
    array_key_exists('contact_method', CampaignPayingProgress::sanitizeAnswers(['contact_method' => 'email'])),
    'rejects an unknown contact method'
);
assertSameValue(
    false,
    array_key_exists('contact_date', CampaignPayingProgress::sanitizeAnswers(['contact_date' => 'tomorrow'])),
    'rejects a non-ISO booking date'
);

$sign = CampaignPayingProgress::sign('a1b2c3d4e5f67890');
assertSameValue(64, strlen($sign), 'sync signatures are 64 hex characters');
assertSameValue(true, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', $sign), 'accepts a valid sync signature');
assertSameValue(false, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', str_repeat('0', 64)), 'rejects a bad sync signature');

fwrite(STDOUT, "PASS campaign paying progress tests\n");
