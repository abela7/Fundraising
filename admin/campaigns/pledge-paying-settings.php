<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = 'Still paying — Settings';
$title = 'Campaign settings';
$savedMessage = trim((string) $campaignSettings['first_message']);
$defaultMessage = (string) $campaignSettings['default_message'];
$messageIsCustom = $savedMessage !== '' && $savedMessage !== $defaultMessage;
$messagePreview = $savedMessage !== '' ? $savedMessage : $defaultMessage;

$savedWelcome = trim((string) ($campaignSettings['welcome_message'] ?? ''));
$defaultWelcome = (string) ($campaignSettings['default_welcome'] ?? CampaignGroupSettings::defaultWelcomeMessage());
$welcomeIsCustom = $savedWelcome !== '' && $savedWelcome !== $defaultWelcome;
$welcomePreview = $savedWelcome !== '' ? $savedWelcome : $defaultWelcome;

$savedCard = CampaignGroupSettings::statusCardCopy(
    (string) ($campaignSettings['status_message'] ?? ''),
    (string) ($campaignSettings['status_title'] ?? '')
);
$savedStatus = $savedCard['footer'];
$savedTitle = $savedCard['title'];
$defaultStatus = (string) ($campaignSettings['default_status'] ?? CampaignGroupSettings::defaultStatusMessage());
$defaultTitle = (string) ($campaignSettings['default_status_title'] ?? CampaignGroupSettings::defaultStatusTitle());
$statusIsCustom = ($savedStatus !== '' && $savedStatus !== $defaultStatus)
    || ($savedTitle !== '' && $savedTitle !== $defaultTitle);
$statusPreview = trim($savedTitle . ' — ' . $savedStatus);

$clip = static function (string $text): string {
    if (function_exists('mb_strlen') && mb_strlen($text) > 90) {
        return mb_substr($text, 0, 90) . '…';
    }
    if (strlen($text) > 90) {
        return substr($text, 0, 90) . '…';
    }

    return $text;
};
$messagePreview = $clip($messagePreview);
$welcomePreview = $clip($welcomePreview);
$statusPreview = $clip($statusPreview);

$savedContactMessage = CampaignGroupSettings::contactMessageText(
    (string) ($campaignSettings['contact_message'] ?? '')
);
$savedContactAsk = CampaignGroupSettings::contactAskText(
    (string) ($campaignSettings['contact_ask'] ?? '')
);
$defaultContactMessage = (string) ($campaignSettings['default_contact_message'] ?? CampaignGroupSettings::defaultContactMessage());
$defaultContactAsk = (string) ($campaignSettings['default_contact_ask'] ?? CampaignGroupSettings::defaultContactAsk());
$contactIsCustom = ($savedContactMessage !== $defaultContactMessage)
    || ($savedContactAsk !== $defaultContactAsk);
$contactPreview = $clip(trim($savedContactAsk !== '' ? $savedContactAsk : $savedContactMessage));

$savedCorrectionMessage = CampaignGroupSettings::correctionMessageText(
    (string) ($campaignSettings['correction_message'] ?? '')
);
$savedCorrectionAsk = CampaignGroupSettings::correctionAskText(
    (string) ($campaignSettings['correction_ask'] ?? '')
);
$defaultCorrectionMessage = (string) ($campaignSettings['default_correction_message'] ?? CampaignGroupSettings::defaultCorrectionMessage());
$defaultCorrectionAsk = (string) ($campaignSettings['default_correction_ask'] ?? CampaignGroupSettings::defaultCorrectionAsk());
$correctionIsCustom = ($savedCorrectionMessage !== $defaultCorrectionMessage)
    || ($savedCorrectionAsk !== $defaultCorrectionAsk);
$correctionPreview = $clip(trim($savedCorrectionAsk !== '' ? $savedCorrectionAsk : $savedCorrectionMessage));

$payingPages = is_array($campaignSettings['paying_pages'] ?? null)
    ? $campaignSettings['paying_pages']
    : CampaignGroupSettings::defaultPayingPages();
$defaultPayingPages = is_array($campaignSettings['default_paying_pages'] ?? null)
    ? $campaignSettings['default_paying_pages']
    : CampaignGroupSettings::defaultPayingPages();
$copySections = [];
foreach (CampaignGroupSettings::payingCopySections() as $key => $section) {
    $previewKey = (string) $section['preview_key'];
    $savedLine = trim((string) ($payingPages[$previewKey] ?? ''));
    $defaultLine = trim((string) ($defaultPayingPages[$previewKey] ?? ''));
    $copySections[$key] = [
        'title' => (string) $section['title'],
        'preview' => $clip($savedLine !== '' ? $savedLine : $defaultLine),
        'custom' => $savedLine !== '' && $savedLine !== $defaultLine,
    ];
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
                    <div class="col-12 col-lg-8">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="pledge-paying-welcome.php">
                            <div class="dvc-stat-icon paying"><i class="fas fa-door-open"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-setup-title">Welcome page</div>
                                <div class="dvc-group-card-meta dvc-am-text"><?php echo htmlspecialchars($welcomePreview, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta"><?php echo $welcomeIsCustom ? 'Saved — tap to edit' : 'Default welcome — tap to write or change'; ?></div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                    <div class="col-12 col-lg-8">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="pledge-paying-status.php">
                            <div class="dvc-stat-icon paying"><i class="fas fa-clipboard-check"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-setup-title">Status check page</div>
                                <div class="dvc-group-card-meta dvc-am-text"><?php echo htmlspecialchars($statusPreview, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta"><?php echo $statusIsCustom ? 'Saved — tap to edit' : 'Default title and footer — tap to write or change'; ?></div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                    <div class="col-12 col-lg-8">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="pledge-paying-contact.php">
                            <div class="dvc-stat-icon paying"><i class="fas fa-phone"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-setup-title">Contact page</div>
                                <div class="dvc-group-card-meta dvc-am-text"><?php echo htmlspecialchars($contactPreview, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta"><?php echo $contactIsCustom ? 'Saved — tap to edit' : 'Default contact page — tap to write or change'; ?></div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                    <div class="col-12 col-lg-8">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="pledge-paying-correction.php">
                            <div class="dvc-stat-icon paying"><i class="fas fa-pen-to-square"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-setup-title">After No page</div>
                                <div class="dvc-group-card-meta dvc-am-text"><?php echo htmlspecialchars($correctionPreview, ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta"><?php echo $correctionIsCustom ? 'Saved — tap to edit' : 'Default after-no page — tap to write or change'; ?></div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                    <?php
                    $copyIcons = [
                        'cash' => 'fa-coins',
                        'proof' => 'fa-image',
                        'date' => 'fa-calendar-day',
                        'phone' => 'fa-mobile-screen',
                        'thanks' => 'fa-heart',
                    ];
                    foreach ($copySections as $copyKey => $copyCard):
                    ?>
                    <div class="col-12 col-lg-8">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="pledge-paying-copy.php?page=<?php echo htmlspecialchars($copyKey, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dvc-stat-icon paying"><i class="fas <?php echo htmlspecialchars($copyIcons[$copyKey] ?? 'fa-file-lines', ENT_QUOTES, 'UTF-8'); ?>"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-setup-title"><?php echo htmlspecialchars($copyCard['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta dvc-am-text"><?php echo htmlspecialchars($copyCard['preview'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="dvc-group-card-meta"><?php echo $copyCard['custom'] ? 'Saved — tap to edit' : 'Default page — tap to write or change'; ?></div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
