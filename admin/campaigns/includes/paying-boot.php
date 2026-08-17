<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../shared/csrf.php';
require_once __DIR__ . '/group-config.php';
require_once __DIR__ . '/../../../shared/DonorCampaignGroups.php';
require_once __DIR__ . '/../../../shared/CampaignGroupSettings.php';

require_login();
require_admin();

$meta = dvc_campaign_group_meta(DonorCampaignGroups::PLEDGE_PAYING);
$campaignSettings = [
    'first_message' => CampaignGroupSettings::defaultFirstMessage(),
    'default_message' => CampaignGroupSettings::defaultFirstMessage(),
    'welcome_message' => CampaignGroupSettings::defaultWelcomeMessage(),
    'default_welcome' => CampaignGroupSettings::defaultWelcomeMessage(),
    'status_message' => CampaignGroupSettings::defaultStatusMessage(),
    'default_status' => CampaignGroupSettings::defaultStatusMessage(),
    'status_title' => CampaignGroupSettings::defaultStatusTitle(),
    'default_status_title' => CampaignGroupSettings::defaultStatusTitle(),
    'status_labels' => CampaignGroupSettings::defaultStatusLabels(),
    'default_status_labels' => CampaignGroupSettings::defaultStatusLabels(),
    'contact_message' => CampaignGroupSettings::defaultContactMessage(),
    'default_contact_message' => CampaignGroupSettings::defaultContactMessage(),
    'contact_ask' => CampaignGroupSettings::defaultContactAsk(),
    'default_contact_ask' => CampaignGroupSettings::defaultContactAsk(),
    'contact_labels' => CampaignGroupSettings::defaultContactLabels(),
    'default_contact_labels' => CampaignGroupSettings::defaultContactLabels(),
    'correction_message' => CampaignGroupSettings::defaultCorrectionMessage(),
    'default_correction_message' => CampaignGroupSettings::defaultCorrectionMessage(),
    'correction_ask' => CampaignGroupSettings::defaultCorrectionAsk(),
    'default_correction_ask' => CampaignGroupSettings::defaultCorrectionAsk(),
    'correction_amount_label' => CampaignGroupSettings::defaultCorrectionAmountLabel(),
    'default_correction_amount_label' => CampaignGroupSettings::defaultCorrectionAmountLabel(),
    'correction_method_ask' => CampaignGroupSettings::defaultCorrectionMethodAsk(),
    'default_correction_method_ask' => CampaignGroupSettings::defaultCorrectionMethodAsk(),
    'correction_cash_label' => CampaignGroupSettings::defaultCorrectionCashLabel(),
    'default_correction_cash_label' => CampaignGroupSettings::defaultCorrectionCashLabel(),
    'correction_card_label' => CampaignGroupSettings::defaultCorrectionCardLabel(),
    'default_correction_card_label' => CampaignGroupSettings::defaultCorrectionCardLabel(),
    'recipient_mode' => CampaignGroupSettings::MODE_ALL,
    'donor_ids' => [],
];
try {
    $campaignSettings = CampaignGroupSettings::get(db(), CampaignGroupSettings::GROUP_PAYING);
} catch (Throwable $e) {
    error_log('Campaign settings load failed: ' . $e->getMessage());
}

$csrfToken = csrf_token();
$cssVersion = (int) (filemtime(__DIR__ . '/../assets/campaigns.css') ?: time());
