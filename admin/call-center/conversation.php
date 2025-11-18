<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

try {
    $db = db();
    $user_id = (int)$_SESSION['user']['id'];
    
    // Get parameters from URL
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    $queue_id = isset($_GET['queue_id']) ? (int)$_GET['queue_id'] : 0;
    
    if (!$donor_id || !$queue_id) {
        header('Location: index.php');
        exit;
    }
    
    // Get donor information
    $donor_query = "SELECT name, phone, balance FROM donors WHERE id = ? LIMIT 1";
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $donor = $result->fetch_object();
    $stmt->close();
    
    if (!$donor) {
        header('Location: index.php');
        exit;
    }
    
} catch (Exception $e) {
    error_log("Conversation Error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}

$page_title = 'Conversation';
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
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div style="max-width: 800px; margin: 0 auto; text-align: center; padding: 3rem 1rem;">
                <div class="alert alert-info">
                    <h4><i class="fas fa-info-circle me-2"></i>Conversation Phase</h4>
                    <p class="mb-0">This is where you'll record the conversation details.</p>
                    <p class="mb-0 mt-2"><strong>Donor:</strong> <?php echo htmlspecialchars($donor->name); ?></p>
                    <p class="mb-0"><strong>Pledge:</strong> £<?php echo number_format((float)$donor->balance, 2); ?></p>
                </div>
                
                <p class="text-muted mt-4">The conversation recording interface will be implemented in the next phase.</p>
                
                <div class="mt-4">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Queue
                    </a>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>

