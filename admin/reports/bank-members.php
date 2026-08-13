<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/url.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/BankStatementMembers.php';
require_once __DIR__ . '/../../shared/BankStatementReviews.php';

require_login();
require_admin();

$page_title = 'Bank Members';
$settings = ['currency_code' => 'GBP'];
$db_error = '';
$result = [
    'file_found' => false,
    'error' => '',
    'rows' => [],
    'totals' => [
        'excel_members' => 0,
        'found' => 0,
        'same_amount' => 0,
        'amount_diff' => 0,
        'not_found' => 0,
        'excel_paid' => 0.0,
        'bank_lump' => 0.0,
    ],
    'donors_available' => false,
];

$dbConn = null;
try {
    $dbConn = db();
    $settingsTable = $dbConn->query("SHOW TABLES LIKE 'settings'");
    if ($settingsTable && $settingsTable->num_rows > 0) {
        $row = $dbConn->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
        if (is_array($row) && isset($row['currency_code'])) {
            $settings['currency_code'] = (string) $row['currency_code'];
        }
    }
    $result = BankStatementMembers::relate($dbConn);
} catch (Throwable $e) {
    $db_error = 'Could not link members. Showing the Excel list only.';
    error_log('Bank members page error: ' . $e->getMessage());
    try {
        $result = BankStatementMembers::relate(null);
    } catch (Throwable $fallbackError) {
        error_log('Bank members Excel fallback failed: ' . $fallbackError->getMessage());
    }
}

$currency = (string) ($settings['currency_code'] ?? 'GBP');
$totals = $result['totals'] ?? [
    'excel_members' => 0,
    'found' => 0,
    'same_amount' => 0,
    'amount_diff' => 0,
    'not_found' => 0,
    'excel_paid' => 0.0,
    'bank_lump' => 0.0,
];
$loadError = $db_error !== '' ? $db_error : trim((string) ($result['error'] ?? ''));
$memberRows = [];
foreach ($result['rows'] ?? [] as $row) {
    if (!is_array($row) || ($row['status'] ?? '') === 'bank_account') {
        continue;
    }
    $memberRows[] = $row;
}

$reviewMap = [];
if ($dbConn instanceof mysqli) {
    try {
        $reviewMap = BankStatementReviews::all($dbConn);
    } catch (Throwable $e) {
        error_log('Bank member reviews load failed: ' . $e->getMessage());
    }
}

$reviewCounts = [
    BankStatementReviews::PENDING => 0,
    BankStatementReviews::IDENTIFIED => 0,
    BankStatementReviews::NOT_IDENTIFIED => 0,
];
foreach ($memberRows as $i => $row) {
    $key = (string) ($row['row_key'] ?? BankStatementReviews::rowKey(
        (int) ($row['excel_row'] ?? 0),
        (string) ($row['excel_name'] ?? ''),
        (string) ($row['excel_ref'] ?? ''),
        (float) ($row['excel_paid'] ?? 0)
    ));
    $review = $reviewMap[$key] ?? BankStatementReviews::PENDING;
    $memberRows[$i]['row_key'] = $key;
    $memberRows[$i]['review_status'] = $review;
    $reviewCounts[$review]++;
}

$csrfToken = csrf_token();

/**
 * Format a money amount for display.
 */
function bsm_money(float $amount, string $currency): string
{
    $symbol = $currency === 'GBP' ? "\u{00A3}" : $currency . ' ';

    return $symbol . number_format(abs($amount), 2);
}

/**
 * Format bank minus system, with an explicit + or -.
 */
function bsm_signed_money(float $amount, string $currency): string
{
    $formatted = bsm_money($amount, $currency);
    if ($amount > 0.009) {
        return '+' . $formatted;
    }
    if ($amount < -0.009) {
        return '-' . $formatted;
    }

    return $formatted;
}

/**
 * Escape text for HTML.
 */
function bsm_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$cssVersion = (int) (filemtime(__DIR__ . '/assets/bank-members.css') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Members - Fundraising System</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/bank-members.css?v=<?php echo $cssVersion; ?>">
</head>
<body>
<div class="admin-wrapper">
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid">
                <div class="bsm-header">
                    <div>
                        <h1>
                            <i class="fas fa-building-columns me-2" style="color: var(--primary);"></i>
                            Bank Members
                        </h1>
                        <p>People listed on the bank Excel, linked to members in the system.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(url_for('admin/reports/'), ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-arrow-left me-1"></i>Back to Reports
                        </a>
                    </div>
                </div>

                <?php if ($loadError !== ''): ?>
                    <div class="alert alert-warning" role="alert" style="color: var(--gray-800);">
                        <i class="fas fa-info-circle me-2"></i><?php echo bsm_h($loadError); ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($result['donors_available']) && $memberRows !== []): ?>
                    <div class="alert alert-warning" role="alert" style="color: var(--gray-800);">
                        <i class="fas fa-triangle-exclamation me-2"></i>
                        This database has no member records to link against. Excel people are listed below as not in the system.
                    </div>
                <?php endif; ?>

                    <div class="bsm-note">
                        <i class="fas fa-info-circle me-1" style="color: var(--primary);"></i>
                        After you check a bank row, mark it <strong>Identified</strong> or <strong>Not identified</strong>.
                        New rows start as <strong>Pending</strong>.
                    </div>

                    <div class="bsm-stats">
                        <button type="button" class="bsm-chip is-active" data-review-filter="all">
                            <span class="bsm-chip-icon excel"><i class="fas fa-list"></i></span>
                            <span>
                                <span class="bsm-chip-val" id="bsmCountAll"><?php echo count($memberRows); ?></span>
                                <span class="bsm-chip-lbl d-block">All</span>
                            </span>
                        </button>
                        <button type="button" class="bsm-chip" data-review-filter="pending">
                            <span class="bsm-chip-icon pending"><i class="fas fa-clock"></i></span>
                            <span>
                                <span class="bsm-chip-val" id="bsmCountPending"><?php echo (int) $reviewCounts[BankStatementReviews::PENDING]; ?></span>
                                <span class="bsm-chip-lbl d-block">Pending</span>
                            </span>
                        </button>
                        <button type="button" class="bsm-chip" data-review-filter="identified">
                            <span class="bsm-chip-icon found"><i class="fas fa-check"></i></span>
                            <span>
                                <span class="bsm-chip-val" id="bsmCountIdentified"><?php echo (int) $reviewCounts[BankStatementReviews::IDENTIFIED]; ?></span>
                                <span class="bsm-chip-lbl d-block">Identified</span>
                            </span>
                        </button>
                        <button type="button" class="bsm-chip" data-review-filter="not_identified">
                            <span class="bsm-chip-icon missing"><i class="fas fa-xmark"></i></span>
                            <span>
                                <span class="bsm-chip-val" id="bsmCountNotIdentified"><?php echo (int) $reviewCounts[BankStatementReviews::NOT_IDENTIFIED]; ?></span>
                                <span class="bsm-chip-lbl d-block">Not identified</span>
                            </span>
                        </button>
                    </div>

                    <div class="bsm-filters">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label" for="bsmSearch">Search</label>
                                <input type="search" class="form-control form-control-sm" id="bsmSearch" placeholder="Name or reference" autocomplete="off">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="bsmMatchFilter">Match</label>
                                <select class="form-select form-select-sm" id="bsmMatchFilter">
                                    <option value="all">All matches</option>
                                    <option value="found">Found in system</option>
                                    <option value="amount_diff">Amount differs</option>
                                    <option value="not_found">Not in system</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="bsm-card">
                        <div class="bsm-card-head">
                            <h6>Excel people</h6>
                            <span class="bsm-count" id="bsmCount"><?php echo count($memberRows); ?> shown</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table bsm-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Excel name</th>
                                        <th>Ref</th>
                                        <th class="text-end">Bank paid</th>
                                        <th>Member</th>
                                        <th class="text-end">System paid</th>
                                        <th class="text-end">Difference<span class="bsm-th-hint">Bank − System</span></th>
                                        <th>Match</th>
                                        <th>Review</th>
                                    </tr>
                                </thead>
                                <tbody id="bsmBody">
                                    <?php if ($memberRows === []): ?>
                                        <tr id="bsmEmpty">
                                            <td colspan="8" class="text-center py-4 bsm-muted">No Excel members to show.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($memberRows as $row): ?>
                                            <?php
                                            $status = (string) ($row['status'] ?? '');
                                            $search = strtolower(
                                                (string) ($row['excel_name'] ?? '') . ' ' .
                                                (string) ($row['excel_ref'] ?? '') . ' ' .
                                                (string) ($row['donor_name'] ?? '')
                                            );
                                            $donorId = isset($row['donor_id']) ? (int) $row['donor_id'] : 0;
                                            $reviewStatus = (string) ($row['review_status'] ?? BankStatementReviews::PENDING);
                                            $rowKey = (string) ($row['row_key'] ?? '');
                                            ?>
                                            <tr data-status="<?php echo bsm_h($status); ?>" data-review="<?php echo bsm_h($reviewStatus); ?>" data-search="<?php echo bsm_h($search); ?>">
                                                <td>
                                                    <div class="bsm-name" title="<?php echo bsm_h((string) ($row['excel_name'] ?? '')); ?>">
                                                        <?php echo bsm_h((string) ($row['excel_name'] ?? '')); ?>
                                                    </div>
                                                </td>
                                                <td class="bsm-ref"><?php echo ($row['excel_ref'] ?? '') !== '' ? bsm_h((string) $row['excel_ref']) : '—'; ?></td>
                                                <td class="text-end"><?php echo bsm_h(bsm_money((float) ($row['excel_paid'] ?? 0), $currency)); ?></td>
                                                <td>
                                                    <?php
                                                    $memberName = trim((string) ($row['donor_name'] ?? ''));
                                                    $matchHint = '';
                                                    if ((string) ($row['match_by'] ?? '') === 'reference') {
                                                        $matchHint = ' <span class="bsm-muted" title="Matched by reference"> · ref</span>';
                                                    } elseif ((string) ($row['match_by'] ?? '') === 'name') {
                                                        $matchHint = ' <span class="bsm-muted" title="Matched by name"> · name</span>';
                                                    }
                                                    if ($donorId > 0) {
                                                        echo '<a class="bsm-link" href="../donor-management/view-donor.php?id=' . $donorId . '">';
                                                        echo bsm_h($memberName);
                                                        echo '</a>' . $matchHint;
                                                    } elseif ($memberName !== '') {
                                                        echo '<span class="bsm-name">' . bsm_h($memberName) . '</span>' . $matchHint;
                                                    } else {
                                                        echo '<span class="bsm-muted">Not in system</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php echo $row['donor_paid'] !== null ? bsm_h(bsm_money((float) $row['donor_paid'], $currency)) : '—'; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php
                                                    $diff = $row['paid_diff'] ?? null;
                                                    if ($diff === null) {
                                                        echo '—';
                                                    } elseif (abs((float) $diff) < 0.01) {
                                                        echo '<span class="bsm-ok">Same</span>';
                                                    } else {
                                                        $diffVal = (float) $diff;
                                                        $cls = $diffVal > 0 ? 'bsm-diff bsm-diff-plus' : 'bsm-diff bsm-diff-minus';
                                                        echo '<span class="' . $cls . '">' . bsm_h(bsm_signed_money($diffVal, $currency)) . '</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if ($status === 'linked'): ?>
                                                        <span class="bsm-badge bsm-badge-match"><i class="fas fa-check"></i> Match</span>
                                                    <?php elseif ($status === 'amount_diff'): ?>
                                                        <span class="bsm-badge bsm-badge-diff"><i class="fas fa-triangle-exclamation"></i> Differs</span>
                                                    <?php else: ?>
                                                        <span class="bsm-badge bsm-badge-missing"><i class="fas fa-xmark"></i> Not found</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <select
                                                        class="form-select form-select-sm bsm-review bsm-review-<?php echo bsm_h($reviewStatus); ?>"
                                                        data-key="<?php echo bsm_h($rowKey); ?>"
                                                        data-row="<?php echo (int) ($row['excel_row'] ?? 0); ?>"
                                                        data-name="<?php echo bsm_h((string) ($row['excel_name'] ?? '')); ?>"
                                                        data-ref="<?php echo bsm_h((string) ($row['excel_ref'] ?? '')); ?>"
                                                        data-paid="<?php echo bsm_h(number_format((float) ($row['excel_paid'] ?? 0), 2, '.', '')); ?>"
                                                        aria-label="Review status"
                                                    >
                                                        <option value="pending"<?php echo $reviewStatus === BankStatementReviews::PENDING ? ' selected' : ''; ?>>Pending</option>
                                                        <option value="identified"<?php echo $reviewStatus === BankStatementReviews::IDENTIFIED ? ' selected' : ''; ?>>Identified</option>
                                                        <option value="not_identified"<?php echo $reviewStatus === BankStatementReviews::NOT_IDENTIFIED ? ' selected' : ''; ?>>Not identified</option>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr id="bsmEmpty" class="d-none">
                                            <td colspan="8" class="text-center py-4 bsm-muted">No people match this filter.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
            </div>
        </main>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
(function () {
  const chips = document.querySelectorAll('.bsm-chip[data-review-filter]');
  const search = document.getElementById('bsmSearch');
  const matchFilterEl = document.getElementById('bsmMatchFilter');
  const rows = Array.from(document.querySelectorAll('#bsmBody tr[data-status]'));
  const empty = document.getElementById('bsmEmpty');
  const count = document.getElementById('bsmCount');
  const csrf = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES); ?>;
  if (!chips.length || !search) return;

  let reviewFilter = 'all';

  function apply() {
    const q = search.value.toLowerCase().trim();
    const matchFilter = matchFilterEl ? matchFilterEl.value : 'all';
    let shown = 0;
    rows.forEach(function (row) {
      const status = row.getAttribute('data-status') || '';
      const review = row.getAttribute('data-review') || 'pending';
      const hay = row.getAttribute('data-search') || '';
      let ok = true;
      if (reviewFilter !== 'all' && review !== reviewFilter) ok = false;
      if (ok && matchFilter === 'found') ok = status === 'linked' || status === 'amount_diff';
      else if (ok && (matchFilter === 'amount_diff' || matchFilter === 'not_found')) ok = status === matchFilter;
      if (ok && q && hay.indexOf(q) === -1) ok = false;
      row.classList.toggle('d-none', !ok);
      if (ok) shown += 1;
    });
    if (empty) empty.classList.toggle('d-none', shown > 0 || rows.length === 0);
    if (count) count.textContent = shown + ' shown';
  }

  function refreshReviewCounts() {
    const totals = { pending: 0, identified: 0, not_identified: 0 };
    rows.forEach(function (row) {
      const review = row.getAttribute('data-review') || 'pending';
      if (totals[review] !== undefined) totals[review] += 1;
    });
    const pendingEl = document.getElementById('bsmCountPending');
    const identifiedEl = document.getElementById('bsmCountIdentified');
    const notEl = document.getElementById('bsmCountNotIdentified');
    if (pendingEl) pendingEl.textContent = String(totals.pending);
    if (identifiedEl) identifiedEl.textContent = String(totals.identified);
    if (notEl) notEl.textContent = String(totals.not_identified);
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      reviewFilter = chip.getAttribute('data-review-filter') || 'all';
      chips.forEach(function (c) { c.classList.toggle('is-active', c === chip); });
      apply();
    });
  });
  search.addEventListener('input', apply);
  if (matchFilterEl) matchFilterEl.addEventListener('change', apply);

  document.querySelectorAll('.bsm-review').forEach(function (select) {
    select.addEventListener('change', function () {
      const rowEl = select.closest('tr');
      if (!rowEl) return;
      const previous = rowEl.getAttribute('data-review') || 'pending';
      const next = select.value;
      const body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('row_key', select.getAttribute('data-key') || '');
      body.set('review_status', next);
      body.set('excel_row', select.getAttribute('data-row') || '0');
      body.set('excel_name', select.getAttribute('data-name') || '');
      body.set('excel_ref', select.getAttribute('data-ref') || '');
      body.set('excel_paid', select.getAttribute('data-paid') || '0');
      select.disabled = true;
      fetch('api/bank-member-review.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: body
      }).then(function (res) { return res.json().then(function (json) { return { ok: res.ok, json: json }; }); })
        .then(function (result) {
          if (!result.ok || !result.json || !result.json.success) {
            throw new Error((result.json && result.json.error) || 'Save failed');
          }
          rowEl.setAttribute('data-review', next);
          select.classList.remove('bsm-review-pending', 'bsm-review-identified', 'bsm-review-not_identified');
          select.classList.add('bsm-review-' + next);
          refreshReviewCounts();
          apply();
        })
        .catch(function (err) {
          select.value = previous;
          alert(err.message || 'Could not save review status.');
        })
        .finally(function () {
          select.disabled = false;
        });
    });
  });
})();
</script>
</body>
</html>
