<?php

declare(strict_types=1);

require_once '../../../shared/auth.php';
require_once '../../../config/db.php';
require_once '../../../shared/CampaignPayingReport.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

require_login();
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$filter = strtolower(trim((string) ($_GET['filter'] ?? CampaignPayingReport::FILTER_ALL)));
if (!in_array($filter, CampaignPayingReport::FILTERS, true)) {
    $filter = CampaignPayingReport::FILTER_ALL;
}
$search = trim((string) ($_GET['donor'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));

try {
    $all = CampaignPayingReport::fetch(db());
    $summary = CampaignPayingReport::summarize($all);
    $filtered = CampaignPayingReport::filterRows($all, $filter, $search);
    usort($filtered, static function (array $a, array $b): int {
        $aBooked = !empty($a['booked']);
        $bBooked = !empty($b['booked']);
        if ($aBooked !== $bBooked) {
            return $aBooked ? -1 : 1;
        }
        if ($aBooked && $bBooked) {
            return strcmp(
                (string) ($a['contact_date'] ?? '') . (string) ($a['contact_time'] ?? ''),
                (string) ($b['contact_date'] ?? '') . (string) ($b['contact_time'] ?? '')
            );
        }
        $openCmp = strcmp((string) ($b['opened_at'] ?? ''), (string) ($a['opened_at'] ?? ''));
        if ($openCmp !== 0) {
            return $openCmp;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });
    $total = count($filtered);
    $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
    $offset = ($page - 1) * $perPage;
    $rows = array_slice($filtered, $offset, $perPage);

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'filter' => $filter,
        'total_count' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'rows' => $rows,
    ]);
} catch (Throwable $e) {
    error_log('Paying report API failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load the still-paying report.']);
}
