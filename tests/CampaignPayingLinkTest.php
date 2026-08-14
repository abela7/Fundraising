<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/CampaignPayingLink.php';

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

$token = 'a1b2c3d4e5f67890';

assertSameValue(
    'https://donate.abuneteklehaymanot.org/paying/' . $token,
    CampaignPayingLink::whatsappUrl($token),
    'WhatsApp link uses the public donate host'
);

assertSameValue(
    'https://donate.abuneteklehaymanot.org/paying/' . $token,
    CampaignPayingLink::publicUrl($token, 'donate.abuneteklehaymanot.org', '/webhooks/ultramsg.php'),
    'production host uses the public paying URL'
);

assertSameValue(
    'https://donate.abuneteklehaymanot.org/paying/' . $token,
    CampaignPayingLink::publicUrl($token, '', '/webhooks/ultramsg.php'),
    'empty host falls back to the public paying URL'
);

assertSameValue(
    'http://localhost/Fundraising/paying/' . $token,
    CampaignPayingLink::publicUrl($token, 'localhost', '/Fundraising/webhooks/ultramsg.php'),
    'localhost webhook builds a local paying URL'
);

assertSameValue(
    'http://127.0.0.1/Fundraising/paying/' . $token,
    CampaignPayingLink::publicUrl($token, '127.0.0.1:8080', '/Fundraising/paying/index.php'),
    'loopback host with a port still builds a local paying URL'
);

assertSameValue('£1,234.50', CampaignPayingLink::formatMoney(1234.5), 'formats GBP amounts');
assertSameValue(16, strlen($token), 'paying tokens are 16 hex characters');

fwrite(STDOUT, "PASS campaign paying link tests\n");
