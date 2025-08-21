<?php
declare(strict_types=1);

/**
 * Intelligent Grid Deallocator
 * Handles freeing up floor cells when donations are unapproved
 */
class IntelligentGridDeallocator
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Deallocate cells for a specific pledge
     */
    public function deallocatePledge(int $pledgeId): array
    {
        try {
            $this->db->begin_transaction();
            
            // Find all cells allocated to this pledge
            $stmt = $this->db->prepare("
                SELECT cell_id, status, pledge_id, payment_id, donor_name, amount 
                FROM floor_grid_cells 
                WHERE pledge_id = ? AND status IN ('pledged', 'paid')
                FOR UPDATE
            ");
            $stmt->bind_param('i', $pledgeId);
            $stmt->execute();
            $allocatedCells = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            if (empty($allocatedCells)) {
                $this->db->rollback();
                return ['success' => true, 'message' => 'No cells found for this pledge', 'deallocated_cells' => []];
            }
            
            // Get cell IDs to deallocate
            $cellIds = array_column($allocatedCells, 'cell_id');
            
            // Reset cells to available
            $this->resetCellsToAvailable($cellIds);
            
            // Log the deallocation
            $this->logDeallocation($pledgeId, null, $cellIds, 'pledge');
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Successfully deallocated ' . count($cellIds) . ' grid cell(s)',
                'deallocated_cells' => $cellIds
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridDeallocator Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Deallocate cells for a specific payment
     */
    public function deallocatePayment(int $paymentId): array
    {
        try {
            $this->db->begin_transaction();
            
            // Find all cells allocated to this payment
            $stmt = $this->db->prepare("
                SELECT cell_id, status, pledge_id, payment_id, donor_name, amount 
                FROM floor_grid_cells 
                WHERE payment_id = ? AND status IN ('pledged', 'paid')
                FOR UPDATE
            ");
            $stmt->bind_param('i', $paymentId);
            $stmt->execute();
            $allocatedCells = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            if (empty($allocatedCells)) {
                $this->db->rollback();
                return ['success' => true, 'message' => 'No cells found for this payment', 'deallocated_cells' => []];
            }
            
            // Get cell IDs to deallocate
            $cellIds = array_column($allocatedCells, 'cell_id');
            
            // Reset cells to available
            $this->resetCellsToAvailable($cellIds);
            
            // Log the deallocation
            $this->logDeallocation(null, $paymentId, $cellIds, 'payment');
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Successfully deallocated ' . count($cellIds) . ' grid cell(s)',
                'deallocated_cells' => $cellIds
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridDeallocator Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Reset cells to available status
     */
    private function resetCellsToAvailable(array $cellIds): void
    {
        if (empty($cellIds)) return;
        
        $placeholders = str_repeat('?,', count($cellIds) - 1) . '?';
        $sql = "UPDATE floor_grid_cells SET 
                status = 'available',
                pledge_id = NULL,
                payment_id = NULL,
                donor_name = NULL,
                amount = NULL,
                allocated_at = NULL
                WHERE cell_id IN ($placeholders)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($cellIds)), ...$cellIds);
        $stmt->execute();
    }

    /**
     * Log the deallocation for audit purposes
     */
    private function logDeallocation(?int $pledgeId, ?int $paymentId, array $cellIds, string $type): void
    {
        $deallocationData = [
            'type' => $type,
            'pledge_id' => $pledgeId,
            'payment_id' => $paymentId,
            'deallocated_cells' => $cellIds,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // You can add this to audit_logs table if needed
        // For now, we'll just log it
        error_log("Grid Deallocation: " . json_encode($deallocationData));
    }
}
