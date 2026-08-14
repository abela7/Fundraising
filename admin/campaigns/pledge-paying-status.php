<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = 'Still paying — Status check';
$savedCard = CampaignGroupSettings::statusCardCopy(
    (string) ($campaignSettings['status_message'] ?? ''),
    (string) ($campaignSettings['status_title'] ?? '')
);
$savedStatus = $savedCard['footer'];
$savedTitle = $savedCard['title'];
$defaultStatus = (string) ($campaignSettings['default_status'] ?? CampaignGroupSettings::defaultStatusMessage());
$defaultTitle = (string) ($campaignSettings['default_status_title'] ?? CampaignGroupSettings::defaultStatusTitle());
$pageConfig = [
    'csrf' => $csrfToken,
    'default_status' => $defaultStatus,
    'default_status_title' => $defaultTitle,
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
                            <i class="fas fa-clipboard-check me-2 dvc-title-icon paying"></i>
                            Status check page
                        </h1>
                        <p>After welcome, donors see a title, pledged / paid / remaining, then the footer you write here.</p>
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
                            <h6>Write the title and footer</h6>
                            <p>The title sits above the amounts. The footer sits under them. Saving does not send WhatsApp.</p>
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
                        <label class="form-label" for="dvcStatusTitle">Title</label>
                        <input class="form-control dvc-am-text" id="dvcStatusTitle" type="text" maxlength="200" lang="am" dir="auto" value="<?php echo htmlspecialchars($savedTitle, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="dvc-msg-meta">
                            <span id="dvcTitleCount">0 / 200</span>
                        </div>
                        <label class="form-label mt-3" for="dvcStatusBody">Footer text</label>
                        <textarea class="form-control dvc-am-text" id="dvcStatusBody" rows="4" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedStatus, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="dvc-msg-meta">
                            <span id="dvcMsgCount">0 / 4000</span>
                            <button type="button" class="btn btn-link btn-sm px-0" id="dvcResetStatus">Reset to default</button>
                        </div>
                        <div class="dvc-preview">
                            <div class="dvc-preview-label">Preview</div>
                            <div class="dvc-status-card dvc-am-text" id="dvcStatusPreview"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-primary" id="dvcSaveStatus">
                                <i class="fas fa-save me-1"></i>Save status page
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
<script src="assets/paying-status.js"></script>
</body>
</html>
