<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = $campaignTitlePrefix . ' — After No page';
$savedMessage = CampaignGroupSettings::correctionMessageText(
    (string) ($campaignSettings['correction_message'] ?? '')
);
$savedAsk = CampaignGroupSettings::correctionAskText(
    (string) ($campaignSettings['correction_ask'] ?? '')
);
$savedAmountLabel = CampaignGroupSettings::correctionAmountLabelText(
    (string) ($campaignSettings['correction_amount_label'] ?? '')
);
$savedMethodAsk = CampaignGroupSettings::correctionMethodAskText(
    (string) ($campaignSettings['correction_method_ask'] ?? '')
);
$savedCashLabel = CampaignGroupSettings::correctionCashLabelText(
    (string) ($campaignSettings['correction_cash_label'] ?? '')
);
$savedCardLabel = CampaignGroupSettings::correctionCardLabelText(
    (string) ($campaignSettings['correction_card_label'] ?? '')
);
$defaultMessage = (string) ($campaignSettings['default_correction_message'] ?? CampaignGroupSettings::defaultCorrectionMessage());
$defaultAsk = (string) ($campaignSettings['default_correction_ask'] ?? CampaignGroupSettings::defaultCorrectionAsk());
$defaultAmountLabel = (string) ($campaignSettings['default_correction_amount_label'] ?? CampaignGroupSettings::defaultCorrectionAmountLabel());
$defaultMethodAsk = (string) ($campaignSettings['default_correction_method_ask'] ?? CampaignGroupSettings::defaultCorrectionMethodAsk());
$defaultCashLabel = (string) ($campaignSettings['default_correction_cash_label'] ?? CampaignGroupSettings::defaultCorrectionCashLabel());
$defaultCardLabel = (string) ($campaignSettings['default_correction_card_label'] ?? CampaignGroupSettings::defaultCorrectionCardLabel());
$pageConfig = [
    'csrf' => $csrfToken,
    'group' => $dvc_campaign_group,
    'preview' => $dvcPreviewDonor,
    'default_correction_message' => $defaultMessage,
    'default_correction_ask' => $defaultAsk,
    'default_correction_amount_label' => $defaultAmountLabel,
    'default_correction_method_ask' => $defaultMethodAsk,
    'default_correction_cash_label' => $defaultCashLabel,
    'default_correction_card_label' => $defaultCardLabel,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/campaigns.css?v=<?php echo $cssVersion; ?>">
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <div class="dvc-page-header animate-fade-in">
                    <div>
                        <h1>
                            <i class="fas fa-pen-to-square me-2 dvc-title-icon <?php echo htmlspecialchars($campaignTone, ENT_QUOTES, 'UTF-8'); ?>"></i>
                            After No page
                        </h1>
                        <p>After the donor says the amounts are not correct, they enter how much they have paid so far, then choose cash or bank transfer.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars($campaignFilePrefix, ENT_QUOTES, 'UTF-8'); ?>-settings.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to settings
                        </a>
                    </div>
                </div>

                <div class="dvc-settings-card animate-fade-in">
                    <div class="dvc-settings-head">
                        <div>
                            <h6>Write every line on the after-no page</h6>
                            <p>The message, the amount prompt, the field label, and the cash / bank transfer labels are all editable. Saving does not send WhatsApp.</p>
                        </div>
                    </div>
                    <div class="dvc-settings-body">
                        <div id="dvcMsgFlash" class="alert d-none" role="status"></div>
                        <div class="dvc-var-row">
                            <span class="dvc-var-label">Insert variable</span>
                            <div class="dvc-var-btns">
                                <button type="button" class="dvc-var-btn" data-token="{name}">Name</button>
                                <button type="button" class="dvc-var-btn" data-token="{pledge_amount}">Pledge amount</button>
                                <button type="button" class="dvc-var-btn" data-token="{total_paid}">Total paid</button>
                                <button type="button" class="dvc-var-btn" data-token="{remaining_amount}">Remaining amount</button>
                            </div>
                        </div>
                        <label class="form-label" for="dvcCorrectionMessage">After-no message</label>
                        <textarea class="form-control dvc-am-text" id="dvcCorrectionMessage" rows="5" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <label class="form-label mt-3" for="dvcCorrectionAsk">Amount prompt</label>
                        <textarea class="form-control dvc-am-text" id="dvcCorrectionAsk" rows="3" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedAsk, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <label class="form-label mt-3" for="dvcCorrectionAmount">Amount field label</label>
                        <input class="form-control dvc-am-text" id="dvcCorrectionAmount" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedAmountLabel, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label mt-3" for="dvcCorrectionMethodAsk">How they paid prompt</label>
                        <textarea class="form-control dvc-am-text" id="dvcCorrectionMethodAsk" rows="2" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedMethodAsk, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <label class="form-label mt-3" for="dvcCorrectionCash">Cash label</label>
                        <input class="form-control dvc-am-text" id="dvcCorrectionCash" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedCashLabel, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label mt-3" for="dvcCorrectionCard">Bank transfer label</label>
                        <input class="form-control dvc-am-text" id="dvcCorrectionCard" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedCardLabel, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="dvc-msg-meta">
                            <span id="dvcMsgCount">0 / 4000</span>
                            <button type="button" class="btn btn-link btn-sm px-0" id="dvcResetCorrection">Reset to default</button>
                        </div>
                        <div class="dvc-preview">
                            <div class="dvc-preview-label">Preview</div>
                            <div class="dvc-contact-preview" id="dvcCorrectionPreview"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-primary" id="dvcSaveCorrection">
                                <i class="fas fa-save me-1"></i>Save after-no page
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
window.DVC_PAGE = <?php echo json_encode($pageConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="assets/paying-correction.js"></script>
</body>
</html>
