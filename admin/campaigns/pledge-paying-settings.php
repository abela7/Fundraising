<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = 'Still paying — Settings';
$title = 'Campaign settings';
$savedMessage = trim((string) $campaignSettings['first_message']);
$defaultMessage = (string) $campaignSettings['default_message'];
$messageIsCustom = $savedMessage !== '' && $savedMessage !== $defaultMessage;
$messagePreview = $savedMessage !== '' ? $savedMessage : $defaultMessage;
if (function_exists('mb_strlen') && mb_strlen($messagePreview) > 90) {
    $messagePreview = mb_substr($messagePreview, 0, 90) . '…';
} elseif (strlen($messagePreview) > 90) {
    $messagePreview = substr($messagePreview, 0, 90) . '…';
}
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
                            <i class="fas fa-sliders me-2 dvc-title-icon paying"></i>
                            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                        </h1>
                        <p>Set up this campaign, then send when you are ready.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="pledge-paying.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to donors
                        </a>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-lg-8">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="pledge-paying-first-message.php">
                            <div class="dvc-stat-icon completed"><i class="fab fa-whatsapp"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-setup-title">First WhatsApp message</div>
                                <div class="dvc-group-card-meta dvc-am-text"><?php echo htmlspecialchars($messagePreview, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta"><?php echo $messageIsCustom ? 'Saved — tap to edit' : 'Default hello — tap to write or change'; ?></div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
