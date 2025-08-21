<?php
declare(strict_types=1);

/**
 * Custom Amount Allocator
 * 
 * Implements the brilliant custom amount system:
 * Rule 1: Under £100 = accumulate until £100, then auto-allocate
 * Rule 2: Over £100 = allocate appropriate cells + track remaining
 * Rule 3: Clean cell ownership (one donor per cell)
 */
class CustomAmountAllocator {
    private mysqli $db;
    
    public function __construct(mysqli $db) {
        $this->db = $db;
    }
    
    /**
     * Process custom amount allocation according to the rules
     */
    public function processCustomAmount(
        int $pledgeId,
        float $amount,
        string $donorName,
        string $status
    ): array {
        try {
            $this->db->begin_transaction();
            
            // Rule 1: Under £100 = accumulate (no immediate allocation)
            if ($amount < 100) {
                $this->trackCustomAmount($pledgeId, $amount, $donorName);
                
                // Check if we can now allocate a cell
                $allocationResult = $this->checkAndAllocateAccumulated();
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => "Amount £{$amount} tracked. No immediate allocation.",
                    'allocation_result' => $allocationResult,
                    'type' => 'accumulated'
                ];
            }
            
            // Rule 2: £100+ = allocate appropriate cells
            $allocationResult = $this->allocateAppropriateCells($pledgeId, $amount, $donorName, $status);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Allocated appropriate cells for £{$amount}",
                'allocation_result' => $allocationResult,
                'type' => 'allocated'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("CustomAmountAllocator Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'error'
            ];
        }
    }
    
    /**
     * Track custom amount in custom_amount_tracking table
     */
    private function trackCustomAmount(int $pledgeId, float $amount, string $donorName): void {
        // Check if donor already has tracking record
        $stmt = $this->db->prepare("
            SELECT id, total_amount, allocated_amount, remaining_amount 
            FROM custom_amount_tracking 
            WHERE donor_name = ? 
            LIMIT 1
        ");
        $stmt->bind_param('s', $donorName);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if ($result) {
            // Update existing record
            $newTotal = $result['total_amount'] + $amount;
            $newRemaining = $result['remaining_amount'] + $amount;
            
            $update = $this->db->prepare("
                UPDATE custom_amount_tracking 
                SET total_amount = ?, remaining_amount = ?, last_updated = NOW()
                WHERE id = ?
            ");
            $update->bind_param('ddi', $newTotal, $newRemaining, $result['id']);
            $update->execute();
        } else {
            // Create new tracking record
            $insert = $this->db->prepare("
                INSERT INTO custom_amount_tracking 
                (donor_id, donor_name, total_amount, allocated_amount, remaining_amount)
                VALUES (0, ?, ?, 0, ?)
            ");
            $insert->bind_param('sdd', $donorName, $amount, $amount);
            $insert->execute();
        }
    }
    
    /**
     * Check if accumulated amounts can now allocate cells
     */
    private function checkAndAllocateAccumulated(): ?array {
        // Find donors with £100+ in remaining amount
        $stmt = $this->db->prepare("
            SELECT donor_name, remaining_amount 
            FROM custom_amount_tracking 
            WHERE remaining_amount >= 100 
            ORDER BY remaining_amount DESC
        ");
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $allocations = [];
        foreach ($results as $donor) {
            $amount = (float)$donor['remaining_amount'];
            $donorName = $donor['donor_name'];
            
            // Calculate how many cells to allocate
            $cellsToAllocate = $this->calculateCellsForAmount($amount);
            $allocatedAmount = $cellsToAllocate * 100; // £100 per 0.25m²
            $remainingAmount = $amount - $allocatedAmount;
            
            // Allocate cells using existing grid allocator
            $gridAllocator = new IntelligentGridAllocator($this->db);
            $allocationResult = $gridAllocator->allocate(
                null, // No specific pledge ID for accumulated amounts
                null, // No payment ID
                $allocatedAmount,
                null, // No package ID
                $donorName,
                'allocated' // Status for accumulated allocations
            );
            
            if ($allocationResult['success']) {
                // Update tracking record
                $update = $this->db->prepare("
                    UPDATE custom_amount_tracking 
                    SET allocated_amount = allocated_amount + ?, 
                        remaining_amount = ?, 
                        last_updated = NOW()
                    WHERE donor_name = ?
                ");
                $update->bind_param('dds', $allocatedAmount, $remainingAmount, $donorName);
                $update->execute();
                
                $allocations[] = [
                    'donor' => $donorName,
                    'allocated' => $allocatedAmount,
                    'remaining' => $remainingAmount,
                    'cells' => $allocationResult['allocated_cells']
                ];
            }
        }
        
        return $allocations;
    }
    
    /**
     * Allocate appropriate cells for £100+ amounts
     */
    private function allocateAppropriateCells(int $pledgeId, float $amount, string $donorName, string $status): array {
        // Calculate how many cells to allocate
        $cellsToAllocate = $this->calculateCellsForAmount($amount);
        $allocatedAmount = $cellsToAllocate * 100; // £100 per 0.25m²
        $remainingAmount = $amount - $allocatedAmount;
        
        // Allocate cells using existing grid allocator
        $gridAllocator = new IntelligentGridAllocator($this->db);
        $allocationResult = $gridAllocator->allocate(
            $pledgeId,
            null, // No payment ID
            $allocatedAmount,
            null, // No package ID
            $donorName,
            $status
        );
        
        // If there's remaining amount, track it
        if ($remainingAmount > 0) {
            $this->trackCustomAmount($pledgeId, $remainingAmount, $donorName);
        }
        
        return [
            'allocated_amount' => $allocatedAmount,
            'remaining_amount' => $remainingAmount,
            'cells_allocated' => $cellsToAllocate,
            'grid_allocation' => $allocationResult
        ];
    }
    
    /**
     * Calculate how many 0.25m² cells to allocate for an amount
     */
    private function calculateCellsForAmount(float $amount): int {
        if ($amount >= 400) return 4;      // 1m² = £400
        if ($amount >= 200) return 2;      // 0.5m² = £200
        if ($amount >= 100) return 1;      // 0.25m² = £100
        return 0;                           // Under £100
    }
    
    /**
     * Get custom amount tracking summary for display
     */
    public function getCustomAmountSummary(): array {
        $stmt = $this->db->prepare("
            SELECT 
                SUM(total_amount) as total_tracked,
                SUM(allocated_amount) as total_allocated,
                SUM(remaining_amount) as total_remaining,
                COUNT(*) as donor_count
            FROM custom_amount_tracking
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return [
            'total_tracked' => (float)($result['total_tracked'] ?? 0),
            'total_allocated' => (float)($result['total_allocated'] ?? 0),
            'total_remaining' => (float)($result['total_remaining'] ?? 0),
            'donor_count' => (int)($result['donor_count'] ?? 0)
        ];
    }
}
