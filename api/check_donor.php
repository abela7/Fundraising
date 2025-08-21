<?php
declare(strict_types=1);
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';

function normalize_uk_mobile(string $raw): string {
	$digits = preg_replace('/[^0-9+]/', '', $raw);
	if (strpos($digits, '+44') === 0) {
		$digits = '0' . substr($digits, 3);
	}
	return $digits;
}

try {
	// Handle both GET and POST requests
	$input = '';
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$postData = json_decode(file_get_contents('php://input'), true);
		$input = trim((string)($postData['phone'] ?? ''));
	} else {
		$input = trim((string)($_GET['phone'] ?? ''));
	}
	
	$phone = normalize_uk_mobile($input);

	$result = [
		'success' => true,
		'normalized' => $phone,
		'valid_uk' => false,
		'exists' => false,
		'pledges' => [
			'pending' => 0,
			'approved' => 0,
			'latest' => null,
		],
	];

	// UK mobile starts with 07 and has 11 digits
	if (preg_match('/^07\d{9}$/', $phone)) {
		$result['valid_uk'] = true;
	}

	if (!$phone) {
		echo json_encode($result);
		exit;
	}

	$db = db();
	// Count pledges for this phone
	$stmt = $db->prepare("SELECT status, COUNT(*) as cnt, MAX(created_at) as latest FROM pledges WHERE donor_phone = ? GROUP BY status");
	$stmt->bind_param('s', $phone);
	$stmt->execute();
	$res = $stmt->get_result();
	$latest = null;
	while ($row = $res->fetch_assoc()) {
		$status = (string)$row['status'];
		$cnt = (int)$row['cnt'];
		if ($status === 'pending') { $result['pledges']['pending'] = $cnt; }
		if ($status === 'approved') { $result['pledges']['approved'] = $cnt; }
		if ($row['latest'] && (!$latest || $row['latest'] > $latest)) { $latest = $row['latest']; }
	}
	$result['pledges']['latest'] = $latest;
	
	// Also check payments for this phone
	$paymentCount = 0;
	$paymentStmt = $db->prepare("SELECT COUNT(*) as cnt FROM payments WHERE donor_phone = ? AND status IN ('pending', 'approved')");
	$paymentStmt->bind_param('s', $phone);
	$paymentStmt->execute();
	$paymentRes = $paymentStmt->get_result();
	if ($paymentRow = $paymentRes->fetch_assoc()) {
		$paymentCount = (int)$paymentRow['cnt'];
	}
	
	$result['exists'] = ($result['pledges']['pending'] + $result['pledges']['approved'] + $paymentCount) > 0;

	echo json_encode($result);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['success' => false]);
}
?>


