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
        
        // Calculate sq.m based on PLEDGE (commitment), not just paid
        // Use the higher of pledged or paid (in case paid exceeds pledge)
        $totalPledged = (float)($selectedDonor['total_pledged'] ?? 0);
        $totalPaid = (float)($selectedDonor['total_paid'] ?? 0);
        $allocationBase = max($totalPledged, $totalPaid);
        $sqmValue = round($allocationBase / 400, 2);
        
        // Calculate payment progress
        $paymentProgress = $totalPledged > 0 ? min(100, round(($totalPaid / $totalPledged) * 100)) : ($totalPaid > 0 ? 100 : 0);
        $isFullyPaid = $totalPledged > 0 && $totalPaid >= $totalPledged;
    }
}

/**
 * Calculate sq.m from pledge amount (not just paid)
 * £400 = 1 sq.m, £200 = 0.5 sq.m, £100 = 0.25 sq.m
 * 
 * @param float $pledged Total pledged amount
 * @param float $paid Total paid amount
 * @return float Square meters allocated
 */
function calculateSqm(float $pledged, float $paid = 0): float {
    // Use the higher of pledged or paid
    $base = max($pledged, $paid);
    return round($base / 400, 2);
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
                <div class="mb-4">
                    <h4 class="mb-1 fw-bold d-flex align-items-center flex-wrap gap-2">
                        <i class="fas fa-certificate text-warning"></i> 
                        <span>Certificate Hub</span>
                    </h4>
                    <p class="text-muted mb-0 small">Generate contribution certificates</p>
                </div>
                
                <!-- Search Section -->
                <div class="row g-3 mb-4">
                    <div class="col-12 col-lg-8">
                        <div class="card border-0 shadow-sm">
                            <div class="card-body p-3 p-md-4">
                                <form method="GET" action="" class="search-form">
                                    <div class="d-flex flex-column flex-sm-row gap-2">
                                        <div class="input-group flex-grow-1">
                                            <span class="input-group-text bg-white border-end-0">
                                                <i class="fas fa-search text-primary"></i>
                                            </span>
                                            <input 
                                                type="text" 
                                                class="form-control border-start-0 py-2 py-md-3" 
                                                name="search" 
                                                placeholder="Name, phone, or reference..."
                                                value="<?= htmlspecialchars($search) ?>"
                                                autofocus
                                            >
                                        </div>
                                        <button class="btn btn-primary px-4 py-2 py-md-3 fw-bold flex-shrink-0" type="submit">
                                            <i class="fas fa-search me-2 d-sm-none"></i>
                                            <span>Search</span>
                                        </button>
                                    </div>
                                    <div class="mt-2 text-muted small d-none d-md-block">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Tip: Try searching by the last 4 digits of the reference number.
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <div class="search-info-card h-100">
                            <span class="search-info-title">Value Conversion</span>
                            <div class="search-info-grid">
                                <div class="search-info-item">
                                    <span class="search-info-value">£400</span>
                                    <span class="search-info-label">1.0 m²</span>
                                </div>
                                <div class="search-info-item">
                                    <span class="search-info-value">£200</span>
                                    <span class="search-info-label">0.5 m²</span>
                                </div>
                                <div class="search-info-item">
                                    <span class="search-info-value">£100</span>
                                    <span class="search-info-label">0.25 m²</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($search) && !$selectedDonor): ?>
                    <!-- Welcome Empty State -->
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <h5 class="fw-bold">Search for a Donor</h5>
                        <p class="text-muted mx-auto mb-0" style="max-width: 320px;">
                            Enter a name or reference number to generate their certificate.
                        </p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($search)): ?>
                <!-- Search Results Section -->
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <h6 class="fw-bold mb-0">
                            <i class="fas fa-users text-primary me-1"></i>
                            Results
                        </h6>
                        <span class="badge bg-primary rounded-pill"><?= count($donors) ?></span>
                    </div>

                    <?php if (empty($donors)): ?>
                        <div class="alert alert-info border-0 shadow-sm rounded-4 p-4 d-flex align-items-center">
                            <i class="fas fa-info-circle fs-3 me-3 text-primary"></i>
                            <div>
                                <h6 class="mb-1 fw-bold">No matches found</h6>
                                <p class="mb-0 text-muted">We couldn't find any donors matching "<strong><?= htmlspecialchars($search) ?></strong>". Try a different name or phone number.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="d-none d-md-block">
                            <div class="table-responsive">
                                <table class="table modern-table align-middle">
                                    <thead>
                                        <tr>
                                            <th>Donor Profile</th>
                                            <th>Contact</th>
                                            <th>Reference</th>
                                            <th class="text-end">Paid Amount</th>
                                            <th class="text-end">Allocation</th>
                                            <th class="text-center">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($donors as $donor): 
                                            $ref = extractReference($donor['pledge_notes'] ?? '');
                                            $sqm = calculateSqm((float)($donor['total_pledged'] ?? 0), (float)($donor['total_paid'] ?? 0));
                                            $isSelected = isset($_GET['donor_id']) && (int)$_GET['donor_id'] === (int)$donor['id'];
                                        ?>
                                            <tr class="<?= $isSelected ? 'glow-primary' : '' ?>" style="<?= $isSelected ? 'border-left: 4px solid var(--primary);' : '' ?>">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-3">
                                                            <?= strtoupper(substr($donor['name'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($donor['name']) ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($donor['email'] ?: 'No email') ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?= htmlspecialchars($donor['phone']) ?></td>
                                                <td>
                                                    <?php if ($ref): ?>
                                                        <span class="badge bg-dark font-monospace px-3 py-2"><?= $ref ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <span class="fw-bold text-success"><?= $currency . number_format((float)$donor['total_paid'], 2) ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <span class="badge bg-warning text-dark px-3 py-2 rounded-pill fw-bold">
                                                        <?= $sqm ?> m²
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <a href="?search=<?= urlencode($search) ?>&donor_id=<?= $donor['id'] ?>" 
                                                       class="btn <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm rounded-pill px-4 shadow-sm">
                                                        <i class="fas fa-certificate me-1"></i> Preview
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Mobile Cards -->
                        <div class="d-md-none">
                            <?php foreach ($donors as $donor): 
                                $ref = extractReference($donor['pledge_notes'] ?? '');
                                $sqm = calculateSqm((float)($donor['total_pledged'] ?? 0), (float)($donor['total_paid'] ?? 0));
                                $isSelected = isset($_GET['donor_id']) && (int)$_GET['donor_id'] === (int)$donor['id'];
                            ?>
                                <div class="donor-result-card <?= $isSelected ? 'border-primary shadow' : '' ?>">
                                    <div class="donor-result-header">
                                        <div class="d-flex align-items-center">
                                            <div class="user-avatar me-3 shadow-sm">
                                                <?= strtoupper(substr($donor['name'], 0, 1)) ?>
                                            </div>
                                            <div class="donor-result-info">
                                                <h6 class="mb-0"><?= htmlspecialchars($donor['name']) ?></h6>
                                                <small class="text-muted"><?= htmlspecialchars($donor['phone']) ?></small>
                                            </div>
                                        </div>
                                        <span class="badge bg-warning text-dark rounded-pill"><?= $sqm ?> m²</span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <div class="text-success fw-bold">
                                            <?= $currency . number_format((float)$donor['total_paid'], 2) ?>
                                        </div>
                                        <a href="?search=<?= urlencode($search) ?>&donor_id=<?= $donor['id'] ?>" 
                                           class="btn btn-primary btn-sm rounded-pill px-4 shadow-sm">
                                            Preview
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($selectedDonor): ?>
                <!-- Preview Section -->
                <div class="mt-4" id="preview-section">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
                        <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                            <i class="fas fa-eye text-primary"></i>
                            <span>Certificate Preview</span>
                        </h6>
                        <div class="cert-actions d-none d-md-flex">
                            <button class="btn btn-outline-success btn-sm rounded-pill px-3" onclick="printCertificate()">
                                <i class="fas fa-print me-1"></i> Print
                            </button>
                            <button class="btn btn-success btn-sm rounded-pill px-3 shadow-sm" onclick="downloadCertificate()">
                                <i class="fas fa-download me-1"></i> Download
                            </button>
                        </div>
                    </div>

                    <div class="row g-2 g-md-3 mb-3 mb-md-4">
                        <div class="col-6 col-lg-3">
                            <div class="donor-stat-card">
                                <div class="donor-stat-icon bg-light text-primary">
                                    <i class="fas fa-hashtag"></i>
                                </div>
                                <div class="donor-stat-content">
                                    <span class="donor-stat-label">Ref</span>
                                    <span class="donor-stat-value font-monospace"><?= $donorReference ?: '----' ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="donor-stat-card">
                                <div class="donor-stat-icon bg-light text-primary">
                                    <i class="fas fa-hand-holding-usd"></i>
                                </div>
                                <div class="donor-stat-content">
                                    <span class="donor-stat-label">Pledged</span>
                                    <span class="donor-stat-value"><?= $currency . number_format($totalPledged, 0) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="donor-stat-card">
                                <div class="donor-stat-icon bg-light <?= $isFullyPaid ? 'text-success' : 'text-warning' ?>">
                                    <i class="fas fa-<?= $isFullyPaid ? 'check-circle' : 'clock' ?>"></i>
                                </div>
                                <div class="donor-stat-content">
                                    <span class="donor-stat-label">Paid</span>
                                    <span class="donor-stat-value"><?= $currency . number_format($totalPaid, 0) ?> <small class="text-muted">(<?= $paymentProgress ?>%)</small></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="donor-stat-card">
                                <div class="donor-stat-icon bg-light text-success">
                                    <i class="fas fa-layer-group"></i>
                                </div>
                                <div class="donor-stat-content">
                                    <span class="donor-stat-label">Area</span>
                                    <span class="donor-stat-value"><?= $sqmValue ?>m²</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="certificate-preview-container animate-bounce-in">
                        <div id="cert-scaler">
                            <div class="certificate shadow-lg">
                                <div class="church-overlay"></div>
                                <div class="top-section">
                                    <div class="top-verse">
                                        "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" ፪ ዜና ፪፡፱
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
                                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=http://donate.abuneteklehaymanot.org/" alt="QR">
                                        </div>
                                        <div class="bank-details">
                                            <div class="bank-row">
                                                <span class="bank-label">Name</span>
                                                <span class="bank-val"><?= htmlspecialchars($selectedDonor['name']) ?></span>
                                            </div>
                                            <div class="bank-row" style="margin-top: 15px;">
                                                <span class="bank-label">Contribution</span>
                                                <span class="bank-val"><?= $currency . number_format($allocationBase, 2) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="right-area">
                                        <div class="pill-box">
                                            <span class="sqm-value"><?= $sqmValue ?>m²</span>
                                        </div>
                                        <?php if ($donorReference): ?>
                                            <div class="reference-number"><?= $donorReference ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mobile Bottom Actions (shows on mobile only) -->
                    <div class="d-md-none cert-actions">
                        <button class="btn btn-outline-success rounded-pill" onclick="printCertificate()">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                        <button class="btn btn-success rounded-pill flex-grow-1 fw-bold" onclick="downloadCertificate()">
                            <i class="fas fa-download me-2"></i> Download
                        </button>
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
