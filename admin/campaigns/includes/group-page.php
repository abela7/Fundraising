<?php

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/group-config.php';
require_once __DIR__ . '/../../../shared/DonorCampaignGroups.php';

require_login();
require_admin();

$dvc_group = isset($dvc_group) && is_string($dvc_group) ? $dvc_group : DonorCampaignGroups::PLEDGE_NOT_STARTED;
if (!DonorCampaignGroups::isValid($dvc_group)) {
    $dvc_group = DonorCampaignGroups::PLEDGE_NOT_STARTED;
}

$meta = dvc_campaign_group_meta($dvc_group);
$db_error_message = '';
$settings = ['currency_code' => 'GBP'];

try {
    $db = db();
    $settingsTable = $db->query("SHOW TABLES LIKE 'settings'");
    if ($settingsTable && $settingsTable->num_rows > 0) {
        $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string)$row['currency_code'];
        }
    }
} catch (Exception $e) {
    $db_error_message = 'Database connection failed.';
}

$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$page_title = (string)$meta['title'];
$tone = htmlspecialchars((string)$meta['tone'], ENT_QUOTES, 'UTF-8');
$icon = htmlspecialchars((string)$meta['icon'], ENT_QUOTES, 'UTF-8');
$title = htmlspecialchars((string)$meta['title'], ENT_QUOTES, 'UTF-8');
$description = htmlspecialchars((string)$meta['description'], ENT_QUOTES, 'UTF-8');
$amountLabel = htmlspecialchars((string)$meta['amount_label'], ENT_QUOTES, 'UTF-8');
$isPledgeFamily = ($meta['family'] ?? '') === 'pledge';
$isPayingCampaign = ($dvc_group === DonorCampaignGroups::PLEDGE_PAYING);
$pageConfig = [
    'group' => $dvc_group,
    'amount_key' => (string)$meta['amount_key'],
    'sort_by' => (string)$meta['sort_by'],
    'sort_order' => (string)$meta['sort_order'],
    'currency' => $settings['currency_code'] ?? 'GBP',
    'campaign' => false,
];
$cssVersion = (int) (filemtime(__DIR__ . '/../assets/campaigns.css') ?: time());
if ($isPayingCampaign) {
    require_once __DIR__ . '/paying-nav.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/campaigns.css?v=<?php echo $cssVersion; ?>">
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include __DIR__ . '/../../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <?php if ($db_error_message !== ''): ?>
                    <div class="alert alert-danger mb-3" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($db_error_message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="dvc-page-header animate-fade-in">
                    <div>
                        <h1>
                            <i class="fas <?php echo $icon; ?> me-2 dvc-title-icon <?php echo $tone; ?>"></i>
                            <?php echo $title; ?>
                        </h1>
                        <p><?php echo $description; ?></p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($isPayingCampaign): ?>
                            <a class="btn btn-outline-primary" href="pledge-paying-settings.php">
                                <i class="fas fa-sliders me-1"></i>Settings
                            </a>
                            <a class="btn btn-primary" href="pledge-paying-send.php">
                                <i class="fas fa-paper-plane me-1"></i>Send
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-outline-secondary" href="index.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to Campaign
                        </a>
                    </div>
                </div>

                <?php if ($isPledgeFamily): ?>
                    <div class="dvc-sibling-nav animate-fade-in" aria-label="Pledge groups">
                        <?php foreach (dvc_pledge_group_nav() as $navItem): ?>
                            <?php
                            $navFile = htmlspecialchars((string)$navItem['file'], ENT_QUOTES, 'UTF-8');
                            $navShort = htmlspecialchars((string)$navItem['short'], ENT_QUOTES, 'UTF-8');
                            $navActive = ((string)$navItem['file'] === (string)$meta['file']);
                            ?>
                            <a href="<?php echo $navFile; ?>" class="<?php echo $navActive ? 'active' : ''; ?>">
                                <?php echo $navShort; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isPayingCampaign): ?>
                    <?php dvc_paying_nav('list'); ?>
                <?php endif; ?>

                <div class="dvc-hero-kpis animate-fade-in">
                    <div class="dvc-hero-kpi">
                        <div class="dvc-stat-icon <?php echo $tone; ?>"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="dvc-hero-value" id="kpiDonors">—</div>
                            <div class="dvc-stat-label">Number of donors</div>
                        </div>
                    </div>
                    <div class="dvc-hero-kpi dvc-hero-kpi-amount <?php echo $tone; ?>">
                        <div class="dvc-stat-icon <?php echo $tone; ?>"><i class="fas fa-coins"></i></div>
                        <div>
                            <div class="dvc-hero-value" id="kpiAmount">—</div>
                            <div class="dvc-stat-label"><?php echo $amountLabel; ?></div>
                        </div>
                    </div>
                </div>

                <div class="dvc-stat-row dvc-kpi-secondary animate-fade-in">
                    <div class="dvc-stat-chip">
                        <div class="dvc-stat-icon paying"><i class="fas fa-hand-holding-heart"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiPledged">—</div>
                            <div class="dvc-stat-label">Pledged</div>
                        </div>
                    </div>
                    <div class="dvc-stat-chip">
                        <div class="dvc-stat-icon completed"><i class="fas fa-check-circle"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiPaid">—</div>
                            <div class="dvc-stat-label">Paid</div>
                        </div>
                    </div>
                    <div class="dvc-stat-chip">
                            <div class="dvc-stat-icon not-started"><i class="fas fa-hourglass-half"></i></div>
                        <div>
                            <div class="dvc-stat-value" id="kpiRemaining">—</div>
                            <div class="dvc-stat-label">Remaining</div>
                        </div>
                    </div>
                </div>

                <div class="dvc-filter-bar">
                    <div class="form-label mb-2"><i class="fas fa-filter me-1"></i>Filters</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="filterDonor">Donor (name, phone, reference)</label>
                            <input type="text" class="form-control form-control-sm" id="filterDonor" placeholder="Search...">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="filterSource">Data source</label>
                            <select class="form-select form-select-sm" id="filterSource">
                                <option value="">All (old and new)</option>
                                <option value="old_system">Old system (imported)</option>
                                <option value="new_system">New system</option>
                            </select>
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
                        <h6><i class="fas fa-users me-2" style="color: var(--primary);"></i><?php echo $title; ?></h6>
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0" for="perPage">Per page</label>
                            <select class="form-select form-select-sm" id="perPage" style="width: auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="dvc-col-num">#</th>
                                    <th class="dvc-sortable" data-sort-by="name">Donor<span class="dvc-sort-icon"></span></th>
                                    <th>Reference</th>
                                    <th class="dvc-sortable" data-sort-by="source">Source<span class="dvc-sort-icon"></span></th>
                                    <th class="text-end dvc-sortable" data-sort-by="pledged">Pledged<span class="dvc-sort-icon"></span></th>
                                    <th class="text-end dvc-sortable" data-sort-by="paid">Paid<span class="dvc-sort-icon"></span></th>
                                    <th class="text-end dvc-sortable" data-sort-by="balance">Remaining<span class="dvc-sort-icon"></span></th>
                                </tr>
                            </thead>
                            <tbody id="dataBody">
                                <tr>
                                    <td colspan="7" class="text-center py-4" style="color: var(--gray-500);">
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
window.DVC_PAGE = <?php echo json_encode($pageConfig, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/group-page.js"></script>
</body>
</html>
