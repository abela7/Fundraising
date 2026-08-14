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
