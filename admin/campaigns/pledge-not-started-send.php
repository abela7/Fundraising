<?php

declare(strict_types=1);

$dvc_campaign_group = 'pledge_not_started';
require_once __DIR__ . '/includes/paying-boot.php';
require_once __DIR__ . '/../../services/UltraMsgService.php';
require_once __DIR__ . '/../../shared/CampaignFirstMessageSend.php';

$page_title = 'Not started — Send';
$savedMessage = trim((string) $campaignSettings['first_message']);
$whatsappReady = false;
try {
    $whatsappReady = UltraMsgService::fromDatabase(db()) !== null;
} catch (Throwable $e) {
    $whatsappReady = false;
}
$pageConfig = [
    'group' => DonorCampaignGroups::PLEDGE_NOT_STARTED,
    'amount_key' => 'pledged',
    'sort_by' => 'pledged',
    'sort_order' => 'desc',
    'currency' => 'GBP',
    'campaign' => true,
    'csrf' => $csrfToken,
    'has_message' => $savedMessage !== '',
    'whatsapp_ready' => $whatsappReady,
    'batch_limit' => CampaignFirstMessageSend::BATCH_LIMIT,
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
        <main class="main-content dvc-send-page">
            <div class="container-fluid">
                <div class="dvc-page-header animate-fade-in">
                    <div>
                        <h1>
                            <i class="fab fa-whatsapp me-2" style="color: var(--success);"></i>
                            Send first message
                        </h1>
                        <p>Tick the not-started donors, then send the saved WhatsApp hello.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="pledge-not-started.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to donors
                        </a>
                    </div>
                </div>

                <?php if ($savedMessage === ''): ?>
                    <div class="alert alert-warning" role="alert">
                        Write and save a first message in
                        <a href="pledge-not-started-first-message.php" class="alert-link">Settings</a>
                        before sending.
                    </div>
                <?php endif; ?>

                <?php if (!$whatsappReady): ?>
                    <div class="alert alert-warning" role="alert">
                        WhatsApp is not configured yet. Messages cannot be sent until UltraMsg is set up.
                    </div>
                <?php endif; ?>

                <div class="dvc-settings-card animate-fade-in">
                    <div class="dvc-settings-head">
                        <div>
                            <h6>Message</h6>
                            <p>Each donor gets their own name and amounts filled in.</p>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="pledge-not-started-first-message.php">Edit message</a>
                    </div>
                    <div class="dvc-settings-body">
                        <?php if ($savedMessage !== ''): ?>
                            <div class="dvc-preview-bubble dvc-am-text"><?php echo htmlspecialchars($savedMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php else: ?>
                            <p class="dvc-muted mb-0">No first message saved yet.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="dvcMsgFlash" class="alert d-none" role="status"></div>
                <div id="dvcSendResult" class="dvc-send-result d-none" role="status"></div>

                <div class="dvc-filter-bar">
                    <div class="form-label mb-2"><i class="fas fa-filter me-1"></i>Find donors</div>
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
                            <button class="btn btn-primary btn-sm flex-fill" type="button" id="applyFilters">Apply</button>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="clearFilters">Clear</button>
                        </div>
                    </div>
                </div>

                <div class="dvc-data-card">
                    <div class="dvc-table-header">
                        <h6>Choose recipients</h6>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="dvcSelectPage">Select this page</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="dvcSelectAll">Select all not-started</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="dvcClearSelected">Clear</button>
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
                                    <th class="dvc-col-check">
                                        <input type="checkbox" id="dvcCheckPage" title="Select this page">
                                    </th>
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
                                    <td colspan="8" class="text-center py-4" style="color: var(--gray-500);">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="dvc-pagination-wrapper">
                        <div class="dvc-pagination-info" id="paginationInfo">—</div>
                        <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
                    </div>
                </div>

                <div class="dvc-send-dock" id="dvcSendDock">
                    <div>
                        <div class="dvc-send-count" id="dvcSelectCount">0 selected</div>
                        <div class="dvc-send-meta" id="dvcSendMeta">Tick people in the table, then send.</div>
                        <div class="progress dvc-send-progress d-none" id="dvcSendProgress">
                            <div class="progress-bar" id="dvcSendProgressBar" role="progressbar" style="width: 0%"></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-success" id="dvcSendNow" disabled>
                        <i class="fab fa-whatsapp me-1"></i>Send on WhatsApp
                    </button>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="modal fade" id="dvcSendModal" tabindex="-1" aria-labelledby="dvcSendModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dvcSendModalTitle">Send WhatsApp messages?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="dvcSendModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="dvcConfirmSend">
                    <i class="fab fa-whatsapp me-1"></i>Send now
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
window.DVC_PAGE = <?php echo json_encode($pageConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="assets/paying-send.js"></script>
<script src="assets/group-page.js"></script>
</body>
</html>
