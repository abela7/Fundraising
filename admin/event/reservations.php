<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../../config/db.php';

$page_title = 'Event Reservations';
$db = db();

// Handle delete reservation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reservation_id'])) {
    $delId = (int)$_POST['delete_reservation_id'];
    $stmt = $db->prepare("DELETE FROM event_reservations WHERE id = ?");
    $stmt->bind_param('i', $delId);
    $stmt->execute();
    $stmt->close();
    header('Location: reservations.php?deleted=1');
    exit;
}

// Handle update reservation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_reservation_id'])) {
    $editId     = (int)$_POST['edit_reservation_id'];
    $editName   = trim($_POST['edit_name'] ?? '');
    $editEmail  = trim($_POST['edit_email'] ?? '');
    $editPhone  = trim($_POST['edit_phone'] ?? '');
    $editAttend = $_POST['edit_attendance'] ?? 'yes';
    $editGuests = max(1, min(20, (int)($_POST['edit_guests'] ?? 1)));
    $editDiet   = trim($_POST['edit_dietary'] ?? '');

    if ($editName !== '') {
        $editEmailVal = $editEmail !== '' ? $editEmail : null;
        $editPhoneVal = $editPhone !== '' ? $editPhone : null;
        $editDietVal  = $editDiet !== '' ? $editDiet : null;

        $stmt = $db->prepare("UPDATE event_reservations SET full_name=?, email=?, phone=?, attendance=?, guests=?, dietary=? WHERE id=?");
        $stmt->bind_param('ssssisi', $editName, $editEmailVal, $editPhoneVal, $editAttend, $editGuests, $editDietVal, $editId);
        $stmt->execute();
        $stmt->close();
    }
    header('Location: reservations.php?updated=1');
    exit;
}

// Handle YouTube URL update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['youtube_url'])) {
    $youtubeUrl = trim($_POST['youtube_url']);
    // Extract video ID from various YouTube URL formats
    $videoId = '';
    if (preg_match('/(?:youtube\.com\/embed\/|youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $youtubeUrl, $m)) {
        $videoId = $m[1];
    } elseif (preg_match('/^[a-zA-Z0-9_-]{11}$/', $youtubeUrl)) {
        $videoId = $youtubeUrl;
    }

    if ($videoId !== '') {
        // Store in a simple settings table or file
        $db->query("CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('invitation_youtube_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param('ss', $videoId, $videoId);
        $stmt->execute();
        $stmt->close();
        $youtube_success = true;
    } else {
        $youtube_error = 'Invalid YouTube URL or video ID';
    }
}

// Ensure site_settings table exists
$db->query("CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure event_reservations table exists
$db->query("CREATE TABLE IF NOT EXISTS event_reservations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    attendance ENUM('yes','maybe','no') NOT NULL DEFAULT 'yes',
    guests TINYINT UNSIGNED NOT NULL DEFAULT 1,
    dietary TEXT DEFAULT NULL,
    whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attendance (attendance),
    INDEX idx_phone (phone),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Get current YouTube ID
$currentYoutubeId = '-8W1zuAOZeA'; // default
try {
    $ytResult = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'invitation_youtube_id' LIMIT 1");
    if ($ytResult && $row = $ytResult->fetch_assoc()) {
        $currentYoutubeId = $row['setting_value'];
    }
} catch (Exception $e) {}

// Get reservation stats
$stats = ['total' => 0, 'confirmed' => 0, 'maybe' => 0, 'declined' => 0, 'total_guests' => 0, 'whatsapp_sent' => 0];
try {
    $statsResult = $db->query("
        SELECT
            COUNT(*) as total,
            SUM(attendance = 'yes') as confirmed,
            SUM(attendance = 'maybe') as maybe,
            SUM(attendance = 'no') as declined,
            SUM(guests) as total_guests,
            SUM(whatsapp_sent = 1) as whatsapp_sent
        FROM event_reservations
    ");
    if ($statsResult) {
        $stats = $statsResult->fetch_assoc();
    }
} catch (Exception $e) {}

// Get all reservations
$reservations = null;
try {
    $reservations = $db->query("
        SELECT * FROM event_reservations
        ORDER BY created_at DESC
    ");
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $page_title; ?></title>
<link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="../assets/admin.css">
<?php include_once __DIR__ . '/../includes/pwa.php'; ?>
<style>
    .page-wrap { max-width: 1200px; margin: 0 auto; padding: 1rem; }

    .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 1.25rem;
        text-align: center;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .stat-card .stat-number { font-size: 2rem; font-weight: 700; line-height: 1; }
    .stat-card .stat-label { font-size: 0.78rem; color: #64748b; margin-top: 0.25rem; font-weight: 500; }
    .stat-confirmed .stat-number { color: #16a34a; }
    .stat-maybe .stat-number { color: #f59e0b; }
    .stat-declined .stat-number { color: #ef4444; }
    .stat-guests .stat-number { color: #0a6286; }
    .stat-whatsapp .stat-number { color: #25d366; }
    .stat-total .stat-number { color: #334155; }

    .section-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .section-card h5 { font-weight: 700; margin-bottom: 1rem; color: #1e293b; }

    .youtube-preview {
        border-radius: 8px;
        overflow: hidden;
        aspect-ratio: 16/9;
        max-width: 400px;
        margin-top: 0.75rem;
    }
    .youtube-preview iframe { width: 100%; height: 100%; border: none; }

    .table-wrap { overflow-x: auto; }
    .table th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; white-space: nowrap; }
    .table td { vertical-align: middle; font-size: 0.875rem; }

    .badge-yes { background: #dcfce7; color: #15803d; }
    .badge-maybe { background: #fef3c7; color: #92400e; }
    .badge-no { background: #fee2e2; color: #991b1b; }
    .badge-wa { background: #d1fae5; color: #065f46; }
    .badge-wa-no { background: #f1f5f9; color: #94a3b8; }

    .filter-tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .filter-tab {
        padding: 0.4rem 1rem;
        border-radius: 8px;
        border: 1.5px solid #e2e8f0;
        background: #fff;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        color: #64748b;
    }
    .filter-tab:hover { border-color: #94a3b8; }
    .filter-tab.active { border-color: #0a6286; background: #eff6ff; color: #0a6286; }

    @media (max-width: 768px) {
        .stat-cards { grid-template-columns: repeat(3, 1fr); }
        .stat-card { padding: 0.75rem; }
        .stat-card .stat-number { font-size: 1.4rem; }
    }
</style>
</head>
<body>
<div class="admin-wrapper">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="admin-content">
<?php include __DIR__ . '/../includes/topbar.php'; ?>
<main class="main-content">

<div class="page-wrap">
    <h4 class="fw-bold mb-3"><i class="fas fa-calendar-check text-primary me-2"></i><?php echo $page_title; ?></h4>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <small><i class="fas fa-check-circle me-1"></i>Reservation deleted successfully.</small>
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <small><i class="fas fa-check-circle me-1"></i>Reservation updated successfully.</small>
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-cards">
        <div class="stat-card stat-total">
            <div class="stat-number"><?php echo (int)$stats['total']; ?></div>
            <div class="stat-label">Total Reservations</div>
        </div>
        <div class="stat-card stat-confirmed">
            <div class="stat-number"><?php echo (int)$stats['confirmed']; ?></div>
            <div class="stat-label">Confirmed</div>
        </div>
        <div class="stat-card stat-maybe">
            <div class="stat-number"><?php echo (int)$stats['maybe']; ?></div>
            <div class="stat-label">Maybe</div>
        </div>
        <div class="stat-card stat-declined">
            <div class="stat-number"><?php echo (int)$stats['declined']; ?></div>
            <div class="stat-label">Declined</div>
        </div>
        <div class="stat-card stat-guests">
            <div class="stat-number"><?php echo (int)$stats['total_guests']; ?></div>
            <div class="stat-label">Total Guests</div>
        </div>
        <div class="stat-card stat-whatsapp">
            <div class="stat-number"><?php echo (int)$stats['whatsapp_sent']; ?></div>
            <div class="stat-label">WhatsApp Sent</div>
        </div>
    </div>

    <!-- YouTube URL Setting -->
    <div class="section-card">
        <h5><i class="fab fa-youtube text-danger me-2"></i>Invitation Video</h5>
        <?php if (!empty($youtube_success)): ?>
            <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                <small><i class="fas fa-check-circle me-1"></i>YouTube video updated successfully!</small>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
        <?php elseif (!empty($youtube_error)): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
                <small><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($youtube_error); ?></small>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <form method="POST" class="d-flex gap-2 align-items-end flex-wrap">
            <div class="flex-grow-1" style="min-width: 250px;">
                <label class="form-label small fw-semibold text-muted mb-1">YouTube URL or Video ID</label>
                <input type="text" name="youtube_url" class="form-control"
                       value="https://www.youtube.com/watch?v=<?php echo htmlspecialchars($currentYoutubeId); ?>"
                       placeholder="Paste YouTube URL or video ID">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update</button>
        </form>
        <div class="youtube-preview">
            <iframe src="https://www.youtube.com/embed/<?php echo htmlspecialchars($currentYoutubeId); ?>?rel=0&modestbranding=1"
                    allowfullscreen></iframe>
        </div>
    </div>

    <!-- Reservations Table -->
    <div class="section-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Reservations</h5>
            <button class="btn btn-sm btn-outline-success" onclick="exportCSV()">
                <i class="fas fa-download me-1"></i>Export CSV
            </button>
        </div>

        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">All</button>
            <button class="filter-tab" data-filter="yes"><i class="fas fa-check-circle text-success me-1"></i>Confirmed</button>
            <button class="filter-tab" data-filter="maybe"><i class="fas fa-question-circle text-warning me-1"></i>Maybe</button>
            <button class="filter-tab" data-filter="no"><i class="fas fa-times-circle text-danger me-1"></i>Declined</button>
        </div>

        <div class="table-wrap">
            <table class="table table-hover" id="reservationsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Attendance</th>
                        <th>Guests</th>
                        <th>Dietary</th>
                        <th>WhatsApp</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 0; while ($reservations && $r = $reservations->fetch_assoc()): $i++; ?>
                    <tr data-attendance="<?php echo $r['attendance']; ?>">
                        <td><?php echo $i; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['full_name']); ?></td>
                        <td><?php echo $r['email'] ? htmlspecialchars($r['email']) : '<span class="text-muted">—</span>'; ?></td>
                        <td><?php echo $r['phone'] ? htmlspecialchars($r['phone']) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php
                            $badgeMap = ['yes' => 'badge-yes', 'maybe' => 'badge-maybe', 'no' => 'badge-no'];
                            $labelMap = ['yes' => 'Confirmed', 'maybe' => 'Maybe', 'no' => 'Declined'];
                            $badgeClass = $badgeMap[$r['attendance']] ?? '';
                            $label = $labelMap[$r['attendance']] ?? $r['attendance'];
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $label; ?></span>
                        </td>
                        <td><?php echo (int)$r['guests']; ?></td>
                        <td><?php echo $r['dietary'] ? htmlspecialchars($r['dietary']) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php if ($r['whatsapp_sent']): ?>
                                <span class="badge badge-wa"><i class="fab fa-whatsapp me-1"></i>Sent</span>
                            <?php else: ?>
                                <span class="badge badge-wa-no">Not sent</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><small><?php echo date('d M Y H:i', strtotime($r['created_at'])); ?></small></td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                    'id' => $r['id'],
                                    'name' => $r['full_name'],
                                    'email' => $r['email'] ?? '',
                                    'phone' => $r['phone'] ?? '',
                                    'attendance' => $r['attendance'],
                                    'guests' => (int)$r['guests'],
                                    'dietary' => $r['dietary'] ?? ''
                                ])); ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" title="Delete"
                                onclick="confirmDelete(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES); ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="edit_reservation_id" id="editId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Reservation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="edit_name" id="editName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="edit_email" id="editEmail" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" name="edit_phone" id="editPhone" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Attendance</label>
                            <select name="edit_attendance" id="editAttendance" class="form-select">
                                <option value="yes">Confirmed</option>
                                <option value="maybe">Maybe</option>
                                <option value="no">Declined</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-semibold">Guests</label>
                            <input type="number" name="edit_guests" id="editGuests" class="form-control" min="1" max="20" value="1">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Dietary Requirements</label>
                        <textarea name="edit_dietary" id="editDietary" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form (hidden) -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="delete_reservation_id" id="deleteId">
</form>

</main>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Filter tabs
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        document.querySelectorAll('#reservationsTable tbody tr').forEach(row => {
            row.style.display = (filter === 'all' || row.dataset.attendance === filter) ? '' : 'none';
        });
    });
});

// Edit modal
function openEditModal(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editName').value = data.name;
    document.getElementById('editEmail').value = data.email;
    document.getElementById('editPhone').value = data.phone;
    document.getElementById('editAttendance').value = data.attendance;
    document.getElementById('editGuests').value = data.guests;
    document.getElementById('editDietary').value = data.dietary;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

// Delete confirmation
function confirmDelete(id, name) {
    if (confirm('Are you sure you want to delete the reservation for "' + name + '"?')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}

// Export CSV
function exportCSV() {
    const rows = [['#', 'Name', 'Email', 'Phone', 'Attendance', 'Guests', 'Dietary', 'WhatsApp', 'Date']];
    document.querySelectorAll('#reservationsTable tbody tr').forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        rows.push(Array.from(cells).map(c => '"' + c.textContent.trim().replace(/"/g, '""') + '"'));
    });
    const csv = rows.map(r => r.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'reservations_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>
</body>
</html>
