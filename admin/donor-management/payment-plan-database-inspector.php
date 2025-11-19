<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$db = db();
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($plan_id <= 0) {
    die("Please provide a plan ID: payment-plan-database-inspector.php?id=11");
}

$plan_data = [];
$donor_data = [];
$pledge_data = [];
$template_data = [];
$payments_data = [];
$errors = [];

try {
    // 1. Get raw payment plan data
    $plan_query = "SELECT * FROM donor_payment_plans WHERE id = ?";
    $stmt = $db->prepare($plan_query);
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $errors[] = "Payment plan #$plan_id not found in database!";
    } else {
        $plan_data = $result->fetch_assoc();
        
        // 2. Get donor data
        if (!empty($plan_data['donor_id'])) {
            $donor_query = "SELECT * FROM donors WHERE id = ?";
            $donor_stmt = $db->prepare($donor_query);
            $donor_stmt->bind_param('i', $plan_data['donor_id']);
            $donor_stmt->execute();
            $donor_result = $donor_stmt->get_result();
            $donor_data = $donor_result->fetch_assoc();
            $donor_stmt->close();
        }
        
        // 3. Get pledge data
        if (!empty($plan_data['pledge_id'])) {
            $pledge_query = "SELECT * FROM pledges WHERE id = ?";
            $pledge_stmt = $db->prepare($pledge_query);
            $pledge_stmt->bind_param('i', $plan_data['pledge_id']);
            $pledge_stmt->execute();
            $pledge_result = $pledge_stmt->get_result();
            $pledge_data = $pledge_result->fetch_assoc();
            $pledge_stmt->close();
        }
        
        // 4. Get template data
        if (!empty($plan_data['template_id'])) {
            $template_query = "SELECT * FROM payment_plan_templates WHERE id = ?";
            $template_stmt = $db->prepare($template_query);
            $template_stmt->bind_param('i', $plan_data['template_id']);
            $template_stmt->execute();
            $template_result = $template_stmt->get_result();
            $template_data = $template_result->fetch_assoc();
            $template_stmt->close();
        }
        
        // 5. Get payments for this plan
        $payment_columns = [];
        $col_query = $db->query("SHOW COLUMNS FROM payments");
        while ($col = $col_query->fetch_assoc()) {
            $payment_columns[] = $col['Field'];
        }
        
        $has_donor_id = in_array('donor_id', $payment_columns);
        $has_pledge_id = in_array('pledge_id', $payment_columns);
        
        if ($has_donor_id && $has_pledge_id) {
            $payments_query = "SELECT * FROM payments WHERE donor_id = ? AND pledge_id = ? ORDER BY created_at DESC";
            $pay_stmt = $db->prepare($payments_query);
            $pay_stmt->bind_param('ii', $plan_data['donor_id'], $plan_data['pledge_id']);
            $pay_stmt->execute();
            $pay_result = $pay_stmt->get_result();
            while ($pay = $pay_result->fetch_assoc()) {
                $payments_data[] = $pay;
            }
            $pay_stmt->close();
        } elseif ($has_donor_id) {
            $payments_query = "SELECT * FROM payments WHERE donor_id = ? ORDER BY created_at DESC LIMIT 10";
            $pay_stmt = $db->prepare($payments_query);
            $pay_stmt->bind_param('i', $plan_data['donor_id']);
            $pay_stmt->execute();
            $pay_result = $pay_stmt->get_result();
            while ($pay = $pay_result->fetch_assoc()) {
                $payments_data[] = $pay;
            }
            $pay_stmt->close();
        }
    }
    $stmt->close();
    
} catch (Exception $e) {
    $errors[] = "Error: " . $e->getMessage();
}

$page_title = "Database Inspector - Plan #$plan_id";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .data-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th {
            background: #f8f9fa;
            padding: 0.75rem;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            font-weight: 600;
            color: #495057;
        }
        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
            font-family: monospace;
            font-size: 0.9rem;
        }
        .data-table tr:hover {
            background: #f8f9fa;
        }
        .null-value {
            color: #dc3545;
            font-style: italic;
        }
        .number-value {
            color: #0a6286;
            font-weight: 600;
        }
        .string-value {
            color: #28a745;
        }
        .section-title {
            color: #0a6286;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #0a6286;
        }
        .raw-json {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-database me-2"></i>Database Inspector - Plan #<?php echo $plan_id; ?></h2>
                    <div>
                        <a href="view-payment-plan.php?id=<?php echo $plan_id; ?>" class="btn btn-outline-primary">
                            <i class="fas fa-eye me-2"></i>View Plan Page
                        </a>
                        <a href="list-payment-plans.php" class="btn btn-outline-secondary">
                            <i class="fas fa-list me-2"></i>All Plans
                        </a>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($plan_data)): ?>
                
                <!-- 1. Payment Plan Raw Data -->
                <div class="data-section">
                    <h3 class="section-title">
                        <i class="fas fa-calendar-alt me-2"></i>
                        1. Payment Plan Table (donor_payment_plans)
                    </h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Column Name</th>
                                    <th style="width: 20%;">Data Type</th>
                                    <th style="width: 50%;">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plan_data as $key => $value): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                                    <td>
                                        <?php
                                        $type = gettype($value);
                                        echo '<span class="badge bg-secondary">' . $type . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($value === null) {
                                            echo '<span class="null-value">NULL</span>';
                                        } elseif (is_numeric($value)) {
                                            if (strpos($key, 'amount') !== false || strpos($key, 'paid') !== false || strpos($key, 'total') !== false) {
                                                echo '<span class="number-value">£' . number_format((float)$value, 2) . '</span>';
                                            } else {
                                                echo '<span class="number-value">' . $value . '</span>';
                                            }
                                        } elseif (is_bool($value)) {
                                            echo $value ? '<span class="badge bg-success">TRUE</span>' : '<span class="badge bg-danger">FALSE</span>';
                                        } else {
                                            echo '<span class="string-value">' . htmlspecialchars($value) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <h5>Raw JSON:</h5>
                        <div class="raw-json"><?php echo json_encode($plan_data, JSON_PRETTY_PRINT); ?></div>
                    </div>
                </div>

                <!-- 2. Donor Data -->
                <?php if (!empty($donor_data)): ?>
                <div class="data-section">
                    <h3 class="section-title">
                        <i class="fas fa-user me-2"></i>
                        2. Donor Table (donors) - ID: <?php echo $plan_data['donor_id']; ?>
                    </h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Column Name</th>
                                    <th style="width: 20%;">Data Type</th>
                                    <th style="width: 50%;">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($donor_data as $key => $value): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                                    <td>
                                        <?php
                                        $type = gettype($value);
                                        echo '<span class="badge bg-secondary">' . $type . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($value === null) {
                                            echo '<span class="null-value">NULL</span>';
                                        } elseif (is_numeric($value)) {
                                            if (strpos($key, 'amount') !== false || strpos($key, 'paid') !== false || strpos($key, 'balance') !== false || strpos($key, 'pledged') !== false) {
                                                echo '<span class="number-value">£' . number_format((float)$value, 2) . '</span>';
                                            } else {
                                                echo '<span class="number-value">' . $value . '</span>';
                                            }
                                        } elseif (is_bool($value)) {
                                            echo $value ? '<span class="badge bg-success">TRUE</span>' : '<span class="badge bg-danger">FALSE</span>';
                                        } else {
                                            echo '<span class="string-value">' . htmlspecialchars($value) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Donor ID <?php echo $plan_data['donor_id']; ?> not found in donors table!
                </div>
                <?php endif; ?>

                <!-- 3. Pledge Data -->
                <?php if (!empty($pledge_data)): ?>
                <div class="data-section">
                    <h3 class="section-title">
                        <i class="fas fa-hand-holding-heart me-2"></i>
                        3. Pledge Table (pledges) - ID: <?php echo $plan_data['pledge_id']; ?>
                    </h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Column Name</th>
                                    <th style="width: 20%;">Data Type</th>
                                    <th style="width: 50%;">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pledge_data as $key => $value): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                                    <td>
                                        <?php
                                        $type = gettype($value);
                                        echo '<span class="badge bg-secondary">' . $type . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($value === null) {
                                            echo '<span class="null-value">NULL</span>';
                                        } elseif (is_numeric($value)) {
                                            if (strpos($key, 'amount') !== false) {
                                                echo '<span class="number-value">£' . number_format((float)$value, 2) . '</span>';
                                            } else {
                                                echo '<span class="number-value">' . $value . '</span>';
                                            }
                                        } elseif (is_bool($value)) {
                                            echo $value ? '<span class="badge bg-success">TRUE</span>' : '<span class="badge bg-danger">FALSE</span>';
                                        } else {
                                            echo '<span class="string-value">' . htmlspecialchars($value) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Pledge ID <?php echo $plan_data['pledge_id'] ?? 'NULL'; ?> not found in pledges table!
                </div>
                <?php endif; ?>

                <!-- 4. Template Data -->
                <?php if (!empty($template_data)): ?>
                <div class="data-section">
                    <h3 class="section-title">
                        <i class="fas fa-file-alt me-2"></i>
                        4. Template Table (payment_plan_templates) - ID: <?php echo $plan_data['template_id']; ?>
                    </h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Column Name</th>
                                    <th style="width: 20%;">Data Type</th>
                                    <th style="width: 50%;">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($template_data as $key => $value): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                                    <td>
                                        <?php
                                        $type = gettype($value);
                                        echo '<span class="badge bg-secondary">' . $type . '</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($value === null) {
                                            echo '<span class="null-value">NULL</span>';
                                        } elseif (is_numeric($value)) {
                                            echo '<span class="number-value">' . $value . '</span>';
                                        } elseif (is_bool($value)) {
                                            echo $value ? '<span class="badge bg-success">TRUE</span>' : '<span class="badge bg-danger">FALSE</span>';
                                        } else {
                                            echo '<span class="string-value">' . htmlspecialchars($value) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Template ID is <?php echo $plan_data['template_id'] ?? 'NULL'; ?> - No template data (this is OK for custom plans)
                </div>
                <?php endif; ?>

                <!-- 5. Payments Data -->
                <?php if (!empty($payments_data)): ?>
                <div class="data-section">
                    <h3 class="section-title">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        5. Payments Table (payments) - <?php echo count($payments_data); ?> records found
                    </h3>
                    <?php foreach ($payments_data as $index => $payment): ?>
                    <div class="mb-4">
                        <h5>Payment #<?php echo $index + 1; ?> (ID: <?php echo $payment['id']; ?>)</h5>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 30%;">Column Name</th>
                                        <th style="width: 20%;">Data Type</th>
                                        <th style="width: 50%;">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payment as $key => $value): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($key); ?></strong></td>
                                        <td>
                                            <?php
                                            $type = gettype($value);
                                            echo '<span class="badge bg-secondary">' . $type . '</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            if ($value === null) {
                                                echo '<span class="null-value">NULL</span>';
                                            } elseif (is_numeric($value)) {
                                                if (strpos($key, 'amount') !== false) {
                                                    echo '<span class="number-value">£' . number_format((float)$value, 2) . '</span>';
                                                } else {
                                                    echo '<span class="number-value">' . $value . '</span>';
                                                }
                                            } elseif (is_bool($value)) {
                                                echo $value ? '<span class="badge bg-success">TRUE</span>' : '<span class="badge bg-danger">FALSE</span>';
                                            } else {
                                                echo '<span class="string-value">' . htmlspecialchars($value) . '</span>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No payments found for this plan
                </div>
                <?php endif; ?>

                <!-- 6. Analysis -->
                <div class="data-section">
                    <h3 class="section-title">
                        <i class="fas fa-chart-line me-2"></i>
                        6. Data Analysis
                    </h3>
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Key Values:</h5>
                            <ul class="list-group">
                                <li class="list-group-item">
                                    <strong>Monthly Amount:</strong> 
                                    <span class="float-end">
                                        <?php 
                                        $monthly = $plan_data['monthly_amount'] ?? 0;
                                        echo $monthly > 0 ? '£' . number_format($monthly, 2) : '<span class="text-danger">£0.00 (ISSUE!)</span>';
                                        ?>
                                    </span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Total Amount:</strong> 
                                    <span class="float-end">£<?php echo number_format($plan_data['total_amount'] ?? 0, 2); ?></span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Amount Paid:</strong> 
                                    <span class="float-end">£<?php echo number_format($plan_data['amount_paid'] ?? 0, 2); ?></span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Total Payments:</strong> 
                                    <span class="float-end"><?php echo $plan_data['total_payments'] ?? 0; ?></span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Payments Made:</strong> 
                                    <span class="float-end"><?php echo $plan_data['payments_made'] ?? 0; ?></span>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h5>Calculations:</h5>
                            <ul class="list-group">
                                <li class="list-group-item">
                                    <strong>Expected Monthly:</strong> 
                                    <span class="float-end">
                                        <?php 
                                        $total = (float)($plan_data['total_amount'] ?? 0);
                                        $payments = (int)($plan_data['total_payments'] ?? 1);
                                        $expected = $payments > 0 ? $total / $payments : 0;
                                        echo '£' . number_format($expected, 2);
                                        ?>
                                    </span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Stored Monthly:</strong> 
                                    <span class="float-end">
                                        <?php 
                                        $stored = (float)($plan_data['monthly_amount'] ?? 0);
                                        echo $stored > 0 ? '£' . number_format($stored, 2) : '<span class="text-danger">£0.00</span>';
                                        ?>
                                    </span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Match:</strong> 
                                    <span class="float-end">
                                        <?php 
                                        if (abs($expected - $stored) < 0.01) {
                                            echo '<span class="badge bg-success">YES</span>';
                                        } else {
                                            echo '<span class="badge bg-danger">NO - MISMATCH!</span>';
                                        }
                                        ?>
                                    </span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Pledge Amount:</strong> 
                                    <span class="float-end">
                                        <?php 
                                        echo !empty($pledge_data['amount']) ? '£' . number_format($pledge_data['amount'], 2) : 'N/A';
                                        ?>
                                    </span>
                                </li>
                                <li class="list-group-item">
                                    <strong>Donor Balance:</strong> 
                                    <span class="float-end">
                                        <?php 
                                        echo !empty($donor_data['balance']) ? '£' . number_format($donor_data['balance'], 2) : 'N/A';
                                        ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Payment plan #<?php echo $plan_id; ?> not found in database!
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

