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
        .donor-card {
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
        }
        .donor-card:hover {
            border-color: #0d6efd;
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .donor-card.selected {
            border-color: #0d6efd;
            background: #e7f1ff;
            box-shadow: 0 2px 8px rgba(13,110,253,0.2);
        }
        .pledge-row {
            cursor: pointer;
            transition: background 0.15s;
        }
        .pledge-row:hover {
            background: #f8f9fa;
        }
        .pledge-row.selected {
            background: #e7f1ff;
            border-left: 3px solid #0d6efd;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
        }
        .stat-card h3 { font-size: 1.75rem; font-weight: 700; margin: 0; }
        .stat-card p { margin: 0; opacity: 0.9; font-size: 0.875rem; }
        .section-title {
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        .step-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: #0d6efd;
            color: white;
            border-radius: 50%;
            font-weight: 600;
            font-size: 0.875rem;
            margin-right: 0.5rem;
        }
        .form-group-custom {
            margin-bottom: 1.25rem;
        }
        .form-group-custom label {
            font-weight: 600;
            font-size: 0.875rem;
            color: #495057;
            margin-bottom: 0.5rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.15);
        }
        @media (max-width: 768px) {
            .stat-card h3 { font-size: 1.5rem; }
            .donor-card { padding: 0.75rem; }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h4 mb-1"><i class="fas fa-hand-holding-usd text-primary me-2"></i>Record Pledge Payment</h1>
                        <p class="text-muted small mb-0">Submit installment payments against pledges for approval</p>
                    </div>
                </div>
                
                <div class="row g-3 g-md-4">
                    <!-- Left: Donor Selection -->
                    <div class="col-lg-4">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body">
                                <div class="section-title">
                                    <span class="step-indicator">1</span>
                                    Select Donor
                                </div>
                                
                                <form method="GET" class="mb-3">
                                    <div class="input-group">
                                        <input type="text" name="search" class="form-control" 
                                               placeholder="Search name, phone, or reference..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                        <button class="btn btn-primary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>Search by donor info or pledge reference
                                    </small>
                                </form>
                                
                                <div id="donorList" style="max-height: 500px; overflow-y: auto;">
                                    <?php if (empty($donors) && $search): ?>
                                        <div class="text-center py-4 text-muted">
                                            <i class="fas fa-search fa-2x mb-2 opacity-25"></i>
                                            <p class="small mb-0">No donors found</p>
                                        </div>
                                    <?php elseif (empty($donors) && !$selected_donor_id): ?>
                                        <div class="text-center py-4 text-muted">
                                            <i class="fas fa-users fa-2x mb-2 opacity-25"></i>
                                            <p class="small mb-0">Search to find donors</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($donors as $d): ?>
                                            <div class="donor-card <?php echo $selected_donor_id == $d['id'] ? 'selected' : ''; ?>" 
                                                 onclick="selectDonor(<?php echo $d['id']; ?>, '<?php echo addslashes($d['name']); ?>')">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <div class="fw-bold mb-1"><?php echo htmlspecialchars($d['name']); ?></div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($d['phone']); ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <span class="badge bg-danger">£<?php echo number_format($d['balance'], 2); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right: Payment Details -->
                    <div class="col-lg-8">
                        <div class="card shadow-sm border-0" id="paymentCard" style="display: none;">
                            <div class="card-body">
                                <div class="section-title">
                                    <span class="step-indicator">2</span>
                                    Select Pledge & Enter Payment
                                </div>
                                
                                <h6 class="mb-3">Active Pledges for <span id="selectedDonorName" class="text-primary fw-bold"></span></h6>
                                
                                <div class="table-responsive mb-4">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="40"></th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th class="text-end">Remaining</th>
                                                <th>Notes</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pledgeListBody">
                                            <tr><td colspan="5" class="text-center py-3 text-muted">
                                                <div class="spinner-border spinner-border-sm me-2"></div>Loading...
                                            </td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <form id="paymentForm">
                                    <input type="hidden" name="donor_id" id="formDonorId">
                                    <input type="hidden" name="pledge_id" id="formPledgeId">
                                    
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Amount</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">£</span>
                                                    <input type="number" step="0.01" name="amount" id="paymentAmount" 
                                                           class="form-control" placeholder="0.00" required>
                                                </div>
                                                <small class="text-muted">Max: £<span id="maxAmount" class="fw-bold">0.00</span></small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Date</label>
                                                <input type="date" name="payment_date" class="form-control" 
                                                       value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Method</label>
                                                <select name="payment_method" class="form-select" required>
                                                    <option value="">Select method...</option>
                                                    <option value="cash">Cash</option>
                                                    <option value="bank_transfer">Bank Transfer</option>
                                                    <option value="card">Card</option>
                                                    <option value="cheque">Cheque</option>
                                                    <option value="other">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group-custom">
                                                <label class="form-label">Reference Number <span class="text-muted">(Optional)</span></label>
                                                <input type="text" name="reference_number" class="form-control" 
                                                       placeholder="Receipt/transaction #">
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group-custom">
                                                <label class="form-label">Payment Proof <span class="text-muted">(Optional)</span></label>
                                                <input type="file" name="payment_proof" class="form-control" 
                                                       accept="image/*,.pdf">
                                                <small class="text-muted">
                                                    <i class="fas fa-paperclip me-1"></i>Upload receipt or screenshot (helps speed up approval)
                                                </small>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-group-custom mb-0">
                                                <label class="form-label">Additional Notes <span class="text-muted">(Optional)</span></label>
                                                <textarea name="notes" class="form-control" rows="2" 
                                                          placeholder="Any additional information..."></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                                        <button type="button" class="btn btn-light" onclick="location.reload()">
                                            <i class="fas fa-times me-2"></i>Cancel
                                        </button>
                                        <button type="submit" class="btn btn-primary px-4" id="btnSubmit">
                                            <i class="fas fa-check me-2"></i>Submit for Approval
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <div id="emptyState" class="card shadow-sm border-0">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-arrow-left fa-3x mb-3 text-muted opacity-25"></i>
                                <h5 class="text-muted">Select a Donor</h5>
                                <p class="text-muted mb-0">Search and select a donor from the list to continue</p>
                            </div>
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
    tbody.innerHTML = `
        <tr>
            <td colspan="5" class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                <span class="text-muted">Loading pledges...</span>
            </td>
        </tr>
    `;
    
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
                    <td class="text-center">
                        <input type="radio" name="pledge_select" value="${p.id}" id="pledge_${p.id}" class="form-check-input">
                    </td>
                    <td><small>${p.date}</small></td>
                    <td class="text-end">£${p.amount.toFixed(2)}</td>
                    <td class="text-end"><strong class="text-danger">£${p.remaining.toFixed(2)}</strong></td>
                    <td><small class="text-muted">${p.notes || '—'}</small></td>
                `;
                tbody.appendChild(tr);
            });
            // Auto-select first one
            selectPledge(res.pledges[0].id, res.pledges[0].remaining);
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-4">
                        <i class="fas fa-inbox fa-2x mb-2 text-muted opacity-25"></i>
                        <p class="text-muted small mb-0">No active unpaid pledges found for this donor</p>
                    </td>
                </tr>
            `;
            document.getElementById('btnSubmit').disabled = true;
        }
    })
    .catch(err => {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center py-3 text-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>Error loading pledges
                </td>
            </tr>
        `;
        console.error(err);
    });
}

function selectPledge(id, remaining) {
    document.getElementById('formPledgeId').value = id;
    document.getElementById('paymentAmount').value = remaining.toFixed(2);
    document.getElementById('paymentAmount').max = remaining;
    document.getElementById('maxAmount').textContent = remaining.toFixed(2);
    document.getElementById('btnSubmit').disabled = false;
    
    // Highlight row and check radio
    document.querySelectorAll('.pledge-row').forEach(r => r.classList.remove('selected'));
    const radio = document.getElementById(`pledge_${id}`);
    radio.checked = true;
    radio.closest('tr').classList.add('selected');
}

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!document.getElementById('formPledgeId').value) {
        alert('Please select a pledge first');
        return;
    }
    
    if (!confirm('Submit this payment for approval?\n\nThe payment will be reviewed by an admin before being finalized.')) {
        return;
    }
    
    const formData = new FormData(this);
    const btn = document.getElementById('btnSubmit');
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
    
    fetch('save-pledge-payment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            // Show success alert
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
            alertDiv.style.zIndex = '9999';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> Payment submitted for approval.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);
            
            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Error: ' + res.message);
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    })
    .catch(err => {
        console.error(err);
        alert('An error occurred. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalHTML;
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

