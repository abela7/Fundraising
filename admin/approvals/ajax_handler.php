<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
require_login();
require_admin();

// Set JSON response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

$db = db();
$response = ['success' => false, 'message' => '', 'data' => []];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }

    // Verify CSRF token
    verify_csrf();
    
    $action = $_POST['action'] ?? '';
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    
    // Handle pledge actions
    if ($pledgeId && in_array($action, ['approve', 'reject', 'update'], true)) {
        $db->begin_transaction();
        
        try {
            $stmt = $db->prepare('SELECT id, amount, type, status, donor_name FROM pledges WHERE id = ? FOR UPDATE');
            $stmt->bind_param('i', $pledgeId);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc();
            
            if (!$pledge || $pledge['status'] !== 'pending') {
                throw new RuntimeException('Invalid pledge state');
            }

            if ($action === 'approve') {
                // Update pledge status
                $upd = $db->prepare("UPDATE pledges SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?");
                $uid = (int)current_user()['id'];
                $upd->bind_param('ii', $uid, $pledgeId);
                $upd->execute();

                // Update counters
                $deltaPaid = 0.0;
                $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') {
                    $deltaPaid = (float)$pledge['amount'];
                } else {
                    $deltaPledged = (float)$pledge['amount'];
                }

                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, ?, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       pledged_total = pledged_total + VALUES(pledged_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $deltaPaid + $deltaPledged;
                $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
                $ctr->execute();

                // Floor grid allocation
                $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
                $status = ($pledge['type'] === 'paid') ? 'paid' : 'pledged';
                $amount = (float)$pledge['amount'];
                
                $customAllocator = new CustomAmountAllocator($db);
                
                if ($pledge['type'] === 'paid') {
                    $allocationResult = $customAllocator->processPaymentCustomAmount(
                        $pledgeId,
                        $amount,
                        $donorName,
                        $status
                    );
                } else {
                    $allocationResult = $customAllocator->processCustomAmount(
                        $pledgeId,
                        $amount,
                        $donorName,
                        $status
                    );
                }
                
                // Audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ipBin = $ip ? @inet_pton($ip) : null;
                $before = json_encode(['status' => 'pending', 'type' => $pledge['type'], 'amount' => (float)$pledge['amount']], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'approved', 'grid_allocation' => $allocationResult], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, ip_address, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, ?, 'admin')");
                $log->bind_param('iisss', $uid, $pledgeId, $before, $after, $ipBin);
                
                if (!$log->execute()) {
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                }
                
                $response['message'] = 'Pledge approved successfully';
                if ($allocationResult['success']) {
                    if ($allocationResult['type'] === 'accumulated') {
                        $response['message'] .= " - {$allocationResult['message']}";
                    } else {
                        $response['message'] .= " - {$allocationResult['message']}";
                    }
                }
                
            } elseif ($action === 'reject') {
                $uid = (int)current_user()['id'];
                $rej = $db->prepare("UPDATE pledges SET status='rejected' WHERE id = ?");
                $rej->bind_param('i', $pledgeId);
                $rej->execute();

                // Audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ipBin = $ip ? @inet_pton($ip) : null;
                $before = json_encode(['status' => 'pending'], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'rejected'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, ip_address, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, ?, 'admin')");
                $log->bind_param('iisss', $uid, $pledgeId, $before, $after, $ipBin);
                
                if (!$log->execute()) {
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                }
                
                $response['message'] = 'Pledge rejected successfully';
                
            } elseif ($action === 'update') {
                // Update pledge details
                $donorName = trim($_POST['donor_name'] ?? '');
                $donorPhone = trim($_POST['donor_phone'] ?? '');
                $donorEmail = trim($_POST['donor_email'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $packageId = isset($_POST['package_id']) ? (int)$_POST['package_id'] : null;
                
                // Validate phone number
                if ($donorPhone !== '') {
                    $digits = preg_replace('/[^0-9+]/', '', $donorPhone);
                    if (strpos($digits, '+44') === 0) { $digits = '0' . substr($digits, 3); }
                    if (!preg_match('/^07\d{9}$/', $digits)) {
                        throw new RuntimeException('Phone must be a valid UK mobile (start with 07)');
                    }
                    $donorPhone = $digits;
                }
                
                // Check for duplicates
                if ($donorPhone !== '') {
                    $chk = $db->prepare("SELECT id FROM pledges WHERE donor_phone=? AND status IN ('pending','approved') AND id<>? LIMIT 1");
                    $chk->bind_param('si', $donorPhone, $pledgeId);
                    $chk->execute();
                    if ($chk->get_result()->fetch_assoc()) {
                        throw new RuntimeException('Another pledge exists with this phone');
                    }
                    $chk->close();
                    
                    $chk2 = $db->prepare("SELECT id FROM payments WHERE donor_phone=? AND status IN ('pending','approved') LIMIT 1");
                    $chk2->bind_param('s', $donorPhone);
                    $chk2->execute();
                    if ($chk2->get_result()->fetch_assoc()) {
                        throw new RuntimeException('A payment exists with this phone');
                    }
                }
                
                if ($donorName && $donorPhone && $amount > 0) {
                    if ($packageId && $packageId > 0) {
                        $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, package_id=?, notes=? WHERE id=?");
                        $upd->bind_param('sssdisi', $donorName, $donorPhone, $donorEmail, $amount, $packageId, $notes, $pledgeId);
                    } else {
                        $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=? WHERE id=?");
                        $upd->bind_param('sssdsi', $donorName, $donorPhone, $donorEmail, $amount, $notes, $pledgeId);
                    }
                    $upd->execute();
                    
                    // Audit log
                    $uid = (int)current_user()['id'];
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $ipBin = $ip ? @inet_pton($ip) : null;
                    $before = json_encode(['status' => 'pending'], JSON_UNESCAPED_SLASHES);
                    $after = json_encode(['donor_name' => $donorName, 'amount' => $amount, 'package_id' => $packageId, 'updated' => true], JSON_UNESCAPED_SLASHES);
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'update', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                    
                    $response['message'] = 'Pledge updated successfully';
                    $response['data'] = [
                        'donor_name' => $donorName,
                        'donor_phone' => $donorPhone,
                        'donor_email' => $donorEmail,
                        'amount' => $amount,
                        'notes' => $notes
                    ];
                } else {
                    throw new RuntimeException('Invalid data provided');
                }
            }
            
            $db->commit();
            $response['success'] = true;
            $response['data']['item_id'] = $pledgeId;
            $response['data']['action'] = $action;
            
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
    
    // Handle payment actions
    elseif ($paymentId && in_array($action, ['approve_payment', 'reject_payment', 'update_payment'], true)) {
        $db->begin_transaction();
        
        try {
            $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
            $sel->bind_param('i', $paymentId);
            $sel->execute();
            $pay = $sel->get_result()->fetch_assoc();
            
            if (!$pay) {
                throw new RuntimeException('Payment not found');
            }

            if ($action === 'approve_payment') {
                if ((string)$pay['status'] !== 'pending') {
                    throw new RuntimeException('Payment not pending');
                }
                
                $upd = $db->prepare("UPDATE payments SET status='approved' WHERE id=?");
                $upd->bind_param('i', $paymentId);
                $upd->execute();

                // Update counters
                $amt = (float)$pay['amount'];
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, 0, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $amt;
                $ctr->bind_param('dd', $amt, $grandDelta);
                $ctr->execute();

                // Floor grid allocation
                $customAllocator = new CustomAmountAllocator($db);
                $paymentDetails = $db->prepare("SELECT donor_name, amount, package_id FROM payments WHERE id = ?");
                $paymentDetails->bind_param('i', $paymentId);
                $paymentDetails->execute();
                $paymentData = $paymentDetails->get_result()->fetch_assoc();

                if ($paymentData) {
                    $allocationResult = $customAllocator->processPaymentCustomAmount(
                        $paymentId,
                        (float)$paymentData['amount'],
                        (string)$paymentData['donor_name'],
                        'paid'
                    );
                    
                    $response['message'] = 'Payment approved successfully';
                    if ($allocationResult['success']) {
                        $response['message'] .= " - " . $allocationResult['message'];
                    }
                } else {
                    $response['message'] = 'Payment approved successfully';
                }
                
            } elseif ($action === 'reject_payment') {
                if ((string)$pay['status'] !== 'pending') {
                    throw new RuntimeException('Payment not pending');
                }
                
                $upd = $db->prepare("UPDATE payments SET status='voided' WHERE id=?");
                $upd->bind_param('i', $paymentId);
                $upd->execute();

                $uid = (int)current_user()['id'];
                $before = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status'=>'voided'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'reject', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                $log->execute();
                
                $response['message'] = 'Payment rejected successfully';
            }
            
            $db->commit();
            $response['success'] = true;
            $response['data']['item_id'] = $paymentId;
            $response['data']['action'] = $action;
            
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
    
    else {
        throw new RuntimeException('Invalid action or missing ID');
    }

} catch (Throwable $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("AJAX Handler Error: " . $e->getMessage());
}

echo json_encode($response);
exit;
