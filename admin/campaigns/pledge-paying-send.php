<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/paying-boot.php';

$page_title = 'Still paying — Send';
$savedMessage = (string) $campaignSettings['first_message'];
$recipientMode = (string) $campaignSettings['recipient_mode'];
$modeAll = $recipientMode !== CampaignGroupSettings::MODE_SELECTED;
$pageConfig = [
    'group' => DonorCampaignGroups::PLEDGE_PAYING,
    'amount_key' => 'remaining',
    'sort_by' => 'balance',
    'sort_order' => 'desc',
    'currency' => 'GBP',
    'campaign' => true,
    'csrf' => $csrfToken,
    'recipient_mode' => $recipientMode,
    'donor_ids' => $campaignSettings['donor_ids'],
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
                            <i class="fas fa-paper-plane me-2 dvc-title-icon paying"></i>
                            Send first message
                        </h1>
                        <p>Choose still-paying pledge donors. Saving the list does not send WhatsApp yet.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-secondary" href="pledge-paying-first-message.php">Edit message</a>
                        <a class="btn btn-outline-secondary" href="pledge-paying.php">Back to donors</a>
                    </div>
                </div>

                <?php dvc_paying_nav('send'); ?>

                <div class="dvc-settings-card animate-fade-in">
                    <div class="dvc-settings-head">
                        <div>
                            <h6>Message to send</h6>
                            <p>This is the saved first message. Variables fill in per donor when we send.</p>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="pledge-paying-first-message.php">Edit</a>
                    </div>
                    <div class="dvc-settings-body">
                        <div class="dvc-preview-bubble dvc-am-text"><?php echo htmlspecialchars($savedMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <div class="dvc-settings-card animate-fade-in">
                    <div class="dvc-settings-head">
                        <div>
                            <h6>Who receives it</h6>
                            <p>Only pledge donors who are still paying.</p>
                        </div>
                        <div class="dvc-select-count" id="dvcSelectCount">All still-paying donors</div>
                    </div>
                    <div class="dvc-settings-body">
                        <div id="dvcMsgFlash" class="alert d-none" role="status"></div>
                        <div class="dvc-mode-row">
                            <label class="dvc-mode-option<?php echo $modeAll ? ' is-active' : ''; ?>">
                                <input type="radio" name="dvcRecipientMode" id="dvcModeAll" value="all"<?php echo $modeAll ? ' checked' : ''; ?>>
                                <span>
                                    <strong>All still-paying donors</strong>
                                    <small>Everyone in this group, including people not on this page.</small>
                                </span>
                            </label>
                            <label class="dvc-mode-option<?php echo $modeAll ? '' : ' is-active'; ?>">
                                <input type="radio" name="dvcRecipientMode" id="dvcModeSelected" value="selected"<?php echo $modeAll ? '' : ' checked'; ?>>
                                <span>
                                    <strong>Choose people</strong>
                                    <small>Tick names in the table below.</small>
                                </span>
                            </label>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" id="dvcSelectPage">Select this page</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="dvcClearSelected">Clear ticks</button>
                            <button type="button" class="btn btn-primary btn-sm" id="dvcSaveRecipients">
                                <i class="fas fa-save me-1"></i>Save recipients
                            </button>
                        </div>
                    </div>
                </div>

                <div class="dvc-hero-kpis animate-fade-in">
                    <div class="dvc-hero-kpi">
                        <div class="dvc-stat-icon paying"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="dvc-hero-value" id="kpiDonors">—</div>
                            <div class="dvc-stat-label">Still-paying donors</div>
                        </div>
                    </div>
                    <div class="dvc-hero-kpi dvc-hero-kpi-amount paying">
                        <div class="dvc-stat-icon paying"><i class="fas fa-coins"></i></div>
                        <div>
                            <div class="dvc-hero-value" id="kpiAmount">—</div>
                            <div class="dvc-stat-label">Total amount remaining</div>
                        </div>
                    </div>
                </div>
                <div class="dvc-stat-row d-none" aria-hidden="true">
                    <div class="dvc-stat-value" id="kpiPledged">0</div>
                    <div class="dvc-stat-value" id="kpiPaid">0</div>
                    <div class="dvc-stat-value" id="kpiRemaining">0</div>
                </div>

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
                        <h6>Still-paying donors</h6>
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
                                    <th class="dvc-col-check">
                                        <input type="checkbox" id="dvcCheckPage" title="Select this page" disabled>
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
            </div>
        </main>
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
