<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/CampaignPayingReport.php';

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

$none = CampaignPayingReport::classify([]);
assertSameValue(false, $none['sent'], 'no link is not sent');
assertSameValue(false, $none['opened'], 'no link is not opened');
assertSameValue(false, $none['answered'], 'no link is not answered');
assertSameValue(null, $none['answer'], 'no answer value');
assertSameValue(false, $none['booked'], 'no link is not booked');

$sentOnly = CampaignPayingReport::classify([
    'last_sent_at' => '2026-08-16 10:00:00',
]);
assertSameValue(true, $sentOnly['sent'], 'last_sent_at counts as sent');
assertSameValue(false, $sentOnly['opened'], 'sent without open is not opened');

$openedByStamp = CampaignPayingReport::classify([
    'last_sent_at' => '2026-08-16 10:00:00',
    'opened_at' => '2026-08-16 10:05:00',
]);
assertSameValue(true, $openedByStamp['opened'], 'opened_at counts as opened');

$openedByProgress = CampaignPayingReport::classify([
    'progress_updated_at' => '2026-08-16 10:06:00',
    'revision' => 1,
]);
assertSameValue(true, $openedByProgress['opened'], 'saved progress counts as opened');

$yes = CampaignPayingReport::classify([
    'opened_at' => '2026-08-16 10:05:00',
    'answers' => ['status_correct' => 'yes'],
]);
assertSameValue(true, $yes['answered'], 'yes is an answer');
assertSameValue('yes', $yes['answer'], 'stores yes');
assertSameValue(false, $yes['booked'], 'yes alone is not a booking');

$no = CampaignPayingReport::classify([
    'answers_json' => '{"status_correct":"no"}',
]);
assertSameValue(true, $no['answered'], 'json no is an answer');
assertSameValue('no', $no['answer'], 'stores no');
assertSameValue(true, $no['opened'], 'an answer means the link was opened');

$booked = CampaignPayingReport::classify([
    'opened_at' => '2026-08-16 10:05:00',
    'answers' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'whatsapp',
    ],
]);
assertSameValue(true, $booked['booked'], 'date, time, and method count as booked');
assertSameValue('2026-08-20', $booked['contact_date'], 'keeps booking date');
assertSameValue('14:30', $booked['contact_time'], 'keeps booking time');
assertSameValue('whatsapp', $booked['contact_method'], 'keeps WhatsApp method');

$partial = CampaignPayingReport::classify([
    'answers' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_method' => 'phone',
    ],
]);
assertSameValue(false, $partial['booked'], 'missing time is not booked');

assertSameValue(true, CampaignPayingReport::matchesFilter($sentOnly, 'sent'), 'sent filter keeps sent');
assertSameValue(true, CampaignPayingReport::matchesFilter($sentOnly, 'not_opened'), 'not-opened filter keeps sent unread');
assertSameValue(false, CampaignPayingReport::matchesFilter($openedByStamp, 'not_opened'), 'opened rows leave not-opened');
assertSameValue(true, CampaignPayingReport::matchesFilter($yes, 'answered'), 'answered filter keeps yes');
assertSameValue(true, CampaignPayingReport::matchesFilter($booked, 'booked'), 'booked filter keeps a full booking');
assertSameValue(true, CampaignPayingReport::matchesFilter($none, 'all'), 'all filter keeps empty rows');

$summary = CampaignPayingReport::summarize([$none, $sentOnly, $openedByStamp, $yes, $no, $booked]);
assertSameValue(6, $summary['donors'], 'counts every still-paying row');
assertSameValue(2, $summary['sent'], 'sent counts last_sent_at only');
assertSameValue(4, $summary['opened'], 'opened counts stamps, progress, or answers');
assertSameValue(1, $summary['not_opened'], 'not-opened counts sent links that were never opened');
assertSameValue(3, $summary['answered'], 'answered counts yes and no');
assertSameValue(2, $summary['answered_yes'], 'counts yes answers');
assertSameValue(1, $summary['answered_no'], 'counts no answers');
assertSameValue(1, $summary['booked'], 'counts complete bookings');

assertSameValue(
    '20 Aug 2026, 2:30 PM · WhatsApp',
    CampaignPayingReport::formatBooking('2026-08-20', '14:30', 'whatsapp'),
    'formats a WhatsApp booking for staff'
);
assertSameValue(
    '20 Aug 2026, 9:05 AM · Phone',
    CampaignPayingReport::formatBooking('2026-08-20', '09:05', 'phone'),
    'formats a phone booking for staff'
);
assertSameValue(
    '16 Aug 2026, 10:05 AM',
    CampaignPayingReport::formatWhen('2026-08-16 10:05:00'),
    'formats an opened time for staff'
);

fwrite(STDOUT, "PASS campaign paying report tests\n");
