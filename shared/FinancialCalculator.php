<?php
/**
 * FinancialCalculator - Centralized Financial Totals Calculator
 * 
 * Single source of truth for ALL financial calculations across the system.
 * This ensures consistency between reports, dashboards, projector, and APIs.
 * 
 * Logic:
 * - Total Paid = Instant Payments + Pledge Payments (Cash Received)
 * - Outstanding Pledged = Total Pledges - Pledge Payments (Promises Remaining)
 * - Grand Total = Total Paid + Outstanding Pledged (Total Campaign Value)
 * 
 * @author Fundraising System
 * @version 1.0
 */

class FinancialCalculator {
    private $db;
    private $hasPledgePayments = false;
    
    public function __construct() {
        $this->db = db();
        
        // Check if pledge_payments table exists
        $check = $this->db->query("SHOW TABLES LIKE 'pledge_payments'");
        $this->hasPledgePayments = ($check && $check->num_rows > 0);
    }
    
    /**
     * Get comprehensive financial totals
     * 
     * @param string|null $dateFrom Start date filter (Y-m-d H:i:s) or null for all time
     * @param string|null $dateTo End date filter (Y-m-d H:i:s) or null for all time
     * @return array Associative array with all financial metrics
     */
    public function getTotals($dateFrom = null, $dateTo = null) {
        $hasDateFilter = ($dateFrom !== null && $dateTo !== null);
        
        // For date-filtered reports, calculate activity WITHIN the date range
        // This matches the behavior expected by visual/comprehensive reports
        
        // 1. Instant Payments (Direct donations)
        if ($hasDateFilter) {
            // Activity in range: Payments BETWEEN dates
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count 
                FROM payments 
                WHERE status = 'approved' AND received_at BETWEEN ? AND ?
            ");
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $instantTotal = (float)$result['total'];
            $instantCount = (int)$result['count'];
        } else {
            $row = $this->db->query("
                SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count 
                FROM payments 
                WHERE status = 'approved'
            ")->fetch_assoc();
            $instantTotal = (float)$row['total'];
            $instantCount = (int)$row['count'];
        }
        
        // 2. Pledge Payments (Installments towards pledges)
        $pledgePaidTotal = 0;
        $pledgePaidCount = 0;
        
        if ($this->hasPledgePayments) {
            if ($hasDateFilter) {
                // Activity in range: Pledge payments BETWEEN dates
                $stmt = $this->db->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count 
                    FROM pledge_payments 
                    WHERE status = 'confirmed' AND created_at BETWEEN ? AND ?
                ");
                $stmt->bind_param('ss', $dateFrom, $dateTo);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $pledgePaidTotal = (float)$result['total'];
                $pledgePaidCount = (int)$result['count'];
            } else {
                $row = $this->db->query("
                    SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count 
                    FROM pledge_payments 
                    WHERE status = 'confirmed'
                ")->fetch_assoc();
                $pledgePaidTotal = (float)$row['total'];
                $pledgePaidCount = (int)$row['count'];
            }
        }
        
        // 3. Total Pledges (Promises made)
        if ($hasDateFilter) {
            // Activity in range: Pledges created BETWEEN dates
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count 
                FROM pledges 
                WHERE status = 'approved' AND created_at BETWEEN ? AND ?
            ");
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $totalPledges = (float)$result['total'];
            $pledgeCount = (int)$result['count'];
        } else {
            $row = $this->db->query("
                SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count 
                FROM pledges 
                WHERE status = 'approved'
            ")->fetch_assoc();
            $totalPledges = (float)$row['total'];
            $pledgeCount = (int)$row['count'];
        }
        
        // Calculate derived metrics
        $totalPaid = $instantTotal + $pledgePaidTotal;
        
        // For date-filtered reports: Outstanding = Pledges IN range - Payments IN range
        // This matches the "activity report" semantic expected by visual/comprehensive reports
        $outstandingPledged = max(0, $totalPledges - $pledgePaidTotal);
        
        $grandTotal = $totalPaid + $outstandingPledged;
        
        return [
            // Raw components
            'instant_payments' => $instantTotal,
            'instant_count' => $instantCount,
            'pledge_payments' => $pledgePaidTotal,
            'pledge_payment_count' => $pledgePaidCount,
            'total_pledges' => $totalPledges,
            'pledge_count' => $pledgeCount,
            
            // Primary metrics (used in displays)
            'total_paid' => $totalPaid,
            'total_payment_count' => $instantCount + $pledgePaidCount,
            'outstanding_pledged' => $outstandingPledged,
            'grand_total' => $grandTotal,
            
            // Metadata
            'has_pledge_payments' => $this->hasPledgePayments,
            'date_filtered' => $hasDateFilter,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'calculated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Get donor-specific financial totals
     * 
     * @param int $donorId Donor ID
     * @return array Donor financial metrics
     */
    public function getDonorTotals($donorId) {
        $donorId = (int)$donorId;
        
        // Get from donors table (pre-calculated)
        $stmt = $this->db->prepare("
            SELECT total_paid, total_pledged, balance 
            FROM donors 
            WHERE id = ?
        ");
        $stmt->bind_param('i', $donorId);
        $stmt->execute();
        $donor = $stmt->get_result()->fetch_assoc();
        
        if (!$donor) {
            return [
                'total_paid' => 0,
                'total_pledged' => 0,
                'outstanding_balance' => 0
            ];
        }
        
        return [
            'total_paid' => (float)$donor['total_paid'],
            'total_pledged' => (float)$donor['total_pledged'],
            'outstanding_balance' => (float)$donor['balance']
        ];
    }
    
    /**
     * Recalculate and update donor totals after APPROVING a payment
     * Uses original approve logic: if balance > 0 -> 'paying', else -> 'completed'
     * 
     * @param int $donorId Donor ID to update
     * @return bool Success status
     */
    public function recalculateDonorTotalsAfterApprove($donorId) {
        $donorId = (int)$donorId;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE donors d
                SET 
                    d.total_paid = (
                        COALESCE((SELECT SUM(amount) FROM payments WHERE donor_id = d.id AND status = 'approved'), 0) + 
                        COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)
                    ),
                    d.balance = (
                        d.total_pledged - 
                        COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)
                    ),
                    d.payment_status = CASE
                        WHEN (d.total_pledged - COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)) <= 0.01 
                        THEN 'completed'
                        ELSE 'paying'
                    END
                WHERE d.id = ?
            ");
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to recalculate donor totals after approve for donor {$donorId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Recalculate and update donor totals after UNDOING a payment
     * Uses original undo logic: reverts to 'pending' if balance > 0
     * 
     * @param int $donorId Donor ID to update
     * @return bool Success status
     */
    public function recalculateDonorTotalsAfterUndo($donorId) {
        $donorId = (int)$donorId;
        
        try {
            $stmt = $this->db->prepare("
                UPDATE donors d
                SET 
                    d.total_paid = (
                        COALESCE((SELECT SUM(amount) FROM payments WHERE donor_id = d.id AND status = 'approved'), 0) + 
                        COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)
                    ),
                    d.balance = (
                        d.total_pledged - 
                        COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)
                    ),
                    d.payment_status = CASE
                        WHEN d.total_pledged > 0 AND (d.total_pledged - COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)) > 0.01 
                        THEN 'pending'
                        WHEN (d.total_pledged - COALESCE((SELECT SUM(amount) FROM pledge_payments WHERE donor_id = d.id AND status = 'confirmed'), 0)) <= 0.01 
                        THEN 'completed'
                        ELSE 'pending'
                    END
                WHERE d.id = ?
            ");
            $stmt->bind_param('i', $donorId);
            $stmt->execute();
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to recalculate donor totals after undo for donor {$donorId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generic recalculate method (kept for backwards compatibility)
     * Uses approve logic by default
     * 
     * @param int $donorId Donor ID to update
     * @return bool Success status
     */
    public function recalculateDonorTotals($donorId) {
        return $this->recalculateDonorTotalsAfterApprove($donorId);
    }
}
?>
