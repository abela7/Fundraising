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
        $sql = "
            INSERT INTO grid_allocation_batches (
                batch_type, request_type, original_pledge_id, original_payment_id,
                new_pledge_id, new_payment_id, donor_id, donor_name, donor_phone,
                original_amount, additional_amount, total_amount,
                requested_by_user_id, requested_by_donor_id, request_source,
                request_date, approval_status, package_id, metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)
        ";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log("GridAllocationBatchTracker: Failed to prepare INSERT - " . $this->db->error);
            return null;
        }

        $metadataJson = isset($data['metadata']) ? json_encode($data['metadata']) : null;

        // Extract values into variables for bind_param (must be variables, not expressions)
        $batch_type = $data['batch_type'];
        $request_type = $data['request_type'];
        $original_pledge_id = $data['original_pledge_id'] ?? null;
        $original_payment_id = $data['original_payment_id'] ?? null;
        $new_pledge_id = $data['new_pledge_id'] ?? null;
        $new_payment_id = $data['new_payment_id'] ?? null;
        $donor_id = $data['donor_id'] ?? null;
        $donor_name = $data['donor_name'];
        $donor_phone = $data['donor_phone'] ?? null;
        $original_amount = $data['original_amount'] ?? 0.00;
        $additional_amount = $data['additional_amount'];
        $total_amount = $data['total_amount'];
        $requested_by_user_id = $data['requested_by_user_id'] ?? null;
        $requested_by_donor_id = $data['requested_by_donor_id'] ?? null;
        $request_source = $data['request_source'] ?? 'volunteer';
        $request_date = $data['request_date'] ?? date('Y-m-d H:i:s');
        $package_id = $data['package_id'] ?? null;

        // Type string: s=string, i=integer, d=double
        // Parameters in order (18 total):
        //  1. batch_type(s), 2. request_type(s), 3. original_pledge_id(i), 4. original_payment_id(i),
        //  5. new_pledge_id(i), 6. new_payment_id(i), 7. donor_id(i), 8. donor_name(s), 9. donor_phone(s),
        //  10. original_amount(d), 11. additional_amount(d), 12. total_amount(d),
        //  13. requested_by_user_id(i), 14. requested_by_donor_id(i), 15. request_source(s), 16. request_date(s),
        //  17. package_id(i), 18. metadataJson(s)
        // Type string must match exactly 18 parameters
        // Breakdown: ss(2) + iiiii(5) + ss(2) + ddd(3) + ii(2) + ss(2) + i(1) + s(1) = 18 chars
        // Original had 'sss' (3) but we only have 2 strings (donor_name, donor_phone), so it's 'ss'
        $stmt->bind_param(
            'ssiiiiissdddiissis',
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

        if ($stmt->execute()) {
            $batchId = (int)$this->db->insert_id;
            $stmt->close();
            return $batchId;
        }

        error_log("GridAllocationBatchTracker: Failed to execute INSERT - " . $stmt->error);
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

