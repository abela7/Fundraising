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
assertSameValue('', $none['call_status'], 'no booking has no call status');

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
assertSameValue('pending', $booked['call_status'], 'a new booking starts as pending');
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

assertSameValue('Welcome page', CampaignPayingReport::stepLabel('welcome'), 'labels the welcome step');
assertSameValue('Status check', CampaignPayingReport::stepLabel('status'), 'labels the status step');
assertSameValue('Status check', CampaignPayingReport::stepLabel('info'), 'maps the old info step');
assertSameValue('Contact page', CampaignPayingReport::stepLabel('contact'), 'labels the contact step');
assertSameValue('Phone check', CampaignPayingReport::stepLabel('phone'), 'labels the phone-check step');
assertSameValue('Thank you', CampaignPayingReport::stepLabel('done'), 'labels the thank-you step');

$activity = CampaignPayingReport::present([
    'id' => 4,
    'name' => 'Abel Demssie',
    'phone' => '07360436171',
    'reference' => '1234',
    'total_pledged' => 400,
    'total_paid' => 19.55,
    'balance' => 380.45,
    'token' => 'a1b2c3d4e5f67890',
    'step' => 'contact',
    'revision' => 3,
    'last_sent_at' => '2026-08-16 19:50:00',
    'opened_at' => '2026-08-16 20:00:00',
    'progress_updated_at' => '2026-08-16 20:12:00',
    'answers' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'whatsapp',
        'phone_correct' => 'no',
        'contact_phone' => '+447360436171',
    ],
]);
assertSameValue(4, $activity['donor_id'], 'keeps the donor id');
assertSameValue('Abel Demssie', $activity['name'], 'keeps the donor name');
assertSameValue('£400.00', $activity['pledged_label'], 'formats pledged');
assertSameValue('£19.55', $activity['paid_label'], 'formats paid');
assertSameValue('£380.45', $activity['balance_label'], 'formats remaining');
assertSameValue(true, $activity['booked'], 'presents a complete booking');
assertSameValue('Yes', $activity['answer_label'], 'labels a yes answer');
assertSameValue('Contact page', $activity['step_label'], 'presents the current page');
assertSameValue(
    'https://donate.abuneteklehaymanot.org/paying/a1b2c3d4e5f67890',
    $activity['paying_url'],
    'exposes the public paying link'
);
assertSameValue('sent', $activity['timeline'][0]['key'] ?? null, 'timeline starts with the send');
assertSameValue('opened', $activity['timeline'][1]['key'] ?? null, 'timeline includes the open');
assertSameValue('saved', $activity['timeline'][2]['key'] ?? null, 'timeline includes the last save');
assertSameValue(
    '20 Aug 2026, 2:30 PM · WhatsApp',
    $activity['booking_label'],
    'presents the booked slot'
);
assertSameValue('07360436171', $activity['contact_phone'], 'presents the number to call');
assertSameValue('New number', $activity['phone_correct_label'], 'labels a replacement number');
assertSameValue('pending', $activity['call_status'], 'activity defaults a booking to pending');
assertSameValue('Pending', $activity['call_status_label'], 'labels pending on activity');

assertSameValue('pending', CampaignPayingReport::sanitizeCallStatus('Pending'), 'accepts pending');
assertSameValue('contacted', CampaignPayingReport::sanitizeCallStatus('CONTACTED'), 'accepts contacted');
assertSameValue('not_answering', CampaignPayingReport::sanitizeCallStatus('not answering'), 'accepts not answering');
assertSameValue('not_answering', CampaignPayingReport::sanitizeCallStatus('not-answering'), 'accepts hyphenated not answering');
assertSameValue('', CampaignPayingReport::sanitizeCallStatus('closed'), 'rejects unknown call statuses');
assertSameValue('', CampaignPayingReport::resolveCallStatus(false, 'contacted'), 'hides call status before a booking');
assertSameValue('pending', CampaignPayingReport::resolveCallStatus(true, ''), 'empty booked status is pending');
assertSameValue('contacted', CampaignPayingReport::resolveCallStatus(true, 'contacted'), 'keeps a staff call status');
assertSameValue('Pending', CampaignPayingReport::callStatusLabel('pending'), 'labels pending');
assertSameValue('Contacted', CampaignPayingReport::callStatusLabel('contacted'), 'labels contacted');
assertSameValue('Not answering', CampaignPayingReport::callStatusLabel('not_answering'), 'labels not answering');

$contacted = CampaignPayingReport::classify([
    'answers' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'whatsapp',
    ],
    'call_status' => 'contacted',
]);
assertSameValue('contacted', $contacted['call_status'], 'keeps contacted after a booking');
$notAnswering = CampaignPayingReport::classify([
    'answers' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'phone',
    ],
    'call_status' => 'not_answering',
]);
assertSameValue(true, CampaignPayingReport::matchesFilter($booked, 'pending'), 'pending filter keeps a new booking');
assertSameValue(false, CampaignPayingReport::matchesFilter($contacted, 'pending'), 'pending filter drops contacted');
assertSameValue(true, CampaignPayingReport::matchesFilter($contacted, 'contacted'), 'contacted filter keeps contacted');
assertSameValue(true, CampaignPayingReport::matchesFilter($notAnswering, 'not_answering'), 'not-answering filter keeps that row');
assertSameValue(false, CampaignPayingReport::matchesFilter($none, 'pending'), 'pending filter ignores donors who have not booked');

$callSummary = CampaignPayingReport::summarize([$none, $booked, $contacted, $notAnswering]);
assertSameValue(3, $callSummary['booked'], 'booked still counts every complete booking');
assertSameValue(1, $callSummary['call_pending'], 'counts pending bookings');
assertSameValue(1, $callSummary['call_contacted'], 'counts contacted bookings');
assertSameValue(1, $callSummary['call_not_answering'], 'counts not-answering bookings');

$pendingSearch = CampaignPayingReport::filterRows(
    [
        [
            'name' => 'Abel Demssie',
            'phone' => '07360436171',
            'reference' => '1234',
            'booked' => true,
            'call_status' => 'pending',
            'call_status_label' => 'Pending',
        ],
        [
            'name' => 'Other Donor',
            'phone' => '07000000000',
            'reference' => '9999',
            'booked' => true,
            'call_status' => 'contacted',
            'call_status_label' => 'Contacted',
        ],
    ],
    'all',
    'pending'
);
assertSameValue(1, count($pendingSearch), 'search finds a call status label');
assertSameValue('Abel Demssie', $pendingSearch[0]['name'] ?? '', 'search keeps the pending donor');

$emptyActivity = CampaignPayingReport::present(['name' => 'No Link']);
assertSameValue(false, $emptyActivity['sent'], 'empty activity is not sent');
assertSameValue('No paying link', $emptyActivity['step_label'], 'empty activity has no page');
assertSameValue([], $emptyActivity['timeline'], 'empty activity has no timeline');
assertSameValue('Not answered', $emptyActivity['answer_label'], 'empty activity has no answer');
assertSameValue('', $emptyActivity['call_status'], 'empty activity has no call status');

$fromJsonArray = CampaignPayingReport::classify([
    'answers_json' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'whatsapp',
    ],
]);
assertSameValue(true, $fromJsonArray['answered'], 'array answers_json still counts as answered');
assertSameValue(true, $fromJsonArray['booked'], 'array answers_json still counts as booked');

$fromDoubleJson = CampaignPayingReport::classify([
    'answers_json' => json_encode(json_encode(['status_correct' => 'yes'], JSON_UNESCAPED_UNICODE), JSON_UNESCAPED_UNICODE),
]);
assertSameValue(true, $fromDoubleJson['answered'], 'double-encoded answers_json still counts as answered');

$msTime = CampaignPayingReport::classify([
    'answers' => [
        'status_correct' => 'yes',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30:00.000',
        'contact_method' => 'phone',
    ],
]);
assertSameValue(true, $msTime['booked'], 'a time with milliseconds still counts as booked');
assertSameValue('14:30', $msTime['contact_time'], 'stores millisecond times as HH:MM');

$doneOnly = CampaignPayingReport::classify(['step' => 'done']);
assertSameValue(true, $doneOnly['answered'], 'thank-you step counts as answered when answers are missing');
assertSameValue('yes', $doneOnly['answer'], 'thank-you step implies a yes on status');
assertSameValue(true, $doneOnly['booked'], 'thank-you step counts as booked when answers are missing');

$phoneOnly = CampaignPayingReport::classify(['step' => 'phone']);
assertSameValue(true, $phoneOnly['booked'], 'phone-check step counts as booked');

$contactOnly = CampaignPayingReport::classify(['step' => 'contact']);
assertSameValue(true, $contactOnly['answered'], 'contact step counts as answered');
assertSameValue(false, $contactOnly['booked'], 'contact step alone is not booked');

fwrite(STDOUT, "PASS campaign paying report tests\n");
