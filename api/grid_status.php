<?php
declare(strict_types=1);

/**
 * Floor Grid Status API
 *
 * Public projector payload is cell_id + status + summary counts only.
 * Donor names and amounts are never sent to the browser.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$cacheDir = sys_get_temp_dir() . '/projector_rl';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}
$cacheFile = $cacheDir . '/' . md5($ip) . '_grid.json';
$now = time();
$window = 60;
$maxRequests = 120;
$hits = file_exists($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?? []) : [];
$hits = array_values(array_filter($hits, static fn($t) => $t > $now - $window));
if (count($hits) >= $maxRequests) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests']);
    exit;
}
$hits[] = $now;
file_put_contents($cacheFile, json_encode($hits));

$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'https://abuneteklehaymanot.org',
    'https://www.abuneteklehaymanot.org',
    'https://donate.abuneteklehaymanot.org',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: false');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/IntelligentGridAllocator.php';

try {
    $db = db();
    $gridAllocator = new IntelligentGridAllocator($db);
    $format = (($_GET['format'] ?? 'detailed') === 'summary') ? 'summary' : 'detailed';

    $response = [
        'success' => true,
        'timestamp' => date('c'),
        'data' => [],
    ];

    $stats = $gridAllocator->getAllocationStats();
    $totalArea = (float) ($stats['total_possible_area'] ?? 0);
    $allocatedArea = (float) ($stats['total_allocated_area'] ?? 0);
    $progressPercentage = ($totalArea > 0) ? ($allocatedArea / $totalArea) * 100 : 0;
    $summary = [
        'total_cells' => (int) ($stats['total_cells'] ?? 0),
        'pledged_cells' => (int) ($stats['pledged_cells'] ?? 0),
        'paid_cells' => (int) ($stats['paid_cells'] ?? 0),
        'available_cells' => (int) ($stats['available_cells'] ?? 0),
        'total_area_sqm' => $totalArea,
        'allocated_area_sqm' => $allocatedArea,
        'progress_percentage' => round($progressPercentage, 2),
    ];

    if ($format === 'summary') {
        $response['data'] = ['statistics' => $summary];
    } else {
        $groupedData = [];
        foreach ($gridAllocator->getGridStatus() as $cell) {
            $rectId = (string) ($cell['rectangle_id'] ?? '');
            if ($rectId === '') {
                continue;
            }
            if (!isset($groupedData[$rectId])) {
                $groupedData[$rectId] = [];
            }
            $groupedData[$rectId][] = [
                'cell_id' => $cell['cell_id'],
                'status' => $cell['status'],
            ];
        }

        $response['data'] = [
            'grid_cells' => $groupedData,
            'summary' => $summary,
        ];
    }
} catch (Throwable $e) {
    error_log('grid_status.php: ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'success' => false,
        'error' => 'Unable to load grid status',
        'timestamp' => date('c'),
    ];
}

echo json_encode($response);
