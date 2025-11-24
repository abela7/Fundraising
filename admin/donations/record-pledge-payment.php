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
            transition: all 0.2s ease;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }
        .donor-card:hover {
            border-color: #0d6efd;
            box-shadow: 0 2px 8px rgba(13, 110, 253, 0.15);
            transform: translateY(-2px);
        }
        .donor-card.selected {
            border-color: #0d6efd;
            background: linear-gradient(135deg, #e7f1ff 0%, #f8f9ff 100%);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }
        .pledge-card {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 1rem;
            padding: 1rem;
        }
        .pledge-card:hover {
            border-color: #198754;
            box-shadow: 0 2px 8px rgba(25, 135, 84, 0.15);
        }
        .pledge-card.selected {
            border-color: #198754;
            background: linear-gradient(135deg, #d1f4e0 0%, #e8f8f0 100%);
            box-shadow: 0 4px 12px rgba(25, 135, 84, 0.2);
        }
        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
            margin-right: 0.5rem;
        }
        .card-header-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 1rem 1.25rem;
        }
        .stat-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .stat-badge.balance {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        .stat-badge.remaining {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: white;
        }
        .empty-state {
            padding: 3rem 1rem;
            text-align: center;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .btn-submit-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            transition: all 0.2s ease;
        }
        .btn-submit-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }
        @media (max-width: 768px) {
            .donor-card {
                margin-bottom: 0.5rem;
            }
            .pledge-card {
                padding: 0.75rem;
            }
            .step-badge {
                width: 28px;
                height: 28px;
                font-size: 0.8rem;
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
                <div class="d-flex align-items-center mb-4">
                    <div class="me-3">
                        <div class="icon-circle" style="width: 48px; height: 48px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <i class="fas fa-hand-holding-usd" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="h3 mb-0">Record Pledge Payment</h1>
                        <p class="text-muted mb-0 small">Submit installment payments for pending approval</p>
                    </div>
                </div>
                
                <div class="row g-3 g-md-4">
                    <!-- Left: Donor Selection -->
                    <div class="col-lg-4">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header-custom">
                                <div class="d-flex align-items-center">
                                    <span class="step-badge">1</span>
                                    <h5 class="mb-0">Select Donor</h5>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="mb-3">
                                    <div class="input-group shadow-sm">
                                        <input type="text" name="search" class="form-control border-0" 
                                               placeholder="Search by name, phone..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                        <button class="btn btn-primary border-0" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted d-block mt-2">
                                        <i class="fas fa-info-circle me-1"></i>You can also search by pledge reference
                                    </small>
                                </form>
                                
                                <div id="donorList" style="max-height: 500px; overflow-y: auto;">
                                    <?php if (empty($donors) && $search): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-search"></i>
                                            <p class="mb-0">No donors found</p>
                                            <small>Try a different search term</small>
                                        </div>
                                    <?php elseif (empty($donors) && !$selected_donor_id): ?>
                                        <div class="empty-state">
                                            <i class="fas fa-users"></i>
                                            <p class="mb-0">Start searching</p>
                                            <small>Enter name, phone, or reference</small>
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
                                                        <span class="stat-badge balance">
                                                            £<?php echo number_format($d['balance'], 2); ?>
                                                        </span>
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
                            <div class="card-header-custom">
                                <div class="d-flex align-items-center">
                                    <span class="step-badge">2</span>
                                    <h5 class="mb-0">Select Pledge & Enter Payment</h5>
                                </div>
                            </div>
                            <div class="card-body">
                                <!-- Pledges Section -->
                                <div class="mb-4">
                                    <h6 class="mb-3">
                                        <i class="fas fa-list-ul me-2"></i>Active Pledges for 
                                        <span id="selectedDonorName" class="text-primary fw-bold"></span>
                                    </h6>
                                    
                                    <div id="pledgeListBody">
                                        <div class="text-center py-4">
                                            <div class="spinner-border text-primary" role="status">
                                                <span class="visually-hidden">Loading...</span>
                                            </div>
                                            <p class="text-muted mt-2 small">Loading pledges...</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment Form -->
                                <div class="form-section">
                                    <h6 class="mb-3">
                                        <i class="fas fa-credit-card me-2"></i>Payment Details
                                    </h6>
                                    
                                    <form id="paymentForm">
                                        <input type="hidden" name="donor_id" id="formDonorId">
                                        <input type="hidden" name="pledge_id" id="formPledgeId">
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="fas fa-pound-sign me-1 text-primary"></i>Payment Amount
                                                </label>
                                                <div class="input-group shadow-sm">
                                                    <span class="input-group-text bg-primary text-white border-0">£</span>
                                                    <input type="number" step="0.01" name="amount" id="paymentAmount" 
                                                           class="form-control border-0" required>
                                                </div>
                                                <small class="text-muted">
                                                    <i class="fas fa-info-circle me-1"></i>Max: £<span id="maxAmount" class="fw-bold">0.00</span>
                                                </small>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="fas fa-calendar me-1 text-success"></i>Payment Date
                                                </label>
                                                <input type="date" name="payment_date" class="form-control shadow-sm border-0" 
                                                       value="<?php echo date('Y-m-d'); ?>" required>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="fas fa-wallet me-1 text-warning"></i>Payment Method
                                                </label>
                                                <select name="payment_method" class="form-select shadow-sm border-0" required>
                                                    <option value="cash">💵 Cash</option>
                                                    <option value="bank_transfer">🏦 Bank Transfer</option>
                                                    <option value="card">💳 Card</option>
                                                    <option value="cheque">📝 Cheque</option>
                                                    <option value="other">📌 Other</option>
                                                </select>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold">
                                                    <i class="fas fa-hashtag me-1 text-info"></i>Reference Number
                                                </label>
                                                <input type="text" name="reference_number" class="form-control shadow-sm border-0" 
                                                       placeholder="e.g. Receipt #123">
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">
                                                    <i class="fas fa-file-upload me-1 text-secondary"></i>Payment Proof 
                                                    <span class="badge bg-light text-secondary ms-1">Optional</span>
                                                </label>
                                                <input type="file" name="payment_proof" class="form-control shadow-sm border-0" 
                                                       accept="image/*,.pdf">
                                                <small class="text-muted">
                                                    <i class="fas fa-shield-check me-1"></i>Upload receipt or screenshot (helps speed up approval)
                                                </small>
                                            </div>
                                            
                                            <div class="col-12">
                                                <label class="form-label fw-semibold">
                                                    <i class="fas fa-sticky-note me-1 text-muted"></i>Notes
                                                </label>
                                                <textarea name="notes" class="form-control shadow-sm border-0" rows="2" 
                                                          placeholder="Any additional information..."></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="mt-4 pt-3 text-center">
                                            <button type="submit" class="btn btn-submit-custom btn-lg px-5" id="btnSubmit">
                                                <i class="fas fa-check-circle me-2"></i>Submit for Approval
                                            </button>
                                            <p class="text-muted small mt-2 mb-0">
                                                <i class="fas fa-info-circle me-1"></i>Payment will be reviewed by an admin before being confirmed
                                            </p>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div id="emptyState" class="card shadow-sm border-0">
                            <div class="card-body">
                                <div class="empty-state">
                                    <i class="fas fa-arrow-left"></i>
                                    <p class="mb-0 fw-semibold">Select a donor to continue</p>
                                    <small>Choose a donor from the list on the left</small>
                                </div>
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
    const container = document.getElementById('pledgeListBody');
    container.innerHTML = `
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="text-muted mt-2 small">Loading pledges...</p>
        </div>
    `;
    
    fetch(`get-donor-pledges.php?donor_id=${donorId}`)
    .then(r => r.json())
    .then(res => {
        container.innerHTML = '';
        if (res.success && res.pledges.length > 0) {
            res.pledges.forEach(p => {
                const card = document.createElement('div');
                card.className = 'pledge-card';
                card.id = `pledge_card_${p.id}`;
                card.onclick = () => selectPledge(p.id, p.remaining);
                card.innerHTML = `
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="pledge_select" value="${p.id}" id="pledge_${p.id}">
                            <label class="form-check-label fw-bold" for="pledge_${p.id}">
                                Pledge from ${p.date}
                            </label>
                        </div>
                        <span class="stat-badge remaining">
                            <i class="fas fa-exclamation-circle me-1"></i>£${p.remaining.toFixed(2)} due
                        </span>
                    </div>
                    <div class="row g-2 small">
                        <div class="col-6">
                            <div class="text-muted">Pledged Amount</div>
                            <div class="fw-semibold">£${p.amount.toFixed(2)}</div>
                        </div>
                        <div class="col-6">
                            <div class="text-muted">Status</div>
                            <div class="fw-semibold text-${p.remaining > 0 ? 'danger' : 'success'}">
                                ${p.remaining > 0 ? 'Outstanding' : 'Fully Paid'}
                            </div>
                        </div>
                        ${p.notes ? `
                        <div class="col-12 mt-1">
                            <div class="text-muted">Notes</div>
                            <div class="small">${p.notes}</div>
                        </div>
                        ` : ''}
                    </div>
                `;
                container.appendChild(card);
            });
            // Auto-select first one
            selectPledge(res.pledges[0].id, res.pledges[0].remaining);
        } else {
            container.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p class="mb-0">No active unpaid pledges</p>
                    <small>This donor has no outstanding pledges</small>
                </div>
            `;
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
    
    // Highlight card
    document.querySelectorAll('.pledge-card').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById(`pledge_card_${id}`);
    if(card) card.classList.add('selected');
}

document.getElementById('paymentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if(!confirm('Submit this payment for approval?\n\nThe payment will be reviewed by an admin before being finalized.')) return;
    
    const formData = new FormData(this);
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
    
    fetch('save-pledge-payment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(res => {
        if(res.success) {
            // Success alert with modern styling
            const successDiv = document.createElement('div');
            successDiv.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
            successDiv.style.zIndex = '9999';
            successDiv.style.minWidth = '300px';
            successDiv.innerHTML = `
                <i class="fas fa-check-circle me-2"></i>
                <strong>Success!</strong> Payment submitted for approval.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(successDiv);
            
            setTimeout(() => location.reload(), 1500);
        } else {
            alert('Error: ' + res.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Submit for Approval';
        }
    })
    .catch(err => {
        alert('System error occurred. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>Submit for Approval';
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

