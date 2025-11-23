<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/auth.php';
require_login();

$user = current_user();
$message = $_SESSION['access_denied_message'] ?? 'You are not allowed to access this page.';
unset($_SESSION['access_denied_message']);

// Redirect to registrar dashboard after 4 seconds
$redirect_url = '/registrar/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Registrar Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css">
    <link rel="stylesheet" href="assets/registrar.css">
    <style>
        .access-denied-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        .access-denied-card {
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
        }
        .countdown {
            font-size: 1.2rem;
            font-weight: 600;
            color: #0a6286;
        }
    </style>
</head>
<body>
    <div class="access-denied-container">
        <div class="container">
            <div class="card access-denied-card">
                <div class="card-body text-center p-5">
                    <div class="mb-4">
                        <i class="fas fa-ban text-danger" style="font-size: 4rem;"></i>
                    </div>
                    <h3 class="card-title mb-3">Access Denied</h3>
                    <p class="text-muted mb-4"><?php echo htmlspecialchars($message); ?></p>
                    <p class="mb-4">
                        <span class="countdown" id="countdown">4</span> seconds
                    </p>
                    <p class="text-muted small mb-0">
                        Redirecting to your dashboard...
                    </p>
                    <div class="mt-4">
                        <a href="<?php echo htmlspecialchars($redirect_url); ?>" class="btn btn-primary">
                            <i class="fas fa-arrow-left me-2"></i>Go to Dashboard Now
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let countdown = 4;
        const countdownElement = document.getElementById('countdown');
        
        const timer = setInterval(function() {
            countdown--;
            if (countdownElement) {
                countdownElement.textContent = countdown;
            }
            
            if (countdown <= 0) {
                clearInterval(timer);
                window.location.href = '<?php echo htmlspecialchars($redirect_url); ?>';
            }
        }, 1000);
    </script>
</body>
</html>

