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
            width: 32px;
            height: 32px;
            background: #0d6efd;
            color: white;
            border-radius: 50%;
            font-weight: 600;
            font-size: 0.875rem;
            margin-right: 0.5rem;
            transition: all 0.3s;
        }
        .step-indicator.active {
            background: #0d6efd;
            box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.2);
        }
        .step-indicator.completed {
            background: #198754;
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
        .donor-list-container {
            max-height: 500px;
            overflow-y: auto;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 991.98px) {
            .main-content {
                padding: 0.5rem !important;
            }
            .section-title {
                font-size: 0.75rem;
            }
            .step-indicator {
                width: 24px;
                height: 24px;
                font-size: 0.75rem;
            }
            .donor-card {
                padding: 0.75rem;
                margin-bottom: 0.5rem;
            }
            .donor-list-container {
                max-height: 300px;
            }
            .table {
                font-size: 0.875rem;
            }
            .form-group-custom {
                margin-bottom: 1rem;
            }
            .form-group-custom label {
                font-size: 0.8rem;
            }
        }
        
        @media (max-width: 575.98px) {
            .main-content {
                padding: 0.5rem !important;
            }
            h1 {
                font-size: 1.25rem !important;
            }
            .card-body {
                padding: 1rem;
            }
            .donor-card {
                padding: 0.625rem;
            }
            .section-title {
                font-size: 0.7rem;
                margin-bottom: 0.75rem;
            }
            .table {
                font-size: 0.75rem;
            }
            .table th, .table td {
                padding: 0.5rem 0.25rem;
            }
            .form-control, .form-select {
                font-size: 0.875rem;
                padding: 0.5rem;
            }
            .btn {
                font-size: 0.875rem;
                padding: 0.5rem 1rem;
            }
            .input-group-text {
                padding: 0.5rem;
                font-size: 0.875rem;
            }
            /* Hide less important columns on very small screens */
            .table .hide-xs {
                display: none;
            }
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
                <div class="d-flex justify-content-between align-items-center mb-3 mb-md-4">
                    <div>
                        <h1 class="h4 mb-1">
                            <i class="fas fa-hand-holding-usd text-primary me-2"></i>
                            <span class="d-none d-md-inline">Record Pledge Payment</span>
                            <span class="d-inline d-md-none">Record Payment</span>
                        </h1>
                        <p class="text-muted small mb-0 d-none d-sm-block">Submit installment payments against pledges for approval</p>
                    </div>
                </div>
                
                <!-- Step Progress Indicator -->
                <div class="mb-4">
                    <div class="d-flex align-items-center justify-content-center gap-3">
                        <div class="d-flex align-items-center">
                            <div class="step-indicator" id="stepIndicator1">1</div>
                            <span class="small fw-bold text-muted" id="stepLabel1">Select Donor</span>
                        </div>
                        <div class="text-muted">→</div>
                        <div class="d-flex align-items-center">
                            <div class="step-indicator bg-secondary" id="stepIndicator2">2</div>
                            <span class="small text-muted" id="stepLabel2">Enter Payment</span>
                        </div>
                    </div>
                </div>

                <div class="row justify-content-center">
                    <!-- Step 1: Donor Selection -->
                    <div class="col-lg-8 col-xl-6" id="step1Container">
                        <div class="card shadow-sm border-0">
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
                                
                                <div class="donor-list-container">
                                    <?php if (empty($donors) && $search): ?>
                                        <div class="text-center py-3 py-md-4 text-muted">
                                            <i class="fas fa-search fa-2x mb-2 opacity-25"></i>
                                            <p class="small mb-0">No donors found</p>
                                        </div>
                                    <?php elseif (empty($donors) && !$selected_donor_id): ?>
                                        <div class="text-center py-3 py-md-4 text-muted">
                                            <i class="fas fa-users fa-2x mb-2 opacity-25"></i>
                                            <p class="small mb-0">Search to find donors</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($donors as $d): ?>
                                            <div class="donor-card <?php echo $selected_donor_id == $d['id'] ? 'selected' : ''; ?>" 
                                                 onclick="selectDonor(<?php echo $d['id']; ?>, '<?php echo addslashes($d['name']); ?>')">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1 me-2">
                                                        <div class="fw-bold mb-1"><?php echo htmlspecialchars($d['name']); ?></div>
                                                        <div class="small text-muted">
                                                            <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($d['phone']); ?>
                                                        </div>
                                                    </div>
                                                    <div class="flex-shrink-0">
                                                        <span class="badge bg-danger">£<?php echo number_format($d['balance'], 2); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Step 1 Action Buttons -->
                                <div class="mt-4 pt-3 border-top text-end" id="step1Actions" style="display: none;">
                                    <button type="button" class="btn btn-primary px-4" id="btnNext">
                                        <span>Next: Enter Payment</span>
                                        <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: Payment Details -->
                    <div class="col-lg-10 col-xl-8" id="step2Container" style="display: none;">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <div class="section-title">
                                    <span class="step-indicator">2</span>
                                    Select Pledge & Enter Payment
                                </div>
                                
                                <div class="alert alert-info mb-3">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-user-circle fa-2x me-3"></i>
                                        <div>
                                            <strong>Selected Donor:</strong> <span id="selectedDonorNameTop" class="text-primary"></span><br>
                                            <small class="text-muted">Recording payment for this donor</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <h6 class="mb-3">
                                    Active Pledges for <span id="selectedDonorName" class="text-primary fw-bold"></span>
                                </h6>
                                
                                <div class="table-responsive mb-3 mb-md-4">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="30"></th>
                                                <th>Date</th>
                                                <th class="text-end">Amount</th>
                                                <th class="text-end">Remaining</th>
                                                <th class="hide-xs">Notes</th>
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
                                    
                                    <div class="mt-3 mt-md-4 pt-3 border-top d-flex flex-column flex-sm-row justify-content-between gap-2">
                                        <button type="button" class="btn btn-light order-2 order-sm-1" id="btnBack">
                                            <i class="fas fa-arrow-left me-2"></i>Back to Donors
                                        </button>
                                        <button type="submit" class="btn btn-primary px-4 order-1 order-sm-2" id="btnSubmit">
                                            <i class="fas fa-check me-2"></i>
                                            <span class="d-none d-sm-inline">Submit for Approval</span>
                                            <span class="d-inline d-sm-none">Submit</span>
                                        </button>
                                    </div>
                                </form>
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
let selectedDonorId = null;
let selectedDonorName = '';

function selectDonor(id, name) {
    selectedDonorId = id;
    selectedDonorName = name;
    
    document.getElementById('formDonorId').value = id;
    document.getElementById('selectedDonorName').textContent = name;
    document.getElementById('selectedDonorNameTop').textContent = name;
    
    // Show next button
    document.getElementById('step1Actions').style.display = 'block';
    
    // Highlight selected card
    document.querySelectorAll('.donor-card').forEach(c => c.classList.remove('selected'));
    event.target.closest('.donor-card').classList.add('selected');
}

// Next button: Go to Step 2
document.getElementById('btnNext').addEventListener('click', function() {
    if (!selectedDonorId) {
        alert('Please select a donor first');
        return;
    }
    
    // Hide Step 1
    document.getElementById('step1Container').style.display = 'none';
    
    // Show Step 2
    document.getElementById('step2Container').style.display = 'block';
    
    // Update progress indicators
    document.getElementById('stepIndicator1').classList.remove('active');
    document.getElementById('stepIndicator1').classList.add('completed');
    document.getElementById('stepLabel1').classList.remove('text-muted');
    document.getElementById('stepLabel1').classList.add('text-success');
    
    document.getElementById('stepIndicator2').classList.remove('bg-secondary');
    document.getElementById('stepIndicator2').classList.add('active');
    document.getElementById('stepLabel2').classList.remove('text-muted');
    document.getElementById('stepLabel2').classList.add('fw-bold');
    
    // Fetch pledges
    fetchPledges(selectedDonorId);
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

// Back button: Go to Step 1
document.getElementById('btnBack').addEventListener('click', function() {
    // Show Step 1
    document.getElementById('step1Container').style.display = 'block';
    
    // Hide Step 2
    document.getElementById('step2Container').style.display = 'none';
    
    // Update progress indicators
    document.getElementById('stepIndicator1').classList.add('active');
    document.getElementById('stepIndicator1').classList.remove('completed');
    document.getElementById('stepLabel1').classList.add('text-muted');
    document.getElementById('stepLabel1').classList.remove('text-success');
    
    document.getElementById('stepIndicator2').classList.add('bg-secondary');
    document.getElementById('stepIndicator2').classList.remove('active');
    document.getElementById('stepLabel2').classList.add('text-muted');
    document.getElementById('stepLabel2').classList.remove('fw-bold');
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
});

function fetchPledges(donorId) {
    const tbody = document.getElementById('pledgeListBody');
    tbody.innerHTML = `
        <tr>
            <td colspan="5" class="text-center py-3">
                <div class="spinner-border spinner-border-sm text-primary me-2"></div>
                <span class="text-muted small">Loading pledges...</span>
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
                
                // Truncate notes for mobile
                const notes = p.notes || '—';
                const displayNotes = notes.length > 20 ? notes.substring(0, 20) + '...' : notes;
                
                tr.innerHTML = `
                    <td class="text-center">
                        <input type="radio" name="pledge_select" value="${p.id}" id="pledge_${p.id}" class="form-check-input">
                    </td>
                    <td><small class="text-nowrap">${p.date}</small></td>
                    <td class="text-end text-nowrap"><small>£${p.amount.toFixed(2)}</small></td>
                    <td class="text-end text-nowrap"><strong class="text-danger">£${p.remaining.toFixed(2)}</strong></td>
                    <td class="hide-xs"><small class="text-muted">${displayNotes}</small></td>
                `;
                tbody.appendChild(tr);
            });
            // Auto-select first one
            selectPledge(res.pledges[0].id, res.pledges[0].remaining);
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center py-3 py-md-4">
                        <i class="fas fa-inbox fa-2x mb-2 text-muted opacity-25 d-block"></i>
                        <p class="text-muted small mb-0">No active unpaid pledges</p>
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
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <span class="small">Error loading pledges</span>
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

