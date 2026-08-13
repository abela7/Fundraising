<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/includes/group-config.php';

require_login();
require_admin();

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
$page_title = 'WhatsApp Donor Campaign';
$catalog = dvc_campaign_group_catalog();
$immediate = $catalog['immediate'];
$review = $catalog['unclassified'];
$pledgeNav = dvc_pledge_group_nav();
$cssVersion = (int) (filemtime(__DIR__ . '/assets/campaigns.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Donor Campaign - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/campaigns.css?v=<?php echo $cssVersion; ?>">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
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
                            <i class="fab fa-whatsapp me-2" style="color: var(--success);"></i>
                            WhatsApp Donor Campaign
                        </h1>
                        <p>Open a group to see every donor, totals, and filters.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="../reports/financial-dashboard.php">
                            <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <div class="dvc-note animate-fade-in">
                    <i class="fas fa-info-circle me-1" style="color: var(--primary);"></i>
                    Groups use pledged, paid, and remaining amounts — not the stored status label.
                    WhatsApp messages will be in Amharic. Templates come next.
                </div>

                <div class="text-muted small mb-3" id="summaryLine">Loading donors...</div>

                <div class="row g-3 mb-4 animate-fade-in">
                    <div class="col-12 col-md-6">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="<?php echo htmlspecialchars((string)$immediate['file'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dvc-stat-icon immediate"><i class="fas fa-bolt"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-group-card-title">Immediate payers</div>
                                <div class="dvc-group-card-count" id="hubCountImmediate">—</div>
                                <div class="dvc-group-card-meta" id="hubMetaImmediate">Paid on the spot</div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                    <div class="col-12 col-md-6">
                        <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="<?php echo htmlspecialchars((string)$review['file'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dvc-stat-icon review"><i class="fas fa-clipboard-check"></i></div>
                            <div class="dvc-group-card-body">
                                <div class="dvc-group-card-title">Needs review</div>
                                <div class="dvc-group-card-count" id="hubCountReview">—</div>
                                <div class="dvc-group-card-meta" id="hubMetaReview">No pledge and no payment</div>
                            </div>
                            <i class="fas fa-chevron-right dvc-group-card-arrow"></i>
                        </a>
                    </div>
                </div>

                <div class="dvc-section-title">Pledge donors</div>
                <p class="dvc-section-sub">Three standalone pages — completed, still paying, and not started.</p>

                <div class="row g-3 mb-4 animate-fade-in">
                    <?php foreach ($pledgeNav as $item): ?>
                        <?php
                        $file = htmlspecialchars((string)$item['file'], ENT_QUOTES, 'UTF-8');
                        $short = htmlspecialchars((string)$item['short'], ENT_QUOTES, 'UTF-8');
                        $desc = htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8');
                        $tone = htmlspecialchars((string)$item['tone'], ENT_QUOTES, 'UTF-8');
                        $icon = htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8');
                        $groupKey = htmlspecialchars((string)$item['group'], ENT_QUOTES, 'UTF-8');
                        $countId = 'hubCount-' . $groupKey;
                        $metaId = 'hubMeta-' . $groupKey;
                        ?>
                        <div class="col-12 col-md-6 col-lg-4">
                            <a class="dvc-group-card d-flex align-items-center text-decoration-none" href="<?php echo $file; ?>" data-group="<?php echo $groupKey; ?>">
                                <div class="dvc-stat-icon <?php echo $tone; ?>"><i class="fas <?php echo $icon; ?>"></i></div>
                                <div class="dvc-group-card-body">
                                    <div class="dvc-group-card-title"><?php echo $short; ?></div>
                                    <div class="dvc-group-card-count" id="<?php echo $countId; ?>">—</div>
                                    <div class="dvc-group-card-meta" id="<?php echo $metaId; ?>"><?php echo $desc; ?></div>
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
<script>
(function () {
  const CURRENCY = <?php echo json_encode($settings['currency_code'] ?? 'GBP'); ?>;
  const AMOUNT_KEYS = {
    immediate: 'paid',
    pledge_completed: 'paid',
    pledge_paying: 'remaining',
    pledge_not_started: 'pledged',
    unclassified: 'paid'
  };

  function fmtMoney(amount) {
    const n = Number(amount || 0);
    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency: CURRENCY, maximumFractionDigits: 2 }).format(n);
    } catch (_) {
      return CURRENCY + ' ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  }

  function setCard(countId, metaId, group, fallback) {
    const countEl = document.getElementById(countId);
    const metaEl = document.getElementById(metaId);
    if (!countEl || !metaEl) return;
    countEl.textContent = Number(group.donors || 0).toLocaleString() + ' donors';
    const key = AMOUNT_KEYS[fallback] || 'pledged';
    metaEl.textContent = fmtMoney(group[key] || 0);
  }

  async function loadSummary() {
    try {
      const res = await fetch('api/donors.php?group=immediate&summary_only=1', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Request failed');
      const summary = data.summary || {};
      document.getElementById('summaryLine').textContent =
        Number(summary.total_donors || 0).toLocaleString() + ' donors in total (old and new).';
      setCard('hubCountImmediate', 'hubMetaImmediate', summary.immediate || {}, 'immediate');
      setCard('hubCountReview', 'hubMetaReview', summary.unclassified || {}, 'unclassified');
      setCard('hubCount-pledge_completed', 'hubMeta-pledge_completed', summary.pledge_completed || {}, 'pledge_completed');
      setCard('hubCount-pledge_paying', 'hubMeta-pledge_paying', summary.pledge_paying || {}, 'pledge_paying');
      setCard('hubCount-pledge_not_started', 'hubMeta-pledge_not_started', summary.pledge_not_started || {}, 'pledge_not_started');
    } catch (err) {
      document.getElementById('summaryLine').textContent = 'Failed to load campaign totals.';
    }
  }

  loadSummary();
})();
</script>
</body>
</html>
