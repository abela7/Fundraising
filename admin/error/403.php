<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Forbidden - Fundraising System</title>
    
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
            <div class="error-icon error-forbidden">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="error-code">403</div>
            <h1 class="error-title">Access Forbidden</h1>
            <p class="error-message">
                You don't have permission to access this resource. Please contact your administrator if you believe this is an error.
            </p>
            
            <div class="error-actions">
                <a href="../dashboard/" class="btn btn-primary">
                    <i class="fas fa-home me-2"></i>Back to Dashboard
                </a>
                <a href="../logout.php" class="btn btn-outline-secondary">
                    <i class="fas fa-sign-out-alt me-2"></i>Sign Out
                </a>
            </div>
            
            <div class="error-info">
                <div class="info-card">
                    <h6><i class="fas fa-info-circle me-2"></i>What can I do?</h6>
                    <ul>
                        <li>Check if you're logged in with the correct account</li>
                        <li>Verify your user role and permissions</li>
                        <li>Contact the system administrator</li>
                        <li>Try accessing a different page</li>
                    </ul>
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
