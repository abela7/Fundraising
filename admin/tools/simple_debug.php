<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/SmartGridAllocator.php';

try {
    $db = db();
    echo "✅ Database connected successfully\n";
    
    $allocator = new SmartGridAllocator($db);
    echo "✅ SmartGridAllocator created successfully\n";
    
    // Test basic methods
    $stats = $allocator->getAllocationStats();
    echo "✅ Stats retrieved: " . json_encode($stats) . "\n";
    
    $gridStatus = $allocator->getGridStatus();
    echo "✅ Grid status retrieved: " . count($gridStatus) . " allocated cells\n";
    
    // Try a test allocation
    echo "\n🧪 Testing allocation...\n";
    $result = $allocator->allocateGridCells(
        null,
        null,
        100.0,
        3, // Package 3 (£100)
        "Debug Test Donor",
        "pledged"
    );
    
    echo "Allocation result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
