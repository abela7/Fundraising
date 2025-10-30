<?php
declare(strict_types=1);

/**
 * Custom Amount Allocator - FIXED VERSION
 * 
 * Implements the brilliant custom amount system:
 * Rule 1: Under £100 = accumulate until £100, then auto-allocate
 * Rule 2: Over £100 = allocate appropriate cells + track remaining
 * Rule 3: Clean cell ownership (one donor per cell)
 * Rule 4: Handle both pledges AND payments
 * Rule 5: Reset totals after allocation
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
        string $status,
        ?int $allocationBatchId = null
    ): array {
        try {
            // DEBUG: Log the pledge processing
            error_log("CustomAmountAllocator: Processing pledge ID {$pledgeId}, amount £{$amount}, donor: {$donorName}, status: {$status}");
            
            $this->db->begin_transaction();
            
            // Rule 1: Under £100 = accumulate (no immediate allocation)
            if ($amount < 100) {
                error_log("CustomAmountAllocator: Amount £{$amount} < £100, tracking for accumulation");
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
            error_log("CustomAmountAllocator: Amount £{$amount} >= £100, allocating cells");
            $allocationResult = $this->allocateAppropriateCells($pledgeId, $amount, $donorName, $status, $allocationBatchId);
            
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
        // DEBUG: Log tracking attempt
        error_log("CustomAmountAllocator: Tracking amount £{$amount} for donor {$donorName} (pledge ID: {$pledgeId})");
        
        // Always update the single record with ID = 1
        $update = $this->db->prepare("
            UPDATE custom_amount_tracking 
            SET total_amount = total_amount + ?,
                remaining_amount = remaining_amount + ?,
                last_updated = NOW()
            WHERE id = 1
        ");
        $update->bind_param('dd', $amount, $amount);
        $update->execute();
        
        error_log("CustomAmountAllocator: Updated collective record with £{$amount} from {$donorName}");
    }
    
    /**
     * Track custom amount for PAYMENTS (separate method)
     */
    private function trackPaymentCustomAmount(int $paymentId, float $amount, string $donorName): void {
        // DEBUG: Log tracking attempt
        error_log("CustomAmountAllocator: Tracking PAYMENT amount £{$amount} for donor {$donorName} (payment ID: {$paymentId})");
        
        // Always update the single record with ID = 1
        $update = $this->db->prepare("
            UPDATE custom_amount_tracking 
            SET total_amount = total_amount + ?,
                remaining_amount = remaining_amount + ?,
                last_updated = NOW()
            WHERE id = 1
        ");
        $update->bind_param('dd', $amount, $amount);
        $update->execute();
        
        error_log("CustomAmountAllocator: Updated collective record with PAYMENT £{$amount} from {$donorName}");
    }
    
    /**
     * Check if accumulated amounts can now allocate cells
     */
    private function checkAndAllocateAccumulated(): ?array {
        // Check if TOTAL accumulated amount reaches £100+ (collective accumulation)
        $stmt = $this->db->prepare("
            SELECT remaining_amount
            FROM custom_amount_tracking
            WHERE id = 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $totalRemaining = (float)($result['remaining_amount'] ?? 0);
        
        error_log("CustomAmountAllocator: Current remaining amount: £{$totalRemaining}");
        
        if ($totalRemaining < 100) {
            error_log("CustomAmountAllocator: Not enough to allocate (£{$totalRemaining} < £100)");
            return null; // Not enough to allocate
        }
        
        // Calculate how many cells to allocate from total
        $cellsToAllocate = $this->calculateCellsForAmount($totalRemaining);
        $allocatedAmount = $cellsToAllocate * 100; // £100 per 0.25m²
        $remainingAmount = $totalRemaining - $allocatedAmount;
        
        // DEBUG: Log the allocation attempt
        error_log("CustomAmountAllocator: Attempting to allocate £{$allocatedAmount} for {$cellsToAllocate} cells");
        error_log("CustomAmountAllocator: Will leave £{$remainingAmount} remaining");
        
        // Check if there are available cells first
        $availableCells = $this->checkAvailableCells($cellsToAllocate);
        if (empty($availableCells)) {
            error_log("CustomAmountAllocator: ERROR - No available cells found for allocation!");
            return null;
        }
        
        error_log("CustomAmountAllocator: Found " . count($availableCells) . " available cells");
        
        // Allocate cells using existing grid allocator with proper status
        $gridAllocator = new IntelligentGridAllocator($this->db);
        $allocationResult = $gridAllocator->allocate(
            null, // No specific pledge ID for accumulated amounts
            null, // No payment ID
            $allocatedAmount,
            null, // No package ID - let it calculate based on amount
            'Collective Custom Donors', // Use descriptive name for collective allocations
            'pledged' // Status for accumulated allocations - use 'pledged' for custom amounts
        );
        
        // DEBUG: Log the allocation result
        error_log("CustomAmountAllocator: Allocation result: " . json_encode($allocationResult));
        
        if ($allocationResult['success']) {
            error_log("CustomAmountAllocator: SUCCESS! Cells allocated successfully");
            // Reset tracking records after successful allocation
            $this->resetTrackingAfterAllocation($allocatedAmount, $remainingAmount);
            
            return [
                [
                    'donor' => 'Collective (Multiple Donors)',
                    'allocated' => $allocatedAmount,
                    'remaining' => $remainingAmount,
                    'cells' => $allocationResult['allocated_cells'],
                    'total_contributors' => $this->getActiveDonorCount()
                ]
            ];
        } else {
            error_log("CustomAmountAllocator: FAILED! Allocation error: " . ($allocationResult['error'] ?? 'Unknown error'));
        }
        
        return null;
    }
    
    /**
     * Check if there are enough available cells for allocation
     */
    private function checkAvailableCells(int $requiredCells): array {
        // Check for any available cells (not just 0.5x0.5)
        $stmt = $this->db->prepare("
            SELECT cell_id, rectangle_id, cell_type, status
            FROM floor_grid_cells
            WHERE status = 'available'
            ORDER BY rectangle_id ASC, CAST(SUBSTRING_INDEX(cell_id, '-', -1) AS UNSIGNED) ASC
            LIMIT ?
        ");
        $stmt->bind_param('i', $requiredCells);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        error_log("CustomAmountAllocator: Found " . count($result) . " available cells out of " . $requiredCells . " required");
        
        // Log details of available cells
        foreach ($result as $cell) {
            error_log("CustomAmountAllocator: Available cell: {$cell['cell_id']} (type: {$cell['cell_type']}, status: {$cell['status']})");
        }
        
        return $result;
    }
    
    /**
     * Reset tracking records after successful allocation
     */
    private function resetTrackingAfterAllocation(float $allocatedAmount, float $remainingAmount): void {
        // Simply update the single record with ID = 1
        $update = $this->db->prepare("
            UPDATE custom_amount_tracking 
            SET allocated_amount = allocated_amount + ?,
                remaining_amount = ?,
                last_updated = NOW()
            WHERE id = 1
        ");
        $update->bind_param('dd', $allocatedAmount, $remainingAmount);
        $update->execute();
        
        error_log("CustomAmountAllocator: Reset collective record - allocated: £{$allocatedAmount}, remaining: £{$remainingAmount}");
    }
    
    /**
     * Get count of active donors with remaining amounts
     */
    private function getActiveDonorCount(): int {
        // Since we only have one collective record, return 1 if there's remaining amount
        $stmt = $this->db->prepare("
            SELECT remaining_amount 
            FROM custom_amount_tracking 
            WHERE id = 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $remaining = (float)($result['remaining_amount'] ?? 0);
        
        return $remaining > 0 ? 1 : 0;
    }
    
    /**
     * Allocate appropriate cells for £100+ amounts
     */
    private function allocateAppropriateCells(?int $pledgeId, float $amount, string $donorName, string $status, ?int $allocationBatchId = null, ?int $paymentId = null): array {
        // Calculate how many cells to allocate
        $cellsToAllocate = $this->calculateCellsForAmount($amount);
        $allocatedAmount = $cellsToAllocate * 100; // £100 per 0.25m²
        $remainingAmount = $amount - $allocatedAmount;
        
        // Allocate cells using existing grid allocator
        $gridAllocator = new IntelligentGridAllocator($this->db);
        $allocationResult = $gridAllocator->allocate(
            $pledgeId,
            $paymentId,
            $allocatedAmount,
            null, // No package ID
            $donorName,
            $status,
            $allocationBatchId
        );
        
        // If there's remaining amount, track it
        if ($remainingAmount > 0) {
            // Use appropriate tracking method based on whether this is a pledge or payment
            if ($pledgeId !== null) {
                $this->trackCustomAmount($pledgeId, $remainingAmount, $donorName);
            } else {
                // This is a payment, use payment tracking
                $this->trackPaymentCustomAmount($paymentId, $remainingAmount, $donorName);
            }
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
    
    /**
     * Process payment custom amount (NEW METHOD)
     */
    public function processPaymentCustomAmount(
        int $paymentId,
        float $amount,
        string $donorName,
        string $status,
        ?int $allocationBatchId = null
    ): array {
        try {
            $this->db->begin_transaction();
            
            // Rule 1: Under £100 = accumulate (no immediate allocation)
            if ($amount < 100) {
                $this->trackPaymentCustomAmount($paymentId, $amount, $donorName);
                
                // Check if we can now allocate a cell
                $allocationResult = $this->checkAndAllocateAccumulated();
                
                $this->db->commit();
                
                return [
                    'success' => true,
                    'message' => "Payment £{$amount} tracked. No immediate allocation.",
                    'allocation_result' => $allocationResult,
                    'type' => 'accumulated'
                ];
            }
            
            // Rule 2: £100+ = allocate appropriate cells
            $allocationResult = $this->allocateAppropriateCells(null, $amount, $donorName, $status, $paymentId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Allocated appropriate cells for payment £{$amount}",
                'allocation_result' => $allocationResult,
                'type' => 'allocated'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("CustomAmountAllocator Payment Error: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'error'
            ];
        }
    }
}
