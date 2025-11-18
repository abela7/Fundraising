<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$success = isset($_GET['success']) && $_GET['success'] == '1';
$donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;

if ($success && $donor_id) {
    try {
        $db = db();
        $donor_query = "SELECT name FROM donors WHERE id = ? LIMIT 1";
        $stmt = $db->prepare($donor_query);
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $donor = $result->fetch_object();
        $stmt->close();
    } catch (Exception $e) {
        $donor = null;
    }
} else {
    header('Location: index.php');
    exit;
}

$page_title = 'Call Complete';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <link rel="stylesheet" href="assets/call-center.css">
    <style>
        .success-page {
            max-width: 600px;
            margin: 0 auto;
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .success-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #d1fae5;
            color: var(--cc-success);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 2rem;
            font-size: 3rem;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="success-page">
                <div class="success-icon">
                    <i class="fas fa-check"></i>
                </div>
                
                <h2 class="mb-3">Call Recorded Successfully!</h2>
                
                <?php if ($donor): ?>
                    <p class="text-muted mb-4">
                        Call information for <strong><?php echo htmlspecialchars($donor->name); ?></strong> has been saved.
                    </p>
                <?php endif; ?>
                
                <div class="d-flex gap-2 justify-content-center">
                    <a href="index.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-list me-2"></i>Back to Queue
                    </a>
                    <a href="make-call.php?donor_id=<?php echo $donor_id; ?>&queue_id=<?php echo isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0; ?>" 
                       class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-redo me-2"></i>Call Again
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script>
// Auto-redirect after 5 seconds
setTimeout(() => {
    window.location.href = 'index.php';
}, 5000);
</script>
</body>
</html>

