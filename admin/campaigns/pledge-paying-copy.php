<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$sections = CampaignGroupSettings::payingCopySections();
$pageKey = strtolower(trim((string) ($_GET['page'] ?? '')));
if (!isset($sections[$pageKey])) {
    header('Location: pledge-paying-settings.php');
    exit;
}
$section = $sections[$pageKey];
$savedPages = is_array($campaignSettings['paying_pages'] ?? null)
    ? $campaignSettings['paying_pages']
    : CampaignGroupSettings::defaultPayingPages();
$defaultPages = is_array($campaignSettings['default_paying_pages'] ?? null)
    ? $campaignSettings['default_paying_pages']
    : CampaignGroupSettings::defaultPayingPages();
$values = [];
$defaults = [];
foreach ($section['fields'] as $field) {
    $key = (string) $field['key'];
    $values[$key] = (string) ($savedPages[$key] ?? $defaultPages[$key] ?? '');
    $defaults[$key] = (string) ($defaultPages[$key] ?? '');
}
$page_title = 'Still paying — ' . $section['title'];
$pageConfig = [
    'csrf' => $csrfToken,
    'page' => $pageKey,
    'fields' => $section['fields'],
    'defaults' => $defaults,
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
                            <i class="fas fa-pen-to-square me-2 dvc-title-icon paying"></i>
                            <?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?>
                        </h1>
                        <p><?php echo htmlspecialchars($section['blurb'], ENT_QUOTES, 'UTF-8'); ?></p>
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
                            <h6>Write every line on this page</h6>
                            <p>Saving does not send WhatsApp.</p>
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
                                <button type="button" class="dvc-var-btn" data-token="{phone}">Phone</button>
                            </div>
                        </div>
                        <?php foreach ($section['fields'] as $index => $field):
                            $key = (string) $field['key'];
                            $id = 'dvcCopy' . $index;
                            $max = CampaignGroupSettings::payingPageMax($key);
                            $value = $values[$key] ?? '';
                        ?>
                            <label class="form-label<?php echo $index > 0 ? ' mt-3' : ''; ?>" for="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string) $field['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                            <?php if (($field['type'] ?? 'input') === 'textarea'): ?>
                                <textarea
                                    class="form-control dvc-am-text"
                                    id="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-copy-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                    rows="4"
                                    maxlength="<?php echo (int) $max; ?>"
                                    lang="am"
                                    dir="auto"
                                ><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php else: ?>
                                <input
                                    class="form-control dvc-am-text"
                                    id="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-copy-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
                                    type="text"
                                    maxlength="<?php echo (int) $max; ?>"
                                    lang="am"
                                    dir="auto"
                                    value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
                                >
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="dvc-msg-meta">
                            <span id="dvcMsgCount"></span>
                            <button type="button" class="btn btn-link btn-sm px-0" id="dvcResetCopy">Reset to default</button>
                        </div>
                        <div class="dvc-preview">
                            <div class="dvc-preview-label">Preview</div>
                            <div class="dvc-contact-preview" id="dvcCopyPreview"></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-primary" id="dvcSaveCopy">
                                <i class="fas fa-save me-1"></i>Save page
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
<script src="assets/paying-copy.js"></script>
</body>
</html>
