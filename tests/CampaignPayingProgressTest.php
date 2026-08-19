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
assertSameValue('correction', CampaignPayingProgress::sanitizeStep('correction'), 'allows the after-no paid step');
assertSameValue('pay_method', CampaignPayingProgress::sanitizeStep('pay_method'), 'allows the how-they-paid step');
assertSameValue('cash_detail', CampaignPayingProgress::sanitizeStep('cash_detail'), 'allows the cash when-and-whom step');
assertSameValue('bank_proof', CampaignPayingProgress::sanitizeStep('bank_proof'), 'allows the bank screenshot step');
assertSameValue('bank_date', CampaignPayingProgress::sanitizeStep('bank_date'), 'allows the bank paid-date step');
assertSameValue('welcome', CampaignPayingProgress::sanitizeStep('../admin'), 'unknown steps fall back to welcome');
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('contact', ['status_correct' => 'yes']),
    'contact is allowed after a yes on status'
);
assertSameValue(
    'correction',
    CampaignPayingProgress::resolveStep('contact', ['status_correct' => 'no']),
    'no on status opens the paid-so-far step instead of contact'
);
assertSameValue(
    'correction',
    CampaignPayingProgress::resolveStep('correction', ['status_correct' => 'no']),
    'after-no stays on the paid-so-far step'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('correction', ['status_correct' => 'yes']),
    'a later yes leaves the after-no step'
);
assertSameValue(
    'correction',
    CampaignPayingProgress::resolveStep('pay_method', ['status_correct' => 'no']),
    'how-they-paid waits until a paid-so-far amount is entered'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::resolveStep('pay_method', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
    ]),
    'how-they-paid opens after a corrected amount'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('pay_method', ['status_correct' => 'yes']),
    'a later yes leaves the how-they-paid step'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('mixed_split', ['status_correct' => 'yes']),
    'a later yes leaves the mixed cash-and-bank step'
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
    '120.50',
    CampaignPayingProgress::sanitizeAnswers(['reported_paid' => '£120.50'])['reported_paid'] ?? null,
    'stores a paid-so-far amount as pounds'
);
assertSameValue(
    '0.00',
    CampaignPayingProgress::normalizeMoney('0'),
    'allows zero as paid so far'
);
assertSameValue(null, CampaignPayingProgress::normalizeMoney('-12'), 'rejects a negative paid amount');
assertSameValue(
    true,
    CampaignPayingProgress::isReportedPaidComplete(['reported_paid' => '80']),
    'a pounds amount completes the paid-so-far step'
);
assertSameValue(
    false,
    CampaignPayingProgress::isReportedPaidComplete(['reported_paid' => '']),
    'an empty paid-so-far amount is not complete'
);
assertSameValue(
    'cash',
    CampaignPayingProgress::sanitizeAnswers(['paid_method' => 'cash'])['paid_method'] ?? null,
    'stores cash as how they paid'
);
assertSameValue(
    'bank',
    CampaignPayingProgress::sanitizeAnswers(['paid_method' => 'Card'])['paid_method'] ?? null,
    'stores the old card choice as bank transfer'
);
assertSameValue(
    'bank',
    CampaignPayingProgress::sanitizeAnswers(['paid_method' => 'bank'])['paid_method'] ?? null,
    'stores bank transfer as how they paid'
);
assertSameValue(
    false,
    array_key_exists('paid_method', CampaignPayingProgress::sanitizeAnswers(['paid_method' => 'cheque'])),
    'rejects an unknown how-they-paid method'
);
assertSameValue(
    true,
    CampaignPayingProgress::isPaidMethodComplete(['paid_method' => 'cash']),
    'cash completes the how-they-paid step'
);
assertSameValue(
    false,
    CampaignPayingProgress::isPaidMethodComplete(['paid_method' => '']),
    'an empty how-they-paid method is not complete'
);
assertSameValue(
    true,
    CampaignPayingProgress::isPaidMethodComplete(['paid_method' => 'bank']),
    'bank transfer completes the how-they-paid step'
);
assertSameValue(
    true,
    CampaignPayingProgress::isPaidMethodComplete(['paid_method' => 'card']),
    'the old card choice still counts as a complete method'
);
assertSameValue(
    'bank',
    CampaignPayingProgress::normalizePaidMethod('card'),
    'normalizes the old card choice to bank'
);
assertSameValue(
    'mixed',
    CampaignPayingProgress::sanitizeAnswers(['paid_method' => 'mixed'])['paid_method'] ?? null,
    'stores mixed as how they paid'
);
assertSameValue(
    true,
    CampaignPayingProgress::isPaidMethodComplete(['paid_method' => 'mixed']),
    'mixed completes the how-they-paid step'
);
assertSameValue(
    '20.00',
    CampaignPayingProgress::sanitizeAnswers(['mixed_cash' => '£20'])['mixed_cash'] ?? null,
    'stores the mixed cash amount'
);
assertSameValue(
    '60.50',
    CampaignPayingProgress::sanitizeAnswers(['mixed_bank' => '60.5'])['mixed_bank'] ?? null,
    'stores the mixed bank-transfer amount'
);
assertSameValue(
    true,
    CampaignPayingProgress::isMixedSplitComplete([
        'paid_method' => 'mixed',
        'mixed_cash' => '20.00',
        'mixed_bank' => '60.00',
    ]),
    'cash and bank amounts complete the mixed split'
);
assertSameValue(
    false,
    CampaignPayingProgress::isMixedSplitComplete([
        'paid_method' => 'mixed',
        'mixed_cash' => '80.00',
        'mixed_bank' => '0',
    ]),
    'mixed needs an amount in both cash and bank transfer'
);
assertSameValue(
    'mixed_split',
    CampaignPayingProgress::sanitizeStep('mixed_split'),
    'allows the mixed cash-and-bank step'
);
assertSameValue(
    'mixed_split',
    CampaignPayingProgress::resolveStep('mixed_split', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'mixed',
    ]),
    'mixed opens the cash-and-bank amounts step'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'mixed',
        'mixed_cash' => '20.00',
        'mixed_bank' => '60.00',
    ]),
    'mixed amounts ask for a photo before thank-you'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'mixed',
        'mixed_cash' => '20.00',
        'mixed_bank' => '60.00',
        'send_proof' => 'no',
    ]),
    'mixed can skip the photo and finish on thank-you'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::previousStep('mixed_split', [
        'status_correct' => 'no',
        'paid_method' => 'mixed',
    ]),
    'mixed amounts back goes to how they paid'
);
assertSameValue(
    'mixed_split',
    CampaignPayingProgress::previousStep('bank_proof', [
        'status_correct' => 'no',
        'paid_method' => 'mixed',
        'mixed_cash' => '20.00',
        'mixed_bank' => '60.00',
    ]),
    'mixed photo step back goes to the split amounts'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::previousStep('done', [
        'status_correct' => 'no',
        'paid_method' => 'mixed',
        'mixed_cash' => '20.00',
        'mixed_bank' => '60.00',
        'send_proof' => 'no',
    ]),
    'mixed thank-you back goes to the photo step after they skip it'
);
assertSameValue(
    '2026-03-01',
    CampaignPayingProgress::sanitizeAnswers(['cash_when' => '2026-03-01'])['cash_when'] ?? null,
    'stores the cash paid-when date'
);
assertSameValue(
    'Abeba',
    CampaignPayingProgress::sanitizeAnswers(['cash_whom' => 'Abeba'])['cash_whom'] ?? null,
    'stores who they paid in cash'
);
assertSameValue(
    'no',
    CampaignPayingProgress::sanitizeAnswers(['cash_remember' => 'no'])['cash_remember'] ?? null,
    'stores that they do not remember the cash details'
);
assertSameValue(
    'yes',
    CampaignPayingProgress::sanitizeAnswers(['send_proof' => 'Yes'])['send_proof'] ?? null,
    'stores whether they can send a bank screenshot'
);
assertSameValue(
    '2026-03-01',
    CampaignPayingProgress::sanitizeAnswers(['paid_date' => '2026-03-01'])['paid_date'] ?? null,
    'stores the bank paid date'
);
assertSameValue(
    'no',
    CampaignPayingProgress::sanitizeAnswers(['paid_remember' => 'NO'])['paid_remember'] ?? null,
    'stores that they do not remember the bank paid date'
);
$proofPath = 'uploads/paying_proofs/a1b2c3d4e5f67890_0123456789abcdef0123456789abcdef.jpg';
assertSameValue(
    $proofPath,
    CampaignPayingProgress::sanitizeAnswers(['proof_file' => $proofPath])['proof_file'] ?? null,
    'stores a paying-proof path'
);
assertSameValue(
    false,
    array_key_exists('proof_file', CampaignPayingProgress::sanitizeAnswers([
        'proof_file' => '../uploads/paying_proofs/secret.jpg',
    ])),
    'rejects a proof path outside the paying-proofs folder'
);
assertSameValue(
    true,
    CampaignPayingProgress::isCashDetailComplete([
        'paid_method' => 'cash',
        'cash_remember' => 'no',
    ]),
    'I do not remember completes the cash follow-up'
);
assertSameValue(
    true,
    CampaignPayingProgress::isCashDetailComplete([
        'paid_method' => 'cash',
        'cash_when' => '2026-03-01',
        'cash_whom' => 'Abeba',
    ]),
    'when and whom complete the cash follow-up'
);
assertSameValue(
    false,
    CampaignPayingProgress::isCashDetailComplete(['paid_method' => 'cash']),
    'cash without a memory answer is not complete'
);
assertSameValue(
    true,
    CampaignPayingProgress::needsCallback([
        'status_correct' => 'no',
        'paid_method' => 'bank',
        'send_proof' => 'no',
        'paid_remember' => 'no',
    ]),
    'bank, no screenshot, and I do not remember needs a callback booking'
);
assertSameValue(
    false,
    CampaignPayingProgress::needsCallback([
        'status_correct' => 'no',
        'paid_method' => 'cash',
        'cash_remember' => 'no',
    ]),
    'cash I do not remember does not open the callback booking'
);
assertSameValue(
    'cash_detail',
    CampaignPayingProgress::resolveStep('cash_detail', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'cash',
    ]),
    'cash opens the when-and-whom step'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'cash',
        'cash_remember' => 'yes',
        'cash_when' => '2026-03-01',
        'cash_whom' => 'Abeba',
    ]),
    'cash details ask for a photo before thank-you'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'cash',
        'cash_remember' => 'yes',
        'cash_when' => '2026-03-01',
        'cash_whom' => 'Abeba',
        'send_proof' => 'no',
    ]),
    'cash can skip the photo and finish on thank-you'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'cash',
        'cash_remember' => 'no',
        'send_proof' => 'yes',
        'proof_file' => $proofPath,
    ]),
    'cash with a photo can finish on thank-you'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep('bank_proof', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
    ]),
    'bank transfer opens the screenshot step'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'yes',
        'proof_file' => $proofPath,
    ]),
    'a bank screenshot can finish on thank-you'
);
assertSameValue(
    'bank_date',
    CampaignPayingProgress::resolveStep('bank_date', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'no',
    ]),
    'no screenshot opens the bank paid-date step'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'no',
        'paid_date' => '2026-03-01',
    ]),
    'a remembered bank date can finish on thank-you'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('contact', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'no',
        'paid_remember' => 'no',
    ]),
    'I do not remember the bank date opens the callback booking'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('phone', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'no',
        'paid_remember' => 'no',
    ]),
    'callback phone check waits for a booked slot'
);
assertSameValue(
    'done',
    CampaignPayingProgress::resolveStep('done', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'no',
        'paid_remember' => 'no',
        'contact_date' => '2026-08-20',
        'contact_time' => '14:30',
        'contact_method' => 'phone',
        'phone_correct' => 'yes',
        'contact_phone' => '07360436171',
    ]),
    'callback thank-you is allowed after the same booking and phone check'
);

$noAmount = ['status_correct' => 'no'];
assertSameValue(
    'status',
    CampaignPayingProgress::resolveStep('status', $noAmount),
    'back from paid-so-far can return to the status check before an amount is typed'
);
assertSameValue(
    'welcome',
    CampaignPayingProgress::resolveStep('welcome', $noAmount),
    'back from status can return to welcome before an amount is typed'
);
assertSameValue(
    'status',
    CampaignPayingProgress::previousStep('correction', $noAmount),
    'paid-so-far back goes to the status check'
);

$cashDone = [
    'status_correct' => 'no',
    'reported_paid' => '80.00',
    'paid_method' => 'cash',
    'cash_remember' => 'yes',
    'cash_when' => '2026-03-01',
    'send_proof' => 'no',
];
assertSameValue(
    'cash_detail',
    CampaignPayingProgress::resolveStep('cash_detail', $cashDone),
    'back from cash thank-you can return to cash details'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep('bank_proof', $cashDone),
    'back from cash thank-you can return to the photo question'
);
assertSameValue(
    'cash_detail',
    CampaignPayingProgress::previousStep('bank_proof', $cashDone),
    'cash photo step back goes to cash details'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::resolveStep('pay_method', $cashDone),
    'back from cash details can return to how they paid'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::previousStep('cash_detail', $cashDone),
    'cash details back goes to how they paid'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep(
        CampaignPayingProgress::previousStep('done', $cashDone),
        $cashDone
    ),
    'cash thank-you back lands on a step that is allowed to stay'
);

$noScreenshot = [
    'status_correct' => 'no',
    'reported_paid' => '80.00',
    'paid_method' => 'bank',
    'send_proof' => 'no',
];
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep('bank_proof', $noScreenshot),
    'back from the paid-date step can return to the screenshot question'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::previousStep('bank_date', $noScreenshot),
    'paid-date back goes to the screenshot question'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep(
        CampaignPayingProgress::previousStep('bank_date', $noScreenshot),
        $noScreenshot
    ),
    'paid-date back is allowed to stay on the screenshot question'
);

$callback = [
    'status_correct' => 'no',
    'reported_paid' => '80.00',
    'paid_method' => 'bank',
    'send_proof' => 'no',
    'paid_remember' => 'no',
];
assertSameValue(
    'bank_date',
    CampaignPayingProgress::resolveStep('bank_date', $callback),
    'back from a callback booking can return to the paid-date step'
);
assertSameValue(
    'bank_proof',
    CampaignPayingProgress::resolveStep('bank_proof', $callback),
    'back from a callback booking can return to the screenshot question'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::resolveStep('pay_method', $callback),
    'back from a callback booking can return to how they paid'
);
assertSameValue(
    'correction',
    CampaignPayingProgress::resolveStep('correction', $callback),
    'back from a callback booking can return to the paid-so-far amount'
);
assertSameValue(
    'bank_date',
    CampaignPayingProgress::previousStep('contact', $callback),
    'callback booking back goes to the paid-date step'
);
assertSameValue(
    'bank_date',
    CampaignPayingProgress::resolveStep(
        CampaignPayingProgress::previousStep('contact', $callback),
        $callback
    ),
    'callback booking back is allowed to stay on the paid-date step'
);
assertSameValue(
    'bank_date',
    CampaignPayingProgress::preferStep('bank_date', 'contact', $callback),
    'an explicit back to the paid-date step is stored'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::preferStep('pay_method', 'cash_detail', $cashDone),
    'an explicit back to how they paid is stored'
);

$yesDone = [
    'status_correct' => 'yes',
    'contact_date' => '2026-08-20',
    'contact_time' => '14:30',
    'contact_method' => 'phone',
    'phone_correct' => 'yes',
    'contact_phone' => '07360436171',
];
assertSameValue(
    'phone',
    CampaignPayingProgress::resolveStep('phone', $yesDone),
    'back from yes thank-you can return to the phone check'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::resolveStep('contact', $yesDone),
    'back from the phone check can return to the booking'
);
assertSameValue(
    'status',
    CampaignPayingProgress::resolveStep('status', $yesDone),
    'back from the booking can return to the status check'
);
assertSameValue(
    'phone',
    CampaignPayingProgress::resolveStep(
        CampaignPayingProgress::previousStep('done', $yesDone),
        $yesDone
    ),
    'yes thank-you back lands on a step that is allowed to stay'
);
assertSameValue(
    'jpg',
    CampaignPayingProgress::proofExtensionForMime('image/jpeg'),
    'accepts a jpeg screenshot'
);
assertSameValue(
    null,
    CampaignPayingProgress::proofExtensionForMime('application/pdf'),
    'rejects a non-image screenshot'
);
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
    'correction',
    CampaignPayingProgress::preferStep('welcome', 'correction', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
    ]),
    'an empty page-open cannot rewind the after-no paid step'
);
assertSameValue(
    'pay_method',
    CampaignPayingProgress::preferStep('welcome', 'pay_method', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'cash',
    ]),
    'an empty page-open cannot rewind the how-they-paid step'
);
assertSameValue(
    'cash_detail',
    CampaignPayingProgress::preferStep('welcome', 'cash_detail', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'cash',
    ]),
    'an empty page-open cannot rewind the cash follow-up'
);
assertSameValue(
    'mixed_split',
    CampaignPayingProgress::preferStep('welcome', 'mixed_split', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'mixed',
        'mixed_cash' => '20.00',
        'mixed_bank' => '60.00',
    ]),
    'an empty page-open cannot rewind the mixed split'
);
assertSameValue(
    'contact',
    CampaignPayingProgress::preferStep('welcome', 'contact', [
        'status_correct' => 'no',
        'reported_paid' => '80.00',
        'paid_method' => 'bank',
        'send_proof' => 'no',
        'paid_remember' => 'no',
    ]),
    'an empty page-open cannot rewind a callback booking'
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

$leftBrowser = CampaignPayingProgress::applyDraft(
    [
        'step' => 'correction',
        'answers' => ['status_correct' => 'no'],
        'revision' => 2,
    ],
    [
        'step' => 'pay_method',
        'answers' => ['status_correct' => 'no', 'reported_paid' => '80.00'],
        'revision' => 2,
    ]
);
assertSameValue('80.00', $leftBrowser['answers']['reported_paid'] ?? null, 'a draft typed before leaving the browser is kept');
assertSameValue('pay_method', $leftBrowser['step'], 'a same-revision draft can keep the page they were on');

$olderDraft = CampaignPayingProgress::applyDraft(
    [
        'step' => 'done',
        'answers' => [
            'status_correct' => 'yes',
            'contact_date' => '2026-08-20',
            'contact_time' => '14:30',
            'contact_method' => 'phone',
            'phone_correct' => 'yes',
        ],
        'revision' => 8,
    ],
    [
        'step' => 'status',
        'answers' => ['status_correct' => 'yes', 'reported_paid' => '10.00'],
        'revision' => 3,
    ]
);
assertSameValue('done', $olderDraft['step'], 'an older phone draft cannot rewind a newer server thank-you');
assertSameValue('yes', $olderDraft['answers']['status_correct'] ?? null, 'the newer yes answer is kept');
assertSameValue('10.00', $olderDraft['answers']['reported_paid'] ?? null, 'an extra draft field is still merged');
assertSameValue('2026-08-20', $olderDraft['answers']['contact_date'] ?? null, 'the server booking is not wiped by an older draft');

$emptyLeave = CampaignPayingProgress::applyDraft(
    [
        'step' => 'contact',
        'answers' => ['status_correct' => 'yes', 'contact_date' => '2026-08-20'],
        'revision' => 4,
    ],
    [
        'step' => 'contact',
        'answers' => [],
        'revision' => 4,
    ]
);
assertSameValue('yes', $emptyLeave['answers']['status_correct'] ?? null, 'an empty leave-flush does not wipe answers');
assertSameValue('2026-08-20', $emptyLeave['answers']['contact_date'] ?? null, 'an empty leave-flush keeps the booking date');

$welcomeOpen = CampaignPayingProgress::applyDraft(
    [
        'step' => 'cash_detail',
        'answers' => ['status_correct' => 'no', 'reported_paid' => '80.00', 'paid_method' => 'cash'],
        'revision' => 5,
    ],
    [
        'step' => 'welcome',
        'answers' => ['status_correct' => 'no'],
        'revision' => 5,
    ]
);
assertSameValue('cash_detail', $welcomeOpen['step'], 'a welcome draft cannot rewind cash details');

$explicitBack = CampaignPayingProgress::applyDraft(
    [
        'step' => 'contact',
        'answers' => [
            'status_correct' => 'no',
            'reported_paid' => '80.00',
            'paid_method' => 'bank',
            'send_proof' => 'no',
            'paid_remember' => 'no',
        ],
        'revision' => 6,
    ],
    [
        'step' => 'bank_date',
        'answers' => [
            'status_correct' => 'no',
            'reported_paid' => '80.00',
            'paid_method' => 'bank',
            'send_proof' => 'no',
            'paid_remember' => 'no',
        ],
        'revision' => 6,
    ]
);
assertSameValue('bank_date', $explicitBack['step'], 'a leave after Back keeps the earlier page');

$staleDraft = CampaignPayingProgress::applyDraft(
    [
        'step' => 'status',
        'answers' => ['status_correct' => 'yes'],
        'revision' => 1,
    ],
    [
        'step' => 'contact',
        'answers' => ['status_correct' => 'yes', 'contact_date' => '2026-08-20'],
        'revision' => 1,
        'saved_at' => time() - (31 * 24 * 60 * 60),
    ]
);
assertSameValue('status', $staleDraft['step'], 'a draft older than 30 days is ignored');
assertSameValue(false, isset($staleDraft['answers']['contact_date']), 'a stale draft does not add old answers');

$sign = CampaignPayingProgress::sign('a1b2c3d4e5f67890');
assertSameValue(64, strlen($sign), 'sync signatures are 64 hex characters');
assertSameValue(true, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', $sign), 'accepts a valid sync signature');
assertSameValue(false, CampaignPayingProgress::verifySign('a1b2c3d4e5f67890', str_repeat('0', 64)), 'rejects a bad sync signature');

fwrite(STDOUT, "PASS campaign paying progress tests\n");
