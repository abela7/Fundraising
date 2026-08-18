<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = $campaignTitlePrefix . ' — First message';
$savedMessage = (string) $campaignSettings['first_message'];
$defaultMessage = (string) $campaignSettings['default_message'];
$pageConfig = [
    'csrf' => $csrfToken,
    'group' => $dvc_campaign_group,
    'default_message' => $defaultMessage,
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
                            <i class="fab fa-whatsapp me-2" style="color: var(--success);"></i>
                            First WhatsApp message
                        </h1>
                        <p>Write the hello message. Saving does not send it.</p>
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
                            <h6>Write the hello message</h6>
                            <p>Use the buttons to insert a donor’s name and amounts. Saving does not send WhatsApp.</p>
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
                        <label class="form-label" for="dvcFirstMessageBody">Message</label>
                        <textarea class="form-control dvc-am-text" id="dvcFirstMessageBody" rows="10" maxlength="4000" lang="am" dir="auto"><?php echo htmlspecialchars($savedMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        <div class="dvc-msg-meta">
                            <span id="dvcMsgCount">0 / 4000</span>
                            <button type="button" class="btn btn-link btn-sm px-0" id="dvcResetMessage">Reset to default</button>
                        </div>
                        <div class="dvc-preview">
                            <div class="dvc-preview-label">Preview</div>
                            <div class="dvc-preview-bubble dvc-am-text" id="dvcMsgPreview"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-primary" id="dvcSaveMessage">
                                <i class="fas fa-save me-1"></i>Save message
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
<script src="assets/paying-first-message.js"></script>
</body>
</html>
