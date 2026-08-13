<?php
declare(strict_types=1);

require_once '../../../shared/auth.php';

require_login();
require_admin();

$path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'donors-bank-data.xlsx';
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Bank statement file not found.']);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: inline; filename="donors-bank-data.xlsx"');
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
