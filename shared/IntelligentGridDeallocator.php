<?php

/**
 * IntelligentGridDeallocator - Robust floor grid deallocation system
 * 
 * This class handles the dynamic deallocation of floor cells when donations
 * are unapproved, ensuring perfect database consistency and real-time updates.
 * 
 * @author Church Fundraising System
 * @version 1.0
 */
class IntelligentGridDeallocator
{
    private $db;
    
    public function __construct($database)
    {
        $this->db = $database;
    }
    
    /**
     * Deallocate floor cells for a specific pledge
     * 
     * @param int $pledgeId The pledge ID to deallocate
     * @return array Result of deallocation operation
     */
    public function deallocatePledge(int $pledgeId): array
    {
        try {
            $this->db->begin_transaction();
            
            // Find all cells allocated to this pledge
            $allocatedCells = $this->findCellsByPledgeId($pledgeId);
            
            if (empty($allocatedCells)) {
                $this->db->rollback();
                return [
                    'success' => true, 
                    'message' => 'No floor cells were allocated to this pledge.',
                    'deallocated_cells' => []
                ];
            }
            
            // Get pledge details for logging
            $pledgeDetails = $this->getPledgeDetails($pledgeId);
            
            // Deallocate the cells
            $deallocatedCount = $this->deallocateCells($allocatedCells);
            
            // Log the deallocation
            $this->logDeallocation('pledge', $pledgeId, $pledgeDetails, $deallocatedCount);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Successfully deallocated {$deallocatedCount} floor cell(s) for pledge #{$pledgeId}.",
                'deallocated_cells' => array_column($allocatedCells, 'cell_id'),
                'deallocated_count' => $deallocatedCount,
                'pledge_amount' => $pledgeDetails['amount'] ?? 0
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridDeallocator Error (Pledge): " . $e->getMessage());
            return [
                'success' => false, 
                'error' => 'Deallocation failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Deallocate floor cells for a specific payment
     * 
     * @param int $paymentId The payment ID to deallocate
     * @return array Result of deallocation operation
     */
    public function deallocatePayment(int $paymentId): array
    {
        try {
            $this->db->begin_transaction();
            
            // Find all cells allocated to this payment
            $allocatedCells = $this->findCellsByPaymentId($paymentId);
            
            if (empty($allocatedCells)) {
                $this->db->rollback();
                return [
                    'success' => true, 
                    'message' => 'No floor cells were allocated to this payment.',
                    'deallocated_cells' => []
                ];
            }
            
            // Get payment details for logging
            $paymentDetails = $this->getPaymentDetails($paymentId);
            
            // Deallocate the cells
            $deallocatedCount = $this->deallocateCells($allocatedCells);
            
            // Log the deallocation
            $this->logDeallocation('payment', $paymentId, $paymentDetails, $deallocatedCount);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => "Successfully deallocated {$deallocatedCount} floor cell(s) for payment #{$paymentId}.",
                'deallocated_cells' => array_column($allocatedCells, 'cell_id'),
                'deallocated_count' => $deallocatedCount,
                'payment_amount' => $paymentDetails['amount'] ?? 0
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridDeallocator Error (Payment): " . $e->getMessage());
            return [
                'success' => false, 
                'error' => 'Deallocation failed: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Find all cells allocated to a specific pledge
     * 
     * @param int $pledgeId The pledge ID
     * @return array Array of allocated cells
     */
    private function findCellsByPledgeId(int $pledgeId): array
    {
        $sql = "
            SELECT 
                cell_id, 
                rectangle_id, 
                cell_type, 
                area, 
                status,
                donor_name,
                amount,
                pledge_id,
                payment_id
            FROM floor_grid_cells 
            WHERE pledge_id = ? 
            AND status IN ('pledged', 'paid')
            ORDER BY rectangle_id ASC, cell_id ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $pledgeId);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Find all cells allocated to a specific payment
     * 
     * @param int $paymentId The payment ID
     * @return array Array of allocated cells
     */
    private function findCellsByPaymentId(int $paymentId): array
    {
        $sql = "
            SELECT 
                cell_id, 
                rectangle_id, 
                cell_type, 
                area, 
                status,
                donor_name,
                amount,
                pledge_id,
                payment_id
            FROM floor_grid_cells 
            WHERE payment_id = ? 
            AND status IN ('pledged', 'paid')
            ORDER BY rectangle_id ASC, cell_id ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Deallocate cells by resetting them to available status
     * 
     * @param array $cells Array of cells to deallocate
     * @return int Number of cells successfully deallocated
     */
    private function deallocateCells(array $cells): int
    {
        if (empty($cells)) {
            return 0;
        }
        
        $cellIds = array_column($cells, 'cell_id');
        $placeholders = str_repeat('?,', count($cellIds) - 1) . '?';
        
        $sql = "
            UPDATE floor_grid_cells 
            SET 
                status = 'available',
                pledge_id = NULL,
                payment_id = NULL,
                donor_name = NULL,
                amount = NULL,
                allocated_at = NULL,
                updated_at = NOW()
            WHERE cell_id IN ({$placeholders})
        ";
        
        $stmt = $this->db->prepare($sql);
        
        // Create array of types and values for bind_param
        $types = str_repeat('s', count($cellIds));
        $stmt->bind_param($types, ...$cellIds);
        
        $stmt->execute();
        
        return $stmt->affected_rows;
    }
    
    /**
     * Get pledge details for logging
     * 
     * @param int $pledgeId The pledge ID
     * @return array Pledge details
     */
    private function getPledgeDetails(int $pledgeId): array
    {
        $sql = "SELECT amount, donor_name, package_id FROM pledges WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $pledgeId);
        $stmt->execute();
        
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: [];
    }
    
    /**
     * Get payment details for logging
     * 
     * @param int $paymentId The payment ID
     * @return array Payment details
     */
    private function getPaymentDetails(int $paymentId): array
    {
        $sql = "SELECT amount, donor_name, pledge_id FROM payments WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $paymentId);
        $stmt->execute();
        
        $result = $stmt->get_result();
        return $result->fetch_assoc() ?: [];
    }
    
    /**
     * Log deallocation for audit trail
     * 
     * @param string $type 'pledge' or 'payment'
     * @param int $id The ID of the deallocated item
     * @param array $details Details of the deallocated item
     * @param int $cellCount Number of cells deallocated
     */
    private function logDeallocation(string $type, int $id, array $details, int $cellCount): void
    {
        $sql = "
            INSERT INTO floor_area_allocations (
                action_type, 
                item_id, 
                item_type, 
                donor_name, 
                amount, 
                cells_affected, 
                action_timestamp
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $this->db->prepare($sql);
        $actionType = 'deallocated';
        $donorName = $details['donor_name'] ?? 'Unknown';
        $amount = $details['amount'] ?? 0;
        
        $stmt->bind_param('sisdsi', $actionType, $id, $type, $donorName, $amount, $cellCount);
        $stmt->execute();
    }
    
    /**
     * Get deallocation statistics
     * 
     * @return array Deallocation statistics
     */
    public function getDeallocationStats(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_deallocations,
                SUM(cells_affected) as total_cells_deallocated,
                SUM(amount) as total_amount_deallocated,
                MAX(action_timestamp) as last_deallocation
            FROM floor_area_allocations 
            WHERE action_type = 'deallocated'
        ";
        
        $result = $this->db->query($sql);
        return $result->fetch_assoc() ?: [];
    }
    
    /**
     * Verify deallocation was successful
     * 
     * @param array $cellIds Array of cell IDs that should be deallocated
     * @return bool True if all cells are available, false otherwise
     */
    public function verifyDeallocation(array $cellIds): bool
    {
        if (empty($cellIds)) {
            return true;
        }
        
        $placeholders = str_repeat('?,', count($cellIds) - 1) . '?';
        
        $sql = "
            SELECT COUNT(*) as unavailable_count
            FROM floor_grid_cells 
            WHERE cell_id IN ({$placeholders}) 
            AND status != 'available'
        ";
        
        $stmt = $this->db->prepare($sql);
        $types = str_repeat('s', count($cellIds));
        $stmt->bind_param($types, ...$cellIds);
        $stmt->execute();
        
        $result = $stmt->get_result()->fetch_assoc();
        return ($result['unavailable_count'] ?? 0) === 0;
    }
}
