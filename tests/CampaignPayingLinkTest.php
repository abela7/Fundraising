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
    'https://donate.abuneteklehaymanot.org/',
    CampaignPayingLink::SITE_HOME,
    'after booking, donors return to the public donate home'
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

$contactMessage = CampaignGroupSettings::defaultContactMessage();
assertSameValue(true, str_contains($contactMessage, '{name}'), 'contact message includes name');
assertSameValue(
    true,
    str_contains($contactMessage, '{remaining_amount}'),
    'contact message includes remaining amount'
);
assertSameValue(
    'እባክዎ የሚመችዎትን ቀን፣ ሰዓት እና የመገናኛ መንገድ ይምረጡ።',
    CampaignGroupSettings::defaultContactAsk(),
    'default contact ask asks for date, time, and method'
);
$contactLabels = CampaignGroupSettings::defaultContactLabels();
assertSameValue('ቀን', $contactLabels['date'], 'default date label');
assertSameValue('ሰዓት', $contactLabels['time'], 'default time label');
assertSameValue('የWhatsApp ጥሪ', $contactLabels['whatsapp'], 'default WhatsApp call label');
assertSameValue('የስልክ ጥሪ', $contactLabels['phone'], 'default phone call label');
assertSameValue(
    'የቤት ስልክ',
    CampaignGroupSettings::contactLabels(json_encode(['phone' => 'የቤት ስልክ'], JSON_UNESCAPED_UNICODE))['phone'],
    'saved phone label is kept'
);
assertSameValue(
    CampaignGroupSettings::defaultContactMessage(),
    CampaignGroupSettings::contactMessageText(''),
    'empty contact message uses the default'
);
assertSameValue(
    'እናመሰግናለን።',
    CampaignGroupSettings::contactMessageText('እናመሰግናለን።'),
    'saved contact message is kept'
);
assertSameValue(
    true,
    str_contains(CampaignGroupSettings::defaultDoneMessage(), '{name}'),
    'thank-you after booking includes the name'
);
assertSameValue(
    true,
    str_contains(CampaignGroupSettings::defaultPhoneAsk(), '{phone}'),
    'phone check includes the stored number'
);
assertSameValue(
    'የስልክ ቁጥርዎን ያስገቡ',
    CampaignGroupSettings::defaultPhoneEnter(),
    'phone enter prompt asks for a new number'
);
assertSameValue(
    'Abeba 07360436171',
    CampaignGroupSettings::preview('Abeba {phone}', ['name' => 'Abeba', 'phone' => '07360436171']),
    'preview replaces {phone}'
);

$correction = CampaignGroupSettings::defaultCorrectionMessage();
assertSameValue(true, str_contains($correction, '{name}'), 'after-no message includes the name');
assertSameValue(
    'እስካሁን ምን ያህል ከፍለዋል?',
    CampaignGroupSettings::defaultCorrectionAsk(),
    'after-no ask requests the amount paid so far'
);
assertSameValue(
    'የተከፈለ መጠን (£)',
    CampaignGroupSettings::defaultCorrectionAmountLabel(),
    'after-no amount field has a default label'
);
assertSameValue(
    CampaignGroupSettings::defaultCorrectionMessage(),
    CampaignGroupSettings::correctionMessageText(''),
    'empty after-no message falls back to the default'
);
assertSameValue(
    'እንዴት ከፍለዋል?',
    CampaignGroupSettings::defaultCorrectionMethodAsk(),
    'after-no method ask has a default prompt'
);
assertSameValue('ጥሬ ገንዘብ', CampaignGroupSettings::defaultCorrectionCashLabel(), 'cash has a default label');
assertSameValue('ባንክ ትራንስፈር', CampaignGroupSettings::defaultCorrectionCardLabel(), 'bank transfer has a default label');
assertSameValue('ድብልቅ', CampaignGroupSettings::defaultCorrectionMixedLabel(), 'mixed has a default label');
assertSameValue(
    'ከዚህ ውስጥ ምን ያህሉ በጥሬ ገንዘብ እና ምን ያህሉ በባንክ ትራንስፈር ነው?',
    CampaignGroupSettings::defaultMixedAsk(),
    'mixed asks how much was cash and how much was bank transfer'
);
assertSameValue(
    'ባንክ ትራንስፈር',
    CampaignGroupSettings::correctionCardLabelText('ካርድ'),
    'the old card label falls forward to bank transfer'
);
assertSameValue(
    'መቼ እና ለማን እንደከፈሉ ያስታውሳሉ?',
    CampaignGroupSettings::defaultCashRememberAsk(),
    'cash follow-up asks when and to whom'
);
assertSameValue('አላስታውስም', CampaignGroupSettings::defaultRememberNoLabel(), 'I do not remember has a default label');
assertSameValue(
    'የክፍያውን ስክሪንሹት ልከው ይችላሉ?',
    CampaignGroupSettings::defaultProofAsk(),
    'bank follow-up asks for a screenshot'
);

$pages = CampaignGroupSettings::payingPages(null);
assertSameValue(
    CampaignGroupSettings::defaultCashRememberAsk(),
    $pages['cash_remember_ask'] ?? null,
    'empty paying-page copy uses the cash default'
);
assertSameValue(
    CampaignGroupSettings::defaultCallbackMessage(),
    $pages['callback_message'] ?? null,
    'empty paying-page copy uses the callback default'
);
$savedPages = CampaignGroupSettings::payingPages(json_encode([
    'cash_remember_ask' => 'መቼ ከፈሉ?',
    'unknown_key' => 'nope',
    'proof_ask' => '',
], JSON_UNESCAPED_UNICODE));
assertSameValue('መቼ ከፈሉ?', $savedPages['cash_remember_ask'] ?? null, 'a saved cash prompt is kept');
assertSameValue(
    CampaignGroupSettings::defaultProofAsk(),
    $savedPages['proof_ask'] ?? null,
    'an empty saved proof ask uses the default'
);
assertSameValue(false, array_key_exists('unknown_key', $savedPages), 'unknown paying-page keys are dropped');
$sections = CampaignGroupSettings::payingCopySections();
assertSameValue(true, isset($sections['cash']), 'staff can edit the cash details page');
assertSameValue(true, isset($sections['mixed']), 'staff can edit the mixed payment page');
assertSameValue(true, isset($sections['proof']), 'staff can edit the bank screenshot page');
assertSameValue(true, isset($sections['date']), 'staff can edit the bank paid-date page');
assertSameValue(true, isset($sections['phone']), 'staff can edit the phone check page');
assertSameValue(true, isset($sections['thanks']), 'staff can edit the thank-you page');
assertSameValue('አዎ', CampaignGroupSettings::defaultStatusLabels()['yes'] ?? null, 'status yes is editable');
assertSameValue('አይደለም', CampaignGroupSettings::defaultStatusLabels()['no'] ?? null, 'status no is editable');

fwrite(STDOUT, "PASS campaign paying link tests\n");
