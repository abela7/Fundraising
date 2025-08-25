<?php
declare(strict_types=1);

// Minimal performance test to identify the exact issue
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<h1>Minimal Performance Test - Debugging</h1>";

try {
    require_once 'config/db.php';
    echo "✅ Config loaded<br>";
    
    $db = db();
    echo "✅ Database connected<br>";
    
    // Check if we can load the functions
    echo "<h2>Testing Functions</h2>";
    
    // Test getRegistrarUsers function
    function getRegistrarUsers(): array {
        $db = db();
        $result = $db->query("SELECT id, name FROM users WHERE role = 'registrar' AND active = 1 ORDER BY id LIMIT 5");
        $registrars = [];
        while ($row = $result->fetch_assoc()) {
            $registrars[] = $row;
        }
        return $registrars;
    }
    
    $registrars = getRegistrarUsers();
    echo "✅ getRegistrarUsers() works - found " . count($registrars) . " registrars<br>";
    
    // Test name generation
    function generateRealisticName(): string {
        $firstNames = ['Abel', 'Abebe', 'Almaz'];
        $lastNames = ['Tadesse', 'Bekele', 'Desta'];
        return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    }
    
    $testName = generateRealisticName();
    echo "✅ generateRealisticName() works - generated: $testName<br>";
    
    // Test phone generation
    function generateUkPhone(): string {
        return '074' . str_pad((string)rand(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    }
    
    $testPhone = generateUkPhone();
    echo "✅ generateUkPhone() works - generated: $testPhone<br>";
    
    // Test UUID generation
    function generateUUID(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    $testUUID = generateUUID();
    echo "✅ generateUUID() works - generated: $testUUID<br>";
    
    echo "<h2>Testing Small Data Generation</h2>";
    
    // Test generating just 5 records
    function generateSmallTestData(): array {
        $registrars = getRegistrarUsers();
        $testData = [];
        
        for ($i = 0; $i < 5; $i++) {
            $testData[] = [
                'donor_name' => generateRealisticName(),
                'donor_phone' => generateUkPhone(),
                'donor_email' => null,
                'package_id' => 1,
                'source' => 'volunteer',
                'anonymous' => 0,
                'amount' => 400.00,
                'type' => 'pledge',
                'status' => 'pending',
                'notes' => 'Test record',
                'client_uuid' => generateUUID(),
                'created_by_user_id' => $registrars[0]['id'],
                'registrar_name' => $registrars[0]['name']
            ];
        }
        
        return $testData;
    }
    
    $smallData = generateSmallTestData();
    echo "✅ generateSmallTestData() works - created " . count($smallData) . " records<br>";
    
    // Test the HTML output
    echo "<h2>Testing HTML Generation</h2>";
    
    $dbStatus = "✅ Database connection successful";
    $testResults = [];
    $errors = [];
    $success = '';
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Test</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
        <div class="container">
            <h1>Test HTML</h1>
            <div class="alert alert-success">
                <?php echo $dbStatus; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    $htmlOutput = ob_get_clean();
    
    echo "✅ HTML generation works<br>";
    
    echo "<h1>✅ ALL BASIC TESTS PASSED</h1>";
    echo "<p>The issue might be in the full HTML or in the large data generation. Let me create a step-by-step version.</p>";
    
} catch (Exception $e) {
    echo "<h2>❌ Error Found!</h2>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
