<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$db = db();

// Get campaigns
$campaigns_query = "
    SELECT 
        c.*,
        u.name as created_by_name,
        (SELECT COUNT(*) FROM call_center_queues WHERE campaign_id = c.id) as total_in_queue,
        (SELECT COUNT(*) FROM call_center_sessions WHERE campaign_id = c.id) as total_calls_made
    FROM call_center_campaigns c
    LEFT JOIN users u ON c.created_by = u.id
    ORDER BY c.created_at DESC
";
$campaigns_result = $db->query($campaigns_query);

$page_title = 'Campaigns';
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
            <div class="content-header">
                <div>
                    <h1 class="content-title">
                        <i class="fas fa-bullhorn me-2"></i>
                        Call Campaigns
                    </h1>
                    <p class="content-subtitle">Organize and track focused calling efforts</p>
                </div>
                <div class="header-actions">
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCampaignModal">
                        <i class="fas fa-plus me-2"></i>Create Campaign
                    </button>
                </div>
            </div>

            <!-- Campaigns List -->
            <div class="row g-4">
                <?php if ($campaigns_result->num_rows > 0): ?>
                    <?php while ($campaign = $campaigns_result->fetch_object()): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0"><?php echo htmlspecialchars($campaign->name); ?></h5>
                                    <span class="badge bg-<?php 
                                        echo $campaign->status === 'active' ? 'success' : 
                                            ($campaign->status === 'planning' ? 'info' : 
                                            ($campaign->status === 'completed' ? 'secondary' : 'warning')); 
                                    ?>">
                                        <?php echo ucfirst($campaign->status); ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <?php if ($campaign->description): ?>
                                        <p class="text-muted small"><?php echo htmlspecialchars($campaign->description); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Campaign Type:</small>
                                        <strong><?php echo ucwords(str_replace('_', ' ', $campaign->campaign_type)); ?></strong>
                                    </div>
                                    
                                    <?php if ($campaign->target_amount): ?>
                                        <div class="mb-3">
                                            <small class="text-muted d-block">Target Amount:</small>
                                            <strong class="text-primary">£<?php echo number_format($campaign->target_amount, 2); ?></strong>
                                            <?php if ($campaign->collected_amount > 0): ?>
                                                <span class="text-muted"> / £<?php echo number_format($campaign->collected_amount, 2); ?> collected</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Duration:</small>
                                        <strong><?php echo date('M j', strtotime($campaign->start_date)); ?></strong>
                                        <?php if ($campaign->end_date): ?>
                                            to <strong><?php echo date('M j, Y', strtotime($campaign->end_date)); ?></strong>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between text-center mb-3">
                                        <div>
                                            <div class="h5 mb-0"><?php echo $campaign->total_donors; ?></div>
                                            <small class="text-muted">Total Donors</small>
                                        </div>
                                        <div>
                                            <div class="h5 mb-0"><?php echo $campaign->contacted_donors; ?></div>
                                            <small class="text-muted">Contacted</small>
                                        </div>
                                        <div>
                                            <div class="h5 mb-0"><?php echo $campaign->successful_contacts; ?></div>
                                            <small class="text-muted">Successful</small>
                                        </div>
                                    </div>
                                    
                                    <?php if ($campaign->target_amount && $campaign->collected_amount): ?>
                                        <?php $progress = ($campaign->collected_amount / $campaign->target_amount) * 100; ?>
                                        <div class="progress mb-2" style="height: 10px;">
                                            <div class="progress-bar bg-success" role="progressbar" 
                                                 style="width: <?php echo min(100, $progress); ?>%" 
                                                 aria-valuenow="<?php echo $progress; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                        </div>
                                        <small class="text-muted"><?php echo number_format($progress, 1); ?>% of target</small>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-light">
                                    <small class="text-muted">
                                        Created by <?php echo htmlspecialchars($campaign->created_by_name); ?> 
                                        on <?php echo date('M j, Y', strtotime($campaign->created_at)); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="empty-state py-5">
                                    <i class="fas fa-bullhorn fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Campaigns Yet</h5>
                                    <p class="text-muted">Create your first campaign to organize your calling efforts</p>
                                    <button class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#createCampaignModal">
                                        <i class="fas fa-plus me-2"></i>Create Campaign
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Create Campaign Modal -->
<div class="modal fade" id="createCampaignModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Campaign</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Coming Soon!</strong> Campaign creation and management features will be available in the next update.
                    </div>
                    <p class="text-muted">This feature will allow you to:</p>
                    <ul class="text-muted">
                        <li>Create targeted calling campaigns</li>
                        <li>Set financial goals and track progress</li>
                        <li>Filter donors by criteria (balance, last contact, etc.)</li>
                        <li>Assign campaigns to specific agents</li>
                        <li>Generate campaign performance reports</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js"></script>
<script src="assets/call-center.js"></script>
</body>
</html>

