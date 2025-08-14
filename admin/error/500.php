<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Error - Fundraising System</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="assets/error.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="error-container">
        <div class="error-content">
            <div class="error-icon error-server">
                <i class="fas fa-server"></i>
            </div>
            <div class="error-code">500</div>
            <h1 class="error-title">Internal Server Error</h1>
            <p class="error-message">
                Something went wrong on our servers. We're working to fix this issue. Please try again later.
            </p>
            
            <div class="error-actions">
                <button class="btn btn-primary" onclick="window.location.reload()">
                    <i class="fas fa-sync me-2"></i>Try Again
                </button>
                <a href="../dashboard/" class="btn btn-outline-secondary">
                    <i class="fas fa-home me-2"></i>Back to Dashboard
                </a>
            </div>
            
            <div class="error-info">
                <div class="info-card">
                    <h6><i class="fas fa-exclamation-triangle me-2"></i>Error Details</h6>
                    <div class="error-details">
                        <div class="detail-item">
                            <strong>Error Code:</strong> 500
                        </div>
                        <div class="detail-item">
                            <strong>Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
                        </div>
                        <div class="detail-item">
                            <strong>Reference ID:</strong> ERR-<?php echo strtoupper(substr(md5(time()), 0, 8)); ?>
                        </div>
                    </div>
                    <small class="text-muted mt-2 d-block">
                        If the problem persists, please contact support with the reference ID above.
                    </small>
                </div>
            </div>
        </div>
        
        <!-- Decorative Elements -->
        <div class="decorative-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/error.js"></script>
</body>
</html>
