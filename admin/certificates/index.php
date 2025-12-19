<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

require_admin();

$db = db();
$currency = '£';

// Search functionality
$search = trim($_GET['search'] ?? '');
$donors = [];

if (!empty($search)) {
    // Search by name, phone, or reference number (4-digit in pledge notes)
    $searchTerm = '%' . $search . '%';
    
    $sql = "
        SELECT DISTINCT 
            d.id,
            d.name,
            d.phone,
            d.email,
            d.total_pledged,
            d.total_paid,
            d.balance,
            d.payment_status,
            (SELECT p.notes FROM pledges p WHERE p.donor_id = d.id ORDER BY p.created_at DESC LIMIT 1) as pledge_notes
        FROM donors d
        LEFT JOIN pledges p ON p.donor_id = d.id
        WHERE 
            d.name LIKE ? 
            OR d.phone LIKE ? 
            OR d.email LIKE ?
            OR p.notes LIKE ?
        ORDER BY d.name ASC
        LIMIT 50
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    $stmt->execute();
    $donors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get donor details for certificate preview
$selectedDonor = null;
$donorReference = '';
$sqmValue = 0;

if (isset($_GET['donor_id'])) {
    $donorId = (int)$_GET['donor_id'];
    
    // Get donor info
    $stmt = $db->prepare("SELECT * FROM donors WHERE id = ?");
    $stmt->bind_param('i', $donorId);
    $stmt->execute();
    $selectedDonor = $stmt->get_result()->fetch_assoc();
    
    if ($selectedDonor) {
        // Get reference number from pledge notes
        $stmt = $db->prepare("SELECT notes FROM pledges WHERE donor_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $pledgeResult = $stmt->get_result()->fetch_assoc();
        
        if ($pledgeResult && !empty($pledgeResult['notes'])) {
            if (preg_match('/\b(\d{4})\b/', $pledgeResult['notes'], $matches)) {
                $donorReference = $matches[1];
            }
        }
        
        // Calculate sq.m: £400 = 1 sq.m
        $sqmValue = round($selectedDonor['total_paid'] / 400, 2);
    }
}

/**
 * Calculate sq.m from amount
 * £400 = 1 sq.m, £200 = 0.5 sq.m, £100 = 0.25 sq.m
 */
function calculateSqm(float $amount): float {
    return round($amount / 400, 2);
}

/**
 * Extract 4-digit reference from notes
 */
function extractReference(string $notes): string {
    if (preg_match('/\b(\d{4})\b/', $notes, $matches)) {
        return $matches[1];
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Certificate Management - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/certificates.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid">
                
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-certificate text-warning"></i> Certificate Management</h2>
                        <p class="text-muted mb-0">Search donors and generate their certificates</p>
                    </div>
                </div>
                
                <!-- Search Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-search"></i> Search Donor</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-8">
                                <div class="input-group input-group-lg">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input 
                                        type="text" 
                                        class="form-control" 
                                        name="search" 
                                        placeholder="Search by name, phone, or 4-digit reference number..."
                                        value="<?= htmlspecialchars($search) ?>"
                                        autofocus
                                    >
                                    <button class="btn btn-primary" type="submit">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="bg-light p-2 rounded text-center">
                                    <small class="text-muted">
                                        <strong>Sq.m Calculation:</strong><br>
                                        £400 = 1m² | £200 = 0.5m² | £100 = 0.25m²
                                    </small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <?php if (!empty($search)): ?>
                <!-- Search Results -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-users"></i> Search Results (<?= count($donors) ?>)</h5>
                        <?php if (!empty($donors)): ?>
                            <span class="badge bg-secondary">Showing up to 50 results</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($donors)): ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle"></i> No donors found matching "<strong><?= htmlspecialchars($search) ?></strong>"
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Donor</th>
                                            <th>Phone</th>
                                            <th>Reference</th>
                                            <th class="text-end">Total Paid</th>
                                            <th class="text-end">Sq.m</th>
                                            <th>Status</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($donors as $donor): 
                                            $ref = extractReference($donor['pledge_notes'] ?? '');
                                            $sqm = calculateSqm((float)$donor['total_paid']);
                                        ?>
                                            <tr class="<?= isset($_GET['donor_id']) && (int)$_GET['donor_id'] === (int)$donor['id'] ? 'table-primary' : '' ?>">
                                                <td>
                                                    <strong><?= htmlspecialchars($donor['name']) ?></strong>
                                                    <?php if ($donor['email']): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($donor['email']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($donor['phone']) ?></td>
                                                <td>
                                                    <?php if ($ref): ?>
                                                        <span class="badge bg-dark font-monospace"><?= $ref ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <strong class="text-success"><?= $currency . number_format((float)$donor['total_paid'], 2) ?></strong>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($sqm > 0): ?>
                                                        <span class="badge bg-warning text-dark fs-6"><?= $sqm ?> m²</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0 m²</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $statusClass = match($donor['payment_status']) {
                                                        'completed' => 'success',
                                                        'partial' => 'warning',
                                                        default => 'secondary'
                                                    };
                                                    ?>
                                                    <span class="badge bg-<?= $statusClass ?>">
                                                        <?= ucfirst($donor['payment_status']) ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="?search=<?= urlencode($search) ?>&donor_id=<?= $donor['id'] ?>" 
                                                       class="btn btn-sm btn-primary">
                                                        <i class="fas fa-certificate"></i> View Certificate
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($selectedDonor): ?>
                <!-- Certificate Preview -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-certificate text-warning"></i> 
                            Certificate for <?= htmlspecialchars($selectedDonor['name']) ?>
                        </h5>
                        <div>
                            <button class="btn btn-success" onclick="printCertificate()">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button class="btn btn-info" onclick="downloadCertificate()">
                                <i class="fas fa-download"></i> Download
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Donor Info Summary -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="bg-light p-3 rounded text-center">
                                    <small class="text-muted">Reference Number</small>
                                    <h4 class="mb-0 font-monospace"><?= $donorReference ?: 'N/A' ?></h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="bg-light p-3 rounded text-center">
                                    <small class="text-muted">Total Paid</small>
                                    <h4 class="mb-0 text-success"><?= $currency . number_format((float)$selectedDonor['total_paid'], 2) ?></h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="bg-light p-3 rounded text-center">
                                    <small class="text-muted">Square Meters</small>
                                    <h4 class="mb-0 text-warning"><?= $sqmValue ?> m²</h4>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="bg-light p-3 rounded text-center">
                                    <small class="text-muted">Phone</small>
                                    <h4 class="mb-0"><?= htmlspecialchars($selectedDonor['phone']) ?></h4>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Certificate Preview Container -->
                        <div class="certificate-preview-container">
                            <div id="cert-scaler">
                                <div class="certificate">
                                    <!-- Subtle church image overlay -->
                                    <div class="church-overlay"></div>

                                    <div class="top-section">
                                        <div class="top-verse">
                                            "የምሠራትም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጅልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ።" ፪ ዜና ፪፡፱
                                        </div>
                                        <div class="church-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>
                                    </div>

                                    <div class="center-section">
                                        <div class="title-am">ይህ ታሪኬ ነው</div>
                                        <div class="title-en">It is My History</div>
                                    </div>

                                    <div class="bottom-section">
                                        <div class="bank-area">
                                            <div class="qr-code">
                                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=https://abuneteklehaymanot.org/" alt="QR">
                                            </div>
                                            <div class="bank-details">
                                                <div class="bank-row">
                                                    <span class="bank-label">Donor</span>
                                                    <span class="bank-val"><?= htmlspecialchars($selectedDonor['name']) ?></span>
                                                </div>
                                                <div class="bank-row">
                                                    <span class="bank-label">Contribution</span>
                                                    <span class="bank-val"><?= $currency . number_format((float)$selectedDonor['total_paid'], 2) ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="right-area">
                                            <div class="sqm-label">Sq.m</div>
                                            <div class="pill-box">
                                                <span class="sqm-value"><?= $sqmValue ?></span>
                                            </div>
                                            <?php if ($donorReference): ?>
                                                <div class="reference-number"><?= $donorReference ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/certificates.js"></script>
</body>
</html>
