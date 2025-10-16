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
		'payments' => [
			'pending' => 0,
			'approved' => 0,
			'latest' => null,
		],
		'recent_donations' => [],
		'donor_name' => null,
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
	
	// Get donor name from pledges or payments (most recent)
	$nameStmt = $db->prepare("
		(SELECT donor_name FROM pledges WHERE donor_phone = ? ORDER BY created_at DESC LIMIT 1)
		UNION ALL
		(SELECT donor_name FROM payments WHERE donor_phone = ? ORDER BY created_at DESC LIMIT 1)
		LIMIT 1
	");
	$nameStmt->bind_param('ss', $phone, $phone);
	$nameStmt->execute();
	$nameRes = $nameStmt->get_result();
	if ($nameRow = $nameRes->fetch_assoc()) {
		$result['donor_name'] = $nameRow['donor_name'];
	}
	$nameStmt->close();
	
	// Count pledges for this phone
	$stmt = $db->prepare("SELECT status, COUNT(*) as cnt, MAX(created_at) as latest FROM pledges WHERE donor_phone = ? GROUP BY status");
	$stmt->bind_param('s', $phone);
	$stmt->execute();
	$res = $stmt->get_result();
	$pledgeLatest = null;
	while ($row = $res->fetch_assoc()) {
		$status = (string)$row['status'];
		$cnt = (int)$row['cnt'];
		if ($status === 'pending') { $result['pledges']['pending'] = $cnt; }
		if ($status === 'approved') { $result['pledges']['approved'] = $cnt; }
		if ($row['latest'] && (!$pledgeLatest || $row['latest'] > $pledgeLatest)) { $pledgeLatest = $row['latest']; }
	}
	$result['pledges']['latest'] = $pledgeLatest;
	$stmt->close();
	
	// Count payments for this phone
	$paymentStmt = $db->prepare("SELECT status, COUNT(*) as cnt, MAX(created_at) as latest FROM payments WHERE donor_phone = ? GROUP BY status");
	$paymentStmt->bind_param('s', $phone);
	$paymentStmt->execute();
	$paymentRes = $paymentStmt->get_result();
	$paymentLatest = null;
	while ($paymentRow = $paymentRes->fetch_assoc()) {
		$status = (string)$paymentRow['status'];
		$cnt = (int)$paymentRow['cnt'];
		if ($status === 'pending') { $result['payments']['pending'] = $cnt; }
		if ($status === 'approved') { $result['payments']['approved'] = $cnt; }
		if ($paymentRow['latest'] && (!$paymentLatest || $paymentRow['latest'] > $paymentLatest)) { $paymentLatest = $paymentRow['latest']; }
	}
	$result['payments']['latest'] = $paymentLatest;
	$paymentStmt->close();
	
	// Get recent donations (pledges + payments combined, last 5)
	$recentSql = "
		(SELECT amount, 'pledge' AS type, created_at as donation_date, status FROM pledges WHERE donor_phone = ?)
		UNION ALL
		(SELECT amount, 'payment' AS type, created_at as donation_date, status FROM payments WHERE donor_phone = ?)
		ORDER BY donation_date DESC
		LIMIT 5
	";
	$recentStmt = $db->prepare($recentSql);
	$recentStmt->bind_param('ss', $phone, $phone);
	$recentStmt->execute();
	$recentRes = $recentStmt->get_result();
	while ($recentRow = $recentRes->fetch_assoc()) {
		$result['recent_donations'][] = [
			'amount' => (float)$recentRow['amount'],
			'type' => $recentRow['type'],
			'date' => $recentRow['donation_date'],
			'status' => $recentRow['status'],
		];
	}
	$recentStmt->close();
	
	$result['exists'] = ($result['pledges']['pending'] + $result['pledges']['approved'] + $result['payments']['pending'] + $result['payments']['approved']) > 0;

	echo json_encode($result);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['success' => false]);
}
?>


