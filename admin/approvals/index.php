<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/IntelligentGridAllocator.php';
require_once __DIR__ . '/../../shared/CustomAmountAllocator.php';
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

// Resiliently load settings and check for DB errors
require_once __DIR__ . '/../includes/resilient_db_loader.php';

$page_title = 'Pending Approvals';
$current_user = current_user();
$db = db();
$actionMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_connection_ok) {
    error_log("=== ADMIN APPROVALS: POST REQUEST RECEIVED ===");
    error_log("POST data keys: " . implode(', ', array_keys($_POST)));
    // htmx posts will include HX-Request header; still support normal POST
    verify_csrf();
    $pledgeId = (int)($_POST['pledge_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($pledgeId && in_array($action, ['approve','reject','update'], true)) {
        $db->begin_transaction();
        try {
            // Check if source column exists
            $has_source_column = false;
            try {
                $check_source = $db->query("SHOW COLUMNS FROM pledges LIKE 'source'");
                if ($check_source && $check_source->num_rows > 0) {
                    $has_source_column = true;
                }
            } catch (Exception $e) {
                // Column doesn't exist, that's fine
            }
            
            $selectFields = 'id, amount, type, status, donor_name, donor_phone, donor_id';
            if ($has_source_column) {
                $selectFields .= ', source';
            }
            
            error_log("=== ADMIN APPROVALS: Processing pledge ID: {$pledgeId}, Action: {$action} ===");
            
            $stmt = $db->prepare("SELECT {$selectFields} FROM pledges WHERE id = ? FOR UPDATE");
            if (!$stmt) {
                error_log("ERROR: Failed to prepare SELECT - " . $db->error);
                throw new RuntimeException('Failed to prepare query: ' . $db->error);
            }
            $stmt->bind_param('i', $pledgeId);
            $stmt->execute();
            $pledge = $stmt->get_result()->fetch_assoc();
            if (!$pledge || $pledge['status'] !== 'pending') { 
                error_log("ERROR: Invalid pledge state - status: " . ($pledge['status'] ?? 'NOT FOUND'));
                throw new RuntimeException('Invalid state'); 
            }
            error_log("Pledge found - Amount: " . ($pledge['amount'] ?? 'N/A') . ", Type: " . ($pledge['type'] ?? 'N/A') . ", Source: " . ($pledge['source'] ?? 'N/A'));

            if ($action === 'approve') {
                $uid = (int)current_user()['id'];
                
                // Check if this is an update request BEFORE any database modifications
                $pledgeSource = (string)($pledge['source'] ?? 'volunteer');
                $isPaidType = ((string)$pledge['type'] === 'paid');
                $isUpdateRequest = ($pledgeSource === 'self' && !$isPaidType);
                $isPledgeUpdate = false;
                $originalPledgeId = null;
                $originalAmount = 0.0;
                
                if ($isUpdateRequest) {
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    if ($donorPhone) {
                        // Normalize phone number
                        $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                        if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                            $normalized_phone = '0' . substr($normalized_phone, 2);
                        }
                        
                        if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                            // Find existing approved pledge for this donor
                            $findOriginalPledge = $db->prepare("
                                SELECT id, amount 
                                FROM pledges 
                                WHERE donor_phone = ? AND status = 'approved' AND type = 'pledge' AND id != ?
                                ORDER BY approved_at DESC, id DESC 
                                LIMIT 1
                            ");
                            $findOriginalPledge->bind_param('si', $normalized_phone, $pledgeId);
                            $findOriginalPledge->execute();
                            $originalPledge = $findOriginalPledge->get_result()->fetch_assoc();
                            $findOriginalPledge->close();
                            
                            if ($originalPledge) {
                                $isPledgeUpdate = true;
                                $originalPledgeId = (int)$originalPledge['id'];
                                $originalAmount = (float)$originalPledge['amount'];
                            }
                        }
                    }
                }
                
                // Only update status if it's NOT an update request (we'll delete update requests)
                if (!$isPledgeUpdate) {
                    $upd = $db->prepare("UPDATE pledges SET status='approved', approved_by_user_id=?, approved_at=NOW() WHERE id=?");
                    $upd->bind_param('ii', $uid, $pledgeId);
                    $upd->execute();
                    $upd->close();
                }

                // Robust counters update: only and exactly on admin approval
                // Determine deltas based on pledge type
                $deltaPaid = 0.0;
                $deltaPledged = 0.0;
                if ((string)$pledge['type'] === 'paid') {
                    $deltaPaid = (float)$pledge['amount'];
                } else {
                    $deltaPledged = (float)$pledge['amount'];
                }

                // Ensure counters row exists and atomically increment fields
                // grand_total is computed from the post-update values
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

                // Allocate floor grid cells using custom amount allocator for smart handling
                $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
                $packageId = isset($pledge['package_id']) ? (int)$pledge['package_id'] : null;
                $status = ($pledge['type'] === 'paid') ? 'paid' : 'pledged';
                $amount = (float)$pledge['amount'];
                
                // Find or create allocation batch record BEFORE grid allocation
                error_log("=== ADMIN APPROVALS: Starting batch tracking ===");
                $batchTracker = new GridAllocationBatchTracker($db);
                $allocationBatchId = null;
                
                // Try to find existing batch first (created when request was made)
                error_log("Looking for existing batch with pledgeId: {$pledgeId}");
                $existingBatch = $batchTracker->getBatchByRequest($pledgeId, null);
                if ($existingBatch) {
                    $allocationBatchId = (int)$existingBatch['id'];
                    error_log("Found existing batch ID: {$allocationBatchId}");
                } else {
                    error_log("No existing batch found - will create new one");
                }
                
                // If no existing batch found, create one (for backward compatibility)
                if (!$allocationBatchId) {
                    error_log("=== ADMIN APPROVALS: Creating new batch ===");
                    error_log("isPledgeUpdate: " . ($isPledgeUpdate ? 'YES' : 'NO') . ", originalPledgeId: " . ($originalPledgeId ?? 'NULL'));
                    if ($isPledgeUpdate && $originalPledgeId) {
                    error_log("Creating batch for PLEDGE UPDATE");
                    // Get donor ID and original pledge details for batch tracking
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                        $normalized_phone = '0' . substr($normalized_phone, 2);
                    }
                    
                    $donorId = null;
                    if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                        $findDonor = $db->prepare("SELECT id FROM donors WHERE phone = ? LIMIT 1");
                        $findDonor->bind_param('s', $normalized_phone);
                        $findDonor->execute();
                        $donorRecord = $findDonor->get_result()->fetch_assoc();
                        $findDonor->close();
                        if ($donorRecord) {
                            $donorId = (int)$donorRecord['id'];
                        }
                    }
                    
                    // Ensure donorName is set (use from pledge or session)
                    if (empty($donorName)) {
                        $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
                    }
                    
                    // Ensure packageId is properly set (can be null)
                    if (!isset($packageId)) {
                        $packageId = isset($pledge['package_id']) ? ((int)$pledge['package_id'] ?: null) : null;
                    }
                    
                    // Create batch for pledge update
                    // Ensure all required fields are set correctly
                    $batchData = [
                        'batch_type' => 'pledge_update',
                        'request_type' => ($pledgeSource === 'self') ? 'donor_portal' : 'registrar',
                        'original_pledge_id' => $originalPledgeId ?: null,
                        'original_payment_id' => null, // Explicitly set to null for pledge updates
                        'new_pledge_id' => $pledgeId ?: null,
                        'new_payment_id' => null, // Explicitly set to null for pledge updates
                        'donor_id' => $donorId ?: null,
                        'donor_name' => $donorName ?: 'Anonymous',
                        'donor_phone' => (!empty($normalized_phone)) ? $normalized_phone : null, // Convert empty string to null
                        'original_amount' => (float)$originalAmount,
                        'additional_amount' => (float)$amount,
                        'total_amount' => (float)($originalAmount + $amount),
                        'requested_by_user_id' => ($pledgeSource === 'self') ? null : (isset($pledge['created_by_user_id']) ? (int)$pledge['created_by_user_id'] : null),
                        'requested_by_donor_id' => ($pledgeSource === 'self') ? ($donorId ?: null) : null,
                        'request_source' => $pledgeSource ?: 'volunteer',
                        'package_id' => isset($packageId) ? ($packageId ?: null) : null,
                        'metadata' => [
                            'client_uuid' => $pledge['client_uuid'] ?? null,
                            'notes' => $pledge['notes'] ?? null
                        ]
                    ];
                    
                    // Validate required fields before creating batch
                    if (empty($batchData['donor_name'])) {
                        error_log("Admin approvals: ERROR - donor_name is empty for batch creation");
                        $batchData['donor_name'] = 'Anonymous';
                    }
                    
                    error_log("=== ADMIN APPROVALS: Batch data for pledge update ===");
                    error_log("batch_type: " . ($batchData['batch_type'] ?? 'MISSING'));
                    error_log("request_type: " . ($batchData['request_type'] ?? 'MISSING'));
                    error_log("original_pledge_id: " . ($batchData['original_pledge_id'] ?? 'NULL'));
                    error_log("new_pledge_id: " . ($batchData['new_pledge_id'] ?? 'NULL'));
                    error_log("donor_id: " . ($batchData['donor_id'] ?? 'NULL'));
                    error_log("donor_name: " . substr($batchData['donor_name'] ?? 'MISSING', 0, 30));
                    error_log("donor_phone: " . ($batchData['donor_phone'] ?? 'NULL'));
                    error_log("original_amount: " . ($batchData['original_amount'] ?? 'MISSING'));
                    error_log("additional_amount: " . ($batchData['additional_amount'] ?? 'MISSING'));
                    error_log("total_amount: " . ($batchData['total_amount'] ?? 'MISSING'));
                    error_log("package_id: " . ($batchData['package_id'] ?? 'NULL'));
                    
                    error_log("Calling createBatch()...");
                    $allocationBatchId = $batchTracker->createBatch($batchData);
                    if (!$allocationBatchId) {
                        $errorMsg = "Failed to create allocation batch. This may cause tracking issues. Please check server logs.";
                        error_log("=== ADMIN APPROVALS: CRITICAL ERROR - Failed to create batch for pledge update ===");
                        error_log("Batch creation returned NULL - check GridAllocationBatchTracker logs above");
                        // Don't throw exception - continue with approval but log the issue
                        // The batch might have been created earlier when donor submitted the request
                    } else {
                        error_log("Batch created successfully with ID: {$allocationBatchId}");
                    }
                    }
                } elseif (!$isPaidType) {
                    // New pledge - create batch
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                        $normalized_phone = '0' . substr($normalized_phone, 2);
                    }
                    
                    $donorId = null;
                    if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                        $findDonor = $db->prepare("SELECT id FROM donors WHERE phone = ? LIMIT 1");
                        $findDonor->bind_param('s', $normalized_phone);
                        $findDonor->execute();
                        $donorRecord = $findDonor->get_result()->fetch_assoc();
                        $findDonor->close();
                        if ($donorRecord) {
                            $donorId = (int)$donorRecord['id'];
                        }
                    }
                    
                    $batchData = [
                        'batch_type' => 'new_pledge',
                        'request_type' => ($pledgeSource === 'self') ? 'donor_portal' : 'registrar',
                        'original_pledge_id' => null, // Explicitly set to null for new pledges
                        'original_payment_id' => null, // Explicitly set to null for new pledges
                        'new_pledge_id' => $pledgeId,
                        'new_payment_id' => null, // Explicitly set to null for new pledges
                        'donor_id' => $donorId,
                        'donor_name' => $donorName,
                        'donor_phone' => $normalized_phone ?: null, // Convert empty string to null
                        'original_amount' => 0.00,
                        'additional_amount' => $amount,
                        'total_amount' => $amount,
                        'requested_by_user_id' => ($pledgeSource === 'self') ? null : ($pledge['created_by_user_id'] ?? null),
                        'requested_by_donor_id' => ($pledgeSource === 'self') ? $donorId : null,
                        'request_source' => $pledgeSource,
                        'package_id' => $packageId,
                        'metadata' => [
                            'client_uuid' => $pledge['client_uuid'] ?? null,
                            'notes' => $pledge['notes'] ?? null
                        ]
                    ];
                    $allocationBatchId = $batchTracker->createBatch($batchData);
                } elseif ($isPaidType) {
                    // New payment - create batch
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                        $normalized_phone = '0' . substr($normalized_phone, 2);
                    }
                    
                    $donorId = null;
                    if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                        $findDonor = $db->prepare("SELECT id FROM donors WHERE phone = ? LIMIT 1");
                        $findDonor->bind_param('s', $normalized_phone);
                        $findDonor->execute();
                        $donorRecord = $findDonor->get_result()->fetch_assoc();
                        $findDonor->close();
                        if ($donorRecord) {
                            $donorId = (int)$donorRecord['id'];
                        }
                    }
                    
                    // For payments, we need to get payment_id - but this is a pledge with type='paid'
                    // Even though it's a payment, the record exists in the pledges table, so we need to track new_pledge_id
                    // This allows getBatchByRequest() to find the batch later for rejection/deallocation operations
                    $batchData = [
                        'batch_type' => 'new_payment',
                        'request_type' => ($pledgeSource === 'self') ? 'donor_portal' : 'registrar',
                        'original_pledge_id' => null, // Explicitly set to null for new payments
                        'original_payment_id' => null, // Explicitly set to null for new payments
                        'new_pledge_id' => $pledgeId, // CRITICAL: Set to pledgeId so batch can be tracked via getBatchByRequest()
                        'new_payment_id' => null, // No separate payment record exists yet
                        'donor_id' => $donorId,
                        'donor_name' => $donorName,
                        'donor_phone' => $normalized_phone ?: null, // Convert empty string to null
                        'original_amount' => 0.00,
                        'additional_amount' => $amount,
                        'total_amount' => $amount,
                        'requested_by_user_id' => ($pledgeSource === 'self') ? null : ($pledge['created_by_user_id'] ?? null),
                        'requested_by_donor_id' => ($pledgeSource === 'self') ? $donorId : null,
                        'request_source' => $pledgeSource,
                        'package_id' => $packageId,
                        'metadata' => [
                            'client_uuid' => $pledge['client_uuid'] ?? null,
                            'notes' => $pledge['notes'] ?? null
                        ]
                    ];
                    $allocationBatchId = $batchTracker->createBatch($batchData);
                }
                
                // Use custom amount allocator for smart allocation
                $customAllocator = new CustomAmountAllocator($db);
                
                // Handle both pledges and payments
                if ($pledge['type'] === 'paid') {
                    $allocationResult = $customAllocator->processPaymentCustomAmount(
                        $pledgeId,
                        $amount,
                        $donorName,
                        $status,
                        $allocationBatchId
                    );
                } else {
                    // For update requests, allocate cells for the INCREASE amount using the original pledge ID
                    if ($isPledgeUpdate && $originalPledgeId) {
                        // Allocate cells for the additional amount only, linked to original pledge
                        $allocationResult = $customAllocator->processCustomAmount(
                            $originalPledgeId, // Use original pledge ID for grid allocation
                            $amount, // This is the INCREASE amount
                            $donorName,
                            $status,
                            $allocationBatchId
                        );
                    } else {
                        // Regular new pledge - allocate for full amount
                        $allocationResult = $customAllocator->processCustomAmount(
                            $pledgeId,
                            $amount,
                            $donorName,
                            $status,
                            $allocationBatchId
                        );
                    }
                }
                
                // Update batch with allocation details after grid allocation
                if ($allocationBatchId && $allocationResult['success']) {
                    // Extract cell IDs and area from nested structure
                    $cellIds = [];
                    $area = 0.0;
                    
                    // Check if cells are directly in allocation result (IntelligentGridAllocator)
                    if (isset($allocationResult['allocated_cells'])) {
                        $cellIds = $allocationResult['allocated_cells'] ?? [];
                        $area = (float)($allocationResult['area_allocated'] ?? 0.0);
                    }
                    // Check if nested in allocation_result -> grid_allocation (CustomAmountAllocator)
                    elseif (isset($allocationResult['allocation_result']['grid_allocation'])) {
                        $gridAlloc = $allocationResult['allocation_result']['grid_allocation'];
                        $cellIds = $gridAlloc['allocated_cells'] ?? [];
                        $area = (float)($gridAlloc['area_allocated'] ?? 0.0);
                    }
                    // Check if nested in allocation_result directly (CustomAmountAllocator alternative structure)
                    elseif (isset($allocationResult['allocation_result']['allocated_cells'])) {
                        $cellIds = $allocationResult['allocation_result']['allocated_cells'] ?? [];
                        $area = (float)($allocationResult['allocation_result']['area_allocated'] ?? 0.0);
                    }
                    
                    // Only update batch if we have cell information
                    if (!empty($cellIds) || $area > 0) {
                        $batchTracker->approveBatch($allocationBatchId, $cellIds, $area, $uid);
                    }
                }
                
                // Audit log - use original pledge ID for update requests
                $auditEntityId = $isPledgeUpdate && $originalPledgeId ? $originalPledgeId : $pledgeId;
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ipBin = $ip ? @inet_pton($ip) : null;
                $before = json_encode(['status' => 'pending', 'type' => $pledge['type'], 'amount' => (float)$pledge['amount']], JSON_UNESCAPED_SLASHES);
                $after  = json_encode([
                    'status' => $isPledgeUpdate ? 'updated' : 'approved',
                    'grid_allocation' => $allocationResult,
                    'is_update_request' => $isPledgeUpdate
                ], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, ip_address, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, ?, 'admin')");
                $log->bind_param('iisss', $uid, $auditEntityId, $before, $after, $ipBin);
                // Workaround: mysqli doesn't support 'b' bind natively well; fallback to send as NULL/escaped string
                // So we re-prepare without ip if bind fails
                if (!$log->execute()) {
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'approve', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $auditEntityId, $before, $after);
                    $log->execute();
                }
                
                // Handle custom amount allocation result
                if ($allocationResult['success']) {
                    if ($allocationResult['type'] === 'accumulated') {
                        $actionMsg = "Approved & {$allocationResult['message']}";
                        if (!empty($allocationResult['allocation_result'])) {
                            $actionMsg .= " (Auto-allocated cells for accumulated amounts)";
                        }
                    } else {
                        $actionMsg = "Approved & {$allocationResult['message']}";
                    }
                } else {
                    $actionMsg = "Approved (Custom allocation failed: {$allocationResult['error']})";
                }
                
                // If it's an update request, process the update
                if ($isPledgeUpdate && $originalPledgeId) {
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
                    $pledgeAmount = (float)$pledge['amount'];
                    $newTotalAmount = $originalAmount + $pledgeAmount;
                    
                    // Lock original pledge for update
                    $lockOriginal = $db->prepare("SELECT id, amount FROM pledges WHERE id = ? FOR UPDATE");
                    $lockOriginal->bind_param('i', $originalPledgeId);
                    $lockOriginal->execute();
                    $lockOriginal->close();
                    
                    // UPDATE existing pledge amount instead of creating new one
                    $updateOriginalPledge = $db->prepare("UPDATE pledges SET amount = ? WHERE id = ?");
                    $updateOriginalPledge->bind_param('di', $newTotalAmount, $originalPledgeId);
                    $updateOriginalPledge->execute();
                    $updateOriginalPledge->close();
                    
                    // Update donor totals using the INCREASE amount (not the new total)
                    $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                    if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                        $normalized_phone = '0' . substr($normalized_phone, 2);
                    }
                    
                    if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                        $findDonor = $db->prepare("SELECT id, total_pledged, total_paid, donor_type FROM donors WHERE phone = ? LIMIT 1");
                        $findDonor->bind_param('s', $normalized_phone);
                        $findDonor->execute();
                        $donorRecord = $findDonor->get_result()->fetch_assoc();
                        $findDonor->close();
                        
                        if ($donorRecord) {
                            $donorId = (int)$donorRecord['id'];
                            $updateDonor = $db->prepare("
                                UPDATE donors SET
                                    name = ?,
                                    total_pledged = total_pledged + ?,
                                    balance = (total_pledged + ?) - total_paid,
                                    donor_type = 'pledge',
                                    payment_status = CASE
                                        WHEN total_paid = 0 THEN 'not_started'
                                        WHEN total_paid >= (total_pledged + ?) THEN 'completed'
                                        WHEN total_paid > 0 THEN 'paying'
                                        ELSE 'not_started'
                                    END,
                                    last_pledge_id = ?,
                                    updated_at = NOW()
                                WHERE id = ?
                            ");
                            $updateDonor->bind_param('sddddii', $donorName, $pledgeAmount, $pledgeAmount, $pledgeAmount, $originalPledgeId, $donorId);
                            $updateDonor->execute();
                            $updateDonor->close();
                        }
                    }
                    
                    // DELETE the pending update request pledge (it's been merged into original)
                    $deleteUpdateRequest = $db->prepare("DELETE FROM pledges WHERE id = ?");
                    $deleteUpdateRequest->bind_param('i', $pledgeId);
                    $deleteUpdateRequest->execute();
                    $deleteUpdateRequest->close();
                    
                    // Update action message
                    $actionMsg = "Pledge update approved: Original pledge #{$originalPledgeId} updated from £" . number_format($originalAmount, 2) . " to £" . number_format($newTotalAmount, 2);
                    if ($allocationResult['success']) {
                        $actionMsg .= " & {$allocationResult['message']}";
                    }
                }
                
                // Only process as new pledge if it's NOT an update request
                if (!$isPledgeUpdate) {
                    // Update donors table based on pledge type
                    $donorPhone = (string)($pledge['donor_phone'] ?? '');
                    $donorName = (string)($pledge['donor_name'] ?? 'Anonymous');
                    $pledgeAmount = (float)$pledge['amount'];
                    
                    if ($donorPhone) {
                        // Normalize phone number (same logic as donor/login.php)
                        $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                        if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                            $normalized_phone = '0' . substr($normalized_phone, 2);
                        }
                        
                        if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                            // Find or create donor
                            $findDonor = $db->prepare("SELECT id, total_pledged, total_paid, donor_type FROM donors WHERE phone = ? LIMIT 1");
                            $findDonor->bind_param('s', $normalized_phone);
                            $findDonor->execute();
                            $donorRecord = $findDonor->get_result()->fetch_assoc();
                            $findDonor->close();
                            
                            $donorId = null;
                            
                            if ($isPaidType) {
                                // PAYMENT APPROVAL: Immediate payer
                                if ($donorRecord) {
                                    $donorId = (int)$donorRecord['id'];
                                    // Update existing donor
                                    $updateDonor = $db->prepare("
                                        UPDATE donors SET
                                            name = ?,
                                            total_paid = total_paid + ?,
                                            balance = total_pledged - (total_paid + ?),
                                            donor_type = CASE 
                                                WHEN total_pledged = 0 THEN 'immediate_payment'
                                                ELSE 'pledge'
                                            END,
                                            payment_status = CASE
                                                WHEN total_pledged = 0 THEN 'completed'
                                                WHEN (total_paid + ?) >= total_pledged THEN 'completed'
                                                WHEN (total_paid + ?) > 0 THEN 'paying'
                                                ELSE payment_status
                                            END,
                                            last_payment_date = NOW(),
                                            payment_count = COALESCE(payment_count, 0) + 1,
                                            updated_at = NOW()
                                        WHERE id = ?
                                    ");
                                    $updateDonor->bind_param('sdddddi', $donorName, $pledgeAmount, $pledgeAmount, $pledgeAmount, $pledgeAmount, $donorId);
                                    $updateDonor->execute();
                                    $updateDonor->close();
                                } else {
                                    // Create new immediate payer donor
                                    $createDonor = $db->prepare("
                                        INSERT INTO donors (
                                            phone, name, total_paid, balance, donor_type, 
                                            payment_status, payment_count, last_payment_date, source, created_at, updated_at
                                        ) VALUES (?, ?, ?, 0, 'immediate_payment', 'completed', 1, NOW(), 'approval', NOW(), NOW())
                                    ");
                                    $createDonor->bind_param('ssd', $normalized_phone, $donorName, $pledgeAmount);
                                    $createDonor->execute();
                                    $donorId = (int)$db->insert_id;
                                    $createDonor->close();
                                }
                                
                                // Link pledge to donor (if pledge has donor_id column)
                                if ($donorId) {
                                    $linkPledge = $db->prepare("UPDATE pledges SET donor_id = ? WHERE id = ?");
                                    $linkPledge->bind_param('ii', $donorId, $pledgeId);
                                    $linkPledge->execute();
                                    $linkPledge->close();
                                }
                            } else {
                                // PLEDGE APPROVAL: Needs tracking
                                if ($donorRecord) {
                                    $donorId = (int)$donorRecord['id'];
                                    // Update existing donor - handle all scenarios
                                    $updateDonor = $db->prepare("
                                        UPDATE donors SET
                                            name = ?,
                                            total_pledged = total_pledged + ?,
                                            balance = (total_pledged + ?) - total_paid,
                                            donor_type = 'pledge',
                                            payment_status = CASE
                                                WHEN total_paid = 0 THEN 'not_started'
                                                WHEN total_paid >= (total_pledged + ?) THEN 'completed'
                                                WHEN total_paid > 0 THEN 'paying'
                                                ELSE 'not_started'
                                            END,
                                            last_pledge_id = ?,
                                            pledge_count = COALESCE(pledge_count, 0) + 1,
                                            updated_at = NOW()
                                        WHERE id = ?
                                    ");
                                    $updateDonor->bind_param('sddddii', $donorName, $pledgeAmount, $pledgeAmount, $pledgeAmount, $pledgeId, $donorId);
                                    $updateDonor->execute();
                                    $updateDonor->close();
                                } else {
                                    // Create new pledge donor
                                    $createDonor = $db->prepare("
                                        INSERT INTO donors (
                                            phone, name, total_pledged, balance, donor_type, 
                                            payment_status, last_pledge_id, pledge_count, source, created_at, updated_at
                                        ) VALUES (?, ?, ?, ?, 'pledge', 'not_started', ?, 1, 'approval', NOW(), NOW())
                                    ");
                                    $createDonor->bind_param('ssdii', $normalized_phone, $donorName, $pledgeAmount, $pledgeAmount, $pledgeId);
                                    $createDonor->execute();
                                    $donorId = (int)$db->insert_id;
                                    $createDonor->close();
                                }
                                
                                // Link pledge to donor (if pledge has donor_id column)
                                if ($donorId) {
                                    $linkPledge = $db->prepare("UPDATE pledges SET donor_id = ? WHERE id = ?");
                                    $linkPledge->bind_param('ii', $donorId, $pledgeId);
                                    $linkPledge->execute();
                                    $linkPledge->close();
                                }
                            }
                        }
                    }
                }
            } elseif ($action === 'reject') {
                $uid = (int)current_user()['id'];
                
                // Find and reject any associated batch
                $batchTracker = new GridAllocationBatchTracker($db);
                $batch = $batchTracker->getBatchByRequest($pledgeId, null);
                if ($batch) {
                    $batchTracker->rejectBatch((int)$batch['id']);
                }
                
                // Check if this is an update request from donor portal
                $pledgeSource = (string)($pledge['source'] ?? 'volunteer');
                $isUpdateRequest = ($pledgeSource === 'self' && (string)$pledge['type'] === 'pledge');
                
                if ($isUpdateRequest) {
                    // For update requests, DELETE the pending request (don't keep rejected updates)
                    $deleteUpdateRequest = $db->prepare("DELETE FROM pledges WHERE id = ?");
                    $deleteUpdateRequest->bind_param('i', $pledgeId);
                    $deleteUpdateRequest->execute();
                    $deleteUpdateRequest->close();
                    
                    $actionMsg = "Pledge update request rejected and removed.";
                } else {
                    // For regular pledges, mark as rejected
                    $rej = $db->prepare("UPDATE pledges SET status='rejected' WHERE id = ?");
                    $rej->bind_param('i', $pledgeId);
                    $rej->execute();
                    $rej->close();
                }

                // Audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ipBin = $ip ? @inet_pton($ip) : null;
                $before = json_encode(['status' => 'pending'], JSON_UNESCAPED_SLASHES);
                $after  = json_encode(['status' => 'rejected'], JSON_UNESCAPED_SLASHES);
                $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, ip_address, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, ?, 'admin')");
                $log->bind_param('iisss', $uid, $pledgeId, $before, $after, $ipBin);
                if (!$log->execute()) {
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'pledge', ?, 'reject', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $pledgeId, $before, $after);
                    $log->execute();
                }
                $actionMsg = 'Rejected';
            } elseif ($action === 'update') {
                // Update pledge details
                $donorName = trim($_POST['donor_name'] ?? '');
                $donorPhone = trim($_POST['donor_phone'] ?? '');
                $donorEmail = trim($_POST['donor_email'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                $packageId = isset($_POST['package_id']) ? (int)$_POST['package_id'] : null;
                // Normalize and validate UK mobile (07XXXXXXXXX)
                if ($donorPhone !== '') {
                    $digits = preg_replace('/[^0-9+]/', '', $donorPhone);
                    if (strpos($digits, '+44') === 0) { $digits = '0' . substr($digits, 3); }
                    if (!preg_match('/^07\d{9}$/', $digits)) {
                        throw new RuntimeException('Phone must be a valid UK mobile (start with 07)');
                    }
                    $donorPhone = $digits;
                }
                // Prevent duplicate pending/approved pledges or payments for same phone (excluding this record)
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
                    // Update pledge to new model (package_id driven)
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
                    
                    $actionMsg = 'Updated successfully';
                } else {
                    throw new RuntimeException('Invalid data provided');
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            $errorMessage = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            $errorTrace = $e->getTraceAsString();
            
            // Log full error details
            error_log("=== ADMIN APPROVALS: EXCEPTION CAUGHT ===");
            error_log("Message: {$errorMessage}");
            error_log("File: {$errorFile}");
            error_log("Line: {$errorLine}");
            error_log("Trace: {$errorTrace}");
            
            // Build detailed error message for display (always show details for debugging)
            $actionMsg = '<strong>Error:</strong> ' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');
            $actionMsg .= '<br><small><strong>File:</strong> ' . htmlspecialchars(basename($errorFile)) . ':' . $errorLine . '</small>';
            
            // Check if it's a bind_param error and add helpful details
            if (stripos($errorMessage, 'bind_param') !== false || stripos($errorMessage, 'type definition string') !== false) {
                $actionMsg .= '<br><small><strong>Debug Info:</strong> This is a database parameter binding error. Check console for full details.</small>';
            }
            
            // Also set error in session for JavaScript to pick up
            $_SESSION['approval_error'] = [
                'message' => $errorMessage,
                'file' => basename($errorFile),
                'line' => $errorLine,
                'full_trace' => $errorTrace
            ];
            
            // Redirect to show error on page
            header('Location: ' . buildRedirectUrl(urlencode($actionMsg), $_POST));
            exit;
        }
    }

    // Payments workflow (standalone payments with status lifecycle)
    if (in_array($action, ['approve_payment','reject_payment','update_payment'], true)) {
        $paymentId = (int)($_POST['payment_id'] ?? 0);
        if ($paymentId > 0) {
            $db->begin_transaction();
            try {
                // Lock payment
                $sel = $db->prepare("SELECT id, amount, status FROM payments WHERE id=? FOR UPDATE");
                $sel->bind_param('i', $paymentId);
                $sel->execute();
                $pay = $sel->get_result()->fetch_assoc();
                if (!$pay) { throw new RuntimeException('Payment not found'); }

                if ($action === 'approve_payment') {
                    if ((string)$pay['status'] !== 'pending') { throw new RuntimeException('Payment not pending'); }
                    $upd = $db->prepare("UPDATE payments SET status='approved' WHERE id=?");
                    $upd->bind_param('i', $paymentId);
                    $upd->execute();

                    // Counters: increment paid_total
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

                    // Allocate floor grid cells for payment using custom amount allocator
                    $customAllocator = new CustomAmountAllocator($db);
                    
                    // Get payment details for allocation
                    $paymentDetails = $db->prepare("SELECT donor_name, donor_phone, amount, package_id FROM payments WHERE id = ?");
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
                        
                        if ($allocationResult['success']) {
                            $actionMsg .= " Custom allocation: " . $allocationResult['message'];
                        } else {
                            $actionMsg .= " Custom allocation failed: " . $allocationResult['error'];
                        }
                        
                        // Update donors table for payment approval (immediate payer)
                        $donorPhone = (string)($paymentData['donor_phone'] ?? '');
                        $donorName = (string)($paymentData['donor_name'] ?? 'Anonymous');
                        $paymentAmount = (float)$paymentData['amount'];
                        
                        if ($donorPhone) {
                            // Normalize phone number
                            $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                            if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                                $normalized_phone = '0' . substr($normalized_phone, 2);
                            }
                            
                            if (strlen($normalized_phone) === 11 && substr($normalized_phone, 0, 2) === '07') {
                                // Find or create donor
                                $findDonor = $db->prepare("SELECT id, total_pledged, total_paid, donor_type FROM donors WHERE phone = ? LIMIT 1");
                                $findDonor->bind_param('s', $normalized_phone);
                                $findDonor->execute();
                                $donorRecord = $findDonor->get_result()->fetch_assoc();
                                $findDonor->close();
                                
                                $donorId = null;
                                
                                if ($donorRecord) {
                                    $donorId = (int)$donorRecord['id'];
                                    // Update existing donor - Immediate payer
                                    $updateDonor = $db->prepare("
                                        UPDATE donors SET
                                            name = ?,
                                            total_paid = total_paid + ?,
                                            balance = total_pledged - (total_paid + ?),
                                            donor_type = CASE 
                                                WHEN total_pledged = 0 THEN 'immediate_payment'
                                                ELSE 'pledge'
                                            END,
                                            payment_status = CASE
                                                WHEN total_pledged = 0 THEN 'completed'
                                                WHEN (total_paid + ?) >= total_pledged THEN 'completed'
                                                WHEN (total_paid + ?) > 0 THEN 'paying'
                                                ELSE payment_status
                                            END,
                                            last_payment_date = NOW(),
                                            payment_count = COALESCE(payment_count, 0) + 1,
                                            updated_at = NOW()
                                        WHERE id = ?
                                    ");
                                    $updateDonor->bind_param('sdddddi', $donorName, $paymentAmount, $paymentAmount, $paymentAmount, $paymentAmount, $donorId);
                                    $updateDonor->execute();
                                    $updateDonor->close();
                                } else {
                                    // Create new immediate payer donor
                                    $createDonor = $db->prepare("
                                        INSERT INTO donors (
                                            phone, name, total_paid, balance, donor_type, 
                                            payment_status, payment_count, last_payment_date, source, created_at, updated_at
                                        ) VALUES (?, ?, ?, 0, 'immediate_payment', 'completed', 1, NOW(), 'approval', NOW(), NOW())
                                    ");
                                    $createDonor->bind_param('ssd', $normalized_phone, $donorName, $paymentAmount);
                                    $createDonor->execute();
                                    $donorId = (int)$db->insert_id;
                                    $createDonor->close();
                                }
                                
                                // Link payment to donor (if payment has donor_id column)
                                if ($donorId) {
                                    $linkPayment = $db->prepare("UPDATE payments SET donor_id = ? WHERE id = ?");
                                    $linkPayment->bind_param('ii', $donorId, $paymentId);
                                    $linkPayment->execute();
                                    $linkPayment->close();
                                }
                            }
                        }
                    }
                    // No need to update audit log here as it's handled separately for payments
                } else if ($action === 'reject_payment') {
                    // Mark as voided. No counter change.
                    if ((string)$pay['status'] !== 'pending') { throw new RuntimeException('Payment not pending'); }
                    $upd = $db->prepare("UPDATE payments SET status='voided' WHERE id=?");
                    $upd->bind_param('i', $paymentId);
                    $upd->execute();

                    $uid = (int)current_user()['id'];
                    $before = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                    $after  = json_encode(['status'=>'voided'], JSON_UNESCAPED_SLASHES);
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'reject', ?, ?, 'admin')");
                    $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                    $log->execute();
                    $actionMsg = 'Payment rejected';
                } else if ($action === 'update_payment') {
                    // Update standalone payment fields while keeping status pending
                    if ((string)$pay['status'] !== 'pending') { throw new RuntimeException('Payment not pending'); }
                    $donorName = trim($_POST['donor_name'] ?? '');
                    $donorPhone = trim($_POST['donor_phone'] ?? '');
                    $donorEmail = trim($_POST['donor_email'] ?? '');
                    $amount = (float)($_POST['amount'] ?? 0);
                    $notes = trim($_POST['notes'] ?? '');
                    $packageId = isset($_POST['package_id']) ? (int)$_POST['package_id'] : null;
                    $method = trim((string)($_POST['method'] ?? 'cash'));

                    if ($donorName && $amount > 0) {
                        if ($packageId && $packageId > 0) {
                            $upd = $db->prepare("UPDATE payments SET donor_name=?, donor_phone=?, donor_email=?, amount=?, method=?, package_id=?, reference=? WHERE id=?");
                            $upd->bind_param('sssdsisi', $donorName, $donorPhone, $donorEmail, $amount, $method, $packageId, $notes, $paymentId);
                        } else {
                            $upd = $db->prepare("UPDATE payments SET donor_name=?, donor_phone=?, donor_email=?, amount=?, method=?, reference=? WHERE id=?");
                            $upd->bind_param('sssds si', $donorName, $donorPhone, $donorEmail, $amount, $method, $notes, $paymentId);
                        }
                        $upd->execute();

                        // Audit
                        $uid = (int)current_user()['id'];
                        $before = json_encode(['status'=>'pending'], JSON_UNESCAPED_SLASHES);
                        $after  = json_encode(['amount'=>$amount,'method'=>$method,'package_id'=>$packageId,'updated'=>true], JSON_UNESCAPED_SLASHES);
                        $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, before_json, after_json, source) VALUES(?, 'payment', ?, 'update', ?, ?, 'admin')");
                        $log->bind_param('iiss', $uid, $paymentId, $before, $after);
                        $log->execute();

                        $actionMsg = 'Payment updated successfully';
                    } else {
                        throw new RuntimeException('Invalid data provided');
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                $errorMessage = $e->getMessage();
                $errorFile = $e->getFile();
                $errorLine = $e->getLine();
                
                error_log("=== ADMIN APPROVALS PAYMENT: EXCEPTION CAUGHT ===");
                error_log("Message: {$errorMessage}");
                error_log("File: {$errorFile}");
                error_log("Line: {$errorLine}");
                
                $actionMsg = '<strong>Error:</strong> ' . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8');
                $actionMsg .= '<br><small><strong>File:</strong> ' . htmlspecialchars(basename($errorFile)) . ':' . $errorLine . '</small>';
                
                $_SESSION['approval_error'] = [
                    'message' => $errorMessage,
                    'file' => basename($errorFile),
                    'line' => $errorLine
                ];
            }
        }
        header('Location: ' . buildRedirectUrl(urlencode($actionMsg), $_POST));
        exit;
    }
    // PRG: redirect to avoid resubmission and show flash message
    header('Location: ' . buildRedirectUrl($actionMsg, $_POST));
    exit;
}

// Get message from URL if redirected after error
$urlMsg = $_GET['msg'] ?? '';
if ($urlMsg && !$actionMsg) {
    $actionMsg = urldecode($urlMsg);
}

// Filter and sort parameters
$filter_type = $_GET['filter_type'] ?? '';
$filter_amount_min = !empty($_GET['filter_amount_min']) ? (float)$_GET['filter_amount_min'] : null;
$filter_amount_max = !empty($_GET['filter_amount_max']) ? (float)$_GET['filter_amount_max'] : null;
$filter_donor = trim($_GET['filter_donor'] ?? '');
$filter_registrar = trim($_GET['filter_registrar'] ?? '');
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = in_array($_GET['sort_order'] ?? 'desc', ['asc', 'desc']) ? $_GET['sort_order'] : 'desc';

// Pagination parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = in_array((int)($_GET['per_page'] ?? 20), [10, 20, 50]) ? (int)($_GET['per_page'] ?? 20) : 20;
$offset = ($page - 1) * $per_page;

// Build WHERE conditions for filters
$where_conditions = ['p.status = \'pending\''];
$payment_where_conditions = ['pay.status = \'pending\''];

// Type filter
if ($filter_type && in_array($filter_type, ['pledge', 'payment'])) {
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
    $payment_where_conditions[] = 'u2.name LIKE \'%' . $escaped_registrar . '%\'';
}

// Date filter
if ($filter_date_from) {
    $escaped_date_from = mysqli_real_escape_string($db, $filter_date_from);
    $where_conditions[] = 'DATE(p.created_at) >= \'' . $escaped_date_from . '\'';
    $payment_where_conditions[] = 'DATE(pay.created_at) >= \'' . $escaped_date_from . '\'';
}
if ($filter_date_to) {
    $escaped_date_to = mysqli_real_escape_string($db, $filter_date_to);
    $where_conditions[] = 'DATE(p.created_at) <= \'' . $escaped_date_to . '\'';
    $payment_where_conditions[] = 'DATE(pay.created_at) <= \'' . $escaped_date_to . '\'';
}

$where_clause = implode(' AND ', $where_conditions);
$payment_where_clause = implode(' AND ', $payment_where_conditions);

// Map sort fields to actual columns
$sort_mapping = [
    'created_at' => 'created_at',
    'amount' => 'amount',
    'donor_name' => 'donor_name',
    'registrar_name' => 'registrar_name',
    'item_type' => 'item_type'
];

$sort_column = $sort_mapping[$sort_by] ?? 'created_at';
$order_clause = "$sort_column $sort_order";

// Get total count for pagination
$count_sql = "
SELECT COUNT(*) as total FROM (
  (SELECT p.id FROM pledges p 
   LEFT JOIN users u ON p.created_by_user_id = u.id 
   WHERE $where_clause)
  UNION ALL
  (SELECT pay.id FROM payments pay 
   LEFT JOIN users u2 ON u2.id = pay.received_by_user_id 
   WHERE $payment_where_clause)
) as combined_count";

$total_items = (int)$db->query($count_sql)->fetch_assoc()['total'];
$total_pages = (int)ceil($total_items / $per_page);

// Check if source column exists in pledges and payments tables
$pledges_has_source = false;
$payments_has_source = false;
try {
    $check_pledges_source = $db->query("SHOW COLUMNS FROM pledges LIKE 'source'");
    if ($check_pledges_source && $check_pledges_source->num_rows > 0) {
        $pledges_has_source = true;
    }
} catch (Exception $e) {}
try {
    $check_payments_source = $db->query("SHOW COLUMNS FROM payments LIKE 'source'");
    if ($check_payments_source && $check_payments_source->num_rows > 0) {
        $payments_has_source = true;
    }
} catch (Exception $e) {}

// Combined pending items (pledges + payments) with filtering and sorting
$combinedSql = "
SELECT 'pledge' AS item_type, p.id AS item_id, p.amount, NULL AS method, p.notes, p.created_at,
       NULL AS sqm_meters, p.anonymous, p.donor_name, p.donor_phone, p.donor_email, " . ($pledges_has_source ? "p.source" : "'' as source") . ",
       u.name AS registrar_name, NULL AS sqm_unit, NULL AS sqm_quantity, NULL AS price_per_sqm,
       dp.label AS package_label, dp.price AS package_price, dp.sqm_meters AS package_sqm, p.package_id AS package_id
FROM pledges p
LEFT JOIN users u ON p.created_by_user_id = u.id
LEFT JOIN donation_packages dp ON dp.id = p.package_id
WHERE $where_clause
UNION ALL
SELECT 'payment' AS item_type, pay.id AS item_id, pay.amount, pay.method, pay.reference AS notes, pay.created_at,
       NULL AS sqm_meters, 0 AS anonymous, pay.donor_name, pay.donor_phone, pay.donor_email, " . ($payments_has_source ? "pay.source" : "'' as source") . ",
       u2.name AS registrar_name, NULL AS sqm_unit, NULL AS sqm_quantity, NULL AS price_per_sqm,
       dp2.label AS package_label, dp2.price AS package_price, dp2.sqm_meters AS package_sqm, pay.package_id AS package_id
FROM payments pay
LEFT JOIN users u2 ON u2.id = pay.received_by_user_id
LEFT JOIN donation_packages dp2 ON dp2.id = pay.package_id
WHERE $payment_where_clause
ORDER BY $order_clause
LIMIT $per_page OFFSET $offset
";
$pending_items = $db->query($combinedSql)->fetch_all(MYSQLI_ASSOC);

// Diagnostics: counts by type to verify data presence
$counts = ['pledge' => 0, 'paid' => 0];
$cntRes = $db->query("SELECT type, COUNT(*) as c FROM pledges WHERE status='pending' GROUP BY type");
if ($cntRes) {
    while ($row = $cntRes->fetch_assoc()) {
        $t = strtolower((string)$row['type']);
        if (isset($counts[$t])) { $counts[$t] = (int)$row['c']; }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1%">
  <title>Pending Approvals - Fundraising Admin</title>
  <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.css'); ?>">
  <link rel="stylesheet" href="assets/approvals.css?v=<?php echo @filemtime(__DIR__ . '/assets/approvals.css'); ?>">
</head>
<body>
<div class="admin-wrapper">
  <?php include '../includes/sidebar.php'; ?>
  
  <div class="admin-content">
    <?php include '../includes/topbar.php'; ?>
    
    <main class="main-content">
      <div class="container-fluid">
        <?php include '../includes/db_error_banner.php'; ?>

          <?php if ($actionMsg): ?>
            <?php 
            $isError = (stripos($actionMsg, 'error') !== false || stripos($actionMsg, 'failed') !== false);
            $alertClass = $isError ? 'alert-danger' : 'alert-info';
            $alertIcon = $isError ? 'fa-exclamation-triangle' : 'fa-info-circle';
            $msgText = strip_tags($actionMsg);
            ?>
            <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert" id="actionMessage" style="max-height: 500px; overflow-y: auto;">
              <i class="fas <?php echo $alertIcon; ?> me-2"></i>
              <div><?php echo $actionMsg; ?></div>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>
              console.group('<?php echo $isError ? '❌ APPROVAL ERROR' : '✅ APPROVAL SUCCESS'; ?>');
              console.<?php echo $isError ? 'error' : 'log'; ?>('<?php echo addslashes($msgText); ?>');
              <?php if ($isError): ?>
              console.error('Full Error Message:', <?php echo json_encode($actionMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
              <?php endif; ?>
              <?php if (isset($_SESSION['approval_error'])): ?>
              console.error('Error Details:', <?php echo json_encode($_SESSION['approval_error'], JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
              <?php unset($_SESSION['approval_error']); ?>
              <?php endif; ?>
              console.groupEnd();
              
              // Keep error visible for longer if it's an error
              <?php if ($isError): ?>
              setTimeout(function() {
                var alertEl = document.getElementById('actionMessage');
                if (alertEl) {
                  alertEl.style.display = 'block';
                  alertEl.classList.remove('fade');
                  alertEl.classList.add('show');
                  // Scroll to error
                  alertEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
              }, 100);
              <?php endif; ?>
            </script>
          <?php endif; ?>
          
          <div class="card animate-fade-in">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                  <i class="fas fa-clock text-warning me-2"></i>
                  Pending Approvals
                </h5>
                <div class="d-flex gap-2">
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filtersCollapse" aria-expanded="false">
                    <i class="fas fa-filter"></i> Filters & Sort
                  </button>
                  <a href="../approved/" class="btn btn-sm btn-outline-success">
                    <i class="fas fa-check-circle"></i> View Approved
                  </a>
                  <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Manual Refresh
                  </button>
                  <button class="btn btn-sm btn-outline-secondary" id="autoRefreshBtn" onclick="toggleAutoRefresh()">
                    <i class="fas fa-play"></i> <span id="autoRefreshText">Auto Refresh</span>
                  </button>
                </div>
              </div>
              
              <!-- Filters and Sort Panel -->
              <div class="collapse" id="filtersCollapse">
                <div class="card-body border-bottom bg-light">
                  <form method="GET" action="index.php" class="row g-3">
                    <div class="col-md-3">
                      <label class="form-label">Type</label>
                      <select name="filter_type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="pledge" <?php echo $filter_type === 'pledge' ? 'selected' : ''; ?>>Pledges Only</option>
                        <option value="payment" <?php echo $filter_type === 'payment' ? 'selected' : ''; ?>>Payments Only</option>
                      </select>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Amount Range</label>
                      <div class="input-group input-group-sm">
                        <span class="input-group-text">£</span>
                        <input type="number" name="filter_amount_min" class="form-control" placeholder="Min" step="0.01" value="<?php echo htmlspecialchars($filter_amount_min ?? ''); ?>">
                        <span class="input-group-text">to</span>
                        <input type="number" name="filter_amount_max" class="form-control" placeholder="Max" step="0.01" value="<?php echo htmlspecialchars($filter_amount_max ?? ''); ?>">
                      </div>
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Date From</label>
                      <input type="date" name="filter_date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Date To</label>
                      <input type="date" name="filter_date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Sort By</label>
                      <select name="sort_by" class="form-select form-select-sm">
                        <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Created Date</option>
                        <option value="amount" <?php echo $sort_by === 'amount' ? 'selected' : ''; ?>>Amount</option>
                        <option value="donor_name" <?php echo $sort_by === 'donor_name' ? 'selected' : ''; ?>>Donor Name</option>
                        <option value="registrar_name" <?php echo $sort_by === 'registrar_name' ? 'selected' : ''; ?>>Registrar</option>
                        <option value="item_type" <?php echo $sort_by === 'item_type' ? 'selected' : ''; ?>>Type</option>
                      </select>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Search Donor</label>
                      <input type="text" name="filter_donor" class="form-control form-control-sm" placeholder="Search by donor name..." value="<?php echo htmlspecialchars($filter_donor); ?>">
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Search Registrar</label>
                      <input type="text" name="filter_registrar" class="form-control form-control-sm" placeholder="Search by registrar..." value="<?php echo htmlspecialchars($filter_registrar); ?>">
                    </div>
                    <div class="col-md-2">
                      <label class="form-label">Order</label>
                      <select name="sort_order" class="form-select form-select-sm">
                        <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>Oldest First</option>
                      </select>
                    </div>
                    <div class="col-md-1">
                      <label class="form-label">&nbsp;</label>
                      <div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                          <i class="fas fa-search"></i> Apply
                        </button>
                      </div>
                    </div>
                    <?php if ($filter_type || $filter_amount_min || $filter_amount_max || $filter_donor || $filter_registrar || $filter_date_from || $filter_date_to || $sort_by !== 'created_at' || $sort_order !== 'desc'): ?>
                    <div class="col-12">
                      <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear All Filters
                      </a>
                    </div>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
              <div class="card-body">
                <?php include __DIR__ . '/partial_list.php'; ?>
                
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Pending items pagination" class="mt-4">
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
                    if ($sort_by !== 'created_at') $filter_params['sort_by'] = $sort_by;
                    if ($sort_order !== 'desc') $filter_params['sort_order'] = $sort_order;
                    
                    function build_pagination_url_approvals($page_num, $per_page_num, $filter_params) {
                      $params = array_merge($filter_params, ['page' => $page_num, 'per_page' => $per_page_num]);
                      return '?' . http_build_query($params);
                    }
                    ?>
                    <div class="btn-group" role="group" aria-label="Items per page">
                      <a href="<?php echo build_pagination_url_approvals(1, 10, $filter_params); ?>" class="btn btn-sm btn-outline-secondary <?php echo $per_page == 10 ? 'active' : ''; ?>">10</a>
                      <a href="<?php echo build_pagination_url_approvals(1, 20, $filter_params); ?>" class="btn btn-sm btn-outline-secondary <?php echo $per_page == 20 ? 'active' : ''; ?>">20</a>
                      <a href="<?php echo build_pagination_url_approvals(1, 50, $filter_params); ?>" class="btn btn-sm btn-outline-secondary <?php echo $per_page == 50 ? 'active' : ''; ?>">50</a>
                    </div>
                  </div>
                  <ul class="pagination pagination-sm justify-content-center">
                    <?php if ($page > 1): ?>
                      <li class="page-item">
                        <a class="page-link" href="<?php echo build_pagination_url_approvals(1, $per_page, $filter_params); ?>" aria-label="First">
                          <i class="fas fa-angle-double-left"></i>
                        </a>
                      </li>
                      <li class="page-item">
                        <a class="page-link" href="<?php echo build_pagination_url_approvals($page - 1, $per_page, $filter_params); ?>" aria-label="Previous">
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
                        <a class="page-link" href="<?php echo build_pagination_url_approvals($i, $per_page, $filter_params); ?>"><?php echo $i; ?></a>
                      </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                      <li class="page-item">
                        <a class="page-link" href="<?php echo build_pagination_url_approvals($page + 1, $per_page, $filter_params); ?>" aria-label="Next">
                          <i class="fas fa-angle-right"></i>
                        </a>
                      </li>
                      <li class="page-item">
                        <a class="page-link" href="<?php echo build_pagination_url_approvals($total_pages, $per_page, $filter_params); ?>" aria-label="Last">
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

<!-- Details Modal (modern layout) -->
<div class="modal fade details-modal" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header details-header">
        <div class="details-header-main">
          <div class="amount-large" id="dAmountLarge">£ 0.00</div>
          <div class="chips">
            <span class="chip chip-type" id="dTypeBadge">PAID</span>
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
            <div class="detail-title"><i class="fas fa-ruler-combined me-2"></i>Pledge</div>
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

<!-- Edit Modal -->
<div class="modal fade edit-modal" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">
          <i class="fas fa-edit me-2"></i>Edit Pledge Details
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" id="editForm" action="index.php">
        <div class="modal-body">
          <?php echo csrf_input(); ?>
          <input type="hidden" name="action" id="editAction" value="update">
          <input type="hidden" name="pledge_id" id="editPledgeId">
          <input type="hidden" name="payment_id" id="editPaymentId">
          
          <?php 
          // Preserve current filter and pagination parameters in edit form
          $preserveParams = ['filter_type', 'filter_amount_min', 'filter_amount_max', 'filter_donor', 'filter_registrar', 'filter_date_from', 'filter_date_to', 'sort_by', 'sort_order', 'page', 'per_page'];
          foreach ($preserveParams as $param) {
              if (isset($_GET[$param]) && $_GET[$param] !== '') {
                  echo '<input type="hidden" name="' . htmlspecialchars($param) . '" value="' . htmlspecialchars($_GET[$param]) . '">';
              }
          }
          ?>
          <input type="hidden" name="method" id="editMethodHidden">
          
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editDonorName" class="form-label">Donor Name</label>
                <input type="text" class="form-control" id="editDonorName" name="donor_name" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editDonorPhone" class="form-label">Phone Number</label>
                <input type="tel" class="form-control" id="editDonorPhone" name="donor_phone" required>
              </div>
            </div>
          </div>
          
          <div class="mb-3">
            <label for="editDonorEmail" class="form-label">Email (Optional)</label>
            <input type="email" class="form-control" id="editDonorEmail" name="donor_email">
          </div>
          
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editAmount" class="form-label">Amount (£)</label>
                <input type="number" class="form-control" id="editAmount" name="amount" step="0.01" min="0" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label for="editPackage" class="form-label">Package (optional)</label>
                <select id="editPackage" name="package_id" class="form-select">
                  <option value="">— Select package —</option>
                  <?php
                  $pkgs = $db->query("SELECT id,label FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
                  foreach ($pkgs as $pkg) {
                      echo '<option value="'.(int)$pkg['id'].'">'.htmlspecialchars($pkg['label']).'</option>';
                  }
                  ?>
                </select>
              </div>
            </div>
          </div>
          <div class="mb-3" id="editMethodWrap" style="display:none">
            <label for="editMethod" class="form-label">Payment Method</label>
            <select id="editMethod" class="form-select" onchange="document.getElementById('editMethodHidden').value=this.value;">
              <option value="cash">Cash</option>
              <option value="card">Card</option>
              <option value="bank">Bank</option>
              <option value="other">Other</option>
            </select>
          </div>
          
          <div class="mb-3">
            <label for="editNotes" class="form-label">Notes</label>
            <textarea class="form-control" id="editNotes" name="notes" rows="3"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i>Update Pledge
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/admin.js?v=<?php echo @filemtime(__DIR__ . '/../assets/admin.js'); ?>"></script>
<script src="assets/approvals.js?v=<?php echo @filemtime(__DIR__ . '/assets/approvals.js'); ?>"></script>

<script>
// Global error handler and debugging
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== ADMIN APPROVALS PAGE LOADED ===');
    
    // Check URL for error messages
    var urlParams = new URLSearchParams(window.location.search);
    var msgParam = urlParams.get('msg');
    if (msgParam) {
        var decodedMsg = decodeURIComponent(msgParam);
        if (decodedMsg.toLowerCase().includes('error') || decodedMsg.toLowerCase().includes('failed')) {
            console.error('Error message from URL:', decodedMsg);
        } else {
            console.log('Message from URL:', decodedMsg);
        }
    }
    
    // Log any existing error messages on page load
    var errorAlert = document.getElementById('actionMessage');
    if (errorAlert) {
        if (errorAlert.classList.contains('alert-danger')) {
            console.error('Error alert found on page:', errorAlert.textContent);
        } else {
            console.log('Info alert found on page:', errorAlert.textContent);
        }
    }
    
    // Intercept form submissions to log errors
    var forms = document.querySelectorAll('form[method="post"]');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            console.log('Form submitting:', form.action || 'current page');
            var formData = new FormData(form);
            var formDataObj = {};
            for (var pair of formData.entries()) {
                formDataObj[pair[0]] = pair[1];
            }
            console.log('Form data:', formDataObj);
            
            // Check for existing errors before submission
            var existingError = document.getElementById('actionMessage');
            if (existingError && existingError.classList.contains('alert-danger')) {
                console.warn('Previous error still visible, submitting anyway...');
            }
        });
    });
    
    // Global error handler for uncaught errors
    window.addEventListener('error', function(e) {
        console.error('Uncaught JavaScript Error:', e.message, 'at', e.filename + ':' + e.lineno);
    });
});
</script>

<script>
function openEditModal(id, name, phone, email, amount, sqm, notes, kind) {
    // Reset ids
    document.getElementById('editPledgeId').value = '';
    document.getElementById('editPaymentId').value = '';
    document.getElementById('editMethodHidden').value = '';

    document.getElementById('editDonorName').value = name || '';
    document.getElementById('editDonorPhone').value = phone || '';
    document.getElementById('editDonorEmail').value = email || '';
    document.getElementById('editAmount').value = amount || 0;
    document.getElementById('editNotes').value = notes || '';

    const methodWrap = document.getElementById('editMethodWrap');
    const actionField = document.getElementById('editAction');

    if ((kind || '').toLowerCase() === 'payment') {
        // Payment edit
        actionField.value = 'update_payment';
        document.getElementById('editPaymentId').value = id;
        methodWrap.style.display = '';
    } else {
        // Pledge edit
        actionField.value = 'update';
        document.getElementById('editPledgeId').value = id;
        methodWrap.style.display = 'none';
    }
}

// Click-to-open details modal for approval cards
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
  if ((card.dataset.type||'').toLowerCase()==='payment') {
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

// Nothing else needed: actions use forms and the PRG pattern
</script>

<!-- Auto-Refresh Functionality -->
<script>
// Auto-refresh state management
let autoRefreshInterval = null;
let isAutoRefreshActive = false;
const REFRESH_INTERVAL = 2000; // 2 seconds

// Toggle auto-refresh functionality
function toggleAutoRefresh() {
    const btn = document.getElementById('autoRefreshBtn');
    const text = document.getElementById('autoRefreshText');
    const icon = btn.querySelector('i');
    
    if (isAutoRefreshActive) {
        // Stop auto-refresh
        stopAutoRefresh();
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-secondary');
        icon.className = 'fas fa-play';
        text.textContent = 'Auto Refresh';
    } else {
        // Start auto-refresh
        startAutoRefresh();
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        icon.className = 'fas fa-pause';
        text.textContent = 'Auto ON';
    }
}

// Start auto-refresh
function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    
    isAutoRefreshActive = true;
    autoRefreshInterval = setInterval(() => {
        // Check if any modals are open - don't refresh if user is interacting
        const openModals = document.querySelectorAll('.modal.show');
        if (openModals.length === 0) {
            refreshPage();
        }
    }, REFRESH_INTERVAL);
    
    console.log('Auto-refresh started: Every 2 seconds');
}

// Stop auto-refresh
function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
    isAutoRefreshActive = false;
    console.log('Auto-refresh stopped');
}

// Refresh the page
function refreshPage() {
    // Add a subtle loading indicator
    const btn = document.getElementById('autoRefreshBtn');
    const originalHTML = btn.innerHTML;
    
    // Show loading state briefly
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Refreshing...</span>';
    
    // Refresh the page
    setTimeout(() => {
        location.reload();
    }, 300);
}

// Initialize auto-refresh state from localStorage
document.addEventListener('DOMContentLoaded', function() {
    const savedState = localStorage.getItem('approvalsAutoRefresh');
    if (savedState === 'true') {
        toggleAutoRefresh(); // This will start auto-refresh
    }
});

// Save auto-refresh state to localStorage
function saveAutoRefreshState() {
    localStorage.setItem('approvalsAutoRefresh', isAutoRefreshActive.toString());
}

// Update the toggle function to save state
const originalToggle = toggleAutoRefresh;
toggleAutoRefresh = function() {
    originalToggle();
    saveAutoRefreshState();
};

// Stop auto-refresh when page visibility changes (user switches tabs)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        if (isAutoRefreshActive) {
            stopAutoRefresh();
            // Will restart when page becomes visible
        }
    } else {
        // Page became visible again
        const savedState = localStorage.getItem('approvalsAutoRefresh');
        if (savedState === 'true' && !isAutoRefreshActive) {
            startAutoRefresh();
        }
    }
});

// Stop auto-refresh when user is about to leave the page
window.addEventListener('beforeunload', function() {
    stopAutoRefresh();
});

// Keyboard shortcut: Ctrl+R or F5 to toggle auto-refresh
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && e.key === 'r') || e.key === 'F5') {
        e.preventDefault();
        if (isAutoRefreshActive) {
            // If auto-refresh is on, just do a manual refresh
            location.reload();
        } else {
            // If auto-refresh is off, start it
            toggleAutoRefresh();
        }
    }
});
</script>

<script>
// Fallback for sidebar toggle if admin.js failed to attach for any reason
if (typeof window.toggleSidebar !== 'function') {
  window.toggleSidebar = function() {
    var body = document.body;
    var sidebar = document.getElementById('sidebar');
    var overlay = document.querySelector('.sidebar-overlay');
    if (window.innerWidth <= 991.98) {
      if (sidebar) sidebar.classList.toggle('active');
      if (overlay) overlay.classList.toggle('active');
      body.style.overflow = (sidebar && sidebar.classList.contains('active')) ? 'hidden' : '';
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  };
}
</script>
</body>
</html>

