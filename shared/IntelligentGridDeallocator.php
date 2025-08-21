<?php

class IntelligentGridDeallocator {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Deallocate floor cells for a specific donation
     */
    public function deallocateDonation($pledgeId = null, $paymentId = null): array {
        if (!$pledgeId && !$paymentId) {
            return ['success' => false, 'error' => 'Either pledge ID or payment ID is required'];
        }

        try {
            $this->db->begin_transaction();

            // Find all cells allocated to this donation
            $allocatedCells = $this->findAllocatedCells($pledgeId, $paymentId);
            
            if (empty($allocatedCells)) {
                $this->db->rollback();
                return ['success' => true, 'message' => 'No cells found to deallocate'];
            }

            // Free up all allocated cells
            $this->freeCells($allocatedCells);

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Successfully deallocated ' . count($allocatedCells) . ' grid cell(s)',
                'deallocated_cells' => array_column($allocatedCells, 'cell_id')
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("IntelligentGridDeallocator Error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find all cells allocated to a specific donation
     */
    private function findAllocatedCells($pledgeId, $paymentId): array {
        $sql = "
            SELECT cell_id, rectangle_id, cell_type, area, status, pledge_id, payment_id, donor_name, amount
            FROM floor_grid_cells 
            WHERE (pledge_id = ? OR payment_id = ?) 
            AND status IN ('pledged', 'paid')
            FOR UPDATE
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $pledgeId, $paymentId);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Free up allocated cells by resetting them to available
     */
    private function freeCells(array $cells): void {
        if (empty($cells)) return;

        $cellIds = array_column($cells, 'cell_id');
        $placeholders = str_repeat('?,', count($cellIds) - 1) . '?';
        
        $sql = "
            UPDATE floor_grid_cells 
            SET status = 'available', 
                pledge_id = NULL, 
                payment_id = NULL, 
                donor_name = NULL, 
                amount = NULL,
                allocated_at = NULL
            WHERE cell_id IN ($placeholders)
        ";
        
        $stmt = $this->db->prepare($sql);
        
        // Create array of types and values for bind_param
        $types = str_repeat('s', count($cellIds));
        $stmt->bind_param($types, ...$cellIds);
        $stmt->execute();
    }
}
