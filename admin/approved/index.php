<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_once __DIR__ . '/../../shared/GridAllocationBatchTracker.php';

// Helper function to build redirect URL preserving current filter parameters
function buildRedirectUrl($message, $postData = []) {
    $params = ['msg' => $message];
    
    // Preserve filter and pagination parameters from POST data (since they were submitted with the form)
    $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
    
    foreach ($preserveParams as $param) {
        if (isset($postData[$param]) && $postData[$param] !== '') {
            $params[$param] = $postData[$param];
        }
    }
    
    return 'index.php?' . http_build_query($params);
}

require_login();
require_admin();

$db = db();
$page_title = 'Approved Items';
$actionMsg = '';

// Load active donation packages for edit modal
$pkgRows = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);

// No helpers needed currently

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'undo_batch') {
        $batchId = (int)($_POST['batch_id'] ?? 0);
        if ($batchId > 0) {
            $db->begin_transaction();
            try {
                $batchTracker = new GridAllocationBatchTracker($db);
                
                // Get batch details
                $batch = $batchTracker->getBatchById($batchId);
                if (!$batch || $batch['approval_status'] !== 'approved') {
                    throw new RuntimeException('Invalid batch state');
                }
                
                // Deallocate the batch
                $deallocationResult = $batchTracker->deallocateBatch($batchId);
                if (!$deallocationResult['success']) {
                    throw new RuntimeException('Batch deallocation failed: ' . ($deallocationResult['error'] ?? 'Unknown error'));
                }
                
                // If this is a pledge_update batch, restore the original pledge amount
                if ($batch['batch_type'] === 'pledge_update' && (int)($batch['original_pledge_id'] ?? 0) > 0) {
                    $pledgeId = (int)$batch['original_pledge_id'];
                    $originalAmount = (float)($batch['original_amount'] ?? 0);
                    $additionalAmount = (float)($batch['additional_amount'] ?? 0);
                    
                    // Lock and update pledge
                    $sel = $db->prepare("SELECT id, amount, status FROM pledges WHERE id=? FOR UPDATE");
                    $sel->bind_param('i', $pledgeId);
                    $sel->execute();
                    $pledge = $sel->get_result()->fetch_assoc();
                    if (!$pledge || (string)($pledge['status'] ?? '') !== 'approved') {
                        throw new RuntimeException('Invalid pledge state');
                    }
                    
                    // Restore original amount
                    $upd = $db->prepare("UPDATE pledges SET amount=? WHERE id=?");
                    $upd->bind_param('di', $originalAmount, $pledgeId);
                    $upd->execute();
                    
                    // Update donor totals
                    $donorId = (int)($batch['donor_id'] ?? 0);
                    if ($donorId > 0) {
                        $donorUpd = $db->prepare("
                            UPDATE donors SET
                                total_pledged = total_pledged - ?,
                                balance = balance - ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $donorUpd->bind_param('ddi', $additionalAmount, $additionalAmount, $donorId);
                        $donorUpd->execute();
                    }
                    
                    // Update counters
                    $deltaPledged = -1 * $additionalAmount;
                    $grandDelta = $deltaPledged;
                    $ctr = $db->prepare(
                        "INSERT INTO counters (id, pledged_total, grand_total, version, recalc_needed)
                         VALUES (1, ?, ?, 1, 0)
                         ON DUPLICATE KEY UPDATE
                           pledged_total = pledged_total + VALUES(pledged_total),
                           grand_total = grand_total + VALUES(grand_total),
                           version = version + 1,
                           recalc_needed = 0"
                    );
                    $ctr->bind_param('dd', $deltaPledged, $grandDelta);
                    $ctr->execute();
                    
                    // Audit log
                    $uid = (int)(current_user()['id'] ?? 0);
                    $before = json_encode([
                        'pledge_id' => $pledgeId,
                        'amount' => (float)$pledge['amount'],
                        'batch_id' => $batchId
                    ], JSON_UNESCAPED_SLASHES);
                    $after = json_encode([
                        'pledge_id' => $pledgeId,
                        'amount' => $originalAmount,
                        'batch_id' => $batchId,
                        'action' => 'batch_undo'
                    ], JSON_UNESCAPED_SLASHES);
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'undo_batch', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                } else {
                    // For payment_update or new batches, handle differently
                    // Update counters based on batch type
                    $deltaPledged = 0;
                    $deltaPaid = 0;
                    if (in_array($batch['batch_type'], ['new_pledge', 'pledge_update'])) {
                        $deltaPledged = -1 * (float)($batch['additional_amount'] ?? 0);
                    } elseif (in_array($batch['batch_type'], ['new_payment', 'payment_update'])) {
                        $deltaPaid = -1 * (float)($batch['additional_amount'] ?? 0);
                    }
                    $grandDelta = $deltaPledged + $deltaPaid;
                    
                    if ($grandDelta != 0) {
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
                        $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
                        $ctr->execute();
                    }
                    
                    // Update donor totals (subtract since we're undoing)
                    $donorId = (int)($batch['donor_id'] ?? 0);
                    if ($donorId > 0) {
                        $donorUpd = $db->prepare("
                            UPDATE donors SET
                                total_pledged = total_pledged + ?,
                                total_paid = total_paid + ?,
                                balance = balance + ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        // deltaPledged and deltaPaid are already negative, so adding them subtracts
                        $donorUpd->bind_param('dddi', $deltaPledged, $deltaPaid, $grandDelta, $donorId);
                        $donorUpd->execute();
                    }
                    
                    // Audit log
                    $uid = (int)(current_user()['id'] ?? 0);
                    $entityId = (int)($batch['original_pledge_id'] ?? $batch['original_payment_id'] ?? $batchId);
                    $entityType = ($batch['batch_type'] === 'pledge_update' || $batch['batch_type'] === 'new_pledge') ? 'pledge' : 'payment';
                    $before = json_encode(['batch_id' => $batchId, 'status' => 'approved'], JSON_UNESCAPED_SLASHES);
                    $after = json_encode(['batch_id' => $batchId, 'status' => 'cancelled', 'action' => 'batch_undo'], JSON_UNESCAPED_SLASHES);
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, ?, ?, 'undo_batch', ?, ?, 'admin')");
                    $log->bind_param('isiiss', $uid, $entityType, $entityId, $before, $after);
                    $log->execute();
                }
                
                $db->commit();
                $actionMsg = 'Batch update undone successfully';
                
                // Set a flag to trigger floor map refresh on page load
                $_SESSION['trigger_floor_refresh'] = true;
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'undo') {
        $pledgeId = (int)($_POST['pledge_id'] ?? 0);
        if ($pledgeId > 0) {
            $db->begin_transaction();
            try {
                // Lock pledge and verify approved
                $sel = $db->prepare("SELECT id, amount, type, status, source FROM pledges WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $pledgeId);
                $sel->execute();
                $pledge = $sel->get_result()->fetch_assoc();
                if (!$pledge || $pledge['status'] !== 'approved') {
                    throw new RuntimeException('Invalid state');
                }

                $batchTracker = new GridAllocationBatchTracker($db);
                
                // Find batch(s) associated with this pledge
                $batches = $batchTracker->getBatchesForPledge($pledgeId);
                
                // Check if this pledge was updated (has batches with original_pledge_id matching this pledge)
                $isUpdateRequest = false;
                $latestBatch = null;
                if (!empty($batches)) {
                    // Check if any batch has this pledge as the original (meaning this pledge was updated)
                    foreach ($batches as $batch) {
                        if ((int)($batch['original_pledge_id'] ?? 0) === $pledgeId && $batch['batch_type'] === 'pledge_update') {
                            $isUpdateRequest = true;
                            $latestBatch = $batch;
                            break;
                        }
                    }
                    // If no batch found with this as original, get the latest batch (this might be the update request itself)
                    if (!$isUpdateRequest && !empty($batches)) {
                        $latestBatch = $batches[count($batches) - 1];
                        // Check if this batch is an update request for a different original pledge
                        if ($latestBatch['batch_type'] === 'pledge_update' && (int)($latestBatch['original_pledge_id'] ?? 0) > 0) {
                            // This pledge might be the update request itself (but it was deleted, so this won't happen)
                            // Or this batch is for updating THIS pledge
                            $isUpdateRequest = false; // We'll handle this case separately
                        }
                    }
                }
                
                // Also check source field as fallback
                $pledgeSource = (string)($pledge['source'] ?? 'volunteer');
                if ($pledgeSource === 'self' && !$isUpdateRequest) {
                    // This is an update request pledge itself (shouldn't happen if deleted, but check anyway)
                    $isUpdateRequest = true;
                }
                
                // If no batches found, use fallback deallocation
                if (empty($batches)) {
                    // Fallback: Use old method if no batch tracking exists
                    $gridAllocator = new IntelligentGridAllocator($db);
                    $deallocationResult = $gridAllocator->deallocate($pledgeId, null);
                    if (!$deallocationResult['success']) {
                        throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                    }
                } else {
                    // Deallocate all batches associated with this pledge (in reverse order)
                    foreach (array_reverse($batches) as $batch) {
                        $batchId = (int)$batch['id'];
                        $deallocationResult = $batchTracker->deallocateBatch($batchId);
                        if (!$deallocationResult['success']) {
                            throw new RuntimeException('Batch deallocation failed: ' . ($deallocationResult['error'] ?? 'Unknown error'));
                        }
                    }
                }
                
                // If this pledge was updated (has batches where this is the original_pledge_id), restore original amount
                if ($isUpdateRequest && !empty($batches) && $latestBatch) {
                    // This pledge was updated - restore it to original amount
                    $additionalAmount = (float)($latestBatch['additional_amount'] ?? 0);
                    $originalAmount = (float)($latestBatch['original_amount'] ?? 0);
                    
                    // Current amount should be original_amount + additional_amount
                    // We want to restore it to original_amount
                    $currentAmount = (float)$pledge['amount'];
                    $restoredAmount = max(0, $currentAmount - $additionalAmount);
                    
                    // Update this pledge back to original amount (keep it approved!)
                    $updatePledge = $db->prepare("UPDATE pledges SET amount = ? WHERE id = ?");
                    $updatePledge->bind_param('di', $restoredAmount, $pledgeId);
                    $updatePledge->execute();
                    $updatePledge->close();
                    
                    // Update donor totals
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    if ($donorPhone) {
                        $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                        if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                            $normalized_phone = '0' . substr($normalized_phone, 2);
                        }
                        
                        if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                            $findDonor = $db->prepare("SELECT id, total_pledged, total_paid FROM donors WHERE phone = ? LIMIT 1");
                            $findDonor->bind_param('s', $normalized_phone);
                            $findDonor->execute();
                            $donorRecord = $findDonor->get_result()->fetch_assoc();
                            $findDonor->close();
                            
                            if ($donorRecord) {
                                $donorId = (int)$donorRecord['id'];
                                $updateDonor = $db->prepare("
                                    UPDATE donors SET
                                        total_pledged = total_pledged - ?,
                                        balance = (total_pledged - ?) - total_paid,
                                        payment_status = CASE
                                            WHEN total_paid = 0 THEN 'not_started'
                                            WHEN total_paid >= (total_pledged - ?) THEN 'completed'
                                            WHEN total_paid > 0 THEN 'paying'
                                            ELSE 'not_started'
                                        END,
                                        updated_at = NOW()
                                    WHERE id = ?
                                ");
                                $updateDonor->bind_param('dddi', $additionalAmount, $additionalAmount, $additionalAmount, $donorId);
                                $updateDonor->execute();
                                $updateDonor->close();
                            }
                        }
                    }
                } else {
                    // Regular pledge - revert to pending
                    $upd = $db->prepare("UPDATE pledges SET status='pending' WHERE id=?");
                    $upd->bind_param('i', $pledgeId);
                    $upd->execute();
                }

                // Decrement counters by the amount previously added
                $deltaPaid = 0.0; $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') { 
                    $deltaPaid = -1 * (float)$pledge['amount']; 
                } else { 
                    // For update requests, use the additional amount from batch, not the full pledge amount
                    if ($isUpdateRequest && $latestBatch) {
                        $deltaPledged = -1 * (float)($latestBatch['additional_amount'] ?? 0);
                    } else {
                        $deltaPledged = -1 * (float)$pledge['amount']; 
                    }
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

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $beforeAmount = (float)$pledge['amount'];
                $afterAmount = $isUpdateRequest && $latestBatch ? ($beforeAmount - (float)($latestBatch['additional_amount'] ?? 0)) : $beforeAmount;
                $before = json_encode([
                    'status' => 'approved',
                    'amount' => $beforeAmount
                ], JSON_UNESCAPED_SLASHES);
                $after = json_encode([
                    'status' => $isUpdateRequest ? 'approved' : 'pending',
                    'amount' => $afterAmount,
                    'is_update_undo' => $isUpdateRequest
                ], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'undo_approve', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = $isUpdateRequest ? 'Pledge update undone (amount restored)' : 'Approval undone';
                
                // Set a flag to trigger floor map refresh on page load
                $_SESSION['trigger_floor_refresh'] = true;
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_pledge') {
        $pledgeId = (int)($_POST['pledge_id'] ?? 0);
        $donorName = trim((string)($_POST['donor_name'] ?? ''));
        $donorPhone = trim((string)($_POST['donor_phone'] ?? ''));
        $donorEmail = trim((string)($_POST['donor_email'] ?? ''));
        $amountNew = (float)($_POST['amount'] ?? 0);
        $sqmMeters = isset($_POST['sqm_meters']) ? (float)$_POST['sqm_meters'] : 0.0; // optional
        $packageId = isset($_POST['package_id']) && $_POST['package_id'] !== '' ? (int)$_POST['package_id'] : null;
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($pledgeId > 0 && $donorName && $donorPhone && $amountNew > 0) {
            $db->begin_transaction();
            try {
                // Lock and load current pledge
                $sel = $db->prepare("SELECT id, amount, type, status FROM pledges WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $pledgeId);
                $sel->execute();
                $pledge = $sel->get_result()->fetch_assoc();
                if (!$pledge || $pledge['status'] !== 'approved') { throw new RuntimeException('Invalid state'); }

                $amountOld = (float)$pledge['amount'];

                // First: move pledge back to pending and subtract old amount from counters
                $updStatus = $db->prepare("UPDATE pledges SET status='pending' WHERE id=?");
                $updStatus->bind_param('i', $pledgeId);
                $updStatus->execute();

                $deltaPaid = 0.0; $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') { $deltaPaid = -1 * $amountOld; } else { $deltaPledged = -1 * $amountOld; }
                $grandDelta = $deltaPaid + $deltaPledged;
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
                $ctr->bind_param('ddd', $deltaPaid, $deltaPledged, $grandDelta);
                $ctr->execute();

                // Prefer explicit package selection; fallback to sqm-meters match
                $pkgId = $packageId;
                if ($pkgId === null && $sqmMeters > 0) {
                    $pkgSel = $db->prepare('SELECT id FROM donation_packages WHERE ABS(sqm_meters - ?) < 0.00001 LIMIT 1');
                    $pkgSel->bind_param('d', $sqmMeters);
                    $pkgSel->execute();
                    $pkgRow = $pkgSel->get_result()->fetch_assoc();
                    if ($pkgRow) { $pkgId = (int)$pkgRow['id']; }
                }

                if ($pkgId !== null) {
                    $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=?, package_id=? WHERE id=?");
                    $upd->bind_param('ssssiii', $donorName, $donorPhone, $donorEmail, $amountNew, $notes, $pkgId, $pledgeId);
                } else {
                    $upd = $db->prepare("UPDATE pledges SET donor_name=?, donor_phone=?, donor_email=?, amount=?, notes=? WHERE id=?");
                    $upd->bind_param('sssssi', $donorName, $donorPhone, $donorEmail, $amountNew, $notes, $pledgeId);
                }
                $upd->execute();

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status' => 'approved', 'amount' => $amountOld], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'pending', 'amount' => $amountNew, 'updated' => true], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'update_to_pending', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Pledge updated and set to pending for re-approval';
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_payment') {
        // Edit an approved payment (standalone); adjust counters by delta
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $amountNew = (float)($_POST['payment_amount'] ?? 0);
        $method = (string)($_POST['payment_method'] ?? 'cash');
        $reference = trim((string)($_POST['payment_reference'] ?? ''));
        $allowed = ['cash','card','bank','other']; if (!in_array($method, $allowed, true)) { $method = 'cash'; }
        if ($paymentId > 0 && $amountNew > 0) {
            $db->begin_transaction();
            try {
                // Lock payment and verify approved
                $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $paymentId);
                $sel->execute();
                $row = $sel->get_result()->fetch_assoc();
                if (!$row || (string)$row['status'] !== 'approved') { throw new RuntimeException('Invalid payment state'); }
                $amountOld = (float)$row['amount'];

                // Move payment back to pending and subtract its previously approved amount
                $updStatus = $db->prepare("UPDATE payments SET status='pending', amount=?, method=?, reference=? WHERE id=?");
                $updStatus->bind_param('dssi', $amountNew, $method, $reference, $paymentId);
                $updStatus->execute();

                $delta = -1 * $amountOld;
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, 0, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $delta;
                $ctr->bind_param('dd', $delta, $grandDelta);
                $ctr->execute();

                // Audit log
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status' => 'approved', 'amount' => $amountOld], JSON_UNESCAPED_SLASHES);
                $after = json_encode(['status' => 'pending', 'amount' => $amountNew, 'method' => $method, 'updated' => true], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'update_to_pending', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Payment updated and set to pending for re-approval';
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'undo_payment') {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $amount = (float)($_POST['payment_amount'] ?? 0);
        if ($paymentId > 0 && $amount > 0) {
            $db->begin_transaction();
            try {
                // Lock payment and ensure currently approved
                $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $paymentId);
                $sel->execute();
                $pay = $sel->get_result()->fetch_assoc();
                if (!$pay || (string)$pay['status'] !== 'approved') { throw new RuntimeException('Payment is not approved'); }

                // Set back to pending
                $upd = $db->prepare("UPDATE payments SET status='pending' WHERE id=?");
                $upd->bind_param('i', $paymentId);
                $upd->execute();

                // Subtract from counters
                $delta = -1 * (float)$pay['amount'];
                $ctr = $db->prepare(
                    "INSERT INTO counters (id, paid_total, pledged_total, grand_total, version, recalc_needed)
                     VALUES (1, ?, 0, ?, 1, 0)
                     ON DUPLICATE KEY UPDATE
                       paid_total = paid_total + VALUES(paid_total),
                       grand_total = grand_total + VALUES(grand_total),
                       version = version + 1,
                       recalc_needed = 0"
                );
                $grandDelta = $delta;
                $ctr->bind_param('dd', $delta, $grandDelta);
                $ctr->execute();

                // Deallocate floor grid cells using batch tracking
                $batchTracker = new GridAllocationBatchTracker($db);
                $batches = $batchTracker->getBatchesForPayment($paymentId);
                
                if (empty($batches)) {
                    // Fallback: Use old method if no batch tracking exists
                    $gridAllocator = new IntelligentGridAllocator($db);
                    $deallocationResult = $gridAllocator->deallocate(null, $paymentId);
                    if (!$deallocationResult['success']) {
                        throw new RuntimeException('Floor deallocation failed: ' . $deallocationResult['error']);
                    }
                } else {
                    // Deallocate all batches associated with this payment (in reverse order)
                    foreach (array_reverse($batches) as $batch) {
                        $batchId = (int)$batch['id'];
                        $deallocationResult = $batchTracker->deallocateBatch($batchId);
                        if (!$deallocationResult['success']) {
                            throw new RuntimeException('Batch deallocation failed: ' . ($deallocationResult['error'] ?? 'Unknown error'));
                        }
                    }
                }

                // Audit
                $uid = (int)(current_user()['id'] ?? 0);
                $before = json_encode(['status'=>'approved'], JSON_UNESCAPED_SLASHES);
                $after  = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'undo_approve', ?, ?, 'admin')");
                $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                $log->execute();

                $db->commit();
                $actionMsg = 'Payment approval undone';
                
                // Set a flag to trigger floor map refresh on page load
                $_SESSION['trigger_floor_refresh'] = true;
            } catch (Throwable $e) {
                $db->rollback();
                $actionMsg = 'Error: ' . $e->getMessage();
            }
        }
    }

    header('Location: ' . buildRedirectUrl($actionMsg, $_POST));
    exit;
}

// Filter and sort parameters
$filter_type = $_GET['filter_type'] ?? '';
$filter_amount_min = !empty($_GET['filter_amount_min']) ? (float)$_GET['filter_amount_min'] : null;
$filter_amount_max = !empty($_GET['filter_amount_max']) ? (float)$_GET['filter_amount_max'] : null;
$filter_donor = trim($_GET['filter_donor'] ?? '');
$filter_registrar = trim($_GET['filter_registrar'] ?? '');
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'approved_at';
$sort_order = in_array($_GET['sort_order'] ?? 'desc', ['asc', 'desc']) ? $_GET['sort_order'] : 'desc';

// Pagination parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = in_array((int)($_GET['per_page'] ?? 20), [10, 20, 50]) ? (int)($_GET['per_page'] ?? 20) : 20;
$offset = ($page - 1) * $per_page;

// Build WHERE conditions for filters
$where_conditions = ['p.status = \'approved\''];
$payment_where_conditions = ['pay.status = \'approved\''];

// Type filter
if ($filter_type && in_array($filter_type, ['pledge', 'paid'])) {
    if ($filter_type === 'pledge') {
        // Only show pledges, exclude payments from results
        $payment_where_conditions[] = '1=0'; // This will exclude all payments
    } else {
        // Only show payments, exclude pledges from results  
        $where_conditions[] = '1=0'; // This will exclude all pledges
    }
}

// Amount filter
if ($filter_amount_min !== null) {
    $where_conditions[] = 'p.amount >= ' . (float)$filter_amount_min;
    $payment_where_conditions[] = 'pay.amount >= ' . (float)$filter_amount_min;
}
if ($filter_amount_max !== null) {
    $where_conditions[] = 'p.amount <= ' . (float)$filter_amount_max;
    $payment_where_conditions[] = 'pay.amount <= ' . (float)$filter_amount_max;
}

// Donor name filter
if ($filter_donor) {
    $escaped_donor = mysqli_real_escape_string($db, $filter_donor);
    $where_conditions[] = 'p.donor_name LIKE \'%' . $escaped_donor . '%\'';
    $payment_where_conditions[] = 'pay.donor_name LIKE \'%' . $escaped_donor . '%\'';
}

// Registrar filter
if ($filter_registrar) {
    $escaped_registrar = mysqli_real_escape_string($db, $filter_registrar);
    $where_conditions[] = 'u.name LIKE \'%' . $escaped_registrar . '%\'';
    $payment_where_conditions[] = 'u.name LIKE \'%' . $escaped_registrar . '%\'';
}

// Date filter
if ($filter_date_from) {
    $escaped_date_from = mysqli_real_escape_string($db, $filter_date_from);
    $where_conditions[] = 'DATE(p.approved_at) >= \'' . $escaped_date_from . '\'';
    $payment_where_conditions[] = 'DATE(pay.received_at) >= \'' . $escaped_date_from . '\'';
}
if ($filter_date_to) {
    $escaped_date_to = mysqli_real_escape_string($db, $filter_date_to);
    $where_conditions[] = 'DATE(p.approved_at) <= \'' . $escaped_date_to . '\'';
    $payment_where_conditions[] = 'DATE(pay.received_at) <= \'' . $escaped_date_to . '\'';
}

$where_clause = implode(' AND ', $where_conditions);
$payment_where_clause = implode(' AND ', $payment_where_conditions);

// Get total count for pagination (including batches)
$batch_where = $filter_donor ? "AND (b.donor_name LIKE '%" . mysqli_real_escape_string($db, $filter_donor) . "%' OR p.donor_name LIKE '%" . mysqli_real_escape_string($db, $filter_donor) . "%')" : "";
$count_sql = "
SELECT COUNT(*) as total FROM (
  (SELECT p.id FROM pledges p 
   LEFT JOIN users u ON p.created_by_user_id = u.id 
   WHERE $where_clause)
  UNION ALL
  (SELECT pay.id FROM payments pay 
   LEFT JOIN users u ON pay.received_by_user_id = u.id 
   WHERE $payment_where_clause)
  UNION ALL
  (SELECT b.id FROM grid_allocation_batches b
   LEFT JOIN pledges p ON b.original_pledge_id = p.id
   WHERE b.approval_status = 'approved'
     AND b.batch_type IN ('pledge_update', 'payment_update')
     AND b.original_pledge_id IS NOT NULL
     $batch_where)
) as combined_count";

// Execute count query with parameters (simplified version for count)
$count_result = $db->query($count_sql);
$total_items = (int)$count_result->fetch_assoc()['total'];
$total_pages = (int)ceil($total_items / $per_page);

// Map sort fields to actual columns
$sort_mapping = [
    'approved_at' => 'approved_at',
    'created_at' => 'created_at', 
    'amount' => 'amount',
    'donor_name' => 'donor_name',
    'registrar_name' => 'registrar_name',
    'type' => 'type'
];

$sort_column = $sort_mapping[$sort_by] ?? 'approved_at';
$order_clause = "$sort_column $sort_order";

// List approved pledges and approved standalone payments with filtering and sorting
$sql = "
(SELECT 
    p.id,
    p.amount,
    'pledge' AS type,
    p.notes,
    p.created_at,
    p.approved_at,
    dp.sqm_meters AS sqm_meters,
    p.anonymous,
    p.donor_name,
    p.donor_phone,
    p.donor_email,
    u.name AS registrar_name,
    NULL AS payment_id,
    NULL AS payment_amount,
    NULL AS payment_method,
    NULL AS payment_reference
  FROM pledges p
  LEFT JOIN donation_packages dp ON dp.id = p.package_id
  LEFT JOIN users u ON p.created_by_user_id = u.id
  WHERE $where_clause)
UNION ALL
(SELECT 
    pay.id AS id,
    pay.amount,
    'paid' AS type,
    pay.reference AS notes,
    pay.created_at,
    pay.received_at AS approved_at,
    dp.sqm_meters AS sqm_meters,
    0 AS anonymous,
    pay.donor_name,
    pay.donor_phone,
    pay.donor_email,
    u.name AS registrar_name,
    pay.id AS payment_id,
    pay.amount AS payment_amount,
    pay.method AS payment_method,
    pay.reference AS payment_reference
  FROM payments pay
  LEFT JOIN donation_packages dp ON dp.id = pay.package_id
  LEFT JOIN users u ON pay.received_by_user_id = u.id
  WHERE $payment_where_clause)
UNION ALL
(SELECT 
    b.id AS id,
    b.additional_amount AS amount,
    CASE 
        WHEN b.batch_type = 'pledge_update' THEN 'pledge_update'
        WHEN b.batch_type = 'payment_update' THEN 'payment_update'
        ELSE 'batch'
    END AS type,
    CONCAT('Update batch for ', COALESCE(p.donor_name, b.donor_name)) AS notes,
    b.request_date AS created_at,
    b.approved_at,
    NULL AS sqm_meters,
    0 AS anonymous,
    COALESCE(p.donor_name, b.donor_name) AS donor_name,
    COALESCE(p.donor_phone, b.donor_phone) AS donor_phone,
    COALESCE(p.donor_email, '') AS donor_email,
    COALESCE(u.name, 'Donor Portal') AS registrar_name,
    NULL AS payment_id,
    NULL AS payment_amount,
    NULL AS payment_method,
    NULL AS payment_reference,
    b.id AS batch_id,
    b.batch_type AS batch_type,
    b.original_pledge_id AS original_pledge_id,
    b.additional_amount AS additional_amount,
    b.original_amount AS original_amount
  FROM grid_allocation_batches b
  LEFT JOIN pledges p ON b.original_pledge_id = p.id
  LEFT JOIN users u ON b.requested_by_user_id = u.id
  WHERE b.approval_status = 'approved'
    AND b.batch_type IN ('pledge_update', 'payment_update')
    AND b.original_pledge_id IS NOT NULL
    " . ($filter_donor ? "AND (b.donor_name LIKE '%" . mysqli_real_escape_string($db, $filter_donor) . "%' OR p.donor_name LIKE '%" . mysqli_real_escape_string($db, $filter_donor) . "%')" : "") . ")
ORDER BY $order_clause, created_at DESC
LIMIT $per_page OFFSET $offset";

$approved = $db->query($sql)->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Approved Items - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
  <link rel="stylesheet" href="../approvals/assets/approvals.css?v=<?php echo @filemtime(__DIR__ . '/../approvals/assets/approvals.css'); ?>">
  <link rel="stylesheet" href="assets/approved.css?v=<?php echo @filemtime(__DIR__ . '/assets/approved.css'); ?>">
  <style>
    /* Fix modal background transparency */
    .modal-content {
      background-color: #ffffff !important;
      border: none;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    }
    .modal-header {
      background-color: #ffffff !important;
      border-bottom: 1px solid #e9ecef;
    }
    .modal-body {
      background-color: #ffffff !important;
    }
    .modal-footer {
      background-color: #ffffff !important;
      border-top: 1px solid #e9ecef;
    }
  </style>
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    <main class="main-content">
      <div class="row">
        <div class="col-12">
          <?php if (!empty($_GET['msg'])): ?>
          <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
          <?php endif; ?>

          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0">
                <i class="fas fa-check-circle text-success me-2"></i>Approved Items
              </h5>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse" aria-expanded="false">
                  <i class="fas fa-filter"></i> Filters & Sort
                </button>
                <button class="btn btn-sm btn-outline-primary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
              </div>
            </div>
            
            <!-- Filters and Sort Panel -->
            <div class="collapse" id="filtersCollapse">
              <div class="card-body border-bottom bg-light">
                <form method="GET" action="index.php" class="row g-3 align-items-end">
                  <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="filter_type" class="form-select">
                      <option value="">All Types</option>
                      <option value="pledge" <?php echo $filter_type === 'pledge' ? 'selected' : ''; ?>>Pledges Only</option>
                      <option value="paid" <?php echo $filter_type === 'paid' ? 'selected' : ''; ?>>Payments Only</option>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Amount Range</label>
                    <div class="input-group">
                      <span class="input-group-text">£</span>
                      <input type="number" name="filter_amount_min" class="form-control" placeholder="Min" step="0.01" value="<?php echo htmlspecialchars($filter_amount_min ?? ''); ?>">
                      <span class="input-group-text">to</span>
                      <input type="number" name="filter_amount_max" class="form-control" placeholder="Max" step="0.01" value="<?php echo htmlspecialchars($filter_amount_max ?? ''); ?>">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Date Range</label>
                    <div class="input-group">
                        <input type="date" name="filter_date_from" class="form-control" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        <span class="input-group-text">to</span>
                        <input type="date" name="filter_date_to" class="form-control" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Sort By</label>
                    <div class="input-group">
                        <select name="sort_by" class="form-select">
                          <option value="approved_at" <?php echo $sort_by === 'approved_at' ? 'selected' : ''; ?>>Approval Date</option>
                          <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                          <option value="amount" <?php echo $sort_by === 'amount' ? 'selected' : ''; ?>>Amount</option>
                          <option value="donor_name" <?php echo $sort_by === 'donor_name' ? 'selected' : ''; ?>>Donor Name</option>
                          <option value="registrar_name" <?php echo $sort_by === 'registrar_name' ? 'selected' : ''; ?>>Registrar</option>
                          <option value="type" <?php echo $sort_by === 'type' ? 'selected' : ''; ?>>Type</option>
                        </select>
                        <select name="sort_order" class="form-select">
                          <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Desc</option>
                          <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Asc</option>
                        </select>
                    </div>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Search Donor</label>
                    <input type="text" name="filter_donor" class="form-control" placeholder="Search by donor name..." value="<?php echo htmlspecialchars($filter_donor); ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Search Registrar</label>
                    <input type="text" name="filter_registrar" class="form-control" placeholder="Search by registrar..." value="<?php echo htmlspecialchars($filter_registrar); ?>">
                  </div>
                  
                  <div class="col-md-2">
                      <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Apply
                      </button>
                  </div>
                    <?php if ($filter_type || $filter_amount_min || $filter_amount_max || $filter_donor || $filter_registrar || $filter_date_from || $filter_date_to || $sort_by !== 'approved_at' || $sort_order !== 'desc'): ?>
                    <div class="col-md-3">
                        <a href="index.php" class="btn btn-outline-secondary w-100">
                          <i class="fas fa-times"></i> Clear Filters
                        </a>
                    </div>
                   <?php endif; ?>
                </form>
              </div>
            </div>
            <div class="card-body">
              <?php include __DIR__ . '/partial_list.php'; ?>
              
              <?php if ($total_pages > 1): ?>
              <nav aria-label="Approved items pagination" class="mt-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <span class="text-muted">
                    Showing <?php echo min(($page - 1) * $per_page + 1, $total_items); ?> to 
                    <?php echo min($page * $per_page, $total_items); ?> of <?php echo $total_items; ?> items
                  </span>
                  <?php
                  // Build query string for maintaining filters in pagination
                  $filter_params = [];
                  if ($filter_type) $filter_params['filter_type'] = $filter_type;
                  if ($filter_amount_min !== null) $filter_params['filter_amount_min'] = $filter_amount_min;
                  if ($filter_amount_max !== null) $filter_params['filter_amount_max'] = $filter_amount_max;
                  if ($filter_donor) $filter_params['filter_donor'] = $filter_donor;
                  if ($filter_registrar) $filter_params['filter_registrar'] = $filter_registrar;
                  if ($filter_date_from) $filter_params['filter_date_from'] = $filter_date_from;
                  if ($filter_date_to) $filter_params['filter_date_to'] = $filter_date_to;
                  if ($sort_by !== 'approved_at') $filter_params['sort_by'] = $sort_by;
                  if ($sort_order !== 'desc') $filter_params['sort_order'] = $sort_order;
                  
                  function build_pagination_url($page_num, $per_page_num, $filter_params) {
                    $params = array_merge($filter_params, ['page' => $page_num, 'per_page' => $per_page_num]);
                    return '?' . http_build_query($params);
                  }
                  ?>
                  <div class="btn-group" role="group" aria-label="Items per page">
                    <a href="<?php echo build_pagination_url(1, 10, $filter_params); ?>" class="btn btn-sm btn-outline-secondary <?php echo $per_page == 10 ? 'active' : ''; ?>">10</a>
                    <a href="<?php echo build_pagination_url(1, 20, $filter_params); ?>" class="btn btn-sm btn-outline-secondary <?php echo $per_page == 20 ? 'active' : ''; ?>">20</a>
                    <a href="<?php echo build_pagination_url(1, 50, $filter_params); ?>" class="btn btn-sm btn-outline-secondary <?php echo $per_page == 50 ? 'active' : ''; ?>">50</a>
                  </div>
                </div>
                <ul class="pagination pagination-sm justify-content-center">
                  <?php if ($page > 1): ?>
                    <li class="page-item">
                      <a class="page-link" href="<?php echo build_pagination_url(1, $per_page, $filter_params); ?>" aria-label="First">
                        <i class="fas fa-angle-double-left"></i>
                      </a>
                    </li>
                    <li class="page-item">
                      <a class="page-link" href="<?php echo build_pagination_url($page - 1, $per_page, $filter_params); ?>" aria-label="Previous">
                        <i class="fas fa-angle-left"></i>
                      </a>
                    </li>
                  <?php endif; ?>
                  
                  <?php
                  $start = max(1, $page - 2);
                  $end = min($total_pages, $page + 2);
                  
                  for ($i = $start; $i <= $end; $i++):
                  ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                      <a class="page-link" href="<?php echo build_pagination_url($i, $per_page, $filter_params); ?>"><?php echo $i; ?></a>
                    </li>
                  <?php endfor; ?>
                  
                  <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                      <a class="page-link" href="<?php echo build_pagination_url($page + 1, $per_page, $filter_params); ?>" aria-label="Next">
                        <i class="fas fa-angle-right"></i>
                      </a>
                    </li>
                    <li class="page-item">
                      <a class="page-link" href="<?php echo build_pagination_url($total_pages, $per_page, $filter_params); ?>" aria-label="Last">
                        <i class="fas fa-angle-double-right"></i>
                      </a>
                    </li>
                  <?php endif; ?>
                </ul>
              </nav>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<!-- Edit Pledge Modal -->
<div class="modal fade" id="editPledgeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Approved Pledge</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="update_pledge">
                    <input type="hidden" name="pledge_id" id="editPledgeId">
                    
                    <?php
                    $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
                    foreach ($preserveParams as $param) {
                        if (isset($_GET[$param]) && $_GET[$param] !== '') {
                            echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
                        }
                    }
                    ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Donor Name</label>
                            <input type="text" class="form-control" id="editDonorName" name="donor_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="editDonorPhone" name="donor_phone" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email (Optional)</label>
                        <input type="email" class="form-control" id="editDonorEmail" name="donor_email">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Amount (£)</label>
                            <input type="number" class="form-control" id="editAmount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Package (optional)</label>
                            <select class="form-select" id="editPackageId" name="package_id">
                                <option value="">— None —</option>
                                <?php foreach ($pkgRows as $pkg): ?>
                                <option value="<?php echo (int)$pkg['id']; ?>" data-sqm="<?php echo htmlspecialchars($pkg['sqm_meters']); ?>" data-price="<?php echo htmlspecialchars($pkg['price']); ?>">
                                    <?php echo htmlspecialchars($pkg['label']); ?> (<?php echo number_format((float)$pkg['sqm_meters'], 2); ?> m² · £<?php echo number_format((float)$pkg['price'], 2); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="editNotes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Pledge</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Payment Modal -->
<div class="modal fade" id="editPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-credit-card me-2"></i>Edit Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post" action="index.php">
                <div class="modal-body">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="action" value="update_payment">
                    <input type="hidden" name="payment_id" id="editPaymentId">
                    
                    <?php
                    $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
                    foreach ($preserveParams as $param) {
                        if (isset($_GET[$param]) && $_GET[$param] !== '') {
                            echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
                        }
                    }
                    ?>

                    <div class="mb-3">
                        <label class="form-label">Amount (£)</label>
                        <input type="number" class="form-control" id="editPaymentAmount" name="payment_amount" step="0.01" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Method</label>
                        <select class="form-select" id="editPaymentMethod" name="payment_method">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="bank">Bank</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference</label>
                        <input type="text" class="form-control" id="editPaymentReference" name="payment_reference">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Details Modal (like approvals page) -->
<div class="modal fade details-modal" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header details-header">
        <div class="details-header-main">
          <div class="amount-large" id="dAmountLarge">£ 0.00</div>
          <div class="chips">
            <span class="chip chip-type" id="dTypeBadge">APPROVED</span>
            <span class="chip chip-anon d-none" id="dAnonChip"><i class="fas fa-user-secret me-1"></i>Anonymous</span>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="detail-grid">
          <div class="detail-card">
            <div class="detail-title"><i class="fas fa-user me-2"></i>Donor</div>
            <div class="detail-row"><span>Name</span><span id="dDonorName">—</span></div>
            <div class="detail-row"><span>Phone</span><span id="dPhone">—</span></div>
            <div class="detail-row"><span>Email</span><span id="dEmail">—</span></div>
          </div>
          <div class="detail-card">
            <div class="detail-title"><i class="fas fa-ruler-combined me-2"></i>Details</div>
            <div class="detail-row"><span>Square meters</span><span id="dSqm">—</span></div>
            <div class="detail-row"><span>Created</span><span id="dCreated">—</span></div>
            <div class="detail-row"><span>Registrar</span><span id="dRegistrar">—</span></div>
          </div>
          <div class="detail-card detail-notes">
            <div class="detail-title"><i class="fas fa-sticky-note me-2"></i>Notes</div>
            <div class="notes-box" id="dNotes">—</div>
          </div>
        </div>
        
        <!-- Donation History Section (shown for repeat donors) -->
        <div class="donation-history-section mt-4 d-none" id="donationHistorySection">
          <div class="detail-card">
            <div class="detail-title"><i class="fas fa-history me-2"></i>Donation History</div>
            <div class="donation-history-list" id="donationHistoryList">
              <div class="text-center text-muted py-3">
                <i class="fas fa-spinner fa-spin"></i> Loading history...
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Donation History Modal -->
<div class="modal fade" id="donationHistoryModal" tabindex="-1" aria-labelledby="donationHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info bg-opacity-10">
                <h5 class="modal-title" id="donationHistoryModalLabel">
                    <i class="fas fa-history me-2 text-info"></i>Donation History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="donationHistoryContent">
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-spinner fa-spin"></i> Loading history...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script src="assets/approved.js?v=<?php echo @filemtime(__DIR__ . '/assets/approved.js'); ?>"></script>
<script>
function openEditPledgeModal(id, name, phone, email, amount, sqm, notes) {
  document.getElementById('editPledgeId').value = id;
  document.getElementById('editDonorName').value = name;
  document.getElementById('editDonorPhone').value = phone;
  document.getElementById('editDonorEmail').value = email || '';
  document.getElementById('editAmount').value = amount;
  document.getElementById('editNotes').value = notes || '';

  // Preselect package if sqm matches one
  var pkgSelect = document.getElementById('editPackageId');
  if (pkgSelect) {
    var matched = false;
    for (var i = 0; i < pkgSelect.options.length; i++) {
      var opt = pkgSelect.options[i];
      var sqmAttr = parseFloat(opt.getAttribute('data-sqm'));
      if (!isNaN(sqmAttr) && Math.abs(sqmAttr - parseFloat(sqm || 0)) < 0.00001) {
        pkgSelect.selectedIndex = i;
        matched = true;
        // If package has a price, set amount accordingly
        var priceAttr = parseFloat(opt.getAttribute('data-price'));
        if (!isNaN(priceAttr) && priceAttr > 0) {
          document.getElementById('editAmount').value = priceAttr;
        }
        break;
      }
    }
    if (!matched) {
      pkgSelect.selectedIndex = 0; // None
    }
  }
}
function openEditPaymentModal(paymentId, amount, method, reference) {
  document.getElementById('editPaymentId').value = paymentId;
  document.getElementById('editPaymentAmount').value = amount;
  document.getElementById('editPaymentMethod').value = method || 'cash';
  document.getElementById('editPaymentReference').value = reference || '';
}

// When package changes, auto-fill amount from the selected package's price
document.addEventListener('DOMContentLoaded', function() {
  var pkg = document.getElementById('editPackageId');
  var amt = document.getElementById('editAmount');
  if (pkg && amt) {
    pkg.addEventListener('change', function() {
      var sel = pkg.options[pkg.selectedIndex];
      if (!sel || !sel.getAttribute) return;
      var price = parseFloat(sel.getAttribute('data-price'));
      if (!isNaN(price) && price > 0) {
        amt.value = price.toFixed(2);
      }
    });
  }
});

// Click-to-open details modal for approved cards (like approvals page)
document.addEventListener('click', function(e){
  const card = e.target.closest('.approval-item');
  if (!card) return;
  // ignore if clicking on action buttons/forms
  if (e.target.closest('.approval-actions')) return;
  const fmt = (n) => new Intl.NumberFormat('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(n)||0);
  const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
  const type = (card.dataset.type||'—').toUpperCase();
  const amount = '£ ' + fmt(card.dataset.amount||0);
  const anon = Number(card.dataset.anonymous)===1;
  document.getElementById('dAmountLarge').textContent = amount;
  const typeBadge = document.getElementById('dTypeBadge');
  typeBadge.textContent = type;
  typeBadge.classList.toggle('is-paid', type==='PAID');
  typeBadge.classList.toggle('is-pledge', type==='PLEDGE');
  document.getElementById('dAnonChip').classList.toggle('d-none', !anon);
  document.getElementById('dDonorName').textContent = card.dataset.donorName||'—';
  document.getElementById('dPhone').textContent = card.dataset.donorPhone||'—';
  document.getElementById('dEmail').textContent = card.dataset.donorEmail||'—';
  if ((card.dataset.type||'').toLowerCase()==='payment' || (card.dataset.type||'').toLowerCase()==='paid') {
    // For payments, show package if available, otherwise method
    const pkg = card.dataset.packageLabel || '';
    document.getElementById('dSqm').textContent = pkg ? pkg : '—';
  } else {
    document.getElementById('dSqm').textContent = fmt(card.dataset.sqmMeters||0) + ' m²';
  }
  document.getElementById('dCreated').textContent = card.dataset.createdAt||'—';
  const registrarEl = document.getElementById('dRegistrar');
  const registrarValue = card.dataset.registrar||'';
  registrarEl.textContent = registrarValue.trim() === '' ? 'Self Pledged' : registrarValue;
  document.getElementById('dNotes').textContent = card.dataset.notes||'—';
  
  // Fetch and display donation history if donor phone is available
  const donorPhone = card.dataset.donorPhoneForHistory;
  const historySection = document.getElementById('donationHistorySection');
  if (donorPhone) {
    fetch(`../../api/donor_history.php?phone=${encodeURIComponent(donorPhone)}`)
      .then(res => res.json())
      .then(data => {
        if (data.success && data.donations && data.donations.length > 1) {
          // Only show history if there are multiple donations
          const historyList = document.getElementById('donationHistoryList');
          historyList.innerHTML = '';
          
          data.donations.forEach(donation => {
            const date = new Date(donation.date);
            const formattedDate = date.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' });
            const formattedTime = date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
            
            const statusClass = donation.status === 'approved' ? 'success' : (donation.status === 'pending' ? 'warning' : 'danger');
            const typeLabel = donation.type === 'pledge' ? 'Pledge' : 'Payment';
            const typeClass = donation.type === 'pledge' ? 'info' : 'primary';
            
            const row = document.createElement('div');
            row.className = 'detail-row';
            row.innerHTML = `
              <span>
                <span class="badge bg-${typeClass} me-2">${typeLabel}</span>
                <span class="text-muted small">${formattedDate} ${formattedTime}</span>
              </span>
              <span>
                <strong>£${parseFloat(donation.amount).toFixed(2)}</strong>
                <span class="badge bg-${statusClass} ms-2">${donation.status}</span>
              </span>
            `;
            historyList.appendChild(row);
          });
          
          historySection.classList.remove('d-none');
        } else {
          historySection.classList.add('d-none');
        }
      })
      .catch(err => {
        console.warn('Failed to load donation history:', err);
        historySection.classList.add('d-none');
      });
  } else {
    historySection.classList.add('d-none');
  }
  
  modal.show();
});

// Helper to escape HTML
function htmlEscape(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Check for floor map refresh trigger
<?php if (isset($_SESSION['trigger_floor_refresh']) && $_SESSION['trigger_floor_refresh']): ?>
// Clear the flag
<?php unset($_SESSION['trigger_floor_refresh']); ?>

// Trigger immediate floor map refresh
console.log('Admin action completed - triggering floor map refresh');
localStorage.setItem('floorMapRefresh', Date.now());

// Also try to call refresh function directly on any open floor map windows
try {
    // Check all open windows/tabs
    for (let i = 0; i < window.length; i++) {
        try {
            if (window[i] && window[i].refreshFloorMap) {
                window[i].refreshFloorMap();
            }
        } catch(e) { /* Cross-origin restrictions */ }
    }
} catch(e) { /* No access to other windows */ }

// Show user feedback
setTimeout(() => {
    const alert = document.createElement('div');
    alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
    alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alert.innerHTML = `
        <i class="fas fa-sync-alt me-2"></i>Floor map refresh signal sent!
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alert);
    setTimeout(() => alert.remove(), 3000);
}, 100);
<?php endif; ?>
</script>
</body>
</html>


