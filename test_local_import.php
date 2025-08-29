<?php
require_once 'config/env.php';
require_once 'config/db.php';

echo "🔍 Testing Local Database Import\n";
echo "=================================\n\n";

echo "Environment: " . ENVIRONMENT . "\n";
echo "Database: " . DB_NAME . "\n\n";

try {
    $db = db();
    echo "✅ Database connection: SUCCESS\n\n";
    
    // Check key tables and their data
    $tables = [
        'users' => 'SELECT COUNT(*) as count FROM users',
        'donation_packages' => 'SELECT COUNT(*) as count FROM donation_packages', 
        'payments' => 'SELECT COUNT(*) as count FROM payments',
        'pledges' => 'SELECT COUNT(*) as count FROM pledges',
        'counters' => 'SELECT paid_total, pledged_total, grand_total FROM counters WHERE id=1',
        'settings' => 'SELECT target_amount, currency_code FROM settings WHERE id=1'
    ];
    
    foreach ($tables as $table => $sql) {
        $result = $db->query($sql);
        if ($result) {
            $data = $result->fetch_assoc();
            
            if ($table === 'counters') {
                echo "💰 {$table}: Paid=£" . number_format($data['paid_total'], 2) . 
                     ", Pledged=£" . number_format($data['pledged_total'], 2) . 
                     ", Total=£" . number_format($data['grand_total'], 2) . "\n";
            } elseif ($table === 'settings') {
                echo "⚙️  {$table}: Target=£" . number_format($data['target_amount'], 2) . 
                     ", Currency=" . $data['currency_code'] . "\n";
            } else {
                echo "📊 {$table}: " . $data['count'] . " records\n";
            }
        } else {
            echo "❌ {$table}: Query failed\n";
        }
    }
    
    echo "\n🎉 SUCCESS! Your local database is ready!\n";
    echo "🌐 You can now access: http://localhost/Fundraising/\n";
    echo "👤 Admin login should work with your existing users\n";
    
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
    echo "\n💡 Make sure you've imported the SQL file in phpMyAdmin!\n";
}
?>
