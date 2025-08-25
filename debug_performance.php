<?php
// Simple debug version to identify the issue
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Performance Test</h1>";

try {
    echo "<h2>Step 1: Check basic PHP</h2>";
    echo "✅ PHP is working<br>";
    
    echo "<h2>Step 2: Check database connection</h2>";
    require_once 'config/db.php';
    $db = db();
    echo "✅ Database connected<br>";
    echo "Thread ID: " . $db->thread_id . "<br>";
    
    echo "<h2>Step 3: Check CSRF function</h2>";
    require_once 'shared/csrf.php';
    echo "✅ CSRF loaded<br>";
    
    echo "<h2>Step 4: Test basic query</h2>";
    $result = $db->query("SELECT COUNT(*) as count FROM users");
    if ($result) {
        $row = $result->fetch_assoc();
        echo "✅ Query successful - User count: " . $row['count'] . "<br>";
    }
    
    echo "<h2>Step 5: Test password_hash function</h2>";
    $testHash = password_hash("test123", PASSWORD_DEFAULT);
    echo "✅ Password hash works: " . substr($testHash, 0, 20) . "...<br>";
    
    echo "<h1 style='color: green;'>✅ All basic functions work!</h1>";
    echo "<p>The issue might be in the complex logic. Let's check the specific error.</p>";
    
} catch (Exception $e) {
    echo "<h1 style='color: red;'>❌ Error Found:</h1>";
    echo "<p style='color: red; font-weight: bold;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<h3>Stack Trace:</h3>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<hr>";
echo "<h3>PHP Info:</h3>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Memory Limit: " . ini_get('memory_limit') . "<br>";
echo "Max Execution Time: " . ini_get('max_execution_time') . "<br>";
?>

<h3>Server Variables:</h3>
<pre>
<?php print_r($_SERVER); ?>
</pre>
