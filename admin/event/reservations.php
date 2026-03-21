<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../../config/db.php';

$page_title = 'Event Reservations';
$db = db();

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

// Get current YouTube ID
$currentYoutubeId = '-8W1zuAOZeA'; // default
$ytResult = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'invitation_youtube_id' LIMIT 1");
if ($ytResult && $row = $ytResult->fetch_assoc()) {
    $currentYoutubeId = $row['setting_value'];
}

// Get reservation stats
$stats = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(attendance = 'yes') as confirmed,
        SUM(attendance = 'maybe') as maybe,
        SUM(attendance = 'no') as declined,
        SUM(guests) as total_guests,
        SUM(whatsapp_sent = 1) as whatsapp_sent
    FROM event_reservations
")->fetch_assoc();

// Get all reservations
$reservations = $db->query("
    SELECT * FROM event_reservations
    ORDER BY created_at DESC
");
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
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<main class="main-content">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="page-wrap">
    <h4 class="fw-bold mb-3"><i class="fas fa-calendar-check text-primary me-2"></i><?php echo $page_title; ?></h4>

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
                    </tr>
                </thead>
                <tbody>
                <?php $i = 0; while ($r = $reservations->fetch_assoc()): $i++; ?>
                    <tr data-attendance="<?php echo $r['attendance']; ?>">
                        <td><?php echo $i; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($r['full_name']); ?></td>
                        <td><?php echo $r['email'] ? htmlspecialchars($r['email']) : '<span class="text-muted">—</span>'; ?></td>
                        <td><?php echo $r['phone'] ? htmlspecialchars($r['phone']) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <?php
                            $badgeClass = match($r['attendance']) {
                                'yes' => 'badge-yes',
                                'maybe' => 'badge-maybe',
                                'no' => 'badge-no',
                                default => ''
                            };
                            $label = match($r['attendance']) {
                                'yes' => 'Confirmed',
                                'maybe' => 'Maybe',
                                'no' => 'Declined',
                                default => $r['attendance']
                            };
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
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>

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
