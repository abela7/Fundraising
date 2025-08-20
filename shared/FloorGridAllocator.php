<?php
declare(strict_types=1);

/**
 * Floor Grid Allocator
 * Handles smart allocation of floor grid cells based on donation amounts and package types
 */
class FloorGridAllocator {
    private mysqli $db;
    
    // Rectangle dimensions for 513m² floor plan (7 rectangles: A-G)
    // Based on your CSS grid system: 41 columns × 20 rows in 0.5m units
    private array $rectangleConfig = [
        'A' => ['start_col' => 1, 'end_col' => 9, 'start_row' => 5, 'end_row' => 16],   // 9×12 = 108 cells = 27m²
        'B' => ['start_col' => 1, 'end_col' => 3, 'start_row' => 17, 'end_row' => 19], // 3×3 = 9 cells = 2.25m²
        'C' => ['start_col' => 10, 'end_col' => 11, 'start_row' => 9, 'end_row' => 16], // 2×8 = 16 cells = 4m²
        'D' => ['start_col' => 24, 'end_col' => 33, 'start_row' => 5, 'end_row' => 16], // 10×12 = 120 cells = 30m²
        'E' => ['start_col' => 12, 'end_col' => 23, 'start_row' => 7, 'end_row' => 16], // 12×10 = 120 cells = 30m²
        'F' => ['start_col' => 34, 'end_col' => 38, 'start_row' => 2, 'end_row' => 5],  // 5×4 = 20 cells = 5m²
        'G' => ['start_col' => 34, 'end_col' => 41, 'start_row' => 6, 'end_row' => 20]  // 8×15 = 120 cells = 30m²
    ];
    
    public function __construct(mysqli $db) {
        $this->db = $db;
    }
    
    /**
     * Allocate grid cells for a donation/pledge
     * 
     * @param int $pledgeId Pledge ID (null for payments)
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
            
            // Determine area size based on package or amount
            $areaSize = $this->calculateAreaSize($amount, $packageId);
            
            if ($areaSize <= 0) {
                throw new RuntimeException("Invalid area size calculated: {$areaSize}");
            }
            
            // Find and allocate available cells
            $allocatedCells = $this->findAndAllocateCells($areaSize, $pledgeId, $paymentId, $donorName, $amount, $status);
            
            // Record allocation in floor_area_allocations table
            $this->recordAllocation(
                $pledgeId ? 'pledge' : 'payment',
                $pledgeId ?? $paymentId,
                $donorName,
                $packageId,
                $amount,
                $areaSize,
                $allocatedCells,
                $status
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'area_allocated' => $areaSize,
                'cells_allocated' => count($allocatedCells),
                'allocated_cells' => $allocatedCells
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("FloorGridAllocator Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'area_allocated' => 0,
                'cells_allocated' => 0
            ];
        }
    }
    
    /**
     * Calculate area size based on amount and package
     */
    private function calculateAreaSize(float $amount, ?int $packageId): float {
        // Package-based allocation
        if ($packageId) {
            $stmt = $this->db->prepare("SELECT sqm_meters FROM donation_packages WHERE id = ? AND active = 1");
            $stmt->bind_param('i', $packageId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            if ($result && $result['sqm_meters'] > 0) {
                return (float)$result['sqm_meters'];
            }
            
            // Package ID 4 (Custom) - threshold-based allocation
            if ($packageId === 4) {
                return $this->calculateCustomAreaSize($amount);
            }
        }
        
        // Fallback: amount-based calculation (£400 per m²)
        return max(0.25, $amount / 400.0); // Minimum 0.25m²
    }
    
    /**
     * Custom package threshold-based area calculation
     */
    private function calculateCustomAreaSize(float $amount): float {
        if ($amount >= 400) {
            // £400+ = 1m² + extra allocated separately
            return 1.0;
        } elseif ($amount >= 200) {
            // £200-£399 = 0.5m²
            return 0.5;
        } elseif ($amount >= 100) {
            // £100-£199 = 0.25m²
            return 0.25;
        } else {
            // Under £100 = proportional allocation
            return max(0.1, $amount / 400.0);
        }
    }
    
    /**
     * Find and allocate available cells using "water-filling" approach
     */
    private function findAndAllocateCells(
        float $areaSize, 
        ?int $pledgeId, 
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): array {
        $cellsNeeded = $this->calculateCellsNeeded($areaSize);
        $allocatedCells = [];
        
        // Try to allocate in optimal patterns
        foreach (['1x1', '1x0.5', '0.5x1', '0.5x0.5'] as $cellSize) {
            if (count($allocatedCells) >= $cellsNeeded) break;
            
            $remaining = $cellsNeeded - count($allocatedCells);
            $allocatedCells = array_merge(
                $allocatedCells,
                $this->allocateCellsBySize($cellSize, $remaining, $pledgeId, $paymentId, $donorName, $amount, $status)
            );
        }
        
        if (count($allocatedCells) < $cellsNeeded) {
            throw new RuntimeException("Could not allocate enough cells. Needed: {$cellsNeeded}, Found: " . count($allocatedCells));
        }
        
        return array_slice($allocatedCells, 0, $cellsNeeded);
    }
    
    /**
     * Calculate number of 0.5x0.5m cells needed
     */
    private function calculateCellsNeeded(float $areaSize): int {
        // Each 0.5x0.5m cell = 0.25m²
        return (int)ceil($areaSize / 0.25);
    }
    
    /**
     * Allocate cells of specific size
     */
    private function allocateCellsBySize(
        string $cellSize,
        int $maxCells,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): array {
        if ($maxCells <= 0) return [];
        
        $allocated = [];
        
        // Sequential allocation through rectangles A-G
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $rectId) {
            if (count($allocated) >= $maxCells) break;
            
            $rectCells = $this->allocateInRectangle(
                $rectId, 
                $cellSize, 
                $maxCells - count($allocated),
                $pledgeId,
                $paymentId,
                $donorName,
                $amount,
                $status
            );
            
            $allocated = array_merge($allocated, $rectCells);
        }
        
        return $allocated;
    }
    
    /**
     * Allocate cells within a specific rectangle
     */
    private function allocateInRectangle(
        string $rectId,
        string $cellSize,
        int $maxCells,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): array {
        if ($maxCells <= 0) return [];
        
        $config = $this->rectangleConfig[$rectId];
        $allocated = [];
        
        // Find available cells in this rectangle
        for ($row = $config['start_row']; $row <= $config['end_row'] && count($allocated) < $maxCells; $row++) {
            for ($col = $config['start_col']; $col <= $config['end_col'] && count($allocated) < $maxCells; $col++) {
                
                // Check if cell is available
                if ($this->isCellAvailable($rectId, $col, $row)) {
                    // Allocate the cell
                    $cellId = $this->insertGridCell(
                        $rectId, $col, $row, $cellSize, $status,
                        $pledgeId, $paymentId, $donorName, $amount
                    );
                    
                    if ($cellId) {
                        $allocated[] = [
                            'id' => $cellId,
                            'rectangle_id' => $rectId,
                            'grid_x' => $col,
                            'grid_y' => $row,
                            'cell_size' => $cellSize
                        ];
                    }
                }
            }
        }
        
        return $allocated;
    }
    
    /**
     * Check if a grid cell is available
     */
    private function isCellAvailable(string $rectId, int $gridX, int $gridY): bool {
        $stmt = $this->db->prepare(
            "SELECT id FROM floor_grid_cells 
             WHERE rectangle_id = ? AND grid_x = ? AND grid_y = ? AND status != 'available'"
        );
        $stmt->bind_param('sii', $rectId, $gridX, $gridY);
        $stmt->execute();
        
        return $stmt->get_result()->num_rows === 0;
    }
    
    /**
     * Insert a new grid cell allocation
     */
    private function insertGridCell(
        string $rectId,
        int $gridX,
        int $gridY,
        string $cellSize,
        string $status,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount
    ): ?int {
        $stmt = $this->db->prepare(
            "INSERT INTO floor_grid_cells 
             (rectangle_id, grid_x, grid_y, cell_size, status, pledge_id, payment_id, donor_name, amount, assigned_date) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
             status = VALUES(status),
             pledge_id = VALUES(pledge_id),
             payment_id = VALUES(payment_id),
             donor_name = VALUES(donor_name),
             amount = VALUES(amount),
             assigned_date = VALUES(assigned_date)"
        );
        
        $stmt->bind_param('siissiissd', $rectId, $gridX, $gridY, $cellSize, $status, $pledgeId, $paymentId, $donorName, $amount);
        
        if ($stmt->execute()) {
            return $this->db->insert_id ?: $this->getExistingCellId($rectId, $gridX, $gridY);
        }
        
        return null;
    }
    
    /**
     * Get existing cell ID for duplicate key updates
     */
    private function getExistingCellId(string $rectId, int $gridX, int $gridY): ?int {
        $stmt = $this->db->prepare(
            "SELECT id FROM floor_grid_cells WHERE rectangle_id = ? AND grid_x = ? AND grid_y = ?"
        );
        $stmt->bind_param('sii', $rectId, $gridX, $gridY);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result ? (int)$result['id'] : null;
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
        $gridCellsJson = json_encode($allocatedCells);
        $currentStatus = $status === 'paid' ? 'approved' : 'pending';
        
        $stmt = $this->db->prepare(
            "INSERT INTO floor_area_allocations 
             (allocation_type, donor_id, donor_name, package_id, amount, area_size, grid_cells, status, allocated_date) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        
        $stmt->bind_param('sisidds s', $allocationType, $recordId, $donorName, $packageId, $amount, $areaSize, $gridCellsJson, $currentStatus);
        $stmt->execute();
    }
    
    /**
     * Get current grid status for visualization
     */
    public function getGridStatus(): array {
        $stmt = $this->db->prepare(
            "SELECT rectangle_id, grid_x, grid_y, cell_size, status, donor_name, amount, assigned_date
             FROM floor_grid_cells 
             WHERE status != 'available'
             ORDER BY rectangle_id, grid_y, grid_x"
        );
        
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
                SUM(CASE WHEN status != 'available' THEN amount ELSE 0 END) as total_amount
             FROM floor_grid_cells"
        );
        
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        
        return [
            'total_cells' => (int)$stats['total_cells'],
            'pledged_cells' => (int)$stats['pledged_cells'],
            'paid_cells' => (int)$stats['paid_cells'],
            'available_cells' => (int)$stats['available_cells'],
            'total_area_pledged' => $stats['pledged_cells'] * 0.25,
            'total_area_paid' => $stats['paid_cells'] * 0.25,
            'total_amount' => (float)$stats['total_amount'],
            'progress_percentage' => $stats['total_cells'] > 0 ? 
                (($stats['pledged_cells'] + $stats['paid_cells']) / $stats['total_cells']) * 100 : 0
        ];
    }
}
