<?php
declare(strict_types=1);

// Step-by-step isolated test
// We'll add complexity ONE piece at a time

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_admin();

$page_title = 'Assign Donors to Agents';

// Step 1: Just get the DB connection
$db = db();

// Step 2: Run the simplest possible donor query
$donors = $db->query("SELECT id, name, phone, balance FROM donors LIMIT 20");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="content-header mb-4">
                <h1 class="content-title">
                    <i class="fas fa-users-cog me-2"></i>Assign Donors to Agents (MINIMAL TEST)
                </h1>
            </div>

            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Simple Donor List (first 20)</h6>
                </div>
                <div class="card-body">
                    <?php if ($donors && $donors->num_rows > 0): ?>
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Phone</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($donor = $donors->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $donor['id']; ?></td>
                                    <td><?php echo htmlspecialchars($donor['name']); ?></td>
                                    <td><?php echo htmlspecialchars($donor['phone'] ?? 'N/A'); ?></td>
                                    <td>£<?php echo number_format($donor['balance'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-muted">No donors found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="alert alert-info mt-4">
                <h6>✅ If you see this, the core page structure works!</h6>
                <p class="mb-0">Next step: we'll add filters, stats, and assignment logic piece by piece.</p>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
</body>
</html>
