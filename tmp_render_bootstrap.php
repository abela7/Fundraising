<?php
session_start();
$_SESSION['user'] = [
    'id' => 1,
    'name' => 'Test Admin',
    'phone' => '0711111111',
    'role' => 'admin'
];
$_SERVER['SCRIPT_NAME'] = '/admin/messaging/whatsapp/inbox.php';
$_SERVER['REQUEST_URI'] = '/admin/messaging/whatsapp/inbox.php?id=1&filter=all';
$_GET['id'] = '1';
$_GET['filter'] = 'all';
require 'admin/messaging/whatsapp/inbox.php';