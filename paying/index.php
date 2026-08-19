<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../shared/CampaignPayingLink.php';
require_once __DIR__ . '/../shared/CampaignGroupSettings.php';
require_once __DIR__ . '/../shared/CampaignPayingProgress.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_GET['t'] ?? ''));
if ($token === '') {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (preg_match('#/(?:paying|not-started)/([A-Za-z0-9]+)/?$#', $path, $match)) {
        $token = $match[1];
    }
}
$token = CampaignPayingProgress::normalizeToken($token) ?? '';

$donor = null;
$error = 'ይህ ሊንክ አይሰራም።';
$welcomeHtml = '';
$statusHtml = '';
$statusTitleHtml = '';
$pledgeLabelHtml = '';
$paidLabelHtml = '';
$remainLabelHtml = '';
$contactMessageHtml = '';
$contactAskHtml = '';
$contactDateLabelHtml = '';
$contactTimeLabelHtml = '';
$contactMethodLabelHtml = '';
$contactWhatsappHtml = '';
$contactPhoneHtml = '';
$correctionMessageHtml = '';
$correctionAskHtml = '';
$correctionAmountLabelHtml = '';
$correctionMethodAskHtml = '';
$correctionCashHtml = '';
$correctionCardHtml = '';
$correctionMixedHtml = '';
$mixedAskHtml = '';
$mixedCashLabelHtml = '';
$mixedBankLabelHtml = '';
$cashRememberAskHtml = '';
$cashWhenLabelHtml = '';
$cashWhomLabelHtml = '';
$rememberNoHtml = '';
$proofAskHtml = '';
$proofAttachHtml = '';
$paidDateAskHtml = '';
$callbackHtml = '';
$thanksHtml = '';
$doneHtml = '';
$phoneAskHtml = '';
$phoneEnterHtml = '';
$displayPhone = '';
try {
    if ($token !== '') {
        $donor = CampaignPayingLink::donorByToken(db(), $token);
    }
} catch (Throwable $e) {
    error_log('Paying page load failed: ' . $e->getMessage());
    $donor = null;
}

if ($donor === null) {
    http_response_code(404);
} else {
    $campaignGroup = CampaignPayingLink::formGroupFromDonorRow($donor);
    $welcomeTemplate = CampaignGroupSettings::defaultWelcomeMessageFor($campaignGroup);
    $statusTemplate = CampaignGroupSettings::defaultStatusMessage();
    $statusTitleTemplate = CampaignGroupSettings::defaultStatusTitle();
    $statusLabels = CampaignGroupSettings::defaultStatusLabels();
    $contactMessageTemplate = CampaignGroupSettings::defaultContactMessageFor($campaignGroup);
    $contactAskTemplate = CampaignGroupSettings::defaultContactAsk();
    $contactLabels = CampaignGroupSettings::defaultContactLabels();
    $correctionMessageTemplate = CampaignGroupSettings::defaultCorrectionMessage();
    $correctionAskTemplate = CampaignGroupSettings::defaultCorrectionAsk();
    $correctionAmountLabelTemplate = CampaignGroupSettings::defaultCorrectionAmountLabel();
    $correctionMethodAskTemplate = CampaignGroupSettings::defaultCorrectionMethodAsk();
    $correctionCashTemplate = CampaignGroupSettings::defaultCorrectionCashLabel();
    $correctionCardTemplate = CampaignGroupSettings::defaultCorrectionCardLabel();
    $doneTemplate = CampaignGroupSettings::defaultDoneMessage();
    $phoneAskTemplate = CampaignGroupSettings::defaultPhoneAsk();
    $phoneEnterTemplate = CampaignGroupSettings::defaultPhoneEnter();
    $payingPages = CampaignGroupSettings::defaultPayingPages();
    try {
        $settings = CampaignGroupSettings::get(db(), $campaignGroup);
        if (trim((string) ($settings['welcome_message'] ?? '')) !== '') {
            $welcomeTemplate = (string) $settings['welcome_message'];
        }
        $card = CampaignGroupSettings::statusCardCopy(
            (string) ($settings['status_message'] ?? ''),
            (string) ($settings['status_title'] ?? '')
        );
        $statusTemplate = $card['footer'];
        $statusTitleTemplate = $card['title'];
        if (isset($settings['status_labels']) && is_array($settings['status_labels'])) {
            $statusLabels = CampaignGroupSettings::statusLabels(null, $settings['status_labels']);
        }
        $contactMessageTemplate = CampaignGroupSettings::contactMessageText(
            (string) ($settings['contact_message'] ?? ''),
            $campaignGroup
        );
        $contactAskTemplate = CampaignGroupSettings::contactAskText(
            (string) ($settings['contact_ask'] ?? '')
        );
        if (isset($settings['contact_labels']) && is_array($settings['contact_labels'])) {
            $contactLabels = CampaignGroupSettings::contactLabels(null, $settings['contact_labels']);
        }
        $correctionMessageTemplate = CampaignGroupSettings::correctionMessageText(
            (string) ($settings['correction_message'] ?? '')
        );
        $correctionAskTemplate = CampaignGroupSettings::correctionAskText(
            (string) ($settings['correction_ask'] ?? '')
        );
        $correctionAmountLabelTemplate = CampaignGroupSettings::correctionAmountLabelText(
            (string) ($settings['correction_amount_label'] ?? '')
        );
        $correctionMethodAskTemplate = CampaignGroupSettings::correctionMethodAskText(
            (string) ($settings['correction_method_ask'] ?? '')
        );
        $correctionCashTemplate = CampaignGroupSettings::correctionCashLabelText(
            (string) ($settings['correction_cash_label'] ?? '')
        );
        $correctionCardTemplate = CampaignGroupSettings::correctionCardLabelText(
            (string) ($settings['correction_card_label'] ?? '')
        );
        if (isset($settings['paying_pages']) && is_array($settings['paying_pages'])) {
            $payingPages = CampaignGroupSettings::payingPages(null, $settings['paying_pages']);
        }
        $doneTemplate = (string) ($payingPages['done_message'] ?? $doneTemplate);
        $phoneAskTemplate = (string) ($payingPages['phone_ask'] ?? $phoneAskTemplate);
        $phoneEnterTemplate = (string) ($payingPages['phone_enter'] ?? $phoneEnterTemplate);
    } catch (Throwable $e) {
        error_log('Paying page text load failed: ' . $e->getMessage());
    }
    $renderText = static function (string $template) use ($donor): string {
        return nl2br(htmlspecialchars(
            CampaignGroupSettings::previewFromDonor($template, $donor),
            ENT_QUOTES,
            'UTF-8'
        ), false);
    };
    $welcomeHtml = $renderText($welcomeTemplate);
    $statusHtml = $renderText($statusTemplate);
    $statusTitleHtml = $renderText($statusTitleTemplate);
    $pledgeLabelHtml = $renderText($statusLabels['pledge']);
    $paidLabelHtml = $renderText($statusLabels['paid']);
    $remainLabelHtml = $renderText($statusLabels['remain']);
    $contactMessageHtml = $renderText($contactMessageTemplate);
    $contactAskHtml = $renderText($contactAskTemplate);
    $contactDateLabelHtml = $renderText($contactLabels['date']);
    $contactTimeLabelHtml = $renderText($contactLabels['time']);
    $contactMethodLabelHtml = $renderText($contactLabels['method']);
    $contactWhatsappHtml = $renderText($contactLabels['whatsapp']);
    $contactPhoneHtml = $renderText($contactLabels['phone']);
    $correctionMessageHtml = $renderText($correctionMessageTemplate);
    $correctionAskHtml = $renderText($correctionAskTemplate);
    $correctionAmountLabelHtml = $renderText($correctionAmountLabelTemplate);
    $correctionMethodAskHtml = $renderText($correctionMethodAskTemplate);
    $correctionCashHtml = $renderText($correctionCashTemplate);
    $correctionCardHtml = $renderText($correctionCardTemplate);
    $correctionMixedHtml = $renderText((string) $payingPages['mixed_label']);
    $mixedAskHtml = $renderText((string) $payingPages['mixed_ask']);
    $mixedCashLabelHtml = $renderText((string) $payingPages['mixed_cash_label']);
    $mixedBankLabelHtml = $renderText((string) $payingPages['mixed_bank_label']);
    $cashRememberAskHtml = $renderText((string) $payingPages['cash_remember_ask']);
    $cashWhenLabelHtml = $renderText((string) $payingPages['cash_when_label']);
    $cashWhomLabelHtml = $renderText((string) $payingPages['cash_whom_label']);
    $cashRememberNoHtml = $renderText((string) $payingPages['cash_remember_no_label']);
    $proofAskHtml = $renderText((string) $payingPages['proof_ask']);
    $proofYesHtml = $renderText((string) $payingPages['proof_yes_label']);
    $proofNoHtml = $renderText((string) $payingPages['proof_no_label']);
    $proofAttachHtml = $renderText((string) $payingPages['proof_attach_label']);
    $paidDateAskHtml = $renderText((string) $payingPages['paid_date_ask']);
    $paidRememberNoHtml = $renderText((string) $payingPages['paid_remember_no_label']);
    $callbackHtml = $renderText((string) $payingPages['callback_message']);
    $thanksHtml = $renderText((string) $payingPages['thanks_message']);
    $doneHtml = $renderText($doneTemplate);
    $phoneAskHtml = $renderText($phoneAskTemplate);
    $phoneEnterHtml = $renderText($phoneEnterTemplate);
    $phoneHintHtml = $renderText((string) $payingPages['phone_hint_label']);
    $phoneYesHtml = $renderText((string) $payingPages['phone_yes_label']);
    $phoneNoHtml = $renderText((string) $payingPages['phone_no_label']);
    $continueHtml = $renderText((string) $payingPages['continue_label']);
    $backHtml = $renderText((string) $payingPages['back_label']);
    $statusYesHtml = $renderText((string) ($statusLabels['yes'] ?? 'አዎ'));
    $statusNoHtml = $renderText((string) ($statusLabels['no'] ?? 'አይደለም'));
    $displayPhone = trim((string) ($donor['phone'] ?? ''));
}

$cssPath = url_for('paying/assets/paying.css');
$jsPath = url_for('paying/assets/paying.js');
$iconPath = url_for('assets/favicon.svg');
$pledged = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['total_pledged'] ?? 0)) : '';
$paid = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['total_paid'] ?? 0)) : '';
$remaining = $donor !== null ? CampaignPayingLink::formatMoney((float) ($donor['balance'] ?? 0)) : '';
$today = date('Y-m-d');
$progress = CampaignPayingProgress::emptyState();
$paySync = null;
if ($donor !== null && $token !== '') {
    try {
        CampaignPayingProgress::markOpened(db(), $token);
        $progress = CampaignPayingProgress::load(db(), $token);
    } catch (Throwable $e) {
        error_log('Paying progress boot failed: ' . $e->getMessage());
    }
    $paySync = [
        'token' => $token,
        'sign' => CampaignPayingProgress::sign($token),
        'saveUrl' => url_for('paying/api/save.php'),
        'uploadUrl' => url_for('paying/api/upload.php'),
        'step' => $progress['step'],
        'answers' => CampaignPayingProgress::answersForClient($progress['answers']),
        'revision' => $progress['revision'],
        'steps' => CampaignPayingProgress::STEPS,
        'phone' => trim((string) ($donor['phone'] ?? '')),
        'homeUrl' => CampaignPayingLink::SITE_HOME,
    ];
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a6286">
    <?php include __DIR__ . '/../shared/noindex.php'; ?>
    <title>እንኳን ደህና መጡ</title>
    <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/paying.css') ?: time()); ?>">
</head>
<body>
    <main class="pay-sheet">
        <header class="pay-brand">
            <p class="pay-kicker">ሊቨርፑል መካነ ቅዱሳን</p>
            <h1>አቡነ ተክለሃይማኖት</h1>
        </header>

        <?php if ($donor === null): ?>
            <p class="pay-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <section class="pay-screen is-active" data-pay-step="welcome" id="payWelcome" aria-label="እንኳን ደህና መጡ">
                <div class="pay-card pay-welcome">
                    <div class="pay-welcome-text"><?php echo $welcomeHtml; ?></div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="status" id="payStatus" hidden aria-label="የክፍያ መረጃ">
                <div class="pay-stack">
                    <div class="pay-card">
                        <?php if ($statusTitleHtml !== ''): ?>
                            <div class="pay-title"><?php echo $statusTitleHtml; ?></div>
                        <?php endif; ?>
                        <div class="pay-row">
                            <span class="pay-label"><?php echo $pledgeLabelHtml; ?></span>
                            <span class="pay-value"><?php echo htmlspecialchars($pledged, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="pay-row">
                            <span class="pay-label"><?php echo $paidLabelHtml; ?></span>
                            <span class="pay-value pay-paid"><?php echo htmlspecialchars($paid, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="pay-row pay-row-last">
                            <span class="pay-label"><?php echo $remainLabelHtml; ?></span>
                            <span class="pay-value pay-remain"><?php echo htmlspecialchars($remaining, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <?php if ($statusHtml !== ''): ?>
                            <div class="pay-footer"><?php echo $statusHtml; ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="pay-choices" role="group" aria-label="ይህ መረጃ ትክክል ነው?">
                        <button type="button" class="pay-choice" data-pay-choice="status_correct" data-pay-value="yes"><?php echo $statusYesHtml; ?></button>
                        <button type="button" class="pay-choice pay-choice-no" data-pay-choice="status_correct" data-pay-value="no"><?php echo $statusNoHtml; ?></button>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="contact" id="payContact" hidden aria-label="የመገናኛ ቀን">
                <div class="pay-stack">
                    <div class="pay-card pay-welcome" data-pay-contact-yes>
                        <div class="pay-welcome-text"><?php echo $contactMessageHtml; ?></div>
                    </div>
                    <div class="pay-card pay-welcome" data-pay-contact-callback hidden>
                        <div class="pay-welcome-text"><?php echo $callbackHtml; ?></div>
                    </div>
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $contactAskHtml; ?></div>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $contactDateLabelHtml; ?></span>
                            <input type="date" data-pay-field="contact_date" min="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $contactTimeLabelHtml; ?></span>
                            <input type="time" data-pay-field="contact_time" required>
                        </label>
                        <div class="pay-field pay-field-last">
                            <span class="pay-label"><?php echo $contactMethodLabelHtml; ?></span>
                            <div class="pay-choices" role="group" aria-label="<?php echo htmlspecialchars(strip_tags($contactMethodLabelHtml), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="pay-choice" data-pay-choice="contact_method" data-pay-value="whatsapp"><?php echo $contactWhatsappHtml; ?></button>
                                <button type="button" class="pay-choice" data-pay-choice="contact_method" data-pay-value="phone"><?php echo $contactPhoneHtml; ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="correction" id="payCorrection" hidden aria-label="እስካሁን የከፈሉት">
                <div class="pay-stack">
                    <div class="pay-card pay-welcome">
                        <div class="pay-welcome-text"><?php echo $correctionMessageHtml; ?></div>
                    </div>
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $correctionAskHtml; ?></div>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $correctionAmountLabelHtml; ?></span>
                            <input type="text" data-pay-field="reported_paid" inputmode="decimal" autocomplete="off">
                        </label>
                        <button type="button" class="pay-continue pay-continue-inline" data-pay-next data-pay-next-stay><?php echo $continueHtml; ?></button>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="pay_method" id="payMethod" hidden aria-label="እንዴት ከፍለዋል">
                <div class="pay-stack">
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $correctionAmountLabelHtml; ?></div>
                        <div class="pay-phone-display" data-pay-reported-paid></div>
                    </div>
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $correctionMethodAskHtml; ?></div>
                        <div class="pay-field pay-field-last">
                            <div class="pay-choices pay-choices-three" role="group" aria-label="<?php echo htmlspecialchars(strip_tags($correctionMethodAskHtml), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="pay-choice" data-pay-choice="paid_method" data-pay-value="cash"><?php echo $correctionCashHtml; ?></button>
                                <button type="button" class="pay-choice" data-pay-choice="paid_method" data-pay-value="bank"><?php echo $correctionCardHtml; ?></button>
                                <button type="button" class="pay-choice" data-pay-choice="paid_method" data-pay-value="mixed"><?php echo $correctionMixedHtml; ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="cash_detail" id="payCashDetail" hidden aria-label="የጥሬ ገንዘብ ዝርዝር">
                <div class="pay-stack">
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $cashRememberAskHtml; ?></div>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $cashWhenLabelHtml; ?></span>
                            <input type="date" data-pay-field="cash_when" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $cashWhomLabelHtml; ?></span>
                            <input type="text" data-pay-field="cash_whom" autocomplete="off">
                        </label>
                        <div class="pay-field pay-field-last">
                            <div class="pay-choices pay-choices-one" role="group">
                                <button type="button" class="pay-choice pay-choice-no" data-pay-choice="cash_remember" data-pay-value="no"><?php echo $cashRememberNoHtml; ?></button>
                            </div>
                        </div>
                        <button type="button" class="pay-continue pay-continue-inline" data-pay-next data-pay-next-stay><?php echo $continueHtml; ?></button>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="mixed_split" id="payMixedSplit" hidden aria-label="ድብልቅ ክፍያ">
                <div class="pay-stack">
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $correctionAmountLabelHtml; ?></div>
                        <div class="pay-phone-display" data-pay-reported-paid></div>
                    </div>
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $mixedAskHtml; ?></div>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $mixedCashLabelHtml; ?></span>
                            <input type="text" data-pay-field="mixed_cash" inputmode="decimal" autocomplete="off">
                        </label>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $mixedBankLabelHtml; ?></span>
                            <input type="text" data-pay-field="mixed_bank" inputmode="decimal" autocomplete="off">
                        </label>
                        <button type="button" class="pay-continue pay-continue-inline" data-pay-next data-pay-next-stay><?php echo $continueHtml; ?></button>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="bank_proof" id="payBankProof" hidden aria-label="ስክሪንሹት">
                <div class="pay-stack">
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $proofAskHtml; ?></div>
                        <div class="pay-field pay-field-last">
                            <div class="pay-choices" role="group" aria-label="<?php echo htmlspecialchars(strip_tags($proofAskHtml), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="pay-choice" data-pay-choice="send_proof" data-pay-value="yes"><?php echo $proofYesHtml; ?></button>
                                <button type="button" class="pay-choice pay-choice-no" data-pay-choice="send_proof" data-pay-value="no"><?php echo $proofNoHtml; ?></button>
                            </div>
                        </div>
                    </div>
                    <div class="pay-card" data-pay-proof-entry hidden>
                        <div class="pay-title"><?php echo $proofAttachHtml; ?></div>
                        <label class="pay-field pay-field-last">
                            <span class="pay-label"><?php echo $proofAttachHtml; ?></span>
                            <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-pay-proof>
                            <span class="pay-proof-name" data-pay-proof-name></span>
                        </label>
                        <button type="button" class="pay-continue pay-continue-inline" data-pay-next data-pay-next-stay><?php echo $continueHtml; ?></button>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="bank_date" id="payBankDate" hidden aria-label="የክፍያ ቀን">
                <div class="pay-stack">
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $paidDateAskHtml; ?></div>
                        <label class="pay-field">
                            <span class="pay-label"><?php echo $paidDateAskHtml; ?></span>
                            <input type="date" data-pay-field="paid_date" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <div class="pay-field pay-field-last">
                            <div class="pay-choices pay-choices-one" role="group">
                                <button type="button" class="pay-choice pay-choice-no" data-pay-choice="paid_remember" data-pay-value="no"><?php echo $paidRememberNoHtml; ?></button>
                            </div>
                        </div>
                        <button type="button" class="pay-continue pay-continue-inline" data-pay-next data-pay-next-stay><?php echo $continueHtml; ?></button>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="phone" id="payPhone" hidden aria-label="ስልክ ቁጥር">
                <div class="pay-stack">
                    <div class="pay-card">
                        <div class="pay-title"><?php echo $phoneAskHtml; ?></div>
                        <?php if ($displayPhone !== ''): ?>
                            <div class="pay-phone-display"><?php echo htmlspecialchars($displayPhone, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <div class="pay-field pay-field-last">
                            <div class="pay-choices" role="group" aria-label="<?php echo htmlspecialchars(strip_tags($phoneAskHtml), ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="button" class="pay-choice" data-pay-choice="phone_correct" data-pay-value="yes"><?php echo $phoneYesHtml; ?></button>
                                <button type="button" class="pay-choice pay-choice-no" data-pay-choice="phone_correct" data-pay-value="no"><?php echo $phoneNoHtml; ?></button>
                            </div>
                        </div>
                    </div>
                    <div class="pay-card" data-pay-phone-entry hidden>
                        <div class="pay-title"><?php echo $phoneEnterHtml; ?></div>
                        <label class="pay-field pay-field-last">
                            <span class="pay-label"><?php echo $phoneHintHtml; ?></span>
                            <input type="tel" data-pay-field="contact_phone" inputmode="tel" autocomplete="tel" placeholder="<?php echo htmlspecialchars(strip_tags($phoneHintHtml), ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                    </div>
                </div>
            </section>

            <section class="pay-screen" data-pay-step="done" id="payDone" hidden aria-label="እናመሰግናለን">
                <div class="pay-card pay-welcome" data-pay-done="booking">
                    <div class="pay-welcome-text"><?php echo $doneHtml; ?></div>
                </div>
                <div class="pay-card pay-welcome" data-pay-done="thanks" hidden>
                    <div class="pay-welcome-text"><?php echo $thanksHtml; ?></div>
                </div>
            </section>

            <div class="pay-actions">
                <button type="button" class="pay-continue" data-pay-next><?php echo $continueHtml; ?></button>
                <button type="button" class="pay-back" data-pay-back hidden><?php echo $backHtml; ?></button>
            </div>
        <?php endif; ?>
    </main>
    <?php if ($paySync !== null): ?>
        <script>
        window.PAY_SYNC = <?php echo json_encode(
            $paySync,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ); ?>;
        </script>
        <script src="<?php echo htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int) (@filemtime(__DIR__ . '/assets/paying.js') ?: time()); ?>"></script>
    <?php endif; ?>
</body>
</html>
