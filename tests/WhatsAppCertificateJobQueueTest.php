<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/WhatsAppCertificateJobQueue.php';

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

assertSameValue(
    60,
    WhatsAppCertificateJobQueue::retryDelaySeconds(1),
    'first failed attempt retries after one minute'
);
assertSameValue(
    300,
    WhatsAppCertificateJobQueue::retryDelaySeconds(2),
    'second failed attempt retries after five minutes'
);
assertSameValue(
    900,
    WhatsAppCertificateJobQueue::retryDelaySeconds(3),
    'later failed attempts use the maximum delay'
);

assertSameValue(
    'pending',
    WhatsAppCertificateJobQueue::statusAfterFailure(1, 3),
    'a retryable failure returns to pending'
);
assertSameValue(
    'failed',
    WhatsAppCertificateJobQueue::statusAfterFailure(3, 3),
    'the final attempt permanently fails the job'
);
assertSameValue(
    '+447700900001',
    WhatsAppCertificateJobQueue::failurePhone([
        'failure_phone' => '+447700900001',
        'destination_phone' => '+447700900002',
    ]),
    'delivery failures are sent to the staff operator'
);
assertSameValue(
    '',
    WhatsAppCertificateJobQueue::failurePhone([
        'failure_phone' => '',
        'destination_phone' => '+447700900002',
    ]),
    'legacy jobs never send technical failures to their destination'
);

$donorDeliveryMessage = WhatsAppCertificateJobQueue::payDeliveryConfirmation([
    'recipient_type' => 'donor',
    'recipient_name' => 'Abel Demssie',
]);
assertSameValue(
    true,
    str_contains($donorDeliveryMessage, 'ለለጋሹ Abel Demssie'),
    'PAY delivery confirms the donor recipient in Amharic'
);

$agentDeliveryMessage = WhatsAppCertificateJobQueue::payDeliveryConfirmation([
    'recipient_type' => 'agent',
    'recipient_name' => 'Kesis Birhanu',
]);
assertSameValue(
    true,
    str_contains($agentDeliveryMessage, 'ለወኪሉ Kesis Birhanu'),
    'PAY delivery confirms the agent recipient in Amharic'
);
foreach ([$donorDeliveryMessage, $agentDeliveryMessage] as $deliveryMessage) {
    foreach (['queue', 'worker', 'Certificate', 'Donor:', 'Agent:'] as $forbidden) {
        assertSameValue(
            false,
            stripos($deliveryMessage, $forbidden) !== false,
            "PAY delivery confirmation hides technical wording: {$forbidden}"
        );
    }
}

$safeNameMessage = WhatsAppCertificateJobQueue::payDeliveryConfirmation([
    'recipient_type' => 'donor',
    'recipient_name' => "Abel\0\u{202E}\nDemssie",
]);
foreach (["\0", "\u{202E}", "\n", "\r"] as $unsafeCharacter) {
    assertSameValue(
        false,
        str_contains($safeNameMessage, $unsafeCharacter),
        'PAY delivery confirmation strips unsafe name controls'
    );
}

$sanitized = WhatsAppCertificateJobQueue::sanitizeFailure(
    'token=token-leak password=hunter2 client_secret=oauth-secret '
    . 'https://example.com/private /home/user/file Bearer abcdefghijklmnop'
);
assertSameValue(
    false,
    str_contains($sanitized, 'token-leak'),
    'failure details redact tokens'
);
assertSameValue(
    false,
    str_contains($sanitized, 'example.com'),
    'failure details redact URLs'
);
assertSameValue(
    false,
    str_contains($sanitized, '/home/user'),
    'failure details redact server paths'
);
assertSameValue(
    false,
    str_contains($sanitized, 'abcdefghijklmnop'),
    'failure details redact bearer credentials'
);
assertSameValue(
    false,
    str_contains($sanitized, 'hunter2'),
    'failure details redact passwords'
);
assertSameValue(
    false,
    str_contains($sanitized, 'oauth-secret'),
    'failure details redact client secrets'
);

echo "PASS: WhatsAppCertificateJobQueue policy tests\n";
