<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';
require_once __DIR__ . '/../../shared/CampaignPayingReport.php';

$page_title = 'Still paying — Activity';
$donorId = (int) ($_GET['id'] ?? 0);
$activity = null;
$error = '';
try {
    $activity = CampaignPayingReport::findDonor(db(), $donorId);
} catch (Throwable $e) {
    error_log('Paying activity page failed: ' . $e->getMessage());
    $error = 'Could not load this donor’s activity.';
}
if ($activity === null && $error === '') {
    $error = 'This donor is not in the still-paying report.';
}

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$dash = static function (string $value) use ($h): string {
    return $value !== '' ? $h($value) : '—';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($page_title); ?> - Fundraising System</title>
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
                            <i class="fas fa-user-clock me-2 dvc-title-icon paying"></i>
                            <?php echo $activity !== null ? $h((string) $activity['name']) : 'Donor activity'; ?>
                        </h1>
                        <p>Everything this donor has done on the still-paying link.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($activity !== null && (int) $activity['donor_id'] > 0): ?>
                            <a class="btn btn-outline-primary" href="../donor-management/view-donor.php?id=<?php echo (int) $activity['donor_id']; ?>">
                                <i class="fas fa-id-card me-1"></i>Donor record
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-outline-secondary" href="pledge-paying-report.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to report
                        </a>
                    </div>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo $h($error); ?>
                    </div>
                <?php else: ?>
                    <?php
                    $yesNo = (string) $activity['answer_label'];
                    $statusChips = [
                        ['label' => 'Link sent', 'ok' => !empty($activity['sent']), 'detail' => (string) $activity['sent_label']],
                        ['label' => 'Opened', 'ok' => !empty($activity['opened']), 'detail' => (string) $activity['opened_label']],
                        ['label' => 'Answered', 'ok' => !empty($activity['answered']), 'detail' => $yesNo],
                        ['label' => 'Booked', 'ok' => !empty($activity['booked']), 'detail' => (string) $activity['booking_label']],
                    ];
                    ?>
                    <div class="dvc-stat-row dvc-report-kpis animate-fade-in">
                        <?php foreach ($statusChips as $chip): ?>
                            <div class="dvc-stat-chip<?php echo $chip['ok'] ? ' active' : ''; ?>">
                                <div class="dvc-stat-icon <?php echo $chip['ok'] ? 'completed' : 'not-started'; ?>">
                                    <i class="fas <?php echo $chip['ok'] ? 'fa-check' : 'fa-minus'; ?>"></i>
                                </div>
                                <div>
                                    <div class="dvc-stat-value"><?php echo $chip['ok'] ? 'Yes' : 'No'; ?></div>
                                    <div class="dvc-stat-label"><?php echo $h($chip['label']); ?></div>
                                    <?php if ($chip['detail'] !== '' && $chip['detail'] !== 'Not answered'): ?>
                                        <div class="dvc-stat-meta"><?php echo $h($chip['detail']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <div class="dvc-settings-card">
                                <div class="dvc-settings-head">
                                    <div>
                                        <h6>Donor</h6>
                                        <p>Who this paying link belongs to.</p>
                                    </div>
                                </div>
                                <div class="dvc-settings-body">
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Name</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['name']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Phone</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['phone']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Reference</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['reference']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Pledged</div>
                                        <div class="dvc-detail-value"><?php echo $h((string) $activity['pledged_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Paid</div>
                                        <div class="dvc-detail-value"><?php echo $h((string) $activity['paid_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Remaining</div>
                                        <div class="dvc-detail-value"><?php echo $h((string) $activity['balance_label']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="dvc-settings-card">
                                <div class="dvc-settings-head">
                                    <div>
                                        <h6>Paying link</h6>
                                        <p>Where they are on the page, and when they used it.</p>
                                    </div>
                                </div>
                                <div class="dvc-settings-body">
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Current page</div>
                                        <div class="dvc-detail-value"><?php echo $h((string) $activity['step_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Link sent</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['sent_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Opened</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['opened_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Last saved</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['saved_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Saves</div>
                                        <div class="dvc-detail-value"><?php echo (int) $activity['revision']; ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Link</div>
                                        <div class="dvc-detail-value">
                                            <?php if ((string) $activity['paying_url'] !== ''): ?>
                                                <a href="<?php echo $h((string) $activity['paying_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php echo $h((string) $activity['paying_url']); ?>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="dvc-settings-card">
                                <div class="dvc-settings-head">
                                    <div>
                                        <h6>Answers</h6>
                                        <p>What they told us on the paying form.</p>
                                    </div>
                                </div>
                                <div class="dvc-settings-body">
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Amounts correct</div>
                                        <div class="dvc-detail-value"><?php echo $h((string) $activity['answer_label']); ?></div>
                                    </div>
                                    <?php if (($activity['answer'] ?? '') === 'no'): ?>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Recorded paid</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) ($activity['paid_label'] ?? '')); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Corrected paid</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) ($activity['reported_paid_label'] ?? '')); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">How they paid</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) ($activity['paid_method_label'] ?? '')); ?></div>
                                    </div>
                                    <?php else: ?>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Call date</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) ($activity['contact_date'] ?? '')); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Call time</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) ($activity['contact_time'] ?? '')); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Call method</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['method_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Booked slot</div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) $activity['booking_label']); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Call status</div>
                                        <div class="dvc-detail-value">
                                            <?php if (!empty($activity['booked'])): ?>
                                                <?php $callStatus = (string) ($activity['call_status'] ?? 'pending'); ?>
                                                <select
                                                    class="form-select form-select-sm dvc-call-status ms-auto"
                                                    data-call-status-select
                                                    data-donor-id="<?php echo (int) $activity['donor_id']; ?>"
                                                    data-status="<?php echo $h($callStatus); ?>"
                                                    aria-label="Call status"
                                                >
                                                    <option value="pending"<?php echo $callStatus === 'pending' ? ' selected' : ''; ?>>Pending</option>
                                                    <option value="contacted"<?php echo $callStatus === 'contacted' ? ' selected' : ''; ?>>Contacted</option>
                                                    <option value="not_answering"<?php echo $callStatus === 'not_answering' ? ' selected' : ''; ?>>Not answering</option>
                                                </select>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label"><?php echo !empty($activity['phone_corrected']) ? 'Corrected phone' : 'Number to call'; ?></div>
                                        <div class="dvc-detail-value"><?php echo $dash((string) ($activity['call_phone'] ?? $activity['contact_phone'] ?? '')); ?></div>
                                    </div>
                                    <div class="dvc-detail-row">
                                        <div class="dvc-detail-label">Phone check</div>
                                        <div class="dvc-detail-value"><?php echo $h((string) ($activity['phone_correct_label'] ?? 'Not confirmed')); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="dvc-settings-card">
                                <div class="dvc-settings-head">
                                    <div>
                                        <h6>Activity</h6>
                                        <p>Times we have for this donor, oldest first.</p>
                                    </div>
                                </div>
                                <div class="dvc-settings-body">
                                    <?php if ($activity['timeline'] === []): ?>
                                        <p class="text-muted mb-0">No link activity yet.</p>
                                    <?php else: ?>
                                        <ol class="dvc-timeline">
                                            <?php foreach ($activity['timeline'] as $event): ?>
                                                <li>
                                                    <div class="dvc-timeline-label"><?php echo $h((string) $event['label']); ?></div>
                                                    <div class="dvc-timeline-when"><?php echo $h((string) $event['when_label']); ?></div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<?php if ($activity !== null && $error === ''): ?>
<script>
window.PAY_REPORT = <?php echo json_encode(['csrf' => $csrfToken], JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/paying-call-status.js?v=<?php echo (int) (filemtime(__DIR__ . '/assets/paying-call-status.js') ?: time()); ?>"></script>
<?php endif; ?>
</body>
</html>
