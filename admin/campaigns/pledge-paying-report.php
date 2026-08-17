<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';
require_once __DIR__ . '/../../shared/CampaignPayingReport.php';

$page_title = 'Still paying — Report';
$jsVersion = (int) max(
    filemtime(__DIR__ . '/assets/paying-report.js') ?: time(),
    filemtime(__DIR__ . '/assets/paying-call-status.js') ?: time()
);
$reportFilter = CampaignPayingReport::sanitizeFilter((string) ($_GET['filter'] ?? CampaignPayingReport::FILTER_ALL));
$reportPage = max(1, (int) ($_GET['page'] ?? 1));
$reportPerPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$reportDonor = trim((string) ($_GET['donor'] ?? ''));
$callFiltersOpen = CampaignPayingReport::isCallFilter($reportFilter);
$chipActive = static function (string $key) use ($reportFilter, $callFiltersOpen): bool {
    if ($key === CampaignPayingReport::FILTER_BOOKED && $callFiltersOpen) {
        return true;
    }
    if (in_array($key, [
        CampaignPayingReport::FILTER_PENDING,
        CampaignPayingReport::FILTER_CONTACTED,
        CampaignPayingReport::FILTER_NOT_ANSWERING,
    ], true)) {
        return $reportFilter === $key;
    }

    return $reportFilter === $key;
};
$chipClass = static function (string $key) use ($chipActive): string {
    return $chipActive($key) ? 'dvc-stat-chip active' : 'dvc-stat-chip';
};
$chipPressed = static function (string $key) use ($chipActive): string {
    return $chipActive($key) ? 'true' : 'false';
};
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
                            <i class="fas fa-chart-column me-2 dvc-title-icon paying"></i>
                            Paying link report
                        </h1>
                        <p>See who opened the still-paying link, who answered, and who booked a call. After a booking, mark Pending, Contacted, or Not answering. Tap a name for their full activity.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="pledge-paying.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to donors
                        </a>
                    </div>
                </div>

                <div class="dvc-stat-row dvc-report-kpis animate-fade-in" role="tablist" aria-label="Report filters">
                    <button type="button" class="<?php echo $chipClass('all'); ?>" data-filter="all" aria-pressed="<?php echo $chipPressed('all'); ?>">
                        <div class="dvc-stat-icon paying"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiDonors">—</div>
                            <div class="dvc-stat-label">Still paying</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('sent'); ?>" data-filter="sent" aria-pressed="<?php echo $chipPressed('sent'); ?>">
                        <div class="dvc-stat-icon completed"><i class="fas fa-paper-plane"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiSent">—</div>
                            <div class="dvc-stat-label">Link sent</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('opened'); ?>" data-filter="opened" aria-pressed="<?php echo $chipPressed('opened'); ?>">
                        <div class="dvc-stat-icon paying"><i class="fas fa-envelope-open"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiOpened">—</div>
                            <div class="dvc-stat-label">Opened</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('not_opened'); ?>" data-filter="not_opened" aria-pressed="<?php echo $chipPressed('not_opened'); ?>">
                        <div class="dvc-stat-icon not-started"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiNotOpened">—</div>
                            <div class="dvc-stat-label">Sent, not opened</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('answered'); ?>" data-filter="answered" aria-pressed="<?php echo $chipPressed('answered'); ?>">
                        <div class="dvc-stat-icon paying"><i class="fas fa-clipboard-check"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiAnswered">—</div>
                            <div class="dvc-stat-label">Answered</div>
                            <div class="dvc-stat-meta" id="kpiAnsweredMeta">—</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('booked'); ?>" data-filter="booked" aria-pressed="<?php echo $chipPressed('booked'); ?>">
                        <div class="dvc-stat-icon completed"><i class="fas fa-calendar-check"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiBooked">—</div>
                            <div class="dvc-stat-label">Booked a time</div>
                        </div>
                    </button>
                </div>
                <div class="dvc-stat-row dvc-report-kpis dvc-call-status-kpis<?php echo $callFiltersOpen ? ' is-open' : ''; ?>" id="callStatusFilters" aria-label="Booked call status">
                    <button type="button" class="<?php echo $chipClass('pending'); ?>" data-filter="pending" aria-pressed="<?php echo $chipPressed('pending'); ?>">
                        <div class="dvc-stat-icon not-started"><i class="fas fa-clock"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiPending">—</div>
                            <div class="dvc-stat-label">Pending</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('contacted'); ?>" data-filter="contacted" aria-pressed="<?php echo $chipPressed('contacted'); ?>">
                        <div class="dvc-stat-icon completed"><i class="fas fa-phone"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiContacted">—</div>
                            <div class="dvc-stat-label">Contacted</div>
                        </div>
                    </button>
                    <button type="button" class="<?php echo $chipClass('not_answering'); ?>" data-filter="not_answering" aria-pressed="<?php echo $chipPressed('not_answering'); ?>">
                        <div class="dvc-stat-icon review"><i class="fas fa-phone-slash"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiNotAnswering">—</div>
                            <div class="dvc-stat-label">Not answering</div>
                        </div>
                    </button>
                </div>

                <div class="dvc-filter-bar">
                    <div class="form-label mb-2"><i class="fas fa-filter me-1"></i>Find a donor</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label" for="filterDonor">Donor (name, phone, reference)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search..." value="<?php echo htmlspecialchars($reportDonor, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill" type="button" id="applyFilters">
                                <i class="fas fa-search me-1"></i>Apply
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="clearFilters">
                                <i class="fas fa-times me-1"></i>Clear
                            </button>
                        </div>
                    </div>
                </div>

                <div class="dvc-data-card">
                    <div class="dvc-table-header">
                        <h6><i class="fas fa-list me-2" style="color: var(--primary);"></i>Donor progress</h6>
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0" for="perPage">Per page</label>
                            <select class="form-select form-select-sm" id="perPage" style="width: auto;">
                                <option value="10"<?php echo $reportPerPage === 10 ? ' selected' : ''; ?>>10</option>
                                <option value="25"<?php echo $reportPerPage === 25 ? ' selected' : ''; ?>>25</option>
                                <option value="50"<?php echo $reportPerPage === 50 ? ' selected' : ''; ?>>50</option>
                                <option value="100"<?php echo $reportPerPage === 100 ? ' selected' : ''; ?>>100</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="dvc-col-num">#</th>
                                    <th>Donor</th>
                                    <th>Opened</th>
                                    <th>Answer</th>
                                    <th>Booked time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="dataBody">
                                <tr>
                                    <td colspan="6" class="text-center py-4" style="color: var(--gray-500);">
                                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                        Loading...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="dvc-pagination-wrapper">
                        <div class="dvc-pagination-info" id="paginationInfo">—</div>
                        <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
window.PAY_REPORT = <?php echo json_encode([
    'csrf' => $csrfToken,
    'filter' => $reportFilter,
    'page' => $reportPage,
    'per_page' => $reportPerPage,
    'donor' => $reportDonor,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="assets/paying-call-status.js?v=<?php echo $jsVersion; ?>"></script>
<script src="assets/paying-report.js?v=<?php echo $jsVersion; ?>"></script>
</body>
</html>
