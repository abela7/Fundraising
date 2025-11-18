<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();

$page_title = 'Plan Created';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?> - Call Center</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .success-card {
            max-width: 500px;
            margin: 100px auto;
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .success-icon {
            font-size: 4rem;
            color: #22c55e;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-card">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2 class="mb-3">Payment Plan Created!</h2>
            <p class="text-muted mb-4">The payment plan has been successfully set up and the call has been logged.</p>
            
            <a href="index.php" class="btn btn-primary btn-lg w-100">
                <i class="fas fa-list me-2"></i>Back to Queue
            </a>
        </div>
    </div>
</body>
</html>

