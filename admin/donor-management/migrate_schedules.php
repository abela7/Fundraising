<?php
// admin/donor-management/migrate_schedules.php
require_once __DIR__ . '/../../config/db.php';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Payment Plan Schedule Migration</h1>";

try {
    $db = db();
    
    // Check if table exists
    $check = $db->query("SHOW TABLES LIKE 'payment_plan_schedule'");
    if ($check->num_rows === 0) {
        die("Error: Table 'payment_plan_schedule' does not exist. Please run the SQL query first.");
    }
    
    // Get all active plans
    $query = "SELECT * FROM donor_payment_plans";
    $result = $db->query($query);
    
    $count = 0;
    $total = $result->num_rows;
    
    echo "<p>Found $total plans to process...</p>";
    echo "<ul>";
    
    while ($plan = $result->fetch_assoc()) {
        $plan_id = $plan['id'];
        
        // Check if schedule already exists
        $check_exist = $db->query("SELECT COUNT(*) as cnt FROM payment_plan_schedule WHERE plan_id = $plan_id");
        $row = $check_exist->fetch_assoc();
        if ($row['cnt'] > 0) {
            echo "<li>Plan #$plan_id: Schedule already exists. Skipping.</li>";
            continue;
        }
        
        // Calculate schedule
        $schedule = [];
        $current_date = new DateTime($plan['start_date']);
        $freq_unit = $plan['plan_frequency_unit'] ?? 'month';
        $freq_num = (int)($plan['plan_frequency_number'] ?? 1);
        $payments_made = (int)($plan['payments_made'] ?? 0);
        $total_payments = (int)($plan['total_payments'] ?? 0);
        $monthly_amount = (float)($plan['monthly_amount'] ?? 0);
        
        // Get related payments to link them
        $payments = [];
        $pay_query = $db->query("SELECT id, created_at FROM payments WHERE donor_id = {$plan['donor_id']} AND pledge_id = {$plan['pledge_id']} ORDER BY created_at ASC");
        while ($p = $pay_query->fetch_assoc()) {
            $payments[] = $p;
        }
        
        for ($i = 1; $i <= $total_payments; $i++) {
            $status = 'pending';
            $payment_id = null;
            $is_paid = ($i <= $payments_made);
            
            if ($is_paid) {
                $status = 'paid';
                // Try to link to an actual payment record roughly matching the index
                if (isset($payments[$i-1])) {
                    $payment_id = $payments[$i-1]['id'];
                }
            } elseif ($current_date < new DateTime('today')) {
                $status = 'overdue';
            }
            
            $due_date = $current_date->format('Y-m-d');
            
            // Insert row
            $stmt = $db->prepare("INSERT INTO payment_plan_schedule (plan_id, installment_number, due_date, amount, status, payment_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisdsi', $plan_id, $i, $due_date, $monthly_amount, $status, $payment_id);
            $stmt->execute();
            $stmt->close();
            
            // Advance date
            if ($freq_unit === 'day') {
                $current_date->modify("+{$freq_num} days");
            } elseif ($freq_unit === 'week') {
                $current_date->modify("+{$freq_num} weeks");
            } elseif ($freq_unit === 'month') {
                $current_date->modify("+{$freq_num} months");
            } elseif ($freq_unit === 'year') {
                $current_date->modify("+{$freq_num} years");
            } else {
                $current_date->modify("+1 month");
            }
        }
        
        $count++;
        echo "<li>Plan #$plan_id: Generated schedule with $total_payments installments.</li>";
    }
    
    echo "</ul>";
    echo "<h3>Migration Completed! Processed $count plans.</h3>";
    echo "<p><a href='donors.php'>Back to Donors</a></p>";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

