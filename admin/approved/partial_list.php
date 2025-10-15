<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_admin();
$db = db();

// Format helper
function format_sqm_fraction(float $value): string {
    if ($value <= 0) return '0';
    $whole = (int)floor($value);
    $fractionPart = $value - $whole;

    if ($fractionPart > 0) {
        if (abs($fractionPart - 0.25) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '¼';
        if (abs($fractionPart - 0.5) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '½';
        if (abs($fractionPart - 0.75) < 0.01) return ($whole > 0 ? $whole . ' ' : '') . '¾';
    }
    
    return $whole > 0 ? (string)$whole : number_format($value, 2);
}

// Expect $approved from parent include
if (empty($approved)) {
    echo '<div class="text-center p-5 text-muted">
            <i class="fas fa-inbox fa-3x mb-3"></i>
            <h4>No approved items yet</h4>
            <p>Once items are approved, they appear here for management.</p>
          </div>';
    return;
}
?>

<div class="table-responsive">
    <table class="table align-middle">
        <thead>
            <tr>
                <th>Donor</th>
                <th>Amount</th>
                <th>Type</th>
                <th>SQM</th>
                <th>Registrar</th>
                <th>Timestamp</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($approved as $row): ?>
          <?php
            $pledge_id = (int)$row['id'];
            $amount = (float)$row['amount'];
            $type = (string)$row['type'];
            $notes = (string)($row['notes'] ?? '');
            $sqm_meters = (float)($row['sqm_meters'] ?? 0);
            $donor_name = (string)($row['donor_name'] ?? '');
            $donor_phone = (string)($row['donor_phone'] ?? '');
            $donor_email = (string)($row['donor_email'] ?? '');
            $registrar = (string)($row['registrar_name'] ?? '');
            $approved_at = $row['approved_at'] ?? null;
            $created_at = $row['created_at'] ?? null;
            $payment_id = isset($row['payment_id']) ? (int)$row['payment_id'] : 0;
            $payment_amount = isset($row['payment_amount']) ? (float)$row['payment_amount'] : null;
            $payment_method = (string)($row['payment_method'] ?? '');
            $payment_reference = (string)($row['payment_reference'] ?? '');
            $type_class = $type === 'paid' ? 'badge-paid' : 'badge-pledge';
          ?>
            <tr>
                <td>
                    <strong><?php echo htmlspecialchars($donor_name ?: 'N/A'); ?></strong>
                    <br>
                    <small class="text-muted"><?php echo htmlspecialchars($donor_phone ?: ''); ?></small>
                </td>
                <td><strong>£<?php echo number_format($amount, 2); ?></strong></td>
                <td><span class="badge <?php echo $type_class; ?>"><?php echo ucfirst($type); ?></span></td>
                <td><?php echo htmlspecialchars(format_sqm_fraction($sqm_meters)); ?> m²</td>
                <td><?php echo htmlspecialchars($registrar ?: 'Unknown'); ?></td>
                <td>
                    <?php
                    $timestamp = $approved_at ?: $created_at;
                    if ($timestamp) {
                      echo date('d/m/y H:i', strtotime($timestamp));
                    } else {
                      echo 'Unknown';
                    }
                    ?>
                </td>
                <td>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPledgeModal"
                                onclick="openEditPledgeModal(<?php echo $pledge_id; ?>, '<?php echo htmlspecialchars($donor_name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($donor_phone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($donor_email ?? '', ENT_QUOTES); ?>', <?php echo $amount; ?>, <?php echo $sqm_meters; ?>, '<?php echo htmlspecialchars($notes ?? '', ENT_QUOTES); ?>')">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ($type === 'paid' && $payment_id): ?>
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#editPaymentModal"
                                onclick="openEditPaymentModal(<?php echo $payment_id; ?>, <?php echo (float)$payment_amount; ?>, '<?php echo htmlspecialchars($payment_method ?: 'cash', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($payment_reference ?? '', ENT_QUOTES); ?>')">
                            <i class="fas fa-credit-card"></i>
                        </button>
                        <?php endif; ?>
                        
                        <?php if ($type !== 'paid'): ?>
                        <form method="post" class="d-inline" action="index.php" onsubmit="return confirm('Undo approval for this item?');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="undo">
                            <input type="hidden" name="pledge_id" value="<?php echo $pledge_id; ?>">
                            <?php
                            $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
                            foreach ($preserveParams as $param) {
                                if (isset($_GET[$param]) && $_GET[$param] !== '') {
                                    echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
                                }
                            }
                            ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Undo approval"><i class="fas fa-undo"></i></button>
                        </form>
                        <?php else: ?>
                        <form method="post" class="d-inline" action="index.php" onsubmit="return confirm('Undo approval for this payment and subtract from totals?');">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="action" value="undo_payment">
                            <input type="hidden" name="payment_id" value="<?php echo (int)($payment_id ?? 0); ?>">
                            <input type="hidden" name="payment_amount" value="<?php echo (float)($payment_amount ?? 0); ?>">
                            <?php
                            $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
                            foreach ($preserveParams as $param) {
                                if (isset($_GET[$param]) && $_GET[$param] !== '') {
                                    echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
                                }
                            }
                            ?>
                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Undo payment approval"><i class="fas fa-rotate-left"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
