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
assertSameValue('done', CampaignPayingProgress::sanitizeStep('done'), 'allows the thank-you step');
assertSameValue('phone', CampaignPayingProgress::sanitizeStep('phone'), 'allows the phone-check step');
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
    'phone',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'phone',
    ]),
    'thank-you is blocked until the phone number is confirmed'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'phone',
        'phone_correct' => 'yes',
        'contact_phone' => '07360436171',
    ]),
    'thank-you is allowed after the stored phone is confirmed'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'whatsapp',
        'phone_correct' => 'no',
        'contact_phone' => '+447360436171',
    ]),
    'thank-you is allowed after a new UK number is entered'
);
assertSameValue(
    'phone',
    CampaignPayingProgress::resolveStep('phone', [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'phone',
    ]),
    'phone check is allowed after a complete booking'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('phone', ['status_correct' => 'yes']),
    'phone check is blocked before date, time, and method'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('done', ['status_correct' => 'yes']),
    'thank-you is blocked before date, time, and method'
);
assertSameValue(
    true,
    CampaignPayingProgress::isBookingComplete([
        'contact_date' => '2026-08-20',
        'contact_time' => '10:30',
        'contact_method' => 'phone',
    ]),
    'a full date, time, and method is a complete booking'
);
assertSameValue(
    false,
    CampaignPayingProgress::isBookingComplete([
        'contact_date' => '2026-08-20',
        'contact_method' => 'phone',
    ]),
    'missing time is not a complete booking'
);
assertSameValue('07360436171', CampaignPayingProgress::normalizeUkPhone('07360436171'), 'keeps a 07 mobile number');
assertSameValue('07360436171', CampaignPayingProgress::normalizeUkPhone('+447360436171'), 'normalizes +44 to 07');
assertSameValue('07360436171', CampaignPayingProgress::normalizeUkPhone('44 7360 436171'), 'normalizes 44 to 07');
assertSameValue('07360436171', CampaignPayingProgress::normalizeUkPhone('00447360436171'), 'normalizes 0044 to 07');
assertSameValue(null, CampaignPayingProgress::normalizeUkPhone('01632960001'), 'rejects a UK landline');
assertSameValue(null, CampaignPayingProgress::normalizeUkPhone('07'), 'rejects a short 07 number');
assertSameValue(
    true,
    CampaignPayingProgress::isPhoneConfirmed(['phone_correct' => 'yes']),
    'yes on the stored number is confirmed'
);
assertSameValue(
    true,
    CampaignPayingProgress::isPhoneConfirmed([
        'phone_correct' => 'no',
        'contact_phone' => '+447360436171',
    ]),
    'a new valid UK number is confirmed'
);
assertSameValue(
    false,
    CampaignPayingProgress::isPhoneConfirmed(['phone_correct' => 'no', 'contact_phone' => '07']),
    'an invalid new number is not confirmed'
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
assertSameValue(
    '14:30',
    CampaignPayingProgress::sanitizeAnswers(['contact_time' => '14:30:00.000'])['contact_time'] ?? null,
    'normalizes a time with milliseconds'
);
assertSameValue(
    '09:05',
    CampaignPayingProgress::sanitizeAnswers(['contact_time' => '9:05'])['contact_time'] ?? null,
    'normalizes a one-digit hour'
);
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

$kept = CampaignPayingProgress::mergeAnswers(
    [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'whatsapp',
    ],
    []
);
assertSameValue('yes', $kept['status_correct'] ?? null, 'empty save keeps the stored yes');
assertSameValue('2026-08-20', $kept['contact_date'] ?? null, 'empty save keeps the stored date');
assertSameValue(
    'phone',
    CampaignPayingProgress::mergeAnswers(
        ['contact_method' => 'whatsapp'],
        ['contact_method' => 'phone']
    )['contact_method'] ?? null,
    'a later save can replace a contact method'
);
assertSameValue(
    'done',
    CampaignPayingProgress::preferStep('welcome', 'done', [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'phone',
        'phone_correct' => 'yes',
        'contact_phone' => '07360436171',
    ]),
    'an empty page-open cannot rewind a finished thank-you step'
);
assertSameValue(
    ['status_correct' => 'yes'],
    CampaignPayingProgress::decodeAnswersJson('{"status_correct":"yes"}'),
    'decodes stored answer JSON'
);
assertSameValue(
    'yes',
    CampaignPayingProgress::decodeAnswersJson(['status_correct' => 'yes'])['status_correct'] ?? null,
    'accepts already-decoded answer arrays'
);

$sign = CampaignPayingProgress::sign('a1b2c3d4e5f67890');
assertSameValue(64, strlen($sign), 'sync signatures are 64 hex characters');
assertSameValue(true, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', $sign), 'accepts a valid sync signature');
assertSameValue(false, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', str_repeat('0', 64)), 'rejects a bad sync signature');

fwrite(STDOUT, "PASS campaign paying progress tests\n");
