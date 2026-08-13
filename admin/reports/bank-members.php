<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/url.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../shared/BankStatementMembers.php';

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

try {
    $db = db();
    try {
        $settingsTable = $db->query("SHOW TABLES LIKE 'settings'");
        if ($settingsTable && $settingsTable->num_rows > 0) {
            $row = $db->query('SELECT currency_code FROM settings WHERE id = 1')->fetch_assoc();
            if (is_array($row) && isset($row['currency_code'])) {
                $settings['currency_code'] = (string) $row['currency_code'];
            }
        }
        if ($settingsTable instanceof mysqli_result) {
            $settingsTable->free();
        }
    } catch (Throwable $e) {
        error_log('Bank members settings lookup failed: ' . $e->getMessage());
    }
    $result = BankStatementMembers::relate($db);
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

$csrfToken = csrf_token();
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
                        This page shows only the <?php echo (int) $totals['excel_members']; ?> people from
                        <strong>donors-bank-data.xlsx</strong>
                        (<span id="bsmExcelPaidTotal"><?php echo bsm_h(bsm_money((float) $totals['excel_paid'], $currency)); ?></span>).
                        Other members in the database are not listed.
                        Double-click a <strong>Bank paid</strong> amount to correct it.
                        <?php if ((float) $totals['bank_lump'] > 0): ?>
                            A bank lump of <?php echo bsm_h(bsm_money((float) $totals['bank_lump'], $currency)); ?> is excluded.
                        <?php endif; ?>
                    </div>

                    <div class="bsm-stats">
                        <button type="button" class="bsm-chip is-active" data-filter="all">
                            <span class="bsm-chip-icon excel"><i class="fas fa-file-excel"></i></span>
                            <span>
                                <span class="bsm-chip-val"><?php echo (int) $totals['excel_members']; ?></span>
                                <span class="bsm-chip-lbl d-block">In Excel</span>
                            </span>
                        </button>
                        <button type="button" class="bsm-chip" data-filter="found">
                            <span class="bsm-chip-icon found"><i class="fas fa-link"></i></span>
                            <span>
                                <span class="bsm-chip-val"><?php echo (int) $totals['found']; ?></span>
                                <span class="bsm-chip-lbl d-block">Found</span>
                            </span>
                        </button>
                        <button type="button" class="bsm-chip" data-filter="amount_diff">
                            <span class="bsm-chip-icon diff"><i class="fas fa-scale-unbalanced"></i></span>
                            <span>
                                <span class="bsm-chip-val"><?php echo (int) $totals['amount_diff']; ?></span>
                                <span class="bsm-chip-lbl d-block">Amount differs</span>
                            </span>
                        </button>
                        <button type="button" class="bsm-chip" data-filter="not_found">
                            <span class="bsm-chip-icon missing"><i class="fas fa-user-slash"></i></span>
                            <span>
                                <span class="bsm-chip-val"><?php echo (int) $totals['not_found']; ?></span>
                                <span class="bsm-chip-lbl d-block">Not in system</span>
                            </span>
                        </button>
                    </div>

                    <div class="bsm-filters">
                        <label class="form-label" for="bsmSearch">Search</label>
                        <input type="search" class="form-control form-control-sm" id="bsmSearch" placeholder="Name or reference" autocomplete="off">
                    </div>

                    <div id="bsmFlash" class="alert d-none" role="status"></div>

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
                                        <th class="text-end">Bank paid<span class="bsm-th-hint">Double-click to edit</span></th>
                                        <th>Member</th>
                                        <th class="text-end">System paid</th>
                                        <th class="text-end">Difference<span class="bsm-th-hint">Bank − System</span></th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="bsmBody">
                                    <?php if ($memberRows === []): ?>
                                        <tr id="bsmEmpty">
                                            <td colspan="7" class="text-center py-4 bsm-muted">No Excel members to show.</td>
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
                                            $bankPaid = (float) ($row['excel_paid'] ?? 0);
                                            $originalPaid = (float) ($row['original_paid'] ?? $bankPaid);
                                            $paidEdited = !empty($row['bank_paid_edited']);
                                            $donorPaid = $row['donor_paid'] ?? null;
                                            ?>
                                            <tr data-status="<?php echo bsm_h($status); ?>" data-search="<?php echo bsm_h($search); ?>">
                                                <td>
                                                    <div class="bsm-name" title="<?php echo bsm_h((string) ($row['excel_name'] ?? '')); ?>">
                                                        <?php echo bsm_h((string) ($row['excel_name'] ?? '')); ?>
                                                    </div>
                                                </td>
                                                <td class="bsm-ref"><?php echo ($row['excel_ref'] ?? '') !== '' ? bsm_h((string) $row['excel_ref']) : '—'; ?></td>
                                                <td class="text-end bsm-paid-cell<?php echo $paidEdited ? ' is-edited' : ''; ?>"
                                                    title="Double-click to edit"
                                                    data-row-key="<?php echo bsm_h((string) ($row['row_key'] ?? '')); ?>"
                                                    data-excel-row="<?php echo (int) ($row['excel_row'] ?? 0); ?>"
                                                    data-excel-name="<?php echo bsm_h((string) ($row['excel_name'] ?? '')); ?>"
                                                    data-excel-ref="<?php echo bsm_h((string) ($row['excel_ref'] ?? '')); ?>"
                                                    data-bank-paid="<?php echo bsm_h(number_format($bankPaid, 2, '.', '')); ?>"
                                                    data-original-paid="<?php echo bsm_h(number_format($originalPaid, 2, '.', '')); ?>"
                                                    data-donor-paid="<?php echo $donorPaid === null ? '' : bsm_h(number_format((float) $donorPaid, 2, '.', '')); ?>">
                                                    <span class="bsm-paid-value"><?php echo bsm_h(bsm_money($bankPaid, $currency)); ?></span>
                                                </td>
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
                                                <td class="text-end bsm-diff-cell">
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
                                                <td class="bsm-status-cell">
                                                    <?php if ($status === 'linked'): ?>
                                                        <span class="bsm-badge bsm-badge-match"><i class="fas fa-check"></i> Match</span>
                                                    <?php elseif ($status === 'amount_diff'): ?>
                                                        <span class="bsm-badge bsm-badge-diff"><i class="fas fa-triangle-exclamation"></i> Differs</span>
                                                    <?php else: ?>
                                                        <span class="bsm-badge bsm-badge-missing"><i class="fas fa-xmark"></i> Not found</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr id="bsmEmpty" class="d-none">
                                            <td colspan="7" class="text-center py-4 bsm-muted">No people match this filter.</td>
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
  const chips = document.querySelectorAll('.bsm-chip');
  const search = document.getElementById('bsmSearch');
  const rows = Array.from(document.querySelectorAll('#bsmBody tr[data-status]'));
  const empty = document.getElementById('bsmEmpty');
  const count = document.getElementById('bsmCount');
  const flash = document.getElementById('bsmFlash');
  const csrf = <?php echo json_encode($csrfToken, JSON_UNESCAPED_SLASHES); ?>;
  const currency = <?php echo json_encode($currency, JSON_UNESCAPED_SLASHES); ?>;
  const saveUrl = <?php echo json_encode(url_for('admin/reports/api/bank-member-amount.php'), JSON_UNESCAPED_SLASHES); ?>;
  if (!chips.length || !search) return;

  let filter = 'all';
  let editing = null;
  let saving = false;

  function apply() {
    const q = search.value.toLowerCase().trim();
    let shown = 0;
    rows.forEach(function (row) {
      const status = row.getAttribute('data-status') || '';
      const hay = row.getAttribute('data-search') || '';
      let ok = true;
      if (filter === 'found') ok = status === 'linked' || status === 'amount_diff';
      else if (filter === 'amount_diff' || filter === 'not_found') ok = status === filter;
      if (ok && q && hay.indexOf(q) === -1) ok = false;
      row.classList.toggle('d-none', !ok);
      if (ok) shown += 1;
    });
    if (empty) empty.classList.toggle('d-none', shown > 0 || rows.length === 0);
    if (count) count.textContent = shown + ' shown';
  }

  function formatMoney(amount) {
    const formatted = Math.abs(amount).toLocaleString('en-GB', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
    return currency === 'GBP' ? '\u00A3' + formatted : currency + ' ' + formatted;
  }

  function formatSigned(amount) {
    const formatted = formatMoney(amount);
    if (amount > 0.009) return '+' + formatted;
    if (amount < -0.009) return '-' + formatted;
    return formatted;
  }

  function showFlash(message, isError) {
    if (!flash) return;
    flash.textContent = message;
    flash.classList.remove('d-none', 'alert-warning', 'alert-success');
    flash.classList.add(isError ? 'alert-warning' : 'alert-success');
    window.clearTimeout(showFlash._t);
    showFlash._t = window.setTimeout(function () {
      flash.classList.add('d-none');
    }, 3200);
  }

  function setChip(name, value) {
    const el = document.querySelector('.bsm-chip[data-filter="' + name + '"] .bsm-chip-val');
    if (el) el.textContent = String(value);
  }

  function recount() {
    let found = 0;
    let diff = 0;
    let missing = 0;
    let excelPaid = 0;
    rows.forEach(function (row) {
      const status = row.getAttribute('data-status') || '';
      const cell = row.querySelector('.bsm-paid-cell');
      const paid = parseFloat(cell ? cell.getAttribute('data-bank-paid') : '0');
      if (isFinite(paid)) excelPaid += paid;
      if (status === 'linked' || status === 'amount_diff') found += 1;
      if (status === 'amount_diff') diff += 1;
      if (status === 'not_found') missing += 1;
    });
    setChip('found', found);
    setChip('amount_diff', diff);
    setChip('not_found', missing);
    const totalEl = document.getElementById('bsmExcelPaidTotal');
    if (totalEl) totalEl.textContent = formatMoney(excelPaid);
  }

  function renderDiff(cell, donorPaidRaw, bankPaid) {
    if (!cell) return;
    if (donorPaidRaw === '') {
      cell.textContent = '—';
      return;
    }
    const donorPaid = parseFloat(donorPaidRaw);
    const diff = Math.round((bankPaid - donorPaid) * 100) / 100;
    if (Math.abs(diff) < 0.01) {
      cell.innerHTML = '<span class="bsm-ok">Same</span>';
      return;
    }
    const cls = diff > 0 ? 'bsm-diff bsm-diff-plus' : 'bsm-diff bsm-diff-minus';
    cell.innerHTML = '<span class="' + cls + '"></span>';
    cell.firstChild.textContent = formatSigned(diff);
  }

  function renderStatus(cell, status) {
    if (!cell) return;
    if (status === 'linked') {
      cell.innerHTML = '<span class="bsm-badge bsm-badge-match"><i class="fas fa-check"></i> Match</span>';
    } else if (status === 'amount_diff') {
      cell.innerHTML = '<span class="bsm-badge bsm-badge-diff"><i class="fas fa-triangle-exclamation"></i> Differs</span>';
    } else {
      cell.innerHTML = '<span class="bsm-badge bsm-badge-missing"><i class="fas fa-xmark"></i> Not found</span>';
    }
  }

  function rowStatus(donorPaidRaw, bankPaid) {
    if (donorPaidRaw === '') return 'not_found';
    const donorPaid = parseFloat(donorPaidRaw);
    const diff = Math.round((bankPaid - donorPaid) * 100) / 100;
    return Math.abs(diff) < 0.01 ? 'linked' : 'amount_diff';
  }

  function closeEditor(cell, restore) {
    if (!cell) return;
    const input = cell.querySelector('.bsm-paid-input');
    if (input) input.remove();
    let valueEl = cell.querySelector('.bsm-paid-value');
    if (!valueEl) {
      valueEl = document.createElement('span');
      valueEl.className = 'bsm-paid-value';
      cell.appendChild(valueEl);
    }
    if (restore) {
      const paid = parseFloat(cell.getAttribute('data-bank-paid') || '0');
      valueEl.textContent = formatMoney(isFinite(paid) ? paid : 0);
    }
    valueEl.hidden = false;
    cell.classList.remove('is-editing');
    if (editing === cell) editing = null;
  }

  function applyPaid(cell, bankPaid) {
    const row = cell.closest('tr');
    const original = parseFloat(cell.getAttribute('data-original-paid') || '0');
    const donorPaidRaw = cell.getAttribute('data-donor-paid') || '';
    const status = rowStatus(donorPaidRaw, bankPaid);
    cell.setAttribute('data-bank-paid', bankPaid.toFixed(2));
    cell.classList.toggle('is-edited', Math.abs(bankPaid - original) >= 0.01);
    const valueEl = cell.querySelector('.bsm-paid-value');
    if (valueEl) valueEl.textContent = formatMoney(bankPaid);
    if (row) {
      row.setAttribute('data-status', status);
      renderDiff(row.querySelector('.bsm-diff-cell'), donorPaidRaw, bankPaid);
      renderStatus(row.querySelector('.bsm-status-cell'), status);
    }
    cell.classList.remove('bsm-paid-flash');
    void cell.offsetWidth;
    cell.classList.add('bsm-paid-flash');
    recount();
    apply();
  }

  async function savePaid(cell, raw) {
    const parsed = parseFloat(raw);
    const current = parseFloat(cell.getAttribute('data-bank-paid') || '0');
    if (!isFinite(parsed) || parsed < 0) {
      showFlash('Enter a valid amount.', true);
      closeEditor(cell, true);
      return;
    }
    const bankPaid = Math.round(parsed * 100) / 100;
    if (Math.abs(bankPaid - current) < 0.001) {
      closeEditor(cell, true);
      return;
    }
    if (saving) return;
    saving = true;
    cell.classList.add('is-saving');
    const body = new URLSearchParams({
      csrf_token: csrf,
      row_key: cell.getAttribute('data-row-key') || '',
      excel_row: cell.getAttribute('data-excel-row') || '',
      excel_name: cell.getAttribute('data-excel-name') || '',
      excel_ref: cell.getAttribute('data-excel-ref') || '',
      original_paid: cell.getAttribute('data-original-paid') || '0.00',
      bank_paid: bankPaid.toFixed(2)
    });
    try {
      const res = await fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: body.toString()
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Could not save Bank paid amount.');
      }
      closeEditor(cell, false);
      applyPaid(cell, typeof data.bank_paid === 'number' ? data.bank_paid : bankPaid);
    } catch (err) {
      showFlash(err.message || 'Could not save Bank paid amount.', true);
      closeEditor(cell, true);
    } finally {
      saving = false;
      cell.classList.remove('is-saving');
    }
  }

  async function startEdit(cell) {
    if (saving || !cell || cell.classList.contains('is-editing')) return;
    if (editing && editing !== cell) {
      const prev = editing;
      const prevInput = prev.querySelector('.bsm-paid-input');
      await savePaid(prev, prevInput ? prevInput.value : '');
      if (saving || editing) return;
    }
    const current = cell.getAttribute('data-bank-paid') || '0.00';
    const valueEl = cell.querySelector('.bsm-paid-value');
    if (valueEl) valueEl.hidden = true;
    const input = document.createElement('input');
    input.type = 'number';
    input.className = 'form-control form-control-sm bsm-paid-input';
    input.step = '0.01';
    input.min = '0';
    input.inputMode = 'decimal';
    input.autocomplete = 'off';
    input.setAttribute('aria-label', 'Bank paid amount');
    input.value = current;
    cell.classList.add('is-editing');
    cell.appendChild(input);
    editing = cell;
    input.focus();
    input.select();
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        savePaid(cell, input.value);
      } else if (event.key === 'Escape') {
        event.preventDefault();
        closeEditor(cell, true);
      }
    });
    input.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (editing === cell && document.activeElement !== input) {
          savePaid(cell, input.value);
        }
      }, 0);
    });
  }

  const tableBody = document.getElementById('bsmBody');
  if (tableBody) {
    tableBody.addEventListener('dblclick', function (event) {
      const cell = event.target.closest('.bsm-paid-cell');
      if (!cell || !tableBody.contains(cell)) return;
      event.preventDefault();
      startEdit(cell);
    });
  }

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      filter = chip.getAttribute('data-filter') || 'all';
      chips.forEach(function (c) { c.classList.toggle('is-active', c === chip); });
      apply();
    });
  });
  search.addEventListener('input', apply);
})();
</script>
</body>
</html>
