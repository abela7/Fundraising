<?php

declare(strict_types=1);

require_once __DIR__ . '/../shared/DonorCampaignGroups.php';
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

$okReplies = ['ok', 'OK', 'Ok', 'oK', 'okay', 'OKAY', 'Okay', ' ok ', 'ok.', 'Okay!'];
foreach ($okReplies as $reply) {
    assertSameValue(
        true,
        CampaignInboundIdentifier::isOkReply($reply),
        'treats "' . $reply . '" as an OK campaign reply'
    );
}

$notOk = ['', 'ok thanks', 'okayish', 'PAY 0335', 'yes please later', 'hello'];
foreach ($notOk as $reply) {
    assertSameValue(
        false,
        CampaignInboundIdentifier::isOkReply($reply),
        'does not treat "' . $reply . '" as an OK campaign reply'
    );
}

assertSameValue(
    DonorCampaignGroups::IMMEDIATE,
    DonorCampaignGroups::fromDonor([
        'donor_type' => 'immediate_payment',
        'total_pledged' => 0,
        'total_paid' => 50,
        'balance' => 0,
    ]),
    'immediate payment type is the immediate group'
);

assertSameValue(
    DonorCampaignGroups::IMMEDIATE,
    DonorCampaignGroups::fromDonor([
        'donor_type' => 'pledge',
        'total_pledged' => 0,
        'total_paid' => 80,
        'balance' => 0,
    ]),
    'paid with no pledge is the immediate group'
);

assertSameValue(
    DonorCampaignGroups::PLEDGE_COMPLETED,
    DonorCampaignGroups::fromDonor([
        'donor_type' => 'pledge',
        'total_pledged' => 400,
        'total_paid' => 400,
        'balance' => 0,
    ]),
    'paid in full is pledge completed'
);

assertSameValue(
    DonorCampaignGroups::PLEDGE_PAYING,
    DonorCampaignGroups::fromDonor([
        'donor_type' => 'pledge',
        'total_pledged' => 400,
        'total_paid' => 120,
        'balance' => 280,
    ]),
    'started paying is still paying'
);

assertSameValue(
    DonorCampaignGroups::PLEDGE_NOT_STARTED,
    DonorCampaignGroups::fromDonor([
        'donor_type' => 'pledge',
        'total_pledged' => 400,
        'total_paid' => 0,
        'balance' => 400,
    ]),
    'pledged with no payment is not started'
);

assertSameValue(
    DonorCampaignGroups::UNCLASSIFIED,
    DonorCampaignGroups::fromDonor([
        'donor_type' => '',
        'total_pledged' => 0,
        'total_paid' => 0,
        'balance' => 0,
    ]),
    'no pledge and no payment needs review'
);

fwrite(STDOUT, "PASS campaign inbound identify tests\n");
