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
assertSameValue('welcome', CampaignPayingProgress::sanitizeStep('../admin'), 'unknown steps fall back to welcome');

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

$sign = CampaignPayingProgress::sign('a1b2c3d4e5f67890');
assertSameValue(64, strlen($sign), 'sync signatures are 64 hex characters');
assertSameValue(true, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', $sign), 'accepts a valid sync signature');
assertSameValue(false, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', str_repeat('0', 64)), 'rejects a bad sync signature');

fwrite(STDOUT, "PASS campaign paying progress tests\n");
