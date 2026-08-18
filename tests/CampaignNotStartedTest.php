<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/CampaignGroupSettings.php';
require_once __DIR__ . '/../shared/DonorCampaignGroups.php';
require_once __DIR__ . '/../shared/CampaignPayingReport.php';
require_once __DIR__ . '/../shared/CampaignPayingLink.php';
require_once __DIR__ . '/../shared/CampaignInboundIdentifier.php';

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
    true,
    CampaignGroupSettings::isAllowedGroup(CampaignGroupSettings::GROUP_PAYING),
    'still-paying stays an allowed campaign group'
);
assertSameValue(
    true,
    CampaignGroupSettings::isAllowedGroup(CampaignGroupSettings::GROUP_NOT_STARTED),
    'not-started is its own allowed campaign group'
);
assertSameValue(
    false,
    CampaignGroupSettings::isAllowedGroup(DonorCampaignGroups::PLEDGE_COMPLETED),
    'completed is not a campaign settings group yet'
);
assertSameValue(
    CampaignGroupSettings::GROUP_NOT_STARTED,
    CampaignGroupSettings::sanitizeGroup('PLEDGE_NOT_STARTED'),
    'accepts the not-started group key'
);
assertSameValue(
    CampaignGroupSettings::GROUP_PAYING,
    CampaignGroupSettings::sanitizeGroup('unknown'),
    'unknown groups fall back to still-paying'
);

$payingHello = CampaignGroupSettings::defaultFirstMessage();
$notStartedHello = CampaignGroupSettings::defaultFirstMessageFor(CampaignGroupSettings::GROUP_NOT_STARTED);
assertSameValue($payingHello, CampaignGroupSettings::defaultFirstMessageFor(CampaignGroupSettings::GROUP_PAYING), 'paying keeps its hello');
assertSameValue(true, $notStartedHello !== $payingHello, 'not-started has its own first WhatsApp hello');
assertSameValue(true, str_contains($notStartedHello, '{name}'), 'not-started hello includes the donor name');
assertSameValue(true, str_contains(strtolower($notStartedHello), 'ok'), 'not-started hello asks them to reply OK');

$preview = CampaignGroupSettings::preview($notStartedHello, [
    'name' => 'Abeba',
    'pledged' => 400,
    'paid' => 0,
    'balance' => 400,
]);
assertSameValue(true, str_contains($preview, 'Abeba'), 'not-started preview fills the name');
assertSameValue(false, str_contains($preview, '{name}'), 'not-started preview leaves no name token');

assertSameValue(
    DonorCampaignGroups::PLEDGE_NOT_STARTED,
    DonorCampaignGroups::fromDonor([
        'total_pledged' => 400,
        'total_paid' => 0,
        'balance' => 400,
    ]),
    'a pledged donor with no payment is not-started'
);
assertSameValue(
    DonorCampaignGroups::PLEDGE_PAYING,
    DonorCampaignGroups::fromDonor([
        'total_pledged' => 400,
        'total_paid' => 20,
        'balance' => 380,
    ]),
    'a pledged donor who has paid something stays in still-paying'
);

assertSameValue(
    DonorCampaignGroups::PLEDGE_NOT_STARTED,
    CampaignPayingReport::sanitizeGroup(DonorCampaignGroups::PLEDGE_NOT_STARTED),
    'the report can load the not-started group'
);
assertSameValue(
    DonorCampaignGroups::PLEDGE_PAYING,
    CampaignPayingReport::sanitizeGroup('completed'),
    'the report does not switch to an unbuilt group'
);

assertSameValue(
    true,
    CampaignInboundIdentifier::supportsLink(DonorCampaignGroups::PLEDGE_NOT_STARTED),
    'an OK from a not-started donor can receive a unique link'
);
assertSameValue(
    true,
    CampaignInboundIdentifier::supportsLink(DonorCampaignGroups::PLEDGE_PAYING),
    'an OK from a still-paying donor still receives a unique link'
);
assertSameValue(
    false,
    CampaignInboundIdentifier::supportsLink(DonorCampaignGroups::PLEDGE_COMPLETED),
    'completed donors still do not get a campaign link'
);
assertSameValue(
    true,
    CampaignPayingLink::isEligibleGroup(DonorCampaignGroups::PLEDGE_NOT_STARTED),
    'the link sender treats not-started as eligible'
);

$token = 'a1b2c3d4e5f67890';
assertSameValue(
    'https://donate.abuneteklehaymanot.org/paying/' . $token,
    CampaignPayingLink::whatsappUrl($token),
    'still-paying WhatsApp links stay on /paying/'
);
assertSameValue(
    'https://donate.abuneteklehaymanot.org/not-started/' . $token,
    CampaignPayingLink::whatsappUrl($token, DonorCampaignGroups::PLEDGE_NOT_STARTED),
    'not-started WhatsApp links use /not-started/'
);
assertSameValue(
    'http://localhost/Fundraising/not-started/' . $token,
    CampaignPayingLink::publicUrl(
        $token,
        'localhost',
        '/Fundraising/webhooks/ultramsg.php',
        DonorCampaignGroups::PLEDGE_NOT_STARTED
    ),
    'localhost webhook builds a local not-started URL'
);

$payingWelcome = CampaignGroupSettings::defaultWelcomeMessage();
$notStartedWelcome = CampaignGroupSettings::defaultWelcomeMessageFor(
    CampaignGroupSettings::GROUP_NOT_STARTED
);
assertSameValue($payingWelcome, CampaignGroupSettings::defaultWelcomeMessageFor(
    CampaignGroupSettings::GROUP_PAYING
), 'paying keeps its welcome');
assertSameValue(true, $notStartedWelcome !== $payingWelcome, 'not-started has its own welcome');
assertSameValue(true, str_contains($notStartedWelcome, '{name}'), 'not-started welcome includes the name');
assertSameValue(
    true,
    str_contains($notStartedWelcome, 'ገና አልጀመሩም'),
    'not-started welcome says they have not started'
);

$payingContact = CampaignGroupSettings::defaultContactMessage();
$notStartedContact = CampaignGroupSettings::defaultContactMessageFor(
    CampaignGroupSettings::GROUP_NOT_STARTED
);
assertSameValue($payingContact, CampaignGroupSettings::defaultContactMessageFor(
    CampaignGroupSettings::GROUP_PAYING
), 'paying keeps its after-yes message');
assertSameValue(true, $notStartedContact !== $payingContact, 'not-started has its own after-yes message');
assertSameValue(true, str_contains($notStartedContact, '{remaining_amount}'), 'not-started after-yes includes remaining');
assertSameValue(
    true,
    str_contains($notStartedContact, 'እንደሚጀምሩ'),
    'not-started after-yes books a call about starting'
);
assertSameValue(
    $notStartedContact,
    CampaignGroupSettings::contactMessageText('', CampaignGroupSettings::GROUP_NOT_STARTED),
    'empty saved after-yes uses the not-started default'
);

$notStartedRow = CampaignPayingReport::present([
    'id' => 9,
    'name' => 'Abeba',
    'token' => $token,
    'step' => 'welcome',
    'total_pledged' => 400,
    'total_paid' => 0,
    'balance' => 400,
]);
assertSameValue(
    'https://donate.abuneteklehaymanot.org/not-started/' . $token,
    $notStartedRow['paying_url'],
    'the report exposes the public not-started link'
);

fwrite(STDOUT, "PASS campaign not-started tests\n");
