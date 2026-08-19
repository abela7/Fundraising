<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/CampaignPayingProgress.php';
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

/**
 * Same pipeline as the donor page: step saves, a blank reopen, JSON storage, then admin view.
 *
 * @param list<array<string, mixed>> $saves
 * @return array<string, mixed>
 */
function persistPayingAnswers(array $saves): array
{
    $stored = [];
    foreach ($saves as $incoming) {
        $stored = CampaignPayingProgress::mergeAnswers($stored, $incoming);
    }
    $stored = CampaignPayingProgress::mergeAnswers($stored, []);
    $json = json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
    if (!is_string($json)) {
        fwrite(STDERR, "FAIL: could not encode stored answers\n");
        exit(1);
    }
    $clientJson = json_encode(
        CampaignPayingProgress::answersForClient($stored),
        JSON_UNESCAPED_UNICODE
    );
    $fromClient = json_decode((string) $clientJson, true);
    $stored = CampaignPayingProgress::mergeAnswers(
        CampaignPayingProgress::decodeAnswersJson($json),
        is_array($fromClient) ? $fromClient : []
    );

    return CampaignPayingProgress::decodeAnswersJson(
        json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)
    );
}

/**
 * @param array<string, mixed> $answers
 * @return array<string, mixed>
 */
function adminView(array $answers, string $step, string $phone = '07360436171'): array
{
    return CampaignPayingReport::present([
        'id' => 99,
        'name' => 'Test Donor',
        'phone' => $phone,
        'reference' => 'A-Z',
        'total_pledged' => 400,
        'total_paid' => 19.55,
        'balance' => 380.45,
        'token' => 'a1b2c3d4e5f67890',
        'step' => $step,
        'revision' => 8,
        'last_sent_at' => '2026-08-16 19:50:00',
        'opened_at' => '2026-08-16 20:00:00',
        'progress_updated_at' => '2026-08-16 20:12:00',
        'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
    ]);
}

$proof = 'uploads/paying_proofs/a1b2c3d4e5f67890_0123456789abcdef0123456789abcdef.jpg';

$yesStored = persistPayingAnswers([
    ['status_correct' => 'yes'],
    ['contact_date' => '2026-08-20', 'contact_time' => '14:30:00.000', 'contact_method' => 'whatsapp'],
    ['phone_correct' => 'yes', 'contact_phone' => '07360436171'],
]);
$yesStoredAdmin = adminView($yesStored, 'done');
assertSameValue('yes', $yesStored['status_correct'] ?? null, 'A yes answer is stored');
assertSameValue('2026-08-20', $yesStored['contact_date'] ?? null, 'A yes booking date is stored');
assertSameValue('14:30', $yesStored['contact_time'] ?? null, 'A yes booking time is stored as HH:MM');
assertSameValue('whatsapp', $yesStored['contact_method'] ?? null, 'A yes WhatsApp method is stored');
assertSameValue('yes', $yesStored['phone_correct'] ?? null, 'A stored-phone confirmation is kept');
assertSameValue('07360436171', $yesStored['contact_phone'] ?? null, 'The stored UK phone is kept');
assertSameValue('Yes', $yesStoredAdmin['answer_label'], 'Admin sees Yes');
assertSameValue(true, $yesStoredAdmin['booked'], 'Admin sees the yes booking');
assertSameValue('20 Aug 2026, 2:30 PM · WhatsApp', $yesStoredAdmin['booking_label'], 'Admin sees the WhatsApp slot');
assertSameValue('07360436171', $yesStoredAdmin['call_phone'], 'Admin sees the stored number to call');
assertSameValue('Stored number', $yesStoredAdmin['phone_correct_label'], 'Admin sees stored-number confirmation');
assertSameValue(false, $yesStoredAdmin['phone_corrected'], 'A confirmed stored number is not a correction');

$yesNewPhone = persistPayingAnswers([
    ['status_correct' => 'yes'],
    ['contact_date' => '2026-08-21', 'contact_time' => '09:05', 'contact_method' => 'phone'],
    ['phone_correct' => 'no', 'contact_phone' => '+447911111111'],
]);
$yesNewAdmin = adminView($yesNewPhone, 'done');
assertSameValue('07911111111', $yesNewPhone['contact_phone'] ?? null, 'A new +44 number is stored as 07');
assertSameValue('Yes', $yesNewAdmin['answer_label'], 'Admin still sees Yes after a new number');
assertSameValue('21 Aug 2026, 9:05 AM · Phone', $yesNewAdmin['booking_label'], 'Admin sees the phone slot');
assertSameValue('07911111111', $yesNewAdmin['call_phone'], 'Admin sees the corrected number to call');
assertSameValue('New number', $yesNewAdmin['phone_correct_label'], 'Admin labels the replacement number');
assertSameValue(true, $yesNewAdmin['phone_corrected'], 'Admin flags the replacement number');
assertSameValue(true, CampaignPayingReport::matchesFilter($yesNewAdmin, 'booked'), 'Yes + new number stays on Booked');

$cashRemembered = persistPayingAnswers([
    ['status_correct' => 'no'],
    ['reported_paid' => '£0'],
    ['paid_method' => 'cash'],
    ['cash_when' => '2026-03-01', 'cash_whom' => 'አበባ ተስፋይ', 'cash_remember' => 'yes'],
]);
$cashAdmin = adminView($cashRemembered, 'done');
assertSameValue('no', $cashRemembered['status_correct'] ?? null, 'A no answer is stored');
assertSameValue('0.00', $cashRemembered['reported_paid'] ?? null, 'Zero paid so far is stored, not dropped');
assertSameValue('cash', $cashRemembered['paid_method'] ?? null, 'Cash is stored');
assertSameValue('2026-03-01', $cashRemembered['cash_when'] ?? null, 'Cash when is stored');
assertSameValue('አበባ ተስፋይ', $cashRemembered['cash_whom'] ?? null, 'Amharic cash-whom is stored');
assertSameValue('yes', $cashRemembered['cash_remember'] ?? null, 'Cash remembered is stored');
assertSameValue('No', $cashAdmin['answer_label'], 'Admin sees No');
assertSameValue('£0.00', $cashAdmin['reported_paid_label'], 'Admin sees £0.00 paid so far');
assertSameValue('Cash', $cashAdmin['paid_method_label'], 'Admin sees Cash');
assertSameValue('1 Mar 2026', $cashAdmin['cash_when_label'], 'Admin sees when they paid cash');
assertSameValue('አበባ ተስፋይ', $cashAdmin['cash_whom'], 'Admin sees who they paid');
assertSameValue('Remembered', $cashAdmin['cash_remember_label'], 'Admin sees cash remembered');
assertSameValue(false, $cashAdmin['booked'], 'Cash thank-you is not a call booking');
assertSameValue(false, CampaignPayingReport::matchesFilter($cashAdmin, 'booked'), 'Cash thank-you stays off Booked');

$cashForgot = persistPayingAnswers([
    ['status_correct' => 'no'],
    ['reported_paid' => '40.5'],
    ['paid_method' => 'cash'],
    ['cash_remember' => 'no'],
]);
$cashForgotAdmin = adminView($cashForgot, 'done');
assertSameValue('40.50', $cashForgot['reported_paid'] ?? null, 'A one-decimal cash amount is stored as 40.50');
assertSameValue('no', $cashForgot['cash_remember'] ?? null, 'Cash I-do-not-remember is stored');
assertSameValue('£40.50', $cashForgotAdmin['reported_paid_label'], 'Admin sees £40.50');
assertSameValue('I do not remember', $cashForgotAdmin['cash_remember_label'], 'Admin sees cash I-do-not-remember');
assertSameValue(false, $cashForgotAdmin['booked'], 'Cash I-do-not-remember is not a callback booking');

$cashProof = persistPayingAnswers([
    ['status_correct' => 'no'],
    ['reported_paid' => '25'],
    ['paid_method' => 'cash'],
    ['cash_remember' => 'no'],
    ['send_proof' => 'yes', 'proof_file' => $proof],
]);
$cashProofAdmin = adminView($cashProof, 'done');
assertSameValue('yes', $cashProof['send_proof'] ?? null, 'Cash screenshot yes is stored');
assertSameValue($proof, $cashProof['proof_file'] ?? null, 'Cash screenshot path is stored');
assertSameValue('Yes', $cashProofAdmin['send_proof_label'], 'Admin sees cash screenshot yes');
assertSameValue(true, $cashProofAdmin['has_proof'], 'Admin flags a cash receipt photo');
assertSameValue($proof, $cashProofAdmin['proof_file'], 'Admin keeps the cash receipt path');
assertSameValue(false, $cashProofAdmin['booked'], 'Cash with a photo is not a call booking');

$bankProof = persistPayingAnswers([
    ['status_correct' => 'no'],
    ['reported_paid' => '80'],
    ['paid_method' => 'card'],
    ['send_proof' => 'yes', 'proof_file' => $proof],
]);
$bankProofAdmin = adminView($bankProof, 'done');
assertSameValue('80.00', $bankProof['reported_paid'] ?? null, 'A whole-pound bank amount is stored as 80.00');
assertSameValue('bank', $bankProof['paid_method'] ?? null, 'Old card is stored as bank');
assertSameValue('yes', $bankProof['send_proof'] ?? null, 'Screenshot yes is stored');
assertSameValue($proof, $bankProof['proof_file'] ?? null, 'Screenshot path is stored');
assertSameValue('Bank transfer', $bankProofAdmin['paid_method_label'], 'Admin sees Bank transfer');
assertSameValue('Yes', $bankProofAdmin['send_proof_label'], 'Admin sees screenshot yes');
assertSameValue(true, $bankProofAdmin['has_proof'], 'Admin flags the attached screenshot');
assertSameValue($proof, $bankProofAdmin['proof_file'], 'Admin keeps the screenshot path');
assertSameValue(false, $bankProofAdmin['booked'], 'A screenshot finish is not a call booking');

$bankDate = persistPayingAnswers([
    ['status_correct' => 'no'],
    ['reported_paid' => '80.00'],
    ['paid_method' => 'bank'],
    ['send_proof' => 'no'],
    ['paid_date' => '2026-03-01'],
]);
$bankDateAdmin = adminView($bankDate, 'done');
assertSameValue('no', $bankDate['send_proof'] ?? null, 'Screenshot no is stored');
assertSameValue('2026-03-01', $bankDate['paid_date'] ?? null, 'Bank paid date is stored');
assertSameValue('No', $bankDateAdmin['send_proof_label'], 'Admin sees screenshot no');
assertSameValue('1 Mar 2026', $bankDateAdmin['paid_date_label'], 'Admin sees the bank paid date');
assertSameValue(false, $bankDateAdmin['booked'], 'A remembered bank date is not a call booking');

$callback = persistPayingAnswers([
    ['status_correct' => 'no'],
    ['reported_paid' => '80.00'],
    ['paid_method' => 'bank'],
    ['send_proof' => 'no'],
    ['paid_remember' => 'no'],
    ['contact_date' => '2026-08-22', 'contact_time' => '16:00', 'contact_method' => 'phone'],
    ['phone_correct' => 'no', 'contact_phone' => '07900000000'],
]);
$callbackAdmin = adminView($callback, 'done');
assertSameValue('no', $callback['paid_remember'] ?? null, 'Bank I-do-not-remember is stored');
assertSameValue('2026-08-22', $callback['contact_date'] ?? null, 'Callback date is stored');
assertSameValue('16:00', $callback['contact_time'] ?? null, 'Callback time is stored');
assertSameValue('phone', $callback['contact_method'] ?? null, 'Callback phone method is stored');
assertSameValue('07900000000', $callback['contact_phone'] ?? null, 'Callback new number is stored');
assertSameValue('No', $callbackAdmin['answer_label'], 'Admin sees No on a callback');
assertSameValue('£80.00', $callbackAdmin['reported_paid_label'], 'Admin still sees paid so far on a callback');
assertSameValue('Bank transfer', $callbackAdmin['paid_method_label'], 'Admin still sees bank transfer on a callback');
assertSameValue('No', $callbackAdmin['send_proof_label'], 'Admin still sees screenshot no on a callback');
assertSameValue('I do not remember', $callbackAdmin['paid_remember_label'], 'Admin sees they do not remember the date');
assertSameValue(true, $callbackAdmin['booked'], 'Admin sees the callback booking');
assertSameValue('22 Aug 2026, 4:00 PM · Phone', $callbackAdmin['booking_label'], 'Admin sees the callback slot');
assertSameValue('07900000000', $callbackAdmin['call_phone'], 'Admin sees the callback number');
assertSameValue('New number', $callbackAdmin['phone_correct_label'], 'Admin labels the callback replacement number');
assertSameValue(true, CampaignPayingReport::matchesFilter($callbackAdmin, 'booked'), 'Callback booking stays on Booked');
assertSameValue(true, CampaignPayingReport::matchesFilter($callbackAdmin, 'pending'), 'A new callback booking starts as Pending');

$staleBooking = persistPayingAnswers([
    ['status_correct' => 'yes'],
    ['contact_date' => '2026-08-20', 'contact_time' => '14:30', 'contact_method' => 'whatsapp'],
    ['status_correct' => 'no'],
    ['reported_paid' => '80.00'],
    ['paid_method' => 'cash'],
    ['cash_remember' => 'yes', 'cash_when' => '2026-03-01', 'cash_whom' => 'Abeba'],
]);
$staleAdmin = adminView($staleBooking, 'done');
assertSameValue('no', $staleBooking['status_correct'] ?? null, 'A later No replaces Yes');
assertSameValue('cash', $staleBooking['paid_method'] ?? null, 'The later cash method is stored');
assertSameValue('Abeba', $staleAdmin['cash_whom'], 'Admin still sees the later cash answers');
assertSameValue(false, $staleAdmin['booked'], 'A leftover yes booking is not shown after they finish cash');
assertSameValue(false, CampaignPayingReport::matchesFilter($staleAdmin, 'booked'), 'Leftover yes booking stays off Booked');

$searchCash = CampaignPayingReport::filterRows(
    [
        [
            'name' => 'Abeba',
            'cash_whom' => 'አበባ ተስፋይ',
            'booked' => false,
        ],
        [
            'name' => 'Other',
            'cash_whom' => 'Someone else',
            'booked' => false,
        ],
    ],
    'all',
    'አበባ'
);
assertSameValue(1, count($searchCash), 'Admin search finds the cash paid-to name');
assertSameValue('Abeba', $searchCash[0]['name'] ?? '', 'Admin search keeps the matching cash donor');

$searchPhone = CampaignPayingReport::filterRows(
    [
        [
            'name' => 'Abel',
            'call_phone' => '07900000000',
            'contact_phone' => '07900000000',
            'booked' => true,
        ],
        [
            'name' => 'Other',
            'call_phone' => '07360436171',
            'booked' => true,
        ],
    ],
    'all',
    '07900000000'
);
assertSameValue(1, count($searchPhone), 'Admin search finds a callback replacement number');

fwrite(STDOUT, "PASS campaign paying answers admin tests\n");
