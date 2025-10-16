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
		'success' => false,
		'normalized' => $phone,
		'donations' => [],
	];

	if (!$phone) {
		echo json_encode($result);
		exit;
	}

	$db = db();
	
	// Get all donations (pledges + payments) for this phone, ordered by date desc
	$sql = "
		(SELECT 
			id,
			amount, 
			'pledge' AS type, 
			created_at as date, 
			status 
		FROM pledges 
		WHERE donor_phone = ?)
		UNION ALL
		(SELECT 
			id,
			amount, 
			'payment' AS type, 
			created_at as date, 
			status 
		FROM payments 
		WHERE donor_phone = ?)
		ORDER BY date DESC
	";
	
	$stmt = $db->prepare($sql);
	$stmt->bind_param('ss', $phone, $phone);
	$stmt->execute();
	$res = $stmt->get_result();
	
	while ($row = $res->fetch_assoc()) {
		$result['donations'][] = [
			'id' => (int)$row['id'],
			'amount' => (float)$row['amount'],
			'type' => $row['type'],
			'date' => $row['date'],
			'status' => $row['status'],
		];
	}
	$stmt->close();
	
	$result['success'] = true;

	echo json_encode($result);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
?>
