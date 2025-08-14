<?php
declare(strict_types=1);
header('Content-Type: application/json');
error_reporting(0); // Suppress PHP errors from output
ini_set('display_errors', '0');

try {
    require_once __DIR__ . '/../config/db.php';
    
    $amount = (float)($_GET['amount'] ?? 0);
    if ($amount <= 0) {
        echo json_encode([
            'success' => false, 
            'error' => 'Invalid amount', 
            'received_amount' => $amount,
            'debug' => 'Amount must be greater than 0'
        ]);
        exit();
    }
    
    $db = db();
    
    // Get donation packages ordered by descending sqm_meters (largest first)
    $result = $db->query("SELECT sqm_meters, price FROM donation_packages WHERE sqm_meters > 0 ORDER BY sqm_meters DESC");
    $packages = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($packages)) {
        echo json_encode(['success' => false, 'error' => 'No packages found']);
        exit();
    }
    
    // Find the best matching package by price
    $bestMatch = null;
    $minDifference = PHP_FLOAT_MAX;
    
    foreach ($packages as $package) {
        $priceDiff = abs((float)$package['price'] - $amount);
        if ($priceDiff < $minDifference) {
            $minDifference = $priceDiff;
            $bestMatch = $package;
        }
    }
    
    if (!$bestMatch) {
        echo json_encode(['success' => false, 'error' => 'No matching package found']);
        exit();
    }
    
    // Calculate square meters based on the amount and best matching package
    $sqmPerUnit = (float)$bestMatch['sqm_meters'];
    $pricePerUnit = (float)$bestMatch['price'];
    
    if ($pricePerUnit > 0) {
        $calculatedSqm = ($amount / $pricePerUnit) * $sqmPerUnit;
    } else {
        $calculatedSqm = 0;
    }
    
    // Format the square meter display
    $sqmDisplay = '';
    if ($calculatedSqm >= 1) {
        $sqmDisplay = number_format($calculatedSqm, 0) . ' Square Meter' . ($calculatedSqm > 1 ? 's' : '');
    } else {
        // Handle fractions
        if ($calculatedSqm == 0.5) {
            $sqmDisplay = '½ Square Meter';
        } elseif ($calculatedSqm == 0.25) {
            $sqmDisplay = '¼ Square Meter';
        } elseif ($calculatedSqm == 0.75) {
            $sqmDisplay = '¾ Square Meter';
        } else {
            $sqmDisplay = number_format($calculatedSqm, 2) . ' Square Meters';
        }
    }
    
    echo json_encode([
        'success' => true,
        'amount' => $amount,
        'sqm_value' => $calculatedSqm,
        'sqm_display' => $sqmDisplay,
        'matched_package' => [
            'sqm_meters' => $sqmPerUnit,
            'price' => $pricePerUnit
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Calculation error',
        'details' => $e->getMessage() // For debugging, remove in production
    ]);
}
?>
