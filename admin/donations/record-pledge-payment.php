<?php
// admin/donations/record-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_login();
$page_title = 'Record Pledge Payment';

$db = db();

// Check if table exists
$check = $db->query("SHOW TABLES LIKE 'pledge_payments'");
if ($check->num_rows === 0) {
    die("<div class='alert alert-danger m-4'>Error: Table 'pledge_payments' not found. Please run the migration SQL first.</div>");
}

// Donor Search
$search = $_GET['search'] ?? '';
$selected_donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

$donors = [];
if ($search || $selected_donor_id) {
    if ($selected_donor_id) {
        // Direct donor ID lookup
        $query = "SELECT id, name, phone, email, balance FROM donors WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $selected_donor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $donors[] = $row;
        }
    } elseif ($search) {
        try {
            // Search by donor details OR pledge notes (reference number)
            $term = "%$search%";
            
            // Get donor IDs from pledges with matching notes OR payment references
            $pledge_donors_sql = "
                SELECT DISTINCT donor_id FROM pledges WHERE notes LIKE ?
                UNION
                SELECT DISTINCT donor_id FROM pledge_payments WHERE reference_number LIKE ?
            ";
            $stmt = $db->prepare($pledge_donors_sql);
            $stmt->bind_param('ss', $term, $term);
            $stmt->execute();
            $pledge_donor_ids = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pledge_donor_ids[] = (int)$row['donor_id'];
            }
            
            // Build donor query
            $donor_sql = "SELECT DISTINCT d.id, d.name, d.phone, d.email, d.balance FROM donors d WHERE ";
            
            if (!empty($pledge_donor_ids)) {
                // Search by name/phone/email OR pledge reference
                $placeholders = implode(',', array_fill(0, count($pledge_donor_ids), '?'));
                $donor_sql .= "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ? OR d.id IN ($placeholders))";
                
                $types = 'sss' . str_repeat('i', count($pledge_donor_ids));
                $params = array_merge([$term, $term, $term], $pledge_donor_ids);
            } else {
                // Only search by name/phone/email
                $donor_sql .= "(d.name LIKE ? OR d.phone LIKE ? OR d.email LIKE ?)";
                $types = 'sss';
                $params = [$term, $term, $term];
            }
            
            $donor_sql .= " LIMIT 20";
            $stmt = $db->prepare($donor_sql);
            
            // PHP 8.0 compatible binding
            $bind_params = [$types];
            // Create a separate array for references to ensure stability
            $refs = [];
            foreach ($params as $key => $value) {
                $refs[$key] = $value;
                $bind_params[] = &$refs[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind_params);
            
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $donors[] = $row;
            }
        } catch (Exception $e) {
            // Silently fail or log error, but don't crash page
            // For admin debugging, maybe echo in comment
            echo "<!-- Search Error: " . htmlspecialchars($e->getMessage()) . " -->";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .donor-card { cursor: pointer; transition: all 0.2s; border: 1px solid #dee2e6; }
        .donor-card:hover { border-color: #0d6efd; background: #f8f9fa; }
        .donor-card.selected { border-color: #0d6efd; background: #e7f1ff; ring: 2px solid #0d6efd; }
        .pledge-row { cursor: pointer; }
        .pledge-row.selected { background-color: #e7f1ff; }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-4">
                <h1 class="h3 mb-4"><i class="fas fa-hand-holding-usd me-2"></i>Record Pledge Payment</h1>
                
                <div class="row g-4">
                    <!-- Left: Donor Selection -->
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-light">
                                <h5 class="mb-0 h6">1. Select Donor</h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="mb-3">
                                    <div class="input-group">
                                        <input type="text" name="search" class="form-control" placeholder="Name, phone, email, or pledge reference..." value="<?php echo htmlspecialchars($search); ?>">
                                        <button class="btn btn-primary"><i class="fas fa-search"></i></button>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>Search by donor details or pledge reference number
                                    </small>
                                </form>
                                
                                <div class="list-group list-group-flush" id="donorList">
                                    <?php if (empty($donors) && $search): ?>
                                        <div class="text-center py-3 text-muted">No donors found.</div>
                                    <?php elseif (empty($donors) && !$selected_donor_id): ?>
                                        <div class="text-center py-3 text-muted small">Search to find donors</div>
                                    <?php else: ?>
                                        <?php foreach ($donors as $d): ?>
                                            <div class="list-group-item donor-card p-3 <?php echo $selected_donor_id == $d['id'] ? 'selected' : ''; ?>" 
                                                 onclick="selectDonor(<?php echo $d['id']; ?>, '<?php echo addslashes($d['name']); ?>')">
                                                <div class="fw-bold"><?php echo htmlspecialchars($d['name']); ?></div>
                                                <div class="small text-muted"><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($d['phone']); ?></div>
                                                <div class="small text-muted"><i class="fas fa-wallet me-1"></i> Balance: £<?php echo number_format($d['balance'], 2); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Payment Details -->
                    <div class="col-md-8">
                        <div class="card shadow-sm" id="paymentCard" style="display: none;">
                            <div class="card-header bg-light">
                                <h5 class="mb-0 h6">2. Select Pledge & Enter Payment</h5>
                            </div>
                            <div class="card-body">
                                <h6 class="border-bottom pb-2 mb-3">Active Pledges for <span id="selectedDonorName" class="text-primary"></span></h6>
                                
                                <div class="table-responsive mb-4">
                                    <table class="table table-hover border" id="pledgeTable">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="40"></th>
                                                <th>Date</th>
                                                <th>Pledge Amount</th>
                                                <th>Remaining</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pledgeListBody">
                                            <tr><td colspan="5" class="text-center">Loading pledges...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <form id="paymentForm">
                                    <input type="hidden" name="donor_id" id="formDonorId">
                                    <input type="hidden" name="pledge_id" id="formPledgeId">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Payment Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text">£</span>
                                                <input type="number" step="0.01" name="amount" id="paymentAmount" class="form-control" required>
                                            </div>
                                            <small class="text-muted">Max: £<span id="maxAmount">0.00</span></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Payment Date</label>
                                            <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Payment Method</label>
                                            <select name="payment_method" class="form-select" required>
                                                <option value="cash">Cash</option>
                                                <option value="bank_transfer">Bank Transfer</option>
                                                <option value="card">Card</option>
                                                <option value="cheque">Cheque</option>
                                                <option value="other">Other</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Reference Number</label>
                                            <input type="text" name="reference_number" class="form-control" placeholder="e.g. Receipt #123">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Payment Proof <small class="text-muted">(Optional)</small></label>
                                            <input type="file" name="payment_proof" class="form-control" accept="image/*,.pdf">
                                            <small class="text-muted">Upload receipt, bank statement, or payment screenshot (recommended for faster approval)</small>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Notes</label>
                                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 pt-3 border-top text-end">
                                        <button type="submit" class="btn btn-success px-4" id="btnSubmit">
                                            <i class="fas fa-save me-2"></i>Save Payment
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div id="emptyState" class="text-center py-5 text-muted border rounded bg-white shadow-sm">
                            <i class="fas fa-arrow-left fa-2x mb-3"></i>
                            <p>Select a donor from the list to proceed.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
function selectDonor(id, name) {
    document.getElementById('formDonorId').value = id;
    document.getElementById('selectedDonorName').textContent = name;
    document.getElementById('paymentCard').style.display = 'block';
    document.getElementById('emptyState').style.display = 'none';
    
    // Update UI selection
    document.querySelectorAll('.donor-card').forEach(c => c.classList.remove('selected'));
    // Ideally select the clicked one but simpler to just reload styling via class logic if using pure JS/PHP mix
    
    fetchPledges(id);
}

function fetchPledges(donorId) {
    const tbody = document.getElementById('pledgeListBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></td></tr>';
    
    fetch(`get-donor-pledges.php?donor_id=${donorId}`)
    .then(r => r.json())
    .then(res => {
        tbody.innerHTML = '';
        if (res.success && res.pledges.length > 0) {
            res.pledges.forEach(p => {
                const tr = document.createElement('tr');
                tr.className = 'pledge-row';
                tr.onclick = () => selectPledge(p.id, p.remaining);
                tr.innerHTML = `
                    <td><input type="radio" name="pledge_select" value="${p.id}" id="pledge_${p.id}"></td>
                    <td>${p.date}</td>
                    <td>£${p.amount.toFixed(2)}</td>
                    <td class="fw-bold text-danger">£${p.remaining.toFixed(2)}</td>
                    <td><small class="text-muted">${p.notes || '-'}</small></td>
                `;
                tbody.appendChild(tr);
            });
            // Auto-select first one
            selectPledge(res.pledges[0].id, res.pledges[0].remaining);
        } else {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">No active unpaid pledges found for this donor.</td></tr>';
            document.getElementById('btnSubmit').disabled = true;
        }
    });
}

function selectPledge(id, remaining) {
    document.getElementById(`pledge_${id}`).checked = true;
    document.getElementById('formPledgeId').value = id;
    document.getElementById('paymentAmount').value = remaining.toFixed(2);
    document.getElementById('maxAmount').textContent = remaining.toFixed(2);
    document.getElementById('btnSubmit').disabled = false;
    
    // Highlight row
    document.querySelectorAll('.pledge-row').forEach(r => r.classList.remove('selected'));
    document.getElementById(`pledge_${id}`).closest('tr').classList.add('selected');
}

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if(!confirm('Submit this payment for approval?\n\nThe payment will be reviewed by an admin before being finalized.')) return;
    
    const formData = new FormData(this);
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
    
    fetch('save-pledge-payment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            alert('Payment submitted for approval!\n\nAn admin will review and approve it shortly.');
            location.reload();
        } else {
            alert('Error: ' + res.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-2"></i>Save Payment';
        }
    })
    .catch(err => {
        alert('System error occurred');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-2"></i>Save Payment';
    });
});

<?php if($selected_donor_id): ?>
    // Auto-select if loaded from URL
    <?php 
    $name = '';
    foreach($donors as $d) { if($d['id'] == $selected_donor_id) $name = $d['name']; } 
    ?>
    selectDonor(<?php echo $selected_donor_id; ?>, '<?php echo addslashes($name); ?>');
<?php endif; ?>
</script>
</body>
</html>

