<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();

// Get rate limiting statistics
$hourlyStats = $db->query("
    SELECT 
        ip_address,
        phone_number,
        COUNT(*) as submission_count,
        MAX(submission_time) as last_submission,
        JSON_EXTRACT(metadata, '$.user_agent') as user_agent
    FROM rate_limits 
    WHERE submission_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
    GROUP BY ip_address, phone_number
    ORDER BY submission_count DESC
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

$dailyStats = $db->query("
    SELECT 
        DATE(submission_time) as date,
        COUNT(*) as total_submissions,
        COUNT(DISTINCT ip_address) as unique_ips,
        COUNT(DISTINCT phone_number) as unique_phones
    FROM rate_limits 
    WHERE submission_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(submission_time)
    ORDER BY date DESC
")->fetch_all(MYSQLI_ASSOC);

$suspiciousActivity = $db->query("
    SELECT 
        ip_address,
        COUNT(*) as submission_count,
        MIN(submission_time) as first_submission,
        MAX(submission_time) as last_submission,
        JSON_EXTRACT(metadata, '$.user_agent') as user_agent
    FROM rate_limits 
    WHERE submission_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)
    GROUP BY ip_address
    HAVING submission_count >= 5
    ORDER BY submission_count DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Limiting Monitor - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h2 mb-0">
                        <i class="fas fa-shield-alt text-primary me-2"></i>
                        Rate Limiting Monitor
                    </h1>
                    <a href="../" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Admin
                    </a>
                </div>
                
                <!-- Daily Overview -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <i class="fas fa-chart-line me-2"></i>Daily Activity (Last 7 Days)
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Submissions</th>
                                                <th>Unique IPs</th>
                                                <th>Unique Phones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dailyStats as $stat): ?>
                                            <tr>
                                                <td><?php echo date('M j', strtotime($stat['date'])); ?></td>
                                                <td><span class="badge bg-info"><?php echo $stat['total_submissions']; ?></span></td>
                                                <td><?php echo $stat['unique_ips']; ?></td>
                                                <td><?php echo $stat['unique_phones']; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <i class="fas fa-exclamation-triangle me-2"></i>Suspicious Activity (24h)
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>IP Address</th>
                                                <th>Submissions</th>
                                                <th>Time Span</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($suspiciousActivity as $activity): ?>
                                            <?php 
                                            $timeSpan = (strtotime($activity['last_submission']) - strtotime($activity['first_submission'])) / 60;
                                            $spanText = $timeSpan < 60 ? round($timeSpan) . 'm' : round($timeSpan/60, 1) . 'h';
                                            ?>
                                            <tr>
                                                <td>
                                                    <code><?php echo htmlspecialchars($activity['ip_address']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger"><?php echo $activity['submission_count']; ?></span>
                                                </td>
                                                <td><?php echo $spanText; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($suspiciousActivity)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-3">
                                                    <i class="fas fa-check-circle text-success me-2"></i>
                                                    No suspicious activity detected
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Hourly Activity -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <i class="fas fa-clock me-2"></i>Recent Activity (Last Hour)
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>IP Address</th>
                                        <th>Phone</th>
                                        <th>Submissions</th>
                                        <th>Last Activity</th>
                                        <th>User Agent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($hourlyStats as $stat): ?>
                                    <tr class="<?php echo $stat['submission_count'] >= 3 ? 'table-warning' : ''; ?>">
                                        <td>
                                            <code><?php echo htmlspecialchars($stat['ip_address']); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($stat['phone_number']): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($stat['phone_number']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Anonymous</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $stat['submission_count'] >= 3 ? 'bg-warning' : 'bg-success'; ?>">
                                                <?php echo $stat['submission_count']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php 
                                                $minutes = (time() - strtotime($stat['last_submission'])) / 60;
                                                echo $minutes < 60 ? round($minutes) . 'm ago' : round($minutes/60, 1) . 'h ago';
                                                ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php 
                                                $ua = json_decode($stat['user_agent'], true) ?: $stat['user_agent'];
                                                echo htmlspecialchars(substr($ua, 0, 50)) . (strlen($ua) > 50 ? '...' : '');
                                                ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($hourlyStats)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="fas fa-info-circle me-2"></i>
                                            No submissions in the last hour
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4">
                    <div class="card border-0 bg-transparent">
                        <div class="card-body">
                            <h6 class="text-muted">Rate Limiting Rules:</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-unstyled small text-muted">
                                        <li><i class="fas fa-clock text-warning me-1"></i> Max 3 submissions per minute per IP</li>
                                        <li><i class="fas fa-clock text-warning me-1"></i> Max 10 submissions per hour per IP</li>
                                        <li><i class="fas fa-calendar text-info me-1"></i> Max 25 submissions per day per IP</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled small text-muted">
                                        <li><i class="fas fa-phone text-primary me-1"></i> Max 2 submissions per hour per phone</li>
                                        <li><i class="fas fa-phone text-primary me-1"></i> Max 5 submissions per day per phone</li>
                                        <li><i class="fas fa-shield text-success me-1"></i> CAPTCHA required after 5 submissions</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
