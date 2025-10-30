<?php
declare(strict_types=1);

/**
 * Grid Allocation Batch Tracker
 * 
 * Tracks allocation batches for pledges and payments, especially for updates.
 * This allows us to:
 * - Track which cells belong to which allocation batch
 * - Undo specific batches without affecting others
 * - Maintain complete audit trail
 * - Handle updates from both donor portal and registrar
 */
class GridAllocationBatchTracker
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new allocation batch record
     * 
     * @param array $data Batch data
     * @return int|null Batch ID or null on failure
     */
    public function createBatch(array $data): ?int
    {
        error_log("=== GridAllocationBatchTracker::createBatch() CALLED ===");
        error_log("Input data keys: " . implode(', ', array_keys($data)));
        
        $sql = "
            INSERT INTO grid_allocation_batches (
                batch_type, request_type, original_pledge_id, original_payment_id,
                new_pledge_id, new_payment_id, donor_id, donor_name, donor_phone,
                original_amount, additional_amount, total_amount,
                requested_by_user_id, requested_by_donor_id, request_source,
                request_date, approval_status, package_id, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ";

        error_log("Preparing SQL statement...");
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log("=== GridAllocationBatchTracker: CRITICAL ERROR - Failed to prepare INSERT ===");
            error_log("MySQL Error: " . $this->db->error);
            error_log("MySQL Error Code: " . $this->db->errno);
            return null;
        }
        error_log("SQL statement prepared successfully");

        $metadataJson = isset($data['metadata']) ? json_encode($data['metadata']) : null;

        // Extract values into variables for bind_param (must be variables, not expressions)
        $batch_type = (string)($data['batch_type'] ?? '');
        $request_type = (string)($data['request_type'] ?? '');
        $original_pledge_id = isset($data['original_pledge_id']) ? ((int)$data['original_pledge_id'] ?: null) : null;
        $original_payment_id = isset($data['original_payment_id']) ? ((int)$data['original_payment_id'] ?: null) : null;
        $new_pledge_id = isset($data['new_pledge_id']) ? ((int)$data['new_pledge_id'] ?: null) : null;
        $new_payment_id = isset($data['new_payment_id']) ? ((int)$data['new_payment_id'] ?: null) : null;
        $donor_id = isset($data['donor_id']) ? ((int)$data['donor_id'] ?: null) : null;
        $donor_name = (string)($data['donor_name'] ?? 'Anonymous');
        $donor_phone = isset($data['donor_phone']) && $data['donor_phone'] !== '' ? (string)$data['donor_phone'] : null;
        $original_amount = (float)($data['original_amount'] ?? 0.00);
        $additional_amount = (float)($data['additional_amount'] ?? 0.00);
        $total_amount = (float)($data['total_amount'] ?? 0.00);
        $requested_by_user_id = isset($data['requested_by_user_id']) ? ((int)$data['requested_by_user_id'] ?: null) : null;
        $requested_by_donor_id = isset($data['requested_by_donor_id']) ? ((int)$data['requested_by_donor_id'] ?: null) : null;
        $request_source = (string)($data['request_source'] ?? 'volunteer');
        $request_date = (string)($data['request_date'] ?? date('Y-m-d H:i:s'));
        $package_id = isset($data['package_id']) ? ((int)$data['package_id'] ?: null) : null;
        
        // Validate required fields
        if (empty($batch_type) || empty($request_type) || empty($donor_name)) {
            error_log("GridAllocationBatchTracker: Missing required fields - batch_type: " . ($batch_type ?: 'EMPTY') . ", request_type: " . ($request_type ?: 'EMPTY') . ", donor_name: " . ($donor_name ?: 'EMPTY'));
            $stmt->close();
            return null;
        }
        
        // Log parameter count for debugging
        $paramCount = 18;
        error_log("=== GridAllocationBatchTracker: Extracted Variables ===");
        error_log("1. batch_type: " . var_export($batch_type, true));
        error_log("2. request_type: " . var_export($request_type, true));
        error_log("3. original_pledge_id: " . var_export($original_pledge_id, true) . " (type: " . gettype($original_pledge_id) . ")");
        error_log("4. original_payment_id: " . var_export($original_payment_id, true) . " (type: " . gettype($original_payment_id) . ")");
        error_log("5. new_pledge_id: " . var_export($new_pledge_id, true) . " (type: " . gettype($new_pledge_id) . ")");
        error_log("6. new_payment_id: " . var_export($new_payment_id, true) . " (type: " . gettype($new_payment_id) . ")");
        error_log("7. donor_id: " . var_export($donor_id, true) . " (type: " . gettype($donor_id) . ")");
        error_log("8. donor_name: " . var_export(substr($donor_name, 0, 30), true) . " (type: " . gettype($donor_name) . ")");
        error_log("9. donor_phone: " . var_export($donor_phone, true) . " (type: " . gettype($donor_phone) . ")");
        error_log("10. original_amount: " . var_export($original_amount, true) . " (type: " . gettype($original_amount) . ")");
        error_log("11. additional_amount: " . var_export($additional_amount, true) . " (type: " . gettype($additional_amount) . ")");
        error_log("12. total_amount: " . var_export($total_amount, true) . " (type: " . gettype($total_amount) . ")");
        error_log("13. requested_by_user_id: " . var_export($requested_by_user_id, true) . " (type: " . gettype($requested_by_user_id) . ")");
        error_log("14. requested_by_donor_id: " . var_export($requested_by_donor_id, true) . " (type: " . gettype($requested_by_donor_id) . ")");
        error_log("15. request_source: " . var_export($request_source, true));
        error_log("16. request_date: " . var_export($request_date, true));
        error_log("17. package_id: " . var_export($package_id, true) . " (type: " . gettype($package_id) . ")");
        error_log("18. metadataJson: " . var_export(substr($metadataJson ?? 'NULL', 0, 50), true) . " (type: " . gettype($metadataJson) . ")");
        error_log("Total parameters: {$paramCount}");

        // Type string: s=string, i=integer, d=double
        // Parameters in order (18 total):
        //  1. batch_type(s), 2. request_type(s), 3. original_pledge_id(i), 4. original_payment_id(i),
        //  5. new_pledge_id(i), 6. new_payment_id(i), 7. donor_id(i), 8. donor_name(s), 9. donor_phone(s),
        //  10. original_amount(d), 11. additional_amount(d), 12. total_amount(d),
        //  13. requested_by_user_id(i), 14. requested_by_donor_id(i), 15. request_source(s), 16. request_date(s),
        //  17. package_id(i), 18. metadataJson(s)
        // Type string must match exactly 18 parameters
        // Type string: 'ssiiiiissdddiissis' = 18 characters
        // Parameters: 18 variables
        $typeString = 'ssiiiiissdddiissis';
        
        // Verify type string matches parameter count before binding
        $typeStringLength = strlen($typeString);
        if ($typeStringLength !== 18) {
            error_log("GridAllocationBatchTracker: CRITICAL - Type string length mismatch: {$typeStringLength} (expected 18)");
            $stmt->close();
            return null;
        }
        
        // Convert null strings to empty strings for bind_param compatibility
        // bind_param with type 's' can handle null, but we'll ensure strings are not null
        if ($donor_phone === null) {
            $donor_phone = '';
            error_log("Converted donor_phone from NULL to empty string");
        }
        if ($metadataJson === null) {
            $metadataJson = '';
            error_log("Converted metadataJson from NULL to empty string");
        }
        
        error_log("=== GridAllocationBatchTracker: About to call bind_param ===");
        error_log("Type string: '{$typeString}' (length: {$typeStringLength})");
        error_log("Expected parameters: 18");
        
        // Count actual parameters being passed
        $actualParams = [
            $batch_type, $request_type, $original_pledge_id, $original_payment_id,
            $new_pledge_id, $new_payment_id, $donor_id, $donor_name, $donor_phone,
            $original_amount, $additional_amount, $total_amount,
            $requested_by_user_id, $requested_by_donor_id, $request_source, $request_date,
            $package_id, $metadataJson
        ];
        error_log("Actual parameters count: " . count($actualParams));
        
        if (count($actualParams) !== 18) {
            error_log("=== CRITICAL ERROR: Parameter count mismatch! ===");
            error_log("Expected: 18, Actual: " . count($actualParams));
            $stmt->close();
            return null;
        }
        
        try {
            error_log("Calling bind_param with type string: '{$typeString}'");
            $bindResult = $stmt->bind_param(
                $typeString,
                $batch_type,                // 1. s
                $request_type,              // 2. s
                $original_pledge_id,        // 3. i
                $original_payment_id,       // 4. i
                $new_pledge_id,             // 5. i
                $new_payment_id,            // 6. i
                $donor_id,                  // 7. i
                $donor_name,                // 8. s
                $donor_phone,               // 9. s
                $original_amount,           // 10. d
                $additional_amount,         // 11. d
                $total_amount,              // 12. d
                $requested_by_user_id,      // 13. i
                $requested_by_donor_id,     // 14. i
                $request_source,            // 15. s
                $request_date,              // 16. s
                $package_id,                // 17. i
                $metadataJson               // 18. s
            );
            
            if (!$bindResult) {
                error_log("=== CRITICAL ERROR: bind_param returned FALSE ===");
                error_log("MySQL Error: " . $stmt->error);
                error_log("MySQL Error Code: " . $stmt->errno);
                error_log("Database Error: " . $this->db->error);
                $stmt->close();
                return null;
            }
            error_log("bind_param succeeded");
        } catch (Exception $e) {
            error_log("=== CRITICAL ERROR: Exception in bind_param ===");
            error_log("Exception message: " . $e->getMessage());
            error_log("Exception code: " . $e->getCode());
            error_log("Exception file: " . $e->getFile());
            error_log("Exception line: " . $e->getLine());
            error_log("Exception trace: " . $e->getTraceAsString());
            error_log("Type string: '{$typeString}', Length: {$typeStringLength}");
            error_log("MySQL Error: " . $stmt->error);
            error_log("MySQL Error Code: " . $stmt->errno);
            $stmt->close();
            return null;
        } catch (Throwable $e) {
            error_log("=== CRITICAL ERROR: Throwable in bind_param ===");
            error_log("Error message: " . $e->getMessage());
            error_log("Error file: " . $e->getFile());
            error_log("Error line: " . $e->getLine());
            error_log("Type string: '{$typeString}', Length: {$typeStringLength}");
            $stmt->close();
            return null;
        }

        error_log("Executing INSERT statement...");
        $executeResult = $stmt->execute();
        if ($executeResult) {
            $batchId = (int)$this->db->insert_id;
            error_log("=== GridAllocationBatchTracker: Batch created successfully with ID: {$batchId} ===");
            $stmt->close();
            return $batchId;
        }

        error_log("=== CRITICAL ERROR: Failed to execute INSERT ===");
        error_log("MySQL Error: " . $stmt->error);
        error_log("MySQL Error Code: " . $stmt->errno);
        error_log("Database Error: " . $this->db->error);
        $stmt->close();
        return null;
    }

    /**
     * Update batch with allocation details after approval
     * 
     * @param int $batchId Batch ID
     * @param array $cellIds Array of cell_id strings
     * @param float $area Total area allocated
     * @param int $approvedByUser Admin user ID who approved
     * @return bool Success
     */
    public function approveBatch(int $batchId, array $cellIds, float $area, int $approvedByUser): bool
    {
        $cellIdsJson = json_encode($cellIds);
        $cellCount = count($cellIds);

        $sql = "
            UPDATE grid_allocation_batches SET
                approval_status = 'approved',
                approved_by_user_id = ?,
                approved_at = NOW(),
                allocated_cell_ids = ?,
                allocated_cell_count = ?,
                allocated_area = ?,
                allocation_date = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log("GridAllocationBatchTracker: Failed to prepare UPDATE - " . $this->db->error);
            return false;
        }

        $stmt->bind_param('isidi', $approvedByUser, $cellIdsJson, $cellCount, $area, $batchId);

        $success = $stmt->execute();
        if (!$success) {
            error_log("GridAllocationBatchTracker: Failed to execute UPDATE - " . $stmt->error);
        }

        $stmt->close();
        return $success;
    }

    /**
     * Reject a batch
     * 
     * @param int $batchId Batch ID
     * @return bool Success
     */
    public function rejectBatch(int $batchId): bool
    {
        $sql = "
            UPDATE grid_allocation_batches SET
                approval_status = 'rejected',
                rejected_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $batchId);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }

    /**
     * Get batch by new pledge/payment ID
     * 
     * @param int|null $pledgeId Pledge ID
     * @param int|null $paymentId Payment ID
     * @return array|null Batch data or null
     */
    public function getBatchByRequest(?int $pledgeId, ?int $paymentId): ?array
    {
        if ($pledgeId === null && $paymentId === null) {
            return null;
        }

        $whereClause = [];
        $params = [];
        $types = '';

        if ($pledgeId !== null) {
            $whereClause[] = "new_pledge_id = ?";
            $params[] = $pledgeId;
            $types .= 'i';
        }

        if ($paymentId !== null) {
            $whereClause[] = "new_payment_id = ?";
            $params[] = $paymentId;
            $types .= 'i';
        }

        $sql = "
            SELECT * FROM grid_allocation_batches
            WHERE (" . implode(' OR ', $whereClause) . ")
            AND approval_status = 'pending'
            ORDER BY request_date DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }

    /**
     * Get batches for a pledge (all updates)
     * 
     * @param int $pledgeId Original pledge ID
     * @return array Array of batch records
     */
    public function getBatchesForPledge(int $pledgeId): array
    {
        $sql = "
            SELECT * FROM grid_allocation_batches
            WHERE (original_pledge_id = ? OR new_pledge_id = ?)
            AND approval_status = 'approved'
            ORDER BY approved_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $pledgeId, $pledgeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result ?: [];
    }

    /**
     * Get batches for a payment
     * 
     * @param int $paymentId Payment ID
     * @return array Array of batch records
     */
    public function getBatchesForPayment(int $paymentId): array
    {
        $sql = "
            SELECT * FROM grid_allocation_batches
            WHERE (original_payment_id = ? OR new_payment_id = ?)
            AND approval_status = 'approved'
            ORDER BY approved_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $paymentId, $paymentId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result ?: [];
    }

    /**
     * Get the latest batch for a pledge (for undo operations)
     * 
     * @param int $pledgeId Pledge ID
     * @return array|null Latest batch or null
     */
    public function getLatestBatchForPledge(int $pledgeId): ?array
    {
        $sql = "
            SELECT * FROM grid_allocation_batches
            WHERE (original_pledge_id = ? OR new_pledge_id = ?)
            AND approval_status = 'approved'
            ORDER BY approved_at DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ii', $pledgeId, $pledgeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }

    /**
     * Deallocate cells for a specific batch
     * 
     * @param int $batchId Batch ID
     * @return array Result with success status and details
     */
    public function deallocateBatch(int $batchId): array
    {
        try {
            $this->db->begin_transaction();

            // Get batch details
            $batch = $this->getBatchById($batchId);
            if (!$batch) {
                throw new Exception("Batch not found: {$batchId}");
            }

            if ($batch['approval_status'] !== 'approved') {
                throw new Exception("Batch is not approved, cannot deallocate");
            }

            $cellIds = json_decode($batch['allocated_cell_ids'] ?? '[]', true);
            if (empty($cellIds)) {
                $this->db->commit();
                return ['success' => true, 'message' => 'No cells to deallocate', 'deallocated_cells' => []];
            }

            // Free the cells
            $placeholders = implode(',', array_fill(0, count($cellIds), '?'));
            $sql = "
                UPDATE floor_grid_cells
                SET
                    status = 'available',
                    pledge_id = NULL,
                    payment_id = NULL,
                    allocation_batch_id = NULL,
                    donor_name = NULL,
                    amount = NULL,
                    assigned_date = NULL
                WHERE cell_id IN ($placeholders) AND allocation_batch_id = ?
            ";

            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare deallocation query");
            }

            $params = array_merge($cellIds, [$batchId]);
            $types = str_repeat('s', count($cellIds)) . 'i';
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $deallocatedCount = $stmt->affected_rows;
            $stmt->close();

            // Mark batch as cancelled
            $updateBatch = $this->db->prepare("
                UPDATE grid_allocation_batches SET
                    approval_status = 'cancelled',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateBatch->bind_param('i', $batchId);
            $updateBatch->execute();
            $updateBatch->close();

            $this->db->commit();

            return [
                'success' => true,
                'message' => "Successfully deallocated {$deallocatedCount} grid cell(s) from batch #{$batchId}",
                'deallocated_cells' => $cellIds,
                'batch_id' => $batchId,
                'deallocated_count' => $deallocatedCount
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("GridAllocationBatchTracker Deallocation Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get batch by ID
     * 
     * @param int $batchId Batch ID
     * @return array|null Batch data or null
     */
    private function getBatchById(int $batchId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM grid_allocation_batches WHERE id = ?");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }
}

