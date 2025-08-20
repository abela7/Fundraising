<?php
declare(strict_types=1);

/**
 * Floor Grid Allocator V2
 * Works with the actual cell IDs from your existing floor plan
 * Handles allocation based on donation packages: 1m², 0.5m², 0.25m², custom
 */
class FloorGridAllocatorV2 {
    private mysqli $db;
    
    public function __construct(mysqli $db) {
        $this->db = $db;
    }
    
    /**
     * Allocate grid cells for a donation/pledge using your exact cell IDs
     * 
     * @param int|null $pledgeId Pledge ID (null for payments)
     * @param int|null $paymentId Payment ID (null for pledges) 
     * @param float $amount Donation amount
     * @param int|null $packageId Package ID (1=1m², 2=0.5m², 3=0.25m², 4=custom)
     * @param string $donorName Donor name
     * @param string $status 'pledged' or 'paid'
     * @return array Result with success status and allocated cells
     */
    public function allocateGridCells(
        ?int $pledgeId,
        ?int $paymentId,
        float $amount,
        ?int $packageId,
        string $donorName,
        string $status
    ): array {
        try {
            $this->db->begin_transaction();
            
            // Determine which cells to allocate based on package/amount
            $cellsToAllocate = $this->determineCellsNeeded($amount, $packageId);
            
            if (empty($cellsToAllocate)) {
                throw new RuntimeException("No cells determined for allocation. Amount: {$amount}, Package: {$packageId}");
            }
            
            // Find and allocate the required cells
            $allocatedCells = $this->findAndAllocateAvailableCells(
                $cellsToAllocate,
                $pledgeId,
                $paymentId,
                $donorName,
                $amount,
                $status
            );
            
            if (empty($allocatedCells)) {
                throw new RuntimeException("No available cells found for allocation");
            }
            
            // Record the allocation
            $this->recordAllocation(
                $pledgeId ? 'pledge' : 'payment',
                $pledgeId ?? $paymentId,
                $donorName,
                $packageId,
                $amount,
                array_sum(array_column($allocatedCells, 'area_size')),
                $allocatedCells,
                $status
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'area_allocated' => array_sum(array_column($allocatedCells, 'area_size')),
                'cells_allocated' => count($allocatedCells),
                'allocated_cells' => $allocatedCells
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("FloorGridAllocatorV2 Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'area_allocated' => 0,
                'cells_allocated' => 0
            ];
        }
    }
    
    /**
     * Determine which cell types/quantities are needed
     */
    private function determineCellsNeeded(float $amount, ?int $packageId): array {
        // Package-based allocation
        if ($packageId) {
            switch ($packageId) {
                case 1: // 1m² package (£400)
                    return [['type' => '1x1', 'quantity' => 1]];
                    
                case 2: // 0.5m² package (£200)  
                    return [['type' => '1x0.5', 'quantity' => 1]];
                    
                case 3: // 0.25m² package (£100)
                    return [['type' => '0.5x0.5', 'quantity' => 1]];
                    
                case 4: // Custom package - threshold-based
                    return $this->calculateCustomAllocation($amount);
                    
                default:
                    break;
            }
        }
        
        // Amount-based allocation (fallback)
        return $this->calculateAmountBasedAllocation($amount);
    }
    
    /**
     * Custom package threshold-based allocation
     */
    private function calculateCustomAllocation(float $amount): array {
        if ($amount >= 400) {
            // £400+ = 1m² 
            return [['type' => '1x1', 'quantity' => 1]];
        } elseif ($amount >= 200) {
            // £200-£399 = 0.5m²
            return [['type' => '1x0.5', 'quantity' => 1]];
        } elseif ($amount >= 100) {
            // £100-£199 = 0.25m²
            return [['type' => '0.5x0.5', 'quantity' => 1]];
        } else {
            // Under £100 = proportional 0.25m² cells
            $cellsNeeded = max(1, (int)ceil($amount / 100));
            return [['type' => '0.5x0.5', 'quantity' => $cellsNeeded]];
        }
    }
    
    /**
     * Amount-based allocation (£400 per m²)
     */
    private function calculateAmountBasedAllocation(float $amount): array {
        $totalArea = $amount / 400.0; // £400 per m²
        $allocation = [];
        
        // Optimize allocation: prefer larger cells first
        $full1m = (int)floor($totalArea);
        $remaining = $totalArea - $full1m;
        
        if ($full1m > 0) {
            $allocation[] = ['type' => '1x1', 'quantity' => $full1m];
        }
        
        if ($remaining >= 0.5) {
            $allocation[] = ['type' => '1x0.5', 'quantity' => 1];
            $remaining -= 0.5;
        }
        
        if ($remaining >= 0.25) {
            $cellsNeeded = (int)ceil($remaining / 0.25);
            $allocation[] = ['type' => '0.5x0.5', 'quantity' => $cellsNeeded];
        }
        
        return $allocation;
    }
    
    /**
     * Find and allocate available cells of specified types
     */
    private function findAndAllocateAvailableCells(
        array $cellsNeeded,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): array {
        $allocatedCells = [];
        
        foreach ($cellsNeeded as $cellSpec) {
            $cellType = $cellSpec['type'];
            $quantity = $cellSpec['quantity'];
            
            // Find available cells of this type
            $stmt = $this->db->prepare(
                "SELECT cell_id, rectangle_id, area_size 
                 FROM floor_grid_cells 
                 WHERE cell_type = ? AND status = 'available' 
                 ORDER BY rectangle_id, cell_id 
                 LIMIT ?"
            );
            $stmt->bind_param('si', $cellType, $quantity);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $foundCells = [];
            while ($row = $result->fetch_assoc()) {
                $foundCells[] = $row;
            }
            
            if (count($foundCells) < $quantity) {
                throw new RuntimeException("Insufficient {$cellType} cells available. Needed: {$quantity}, Found: " . count($foundCells));
            }
            
            // Allocate the found cells
            foreach (array_slice($foundCells, 0, $quantity) as $cell) {
                $this->allocateCell(
                    $cell['cell_id'],
                    $pledgeId,
                    $paymentId,
                    $donorName,
                    $amount,
                    $status
                );
                
                $allocatedCells[] = [
                    'cell_id' => $cell['cell_id'],
                    'rectangle_id' => $cell['rectangle_id'],
                    'cell_type' => $cellType,
                    'area_size' => (float)$cell['area_size']
                ];
            }
        }
        
        return $allocatedCells;
    }
    
    /**
     * Allocate a specific cell
     */
    private function allocateCell(
        string $cellId,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE floor_grid_cells 
             SET status = ?, pledge_id = ?, payment_id = ?, donor_name = ?, amount = ?, assigned_date = NOW()
             WHERE cell_id = ? AND status = 'available'"
        );
        
        $stmt->bind_param('siisds', $status, $pledgeId, $paymentId, $donorName, $amount, $cellId);
        
        if (!$stmt->execute() || $stmt->affected_rows === 0) {
            throw new RuntimeException("Failed to allocate cell: {$cellId}");
        }
    }
    
    /**
     * Record allocation in tracking table
     */
    private function recordAllocation(
        string $allocationType,
        ?int $recordId,
        string $donorName,
        ?int $packageId,
        float $amount,
        float $areaSize,
        array $allocatedCells,
        string $status
    ): void {
        $cellIds = array_column($allocatedCells, 'cell_id');
        $cellIdsJson = json_encode($cellIds);
        $gridCellsJson = json_encode($allocatedCells);
        $currentStatus = $status === 'paid' ? 'approved' : 'pending';
        
        $stmt = $this->db->prepare(
            "INSERT INTO floor_area_allocations 
             (allocation_type, donor_id, donor_name, package_id, amount, area_size, grid_cells, cell_ids, status, allocated_date) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        $stmt->bind_param('sisiddss s', $allocationType, $recordId, $donorName, $packageId, $amount, $areaSize, $gridCellsJson, $cellIdsJson, $currentStatus);
        $stmt->execute();
    }
    
    /**
     * Get current grid status for visualization
     */
    public function getGridStatus(?string $rectangleId = null): array {
        $sql = "SELECT cell_id, rectangle_id, cell_type, area_size, status, donor_name, amount, assigned_date
                FROM floor_grid_cells 
                WHERE status != 'available'";
        
        $params = [];
        $types = "";
        
        if ($rectangleId) {
            $sql .= " AND rectangle_id = ?";
            $params[] = $rectangleId;
            $types .= "s";
        }
        
        $sql .= " ORDER BY rectangle_id, cell_id";
        
        $stmt = $this->db->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $gridStatus = [];
        while ($row = $result->fetch_assoc()) {
            $gridStatus[] = $row;
        }
        
        return $gridStatus;
    }
    
    /**
     * Get allocation statistics
     */
    public function getAllocationStats(): array {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total_cells,
                SUM(CASE WHEN status = 'pledged' THEN 1 ELSE 0 END) as pledged_cells,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_cells,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_cells,
                SUM(CASE WHEN status = 'pledged' THEN area_size ELSE 0 END) as pledged_area,
                SUM(CASE WHEN status = 'paid' THEN area_size ELSE 0 END) as paid_area,
                SUM(CASE WHEN status != 'available' THEN amount ELSE 0 END) as total_amount
             FROM floor_grid_cells"
        );
        
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        
        $totalCells = (int)$stats['total_cells'];
        $pledgedCells = (int)$stats['pledged_cells'];
        $paidCells = (int)$stats['paid_cells'];
        $availableCells = (int)$stats['available_cells'];
        
        return [
            'total_cells' => $totalCells,
            'pledged_cells' => $pledgedCells,
            'paid_cells' => $paidCells,
            'available_cells' => $availableCells,
            'total_area_pledged' => (float)$stats['pledged_area'],
            'total_area_paid' => (float)$stats['paid_area'],
            'total_area' => $totalCells > 0 ? $totalCells * 0.25 : 0, // Assuming average 0.25m² per cell
            'total_amount' => (float)$stats['total_amount'],
            'progress_percentage' => $totalCells > 0 ? 
                (($pledgedCells + $paidCells) / $totalCells) * 100 : 0
        ];
    }
}
