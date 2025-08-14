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
	$raw = trim((string)($_GET['phone'] ?? ''));
	$phone = normalize_uk_mobile($raw);

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
	$result['exists'] = ($result['pledges']['pending'] + $result['pledges']['approved']) > 0;

	echo json_encode($result);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['success' => false]);
}
?>


