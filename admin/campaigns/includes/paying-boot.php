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

$dvc_campaign_group = isset($dvc_campaign_group) && is_string($dvc_campaign_group)
    ? CampaignGroupSettings::sanitizeGroup($dvc_campaign_group)
    : CampaignGroupSettings::GROUP_PAYING;
$meta = dvc_campaign_group_meta($dvc_campaign_group);
$hello = CampaignGroupSettings::defaultFirstMessageFor($dvc_campaign_group);
$isNotStartedCampaign = $dvc_campaign_group === CampaignGroupSettings::GROUP_NOT_STARTED;
$campaignFilePrefix = $isNotStartedCampaign ? 'pledge-not-started' : 'pledge-paying';
$campaignTitlePrefix = $isNotStartedCampaign ? 'Not started' : 'Still paying';
$campaignTone = $isNotStartedCampaign ? 'not-started' : 'paying';
$campaignSettings = [
    'first_message' => $hello,
    'default_message' => $hello,
    'welcome_message' => CampaignGroupSettings::defaultWelcomeMessageFor($dvc_campaign_group),
    'default_welcome' => CampaignGroupSettings::defaultWelcomeMessageFor($dvc_campaign_group),
    'status_message' => CampaignGroupSettings::defaultStatusMessage(),
    'default_status' => CampaignGroupSettings::defaultStatusMessage(),
    'status_title' => CampaignGroupSettings::defaultStatusTitle(),
    'default_status_title' => CampaignGroupSettings::defaultStatusTitle(),
    'status_labels' => CampaignGroupSettings::defaultStatusLabels(),
    'default_status_labels' => CampaignGroupSettings::defaultStatusLabels(),
    'contact_message' => CampaignGroupSettings::defaultContactMessageFor($dvc_campaign_group),
    'default_contact_message' => CampaignGroupSettings::defaultContactMessageFor($dvc_campaign_group),
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
    'paying_pages' => CampaignGroupSettings::defaultPayingPages(),
    'default_paying_pages' => CampaignGroupSettings::defaultPayingPages(),
    'recipient_mode' => CampaignGroupSettings::MODE_ALL,
    'donor_ids' => [],
];
try {
    $campaignSettings = CampaignGroupSettings::get(db(), $dvc_campaign_group);
} catch (Throwable $e) {
    error_log('Campaign settings load failed: ' . $e->getMessage());
}

$csrfToken = csrf_token();
$cssVersion = (int) (filemtime(__DIR__ . '/../assets/campaigns.css') ?: time());
