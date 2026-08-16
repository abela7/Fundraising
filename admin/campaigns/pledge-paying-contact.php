<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = 'Still paying — Contact page';
$savedMessage = CampaignGroupSettings::contactMessageText(
    (string) ($campaignSettings['contact_message'] ?? '')
);
$savedAsk = CampaignGroupSettings::contactAskText(
    (string) ($campaignSettings['contact_ask'] ?? '')
);
$savedLabels = CampaignGroupSettings::contactLabels(
    null,
    is_array($campaignSettings['contact_labels'] ?? null) ? $campaignSettings['contact_labels'] : []
);
$defaultMessage = (string) ($campaignSettings['default_contact_message'] ?? CampaignGroupSettings::defaultContactMessage());
$defaultAsk = (string) ($campaignSettings['default_contact_ask'] ?? CampaignGroupSettings::defaultContactAsk());
$defaultLabels = $campaignSettings['default_contact_labels'] ?? CampaignGroupSettings::defaultContactLabels();
$pageConfig = [
    'csrf' => $csrfToken,
    'default_contact_message' => $defaultMessage,
    'default_contact_ask' => $defaultAsk,
    'default_contact_labels' => $defaultLabels,
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
                            <i class="fas fa-phone me-2 dvc-title-icon paying"></i>
                            Contact page
                        </h1>
                        <p>After the donor says the amounts are correct, they see this message and pick a date, time, and how to be called.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="pledge-paying-settings.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to settings
                        </a>
                    </div>
                </div>

                <div class="dvc-settings-card animate-fade-in">
                    <div class="dvc-settings-head">
                        <div>
                            <h6>Write every line on the contact page</h6>
                            <p>The thank-you, the ask, and the date / time / method labels are all editable. Saving does not send WhatsApp.</p>
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
                        <label class="form-label" for="dvcContactMessage">After-yes message</label>
                        <textarea class="form-control dvc-am-text" id="dvcContactMessage" rows="5" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <label class="form-label mt-3" for="dvcContactAsk">Date and time prompt</label>
                        <textarea class="form-control dvc-am-text" id="dvcContactAsk" rows="3" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedAsk, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <label class="form-label mt-3" for="dvcContactDate">Date label</label>
                        <input class="form-control dvc-am-text" id="dvcContactDate" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedLabels['date'], ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label mt-3" for="dvcContactTime">Time label</label>
                        <input class="form-control dvc-am-text" id="dvcContactTime" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedLabels['time'], ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label mt-3" for="dvcContactMethod">Contact method label</label>
                        <input class="form-control dvc-am-text" id="dvcContactMethod" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedLabels['method'], ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label mt-3" for="dvcContactWhatsapp">WhatsApp call label</label>
                        <input class="form-control dvc-am-text" id="dvcContactWhatsapp" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedLabels['whatsapp'], ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="form-label mt-3" for="dvcContactPhone">Phone call label</label>
                        <input class="form-control dvc-am-text" id="dvcContactPhone" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedLabels['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="dvc-msg-meta">
                            <span id="dvcMsgCount">0 / 4000</span>
                            <button type="button" class="btn btn-link btn-sm px-0" id="dvcResetContact">Reset to default</button>
                        </div>
                        <div class="dvc-preview">
                            <div class="dvc-preview-label">Preview</div>
                            <div class="dvc-contact-preview" id="dvcContactPreview"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-primary" id="dvcSaveContact">
                                <i class="fas fa-save me-1"></i>Save contact page
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
<script src="assets/paying-contact.js"></script>
</body>
</html>
