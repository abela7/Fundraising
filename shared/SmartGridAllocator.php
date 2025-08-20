<?php
declare(strict_types=1);

/**
 * Smart Grid Allocator with Overlap Management
 * 
 * Handles your brilliant hierarchical grid system:
 * - 1m×1m (A0101-XX) covers 1m² = £400
 * - 1m×0.5m (A0105-XX) covers 0.5m² = £200  
 * - 0.5m×0.5m (A0505-XX) covers 0.25m² = £100
 * 
 * Prevents conflicts and handles smart overlap logic
 */
class SmartGridAllocator {
    private mysqli $db;
    
    public function __construct(mysqli $db) {
        $this->db = $db;
    }
    
    /**
     * Main allocation method with intelligent overlap handling
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
            
            // Determine what to allocate
            $allocationStrategy = $this->determineAllocationStrategy($amount, $packageId);
            
            // Find available space with conflict detection
            $allocatedCells = $this->findAndAllocateWithConflictDetection(
                $allocationStrategy,
                $pledgeId,
                $paymentId,
                $donorName,
                $amount,
                $status
            );
            
            $this->db->commit();
            
            return [
                'success' => true,
                'area_allocated' => $allocationStrategy['area'],
                'cells_allocated' => count($allocatedCells),
                'allocated_cells' => $allocatedCells,
                'strategy' => $allocationStrategy
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("SmartGridAllocator Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'area_allocated' => 0,
                'cells_allocated' => 0
            ];
        }
    }
    
    /**
     * Determine optimal allocation strategy
     */
    private function determineAllocationStrategy(float $amount, ?int $packageId): array {
        if ($packageId) {
            switch ($packageId) {
                case 1: // £400 - 1m²
                    return ['type' => '1x1', 'area' => 1.0, 'pattern' => '0101'];
                case 2: // £200 - 0.5m²  
                    return ['type' => '1x0.5', 'area' => 0.5, 'pattern' => '0105'];
                case 3: // £100 - 0.25m²
                    return ['type' => '0.5x0.5', 'area' => 0.25, 'pattern' => '0505'];
                case 4: // Custom - smart allocation
                    return $this->calculateCustomStrategy($amount);
            }
        }
        
        // Fallback: amount-based
        if ($amount >= 400) {
            return ['type' => '1x1', 'area' => 1.0, 'pattern' => '0101'];
        } elseif ($amount >= 200) {
            return ['type' => '1x0.5', 'area' => 0.5, 'pattern' => '0105'];
        } else {
            return ['type' => '0.5x0.5', 'area' => 0.25, 'pattern' => '0505'];
        }
    }
    
    /**
     * Custom allocation strategy with smart thresholds
     */
    private function calculateCustomStrategy(float $amount): array {
        if ($amount >= 400) {
            return ['type' => '1x1', 'area' => 1.0, 'pattern' => '0101'];
        } elseif ($amount >= 200) {
            return ['type' => '1x0.5', 'area' => 0.5, 'pattern' => '0105'];
        } elseif ($amount >= 100) {
            return ['type' => '0.5x0.5', 'area' => 0.25, 'pattern' => '0505'];
        } else {
            // Micro donations - still get 0.25m²
            return ['type' => '0.5x0.5', 'area' => 0.25, 'pattern' => '0505'];
        }
    }
    
    /**
     * Find and allocate with intelligent conflict detection
     */
    private function findAndAllocateWithConflictDetection(
        array $strategy,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): array {
        $pattern = $strategy['pattern'];
        $cellType = $strategy['type'];
        
        // Get available cells in smart order
        $availableCells = $this->getAvailableCellsInOrder($pattern, $cellType);
        
        foreach ($availableCells as $cellCandidate) {
            // Check for conflicts with this cell
            if ($this->isAllocationConflictFree($cellCandidate, $strategy)) {
                // Allocate the cell
                $allocatedCell = $this->allocateSpecificCell(
                    $cellCandidate,
                    $strategy,
                    $pledgeId,
                    $paymentId,
                    $donorName,
                    $amount,
                    $status
                );
                
                if ($allocatedCell) {
                    // Block conflicting cells
                    $this->blockConflictingCells($cellCandidate, $strategy, $status);
                    
                    return [$allocatedCell];
                }
            }
        }
        
        throw new RuntimeException("No conflict-free space available for {$cellType} allocation");
    }
    
    /**
     * Get available cells in smart order (starting from A0505-185 for 0.5x0.5)
     */
    private function getAvailableCellsInOrder(string $pattern, string $cellType): array {
        $orderClause = $this->getSmartOrderClause($pattern);
        
        $stmt = $this->db->prepare(
            "SELECT cell_id, rectangle_id, area_size 
             FROM floor_grid_cells 
             WHERE cell_id LIKE ? AND status = 'available'
             ORDER BY {$orderClause}
             LIMIT 50"
        );
        
        $searchPattern = "%{$pattern}-%";
        $stmt->bind_param('s', $searchPattern);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Smart ordering for natural allocation flow
     */
    private function getSmartOrderClause(string $pattern): string {
        switch ($pattern) {
            case '0505': // 0.5x0.5 cells - start from A0505-185
                return "
                    CASE 
                        WHEN rectangle_id = 'A' THEN 1
                        WHEN rectangle_id = 'B' THEN 2
                        WHEN rectangle_id = 'C' THEN 3
                        WHEN rectangle_id = 'D' THEN 4
                        WHEN rectangle_id = 'E' THEN 5
                        WHEN rectangle_id = 'F' THEN 6
                        WHEN rectangle_id = 'G' THEN 7
                        ELSE 8
                    END,
                    CASE 
                        WHEN rectangle_id = 'A' AND CAST(SUBSTRING(cell_id, 7) AS UNSIGNED) >= 185 
                        THEN CAST(SUBSTRING(cell_id, 7) AS UNSIGNED)
                        WHEN rectangle_id = 'A' AND CAST(SUBSTRING(cell_id, 7) AS UNSIGNED) < 185 
                        THEN CAST(SUBSTRING(cell_id, 7) AS UNSIGNED) + 10000
                        ELSE CAST(SUBSTRING(cell_id, 7) AS UNSIGNED)
                    END
                ";
                
            case '0105': // 1x0.5 cells  
            case '0101': // 1x1 cells
            default:
                return "
                    CASE 
                        WHEN rectangle_id = 'A' THEN 1
                        WHEN rectangle_id = 'B' THEN 2
                        WHEN rectangle_id = 'C' THEN 3
                        WHEN rectangle_id = 'D' THEN 4
                        WHEN rectangle_id = 'E' THEN 5
                        WHEN rectangle_id = 'F' THEN 6
                        WHEN rectangle_id = 'G' THEN 7
                        ELSE 8
                    END,
                    CAST(SUBSTRING(cell_id, 7) AS UNSIGNED)
                ";
        }
    }
    
    /**
     * Check if allocation would cause conflicts
     */
    private function isAllocationConflictFree(array $cellCandidate, array $strategy): bool {
        $cellId = $cellCandidate['cell_id'];
        $conflictingCells = $this->getConflictingCells($cellId, $strategy);
        
        // Check if any conflicting cells are already allocated
        if (!empty($conflictingCells)) {
            $placeholders = str_repeat('?,', count($conflictingCells) - 1) . '?';
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as conflict_count 
                 FROM floor_grid_cells 
                 WHERE cell_id IN ({$placeholders}) AND status != 'available'"
            );
            $stmt->bind_param(str_repeat('s', count($conflictingCells)), ...$conflictingCells);
            $stmt->execute();
            
            $result = $stmt->get_result()->fetch_assoc();
            return (int)$result['conflict_count'] === 0;
        }
        
        return true;
    }
    
    /**
     * Get list of cells that would conflict with this allocation
     */
    private function getConflictingCells(string $cellId, array $strategy): array {
        // Parse cell ID to understand hierarchy
        if (!preg_match('/^([A-G])(\d{4})-(\d+)$/', $cellId, $matches)) {
            return [];
        }
        
        $rectangle = $matches[1];
        $pattern = $matches[2];
        $number = (int)$matches[3];
        
        $conflicts = [];
        
        switch ($strategy['pattern']) {
            case '0101': // 1x1 allocation
                // Conflicts with all 1x0.5 and 0.5x0.5 cells in same position
                $conflicts = array_merge(
                    $this->getChildCells($rectangle, '0105', $number),
                    $this->getChildCells($rectangle, '0505', $number)
                );
                break;
                
            case '0105': // 1x0.5 allocation
                // Conflicts with parent 1x1 and sibling 1x0.5, plus child 0.5x0.5
                $conflicts = array_merge(
                    $this->getParentCell($rectangle, '0101', $number),
                    $this->getChildCells($rectangle, '0505', $number)
                );
                break;
                
            case '0505': // 0.5x0.5 allocation  
                // Conflicts with parent 1x1 and parent 1x0.5
                $conflicts = array_merge(
                    $this->getParentCell($rectangle, '0101', $number),
                    $this->getParentCell($rectangle, '0105', $number)
                );
                break;
        }
        
        return array_filter($conflicts);
    }
    
    /**
     * Get child cells for conflict detection
     */
    private function getChildCells(string $rectangle, string $childPattern, int $parentNumber): array {
        // This is complex logic based on your ID generation
        // For now, simplified approach - you may need to adjust based on exact ID patterns
        $children = [];
        
        if ($childPattern === '0105') {
            // 1x1 to 1x0.5: typically 2 children per parent
            $children[] = "{$rectangle}{$childPattern}-" . str_pad((string)(($parentNumber * 2) - 1), 2, '0', STR_PAD_LEFT);
            $children[] = "{$rectangle}{$childPattern}-" . str_pad((string)($parentNumber * 2), 2, '0', STR_PAD_LEFT);
        } elseif ($childPattern === '0505') {
            // 1x1 to 0.5x0.5: typically 4 children per parent
            for ($i = 1; $i <= 4; $i++) {
                $children[] = "{$rectangle}{$childPattern}-" . str_pad((string)(($parentNumber * 4) - 4 + $i), 2, '0', STR_PAD_LEFT);
            }
        }
        
        return $children;
    }
    
    /**
     * Get parent cell for conflict detection
     */
    private function getParentCell(string $rectangle, string $parentPattern, int $childNumber): array {
        if ($parentPattern === '0101') {
            // Calculate parent 1x1 from child number
            $parentNumber = (int)ceil($childNumber / 4); // Assuming 4 children per parent
            return ["{$rectangle}{$parentPattern}-" . str_pad((string)$parentNumber, 2, '0', STR_PAD_LEFT)];
        } elseif ($parentPattern === '0105') {
            // Calculate parent 1x0.5 from 0.5x0.5 child
            $parentNumber = (int)ceil($childNumber / 2); // Assuming 2 children per parent
            return ["{$rectangle}{$parentPattern}-" . str_pad((string)$parentNumber, 2, '0', STR_PAD_LEFT)];
        }
        
        return [];
    }
    
    /**
     * Allocate a specific cell
     */
    private function allocateSpecificCell(
        array $cellCandidate,
        array $strategy,
        ?int $pledgeId,
        ?int $paymentId,
        string $donorName,
        float $amount,
        string $status
    ): ?array {
        $cellId = $cellCandidate['cell_id'];
        
        $stmt = $this->db->prepare(
            "UPDATE floor_grid_cells 
             SET status = ?, pledge_id = ?, payment_id = ?, donor_name = ?, amount = ?, assigned_date = NOW()
             WHERE cell_id = ? AND status = 'available'"
        );
        
        $stmt->bind_param('siisds', $status, $pledgeId, $paymentId, $donorName, $amount, $cellId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            return [
                'cell_id' => $cellId,
                'rectangle_id' => $cellCandidate['rectangle_id'],
                'cell_type' => $strategy['type'],
                'area_size' => $strategy['area'],
                'status' => $status
            ];
        }
        
        return null;
    }
    
    /**
     * Block conflicting cells to prevent double allocation
     */
    private function blockConflictingCells(array $allocatedCell, array $strategy, string $status): void {
        $conflictingCells = $this->getConflictingCells($allocatedCell['cell_id'], $strategy);
        
        if (!empty($conflictingCells)) {
            $placeholders = str_repeat('?,', count($conflictingCells) - 1) . '?';
            $stmt = $this->db->prepare(
                "UPDATE floor_grid_cells 
                 SET status = 'blocked', assigned_date = NOW()
                 WHERE cell_id IN ({$placeholders}) AND status = 'available'"
            );
            $stmt->bind_param(str_repeat('s', count($conflictingCells)), ...$conflictingCells);
            $stmt->execute();
        }
    }
    
    /**
     * Get current grid status for visualization
     */
    public function getGridStatus(?string $rectangleId = null): array {
        $sql = "SELECT cell_id, rectangle_id, cell_type, area_size, status, donor_name, amount, assigned_date
                FROM floor_grid_cells 
                WHERE status IN ('pledged', 'paid')";
        
        if ($rectangleId) {
            $sql .= " AND rectangle_id = ?";
        }
        
        $sql .= " ORDER BY rectangle_id, assigned_date";
        
        $stmt = $this->db->prepare($sql);
        if ($rectangleId) {
            $stmt->bind_param('s', $rectangleId);
        }
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
                SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_cells,
                SUM(CASE WHEN status = 'pledged' THEN area_size ELSE 0 END) as pledged_area,
                SUM(CASE WHEN status = 'paid' THEN area_size ELSE 0 END) as paid_area,
                SUM(CASE WHEN status IN ('pledged', 'paid') THEN amount ELSE 0 END) as total_amount
             FROM floor_grid_cells"
        );
        
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        
        $totalCells = (int)$stats['total_cells'];
        $pledgedCells = (int)$stats['pledged_cells'];
        $paidCells = (int)$stats['paid_cells'];
        
        return [
            'total_cells' => $totalCells,
            'pledged_cells' => $pledgedCells,
            'paid_cells' => $paidCells,
            'available_cells' => (int)$stats['available_cells'],
            'blocked_cells' => (int)$stats['blocked_cells'],
            'total_area_pledged' => (float)$stats['pledged_area'],
            'total_area_paid' => (float)$stats['paid_area'],
            'total_amount' => (float)$stats['total_amount'],
            'progress_percentage' => $totalCells > 0 ? 
                (($pledgedCells + $paidCells) / $totalCells) * 100 : 0
        ];
    }
}
