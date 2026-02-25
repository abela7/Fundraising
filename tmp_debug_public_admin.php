<?php
declare(strict_types=1);
require_once __DIR__.'/../config/env.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/admin/approvals/public-donations.php';
$_SERVER['REQUEST_URI'] = '/admin/approvals/public-donations.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = 'C:/xampp/htdocs/Fundraising';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = [];
$_POST = [];
ob_start();
require __DIR__ . '/admin/approvals/public-donations.php';
$out = ob_get_clean();
echo 'LENGTH:' . strlen($out) . "`n";
if (strpos($out, 'Unable to load donation request records.') !== false) { echo 'HAS_RECORDS_ERROR`n'; }
if (strpos($out, 'Database table missing') !== false) { echo 'HAS_TABLE_MISSING`n'; }
if (strpos($out, 'Database Connection Error') !== false) { echo 'HAS_DB_ERROR`n'; }
echo substr($out, 0, 220);
