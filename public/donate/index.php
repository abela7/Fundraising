<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';

$success = '';
$error = '';

$name = '';
$phone = '';
$message = '';
$submissionTimestamp = (string)time();

/**
 * Normalize a phone number into a safe storage format.
 * Keeps leading + and digits only.
 */
$normalizePhone = static function (string $rawPhone): string {
    $clean = preg_replace('/[^0-9+]/', '', trim($rawPhone));
    if ($clean === null) {
        return '';
    }

    if (str_starts_with($clean, '00')) {
        $clean = '+' . substr($clean, 2);
    }

    if ($clean !== '' && str_starts_with($clean, '0') && str_starts_with($clean, '07')) {
        return $clean;
    }

    if ($clean !== '' && str_starts_with($clean, '+')) {
        return $clean;
    }

    return preg_replace('/\D/', '', $clean) ?? '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = db();
        verify_csrf();

        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $formLoadedAt = (int)($_POST['form_loaded_at'] ?? 0);

        $honeypotFields = ['website', 'email_check', 'company', 'address_2'];
        foreach ($honeypotFields as $honeypot) {
            if (!empty($_POST[$honeypot] ?? '')) {
                $error = 'Unable to process submission.';
                break;
            }
        }

        if ($error === '') {
            if ($name === '') {
                $error = 'Please provide your name.';
            } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                $error = 'Name must be between 2 and 120 characters.';
            } elseif ($phone === '') {
                $error = 'Please provide a valid phone number.';
            } else {
                $normalizedPhone = $normalizePhone($phone);
                if (!preg_match('/^[+]?\d{7,20}$/', $normalizedPhone)) {
                    $error = 'Please provide a valid phone number (7 to 20 digits).';
                } else {
                    $phone = $normalizedPhone;
                }
            }

            if ($message === '') {
                $message = null;
            } elseif (mb_strlen($message) > 2000) {
                $error = 'Message is too long. Keep it under 2,000 characters.';
            }

            if ($formLoadedAt > 0 && (time() - $formLoadedAt) < 2) {
                // Lightweight anti-bot timing guard.
                $error = 'Please take a moment to complete the form before sending.';
            }
        }

        if ($error === '') {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

            if ($error === '') {
                $tableCheck = $db->query("SHOW TABLES LIKE 'public_donation_requests'");
                if (!$tableCheck || $tableCheck->num_rows === 0) {
                    $error = 'Public donation requests table is not configured. Please create it in the database before accepting submissions.';
                }
            }

            if ($error === '') {
                $sourcePage = '/public/donate';
                $sourceUrl = $_SERVER['REQUEST_URI'] ?? '';
                $referrer = $_SERVER['HTTP_REFERER'] ?? null;
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

                $sourcePage = mb_substr($sourcePage, 0, 255);
                $sourceUrl = mb_substr($sourceUrl, 0, 500);
                $referrer = $referrer !== null ? mb_substr($referrer, 0, 500) : null;
                $userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 512) : null;
                $ipAddress = mb_substr($clientIp, 0, 45);

                $stmt = $db->prepare('INSERT INTO public_donation_requests
                    (full_name, phone_number, message, status, source_page, source_url, referrer_url, ip_address, user_agent)
                    VALUES (?, ?, ?, "new", ?, ?, ?, ?, ?)');
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare submission query.');
                }

                $stmt->bind_param('ssssssss', $name, $phone, $message, $sourcePage, $sourceUrl, $referrer, $ipAddress, $userAgent);
                $stmt->execute();

                $success = 'Thank you for reaching out. We have received your request and will call and reach you shortly.';
                $name = '';
                $phone = '';
                $message = '';
                $submissionTimestamp = (string)time();
            }
        }
    } catch (Throwable $e) {
        error_log('Public donate submission failed: ' . $e->getMessage());
        if ($error === '') {
            $error = 'Something went wrong. Please try again or contact support.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/../../shared/noindex.php'; ?>
    <title>Contact Support - Church Fundraising</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donate.css?v=<?php echo @filemtime(__DIR__ . '/assets/donate.css'); ?>">
</head>
<body>
    <div class="app-wrapper">
        <div class="app-content">
            <main class="main-content">
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-phone-alt me-2"></i>
                        Contact Church Support
                    </h1>
                    <p class="text-muted">Leave your details and we will call you shortly</p>
                </div>

                <div class="instructions-hero mb-4">
                    <div class="container-fluid px-0">
                        <div class="row g-0">
                            <div class="col-12">
                                <div class="hero-card">
                                    <div class="hero-header">
                                        <div class="hero-icon">
                                            <i class="fas fa-headset"></i>
                                        </div>
                                        <h2 class="hero-title">Need to Reach Out?</h2>
                                        <p class="hero-subtitle">Send us your contact details and message and our team will call you.</p>
                                    </div>

                                    <div class="hero-footer">
                                        <div class="contact-notice">
                                            <i class="fas fa-clock"></i>
                                            <span><strong>We'll contact you shortly</strong> after your request is submitted.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success !== ''): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form id="publicDonationForm" method="POST" class="registration-form" novalidate>
                        <?php echo csrf_input(); ?>
                        <input type="hidden" id="form_loaded_at" name="form_loaded_at" value="<?php echo htmlspecialchars($submissionTimestamp, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="form-section">
                            <h3 class="form-section-title">
                                <i class="fas fa-user"></i>
                                Contact Information
                            </h3>

                            <div class="mb-3">
                                <label for="name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="name" name="name" maxlength="120" required
                                       placeholder="Enter full name" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" maxlength="25" required
                                       placeholder="e.g. +1234567890 or 0712345678" value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="message" class="form-label">Message (optional)</label>
                                <textarea class="form-control" id="message" name="message" rows="5" maxlength="2000" placeholder="Share how we can help you."><?php echo htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                <div class="form-text">You can include the amount you want to pledge or any additional note here.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit" data-submit-btn>
                            <i class="fas fa-paper-plane"></i>
                            Submit Request
                        </button>

                        <div class="d-none" aria-hidden="true">
                            <input type="text" name="website" autocomplete="off" tabindex="-1">
                            <input type="email" name="email_check" autocomplete="off" tabindex="-1">
                            <input type="text" name="company" autocomplete="off" tabindex="-1">
                            <input type="text" name="address_2" autocomplete="off" tabindex="-1">
                        </div>
                    </form>
                </div>

                <div class="navigation-section">
                    <div class="nav-buttons">
                        <a href="../projector/" class="nav-btn btn-projector">
                            <i class="fas fa-tv me-2"></i>
                            <span class="btn-text">Donation status</span>
                        </a>

                        <a href="../projector/floor/" class="nav-btn btn-floor">
                            <i class="fas fa-map me-2"></i>
                            <span class="btn-text">The Church's Floor Map</span>
                        </a>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/donate.js"></script>
</body>
</html>
