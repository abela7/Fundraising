<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - Fundraising System</title>
    
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
            <div class="error-icon">
                <i class="fas fa-search"></i>
            </div>
            <div class="error-code">404</div>
            <h1 class="error-title">Page Not Found</h1>
            <p class="error-message">
                Sorry, the page you are looking for doesn't exist or has been moved.
            </p>
            
            <div class="error-actions">
                <a href="../dashboard/" class="btn btn-primary">
                    <i class="fas fa-home me-2"></i>Back to Dashboard
                </a>
                <button class="btn btn-outline-secondary" onclick="history.back()">
                    <i class="fas fa-arrow-left me-2"></i>Go Back
                </button>
            </div>
            
            <div class="error-suggestions">
                <h6>You might be looking for:</h6>
                <ul>
                    <li><a href="../dashboard/">Dashboard</a></li>
                    <li><a href="../pledges/">Pledges Management</a></li>
                    <li><a href="../members/">Members Management</a></li>
                    <li><a href="../settings/">System Settings</a></li>
                </ul>
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
