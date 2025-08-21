<?php
declare(strict_types=1);

/**
 * Intelligent Grid Allocator (Version 3)
 *
 * This allocator implements a "space-filling" algorithm. It treats donations as an area
 * to be allocated and fills the smallest available grid units (0.5m x 0.5m) sequentially
 * across the entire floor plan. This prevents gaps and ensures an organic fill pattern.
 */
class IntelligentGridAllocator
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Allocates a specific area based on the donation amount.
     *
     * @param int|null $pledgeId The ID of the pledge, if applicable.
     * @param int|null $paymentId The ID of the payment, if applicable.
     * @param float $amount The donation amount.
     * @param int|null $packageId The donation package ID, if applicable.
     * @param string $donorName The name of the donor.
     * @param string $status The status of the allocation ('pledged' or 'paid').
     * @return array The result of the allocation.
     */
    public function allocate(
        ?int $pledgeId,
        ?int $paymentId,
        float $amount,
        ?int $packageId,
        string $donorName,
        string $status
    ): array {
        // Step 1: Determine the number of 0.25m² blocks to allocate
        $blocksToAllocate = $this->getBlocksForAmount($amount, $packageId);

        if ($blocksToAllocate === 0) {
            return ['success' => true, 'message' => 'No allocation needed for this amount.', 'allocated_cells' => []];
        }

        try {
            $this->db->begin_transaction();

            // Step 2: Find the required number of available 0.5x0.5 cells sequentially
            $availableCells = $this->findAvailableQuarterCells($blocksToAllocate);

            if (count($availableCells) < $blocksToAllocate) {
                throw new RuntimeException("Allocation failed: Not enough space available on the floor plan.");
            }

            // Step 3: Update the found cells in the database
            $cellIds = array_column($availableCells, 'cell_id');
            $this->updateCells($cellIds, $pledgeId, $paymentId, $donorName, $amount / $blocksToAllocate, $status);

            $this->db->commit();

            return [
                'success' => true,
                'message' => "Successfully allocated {$blocksToAllocate} grid cell(s).",
                'area_allocated' => $blocksToAllocate * 0.25,
                'allocated_cells' => $cellIds,
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridAllocator Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calculates how many 0.25m² blocks a donation is worth.
     */
    private function getBlocksForAmount(float $amount, ?int $packageId): int
    {
        // Prioritize package ID if provided
        if ($packageId) {
            $stmt = $this->db->prepare("SELECT price, sqm_meters FROM donation_packages WHERE id = ?");
            $stmt->bind_param('i', $packageId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            if ($result) {
                // Use package area, ensuring it's at least the amount's worth for custom packages
                $area = max((float)$result['sqm_meters'], floor($amount / 100) * 0.25);
                return (int)round($area / 0.25);
            }
        }
        
        // Fallback to amount-based calculation (£100 = 0.25m²)
        return (int)floor($amount / 100);
    }

    /**
     * Finds the next available 0.5x0.5m cells in strict sequential order.
     */
    private function findAvailableQuarterCells(int $limit): array
    {
        // The ORDER BY clause ensures a strict, predictable filling order.
        $sql = "
            SELECT cell_id
            FROM floor_grid_cells
            WHERE status = 'available' AND cell_type = '0.5x0.5'
            ORDER BY
                rectangle_id ASC,
                CAST(SUBSTRING_INDEX(cell_id, '-', -1) AS UNSIGNED) ASC
            LIMIT ?
            FOR UPDATE
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Updates a list of cells to mark them as allocated.
     */
    private function updateCells(array $cellIds, ?int $pledgeId, ?int $paymentId, string $donorName, float $amountPerBlock, string $status): void
    {
        $placeholders = implode(',', array_fill(0, count($cellIds), '?'));
        
        $sql = "
            UPDATE floor_grid_cells
            SET
                status = ?,
                pledge_id = ?,
                payment_id = ?,
                donor_name = ?,
                amount = ?,
                assigned_date = NOW()
            WHERE cell_id IN ($placeholders)
        ";
        
        $stmt = $this->db->prepare($sql);
        
        // Dynamically bind parameters
        $types = 'siisd' . str_repeat('s', count($cellIds));
        $params = array_merge([$status, $pledgeId, $paymentId, $donorName, $amountPerBlock], $cellIds);
        $stmt->bind_param($types, ...$params);
        
        $stmt->execute();
    }
    
    /**
     * Retrieves the status of all allocated cells for frontend display.
     */
    public function getGridStatus(): array
    {
        $sql = "
            SELECT cell_id, rectangle_id, cell_type, area_size, status, donor_name, amount, assigned_date
            FROM floor_grid_cells 
            WHERE status IN ('pledged', 'paid')
            ORDER BY rectangle_id, assigned_date
        ";
        
        $result = $this->db->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Deallocates cells for a specific donation when it's unapproved.
     * 
     * @param int|null $pledgeId The pledge ID to deallocate (if pledge)
     * @param int|null $paymentId The payment ID to deallocate (if payment)
     * @return array Result of the deallocation
     */
    public function deallocate(?int $pledgeId, ?int $paymentId): array
    {
        try {
            $this->db->begin_transaction();
            
            // Find all cells allocated to this donation
            $allocatedCells = $this->findAllocatedCells($pledgeId, $paymentId);
            
            if (empty($allocatedCells)) {
                $this->db->commit();
                return ['success' => true, 'message' => 'No cells were allocated for this donation.', 'deallocated_cells' => []];
            }
            
            // Free up the allocated cells
            $this->freeCells($pledgeId, $paymentId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Successfully deallocated " . count($allocatedCells) . " grid cell(s).",
                'deallocated_cells' => array_column($allocatedCells, 'cell_id'),
                'area_deallocated' => count($allocatedCells) * 0.25
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridAllocator Deallocation Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Finds all cells allocated to a specific donation.
     */
    private function findAllocatedCells(?int $pledgeId, ?int $paymentId): array
    {
        // Build the WHERE condition based on what IDs we have
        $whereConditions = [];
        $params = [];
        $types = '';
        
        if ($pledgeId !== null) {
            $whereConditions[] = "pledge_id = ?";
            $params[] = $pledgeId;
            $types .= 'i';
        }
        
        if ($paymentId !== null) {
            $whereConditions[] = "payment_id = ?";
            $params[] = $paymentId;
            $types .= 'i';
        }
        
        if (empty($whereConditions)) {
            return []; // No IDs provided
        }
        
        $whereClause = implode(' OR ', $whereConditions);
        
        $sql = "
            SELECT cell_id, status, pledge_id, payment_id, donor_name, amount
            FROM floor_grid_cells
            WHERE ($whereClause) AND status IN ('pledged', 'paid')
            ORDER BY cell_id ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Frees up cells by resetting them to available status.
     */
    private function freeCells(?int $pledgeId, ?int $paymentId): void
    {
        // Build the WHERE condition based on what IDs we have
        $whereConditions = [];
        $params = [];
        $types = '';
        
        if ($pledgeId !== null) {
            $whereConditions[] = "pledge_id = ?";
            $params[] = $pledgeId;
            $types .= 'i';
        }
        
        if ($paymentId !== null) {
            $whereConditions[] = "payment_id = ?";
            $params[] = $paymentId;
            $types .= 'i';
        }
        
        if (empty($whereConditions)) {
            return; // No IDs provided
        }
        
        $whereClause = implode(' OR ', $whereConditions);
        
        $sql = "
            UPDATE floor_grid_cells
            SET
                status = 'available',
                pledge_id = NULL,
                payment_id = NULL,
                donor_name = NULL,
                amount = NULL,
                assigned_date = NULL
            WHERE ($whereClause) AND status IN ('pledged', 'paid')
        ";
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
    }

    /**
     * Retrieves overall allocation statistics.
     */
    public function getAllocationStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total_cells,
                SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged_cells,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_cells,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_cells,
                SUM(CASE WHEN status IN ('pledged', 'paid') THEN area_size ELSE 0 END) as total_allocated_area,
                SUM(area_size) as total_possible_area
             FROM floor_grid_cells
             WHERE cell_type = '0.5x0.5'"
        );
        
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
