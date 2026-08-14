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

require_once __DIR__ . '/../shared/CampaignGroupSettings.php';

$welcome = CampaignGroupSettings::defaultWelcomeMessage();
assertSameValue(
    true,
    str_contains($welcome, '{name}'),
    'default welcome includes the name variable'
);
assertSameValue(
    'የተከበሩ Abeba፣',
    explode("\n", CampaignGroupSettings::preview($welcome, ['name' => 'Abeba']))[0],
    'welcome preview replaces {name}'
);

$status = CampaignGroupSettings::defaultStatusMessage();
assertSameValue('ይህ መረጃ ትክክል ነው?', $status, 'default status footer asks if the amounts are right');
assertSameValue(false, str_contains($status, '{pledge_amount}'), 'footer does not repeat pledge amount');
assertSameValue(
    'ባለን መረጃ መሰረት',
    CampaignGroupSettings::defaultStatusTitle(),
    'default status title sits above the amounts'
);
assertSameValue(
    'የተከበሩ Abeba፣ ይህ መረጃ ትክክል ነው?',
    CampaignGroupSettings::preview(
        'የተከበሩ {name}፣ ይህ መረጃ ትክክል ነው?',
        ['name' => 'Abeba']
    ),
    'status footer preview still replaces {name}'
);
assertSameValue(
    'ይህ መረጃ ትክክል ነው?',
    CampaignGroupSettings::statusFooterText(''),
    'empty saved status uses the footer default'
);
assertSameValue(
    'ይህ መረጃ ትክክል ነው?',
    CampaignGroupSettings::statusFooterText(CampaignGroupSettings::legacyStatusMessage()),
    'old amount-repeating status text becomes the footer default'
);
$card = CampaignGroupSettings::statusCardCopy(
    "ባለን መረጃ መሰረት\nለመክፈል ቃል የገቡት የገንዘብ መጠን :- {pledge_amount}\nእስካሁን የከፈሉት:- {total_paid}\nቀሪ:- {remaining_amount}\nይህ መረጃ ትክክል ነው?"
);
assertSameValue('ባለን መረጃ መሰረት', $card['title'], 'heading line becomes the card title');
assertSameValue('ይህ መረጃ ትክክል ነው?', $card['footer'], 'question line stays as the footer');
assertSameValue(
    'ይህ መረጃ ትክክል ነው?',
    CampaignGroupSettings::statusFooterText(
        "ባለን መረጃ መሰረት\nለመክፈል ቃል የገቡት የገንዘብ መጠን :- {pledge_amount}\nእስካሁን የከፈሉት:- {total_paid}\nቀሪ:- {remaining_amount}\nይህ መረጃ ትክክል ነው?"
    ),
    'amount-repeating lines are dropped from a custom status body'
);
assertSameValue(
    'ቀሪው {remaining_amount} ትክክል ነው?',
    CampaignGroupSettings::statusFooterText('ቀሪው {remaining_amount} ትክክል ነው?'),
    'a footer that only mentions remaining is kept'
);
assertSameValue(
    'የእርስዎ መረጃ',
    CampaignGroupSettings::statusTitleText('የእርስዎ መረጃ'),
    'a saved card title is kept'
);
assertSameValue(
    'ባለን መረጃ መሰረት',
    CampaignGroupSettings::statusTitleText(''),
    'empty title uses the default heading'
);
$labels = CampaignGroupSettings::defaultStatusLabels();
assertSameValue('ጠቅላላ የገቡት ቃልኪዳን መጠን', $labels['pledge'], 'default pledge label');
assertSameValue('እስካሁን የከፈሉት', $labels['paid'], 'default paid label');
assertSameValue('ቀሪ', $labels['remain'], 'default remaining label');
$customLabels = CampaignGroupSettings::statusLabels(json_encode([
    'pledge' => 'ቃልኪዳን',
    'paid' => '',
    'remain' => 'የቀረው',
], JSON_UNESCAPED_UNICODE));
assertSameValue('ቃልኪዳን', $customLabels['pledge'], 'saved pledge label is kept');
assertSameValue('እስካሁን የከፈሉት', $customLabels['paid'], 'empty paid label uses the default');
assertSameValue('የቀረው', $customLabels['remain'], 'saved remaining label is kept');
assertSameValue(
    $labels,
    CampaignGroupSettings::statusLabels('not-json'),
    'invalid saved labels fall back to defaults'
);

fwrite(STDOUT, "PASS campaign paying link tests\n");
