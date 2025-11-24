<?php
// admin/donations/undo-pledge-payment.php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json');

try {
    require_admin();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $payment_id = isset($input['id']) ? (int)$input['id'] : 0;
    $reason = isset($input['reason']) ? trim($input['reason']) : '';
    $user_id = (int)$_SESSION['user']['id'];
    
    if ($payment_id <= 0) {
        throw new Exception('Invalid payment ID');
    }
    
    if (empty($reason)) {
        throw new Exception('Undo reason is required for audit trail');
    }
    
    $db = db();
    $db->begin_transaction();
    
    // 1. Fetch payment details
    // Check if payment_plan_id column exists
    $has_plan_col = $db->query("SHOW COLUMNS FROM pledge_payments LIKE 'payment_plan_id'")->num_rows > 0;
    
    $stmt = $db->prepare("SELECT * FROM pledge_payments WHERE id = ?");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
    // If column doesn't exist, set payment_plan_id to null
    if (!$has_plan_col) {
        $payment['payment_plan_id'] = null;
    }
    
    if (!$payment) {
        throw new Exception('Payment not found');
    }
    
    if ($payment['status'] !== 'confirmed') {
        throw new Exception('Only approved payments can be undone. Current status: ' . $payment['status']);
    }
    
    // 2. Store original state for audit
    $original_state = json_encode($payment);
    
    // 3. Update payment status to 'voided' (reversal)
    $stmt = $db->prepare("
        UPDATE pledge_payments 
        SET 
            status = 'voided',
            voided_by_user_id = ?,
            voided_at = NOW(),
            void_reason = ?
        WHERE id = ?
    ");
    $undo_reason = "UNDO: " . $reason;
    $stmt->bind_param('isi', $user_id, $undo_reason, $payment_id);
    $stmt->execute();
    
    // 4. REVERSE donor balance updates using centralized FinancialCalculator
    // Recalculate totals WITHOUT this payment (since it's now voided)
    // Use undo-specific logic to revert status to 'pending' if balance remains
    require_once __DIR__ . '/../../shared/FinancialCalculator.php';
    
    $donor_id = (int)$payment['donor_id'];
    $calculator = new FinancialCalculator();
    
    if (!$calculator->recalculateDonorTotalsAfterUndo($donor_id)) {
        throw new Exception('Failed to reverse donor totals');
    }
    
    // 5. REVERSE Payment Plan updates ONLY if payment was explicitly linked to a plan
    // We do NOT reverse plan updates for standalone payments (even if amount matches)
    $payment_plan_id = null;
    $plan = null;
    $plan_reversed = false; // Track if plan was actually reversed (not just if payment_plan_id exists)
    
    // Only reverse plan if payment_plan_id is set (explicitly linked)
    if (isset($payment['payment_plan_id']) && $payment['payment_plan_id'] > 0) {
        $payment_plan_id = (int)$payment['payment_plan_id'];
        
        // Fetch payment plan details
        // IMPORTANT: Only reverse plan updates if plan is active (matches approve behavior)
        // OR if plan is completed and we're undoing the final payment (reactivation case)
        $plan_stmt = $db->prepare("
            SELECT * FROM donor_payment_plans 
            WHERE id = ? AND donor_id = ?
            LIMIT 1
        ");
        $plan_stmt->bind_param('ii', $payment_plan_id, $donor_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();
        
        // Only proceed if plan exists AND (plan is active OR plan is completed with final payment being undone)
        // This matches approve-pledge-payment.php which only updates active plans
        // We allow completed plans only when undoing what was the final payment (reactivation)
        if ($plan) {
            $plan_status_current = $plan['status'] ?? 'active';
            $current_payments_made = (int)($plan['payments_made'] ?? 0);
            $total_payments = (int)($plan['total_payments'] ?? $plan['total_months'] ?? 1);
            
            // Only reverse if:
            // 1. Plan is active (matches approve behavior), OR
            // 2. Plan is completed AND we're undoing the final payment (reactivation case)
            $should_reverse = false;
            if ($plan_status_current === 'active') {
                $should_reverse = true;
            } elseif ($plan_status_current === 'completed' && $current_payments_made >= $total_payments) {
                // Undoing final payment of completed plan - allow reactivation
                $should_reverse = true;
            } else {
                // Plan is completed but this isn't the final payment
                // This means the plan wasn't updated during approval, so don't reverse
                error_log("Skipping plan reversal for payment plan ID {$payment_plan_id}: Plan is completed and payment is not the final payment (payments_made: {$current_payments_made}, total: {$total_payments})");
            }
            
            if ($should_reverse) {
                $plan_reversed = true; // Mark that plan will be reversed
                $payment_amount = (float)$payment['amount'];
            $current_payments_made = (int)($plan['payments_made'] ?? 0);
            $current_amount_paid = (float)($plan['amount_paid'] ?? 0);
            $total_payments = (int)($plan['total_payments'] ?? $plan['total_months'] ?? 1);
            
            // Reverse: decrement payments_made and subtract amount
            $new_payments_made = max(0, $current_payments_made - 1);
            $new_amount_paid = max(0, $current_amount_paid - $payment_amount);
            
            // Recalculate next payment due (go back one period)
            $next_payment_due = null;
            $plan_status = 'active';
            
            if ($new_payments_made >= $total_payments) {
                // Shouldn't happen, but handle edge case
                $plan_status = 'completed';
            } else {
                // Calculate next payment due date after undoing this payment
                $frequency_unit = $plan['plan_frequency_unit'] ?? 'month';
                $frequency_number = (int)($plan['plan_frequency_number'] ?? 1);
                $payment_day = (int)($plan['payment_day'] ?? 1);
                
                // Determine base date for calculation
                // If plan has next_payment_due, use it and go back one period
                // If plan was completed (next_payment_due is NULL), use payment_date as reference
                // (the payment being undone was due on payment_date, so next_payment_due should be payment_date)
                if ($plan['next_payment_due']) {
                    // Plan is still active - go back one period from current next_payment_due
                    $base_date = $plan['next_payment_due'];
                    
                    try {
                        $prev_date = new DateTime($base_date);
                        
                        // Subtract frequency period
                        if ($frequency_unit === 'week') {
                            $prev_date->modify("-{$frequency_number} weeks");
                        } elseif ($frequency_unit === 'month') {
                            $prev_date->modify("-{$frequency_number} months");
                            if ($payment_day >= 1 && $payment_day <= 28) {
                                $day_to_set = min($payment_day, (int)$prev_date->format('t'));
                                $prev_date->setDate((int)$prev_date->format('Y'), (int)$prev_date->format('m'), $day_to_set);
                            }
                        } elseif ($frequency_unit === 'year') {
                            $prev_date->modify("-{$frequency_number} years");
                            if ($payment_day >= 1 && $payment_day <= 28) {
                                $day_to_set = min($payment_day, (int)$prev_date->format('t'));
                                $prev_date->setDate((int)$prev_date->format('Y'), (int)$prev_date->format('m'), $day_to_set);
                            }
                        }
                        
                        $next_payment_due = $prev_date->format('Y-m-d');
                    } catch (Exception $date_error) {
                        // next_payment_due is invalid - fallback to calculating from start_date
                        error_log("Invalid next_payment_due for payment plan ID {$payment_plan_id}: {$base_date}. Calculating from start_date. Error: " . $date_error->getMessage());
                        
                        $start_date_str = $plan['start_date'] ?? null;
                        if ($start_date_str) {
                            try {
                                $start_date = new DateTime($start_date_str);
                                // Calculate: start_date + (new_payments_made * frequency_periods)
                                $periods_to_add = $new_payments_made;
                                
                                if ($frequency_unit === 'week') {
                                    $start_date->modify("+{$periods_to_add} weeks");
                                } elseif ($frequency_unit === 'month') {
                                    $start_date->modify("+{$periods_to_add} months");
                                    if ($payment_day >= 1 && $payment_day <= 28) {
                                        $day_to_set = min($payment_day, (int)$start_date->format('t'));
                                        $start_date->setDate((int)$start_date->format('Y'), (int)$start_date->format('m'), $day_to_set);
                                    }
                                } elseif ($frequency_unit === 'year') {
                                    $start_date->modify("+{$periods_to_add} years");
                                    if ($payment_day >= 1 && $payment_day <= 28) {
                                        $day_to_set = min($payment_day, (int)$start_date->format('t'));
                                        $start_date->setDate((int)$start_date->format('Y'), (int)$start_date->format('m'), $day_to_set);
                                    }
                                }
                                
                                $next_payment_due = $start_date->format('Y-m-d');
                            } catch (Exception $fallback_error) {
                                // Last resort: use payment_date
                                error_log("Invalid start_date for payment plan ID {$payment_plan_id}: {$start_date_str}. Using payment_date. Error: " . $fallback_error->getMessage());
                                $payment_date = $payment['payment_date'] ?? date('Y-m-d');
                                $next_payment_due = $payment_date;
                            }
                        } else {
                            // Last resort: use payment_date if start_date is missing
                            error_log("Missing start_date for payment plan ID {$payment_plan_id}. Using payment_date.");
                            $payment_date = $payment['payment_date'] ?? date('Y-m-d');
                            $next_payment_due = $payment_date;
                        }
                    }
                } else {
                    // Plan was completed - calculate next_payment_due from start_date + payment count
                    // This is accurate because payment_date is when payment was received, not when it was due
                    // Example: Payment due 2025-05-01 but paid 2025-05-15 should restore next_payment_due to 2025-05-01
                    $start_date_str = $plan['start_date'] ?? null;
                    
                    if ($start_date_str) {
                        try {
                            $start_date = new DateTime($start_date_str);
                            // Calculate: start_date + (new_payments_made * frequency_periods)
                            // new_payments_made is the count after undoing (e.g., 11 if we undo payment 12 of 12)
                            // So next payment due is for payment (new_payments_made + 1), which is start_date + new_payments_made periods
                            $periods_to_add = $new_payments_made;
                            
                            if ($frequency_unit === 'week') {
                                $start_date->modify("+{$periods_to_add} weeks");
                            } elseif ($frequency_unit === 'month') {
                                $start_date->modify("+{$periods_to_add} months");
                                if ($payment_day >= 1 && $payment_day <= 28) {
                                    $day_to_set = min($payment_day, (int)$start_date->format('t'));
                                    $start_date->setDate((int)$start_date->format('Y'), (int)$start_date->format('m'), $day_to_set);
                                }
                            } elseif ($frequency_unit === 'year') {
                                $start_date->modify("+{$periods_to_add} years");
                                if ($payment_day >= 1 && $payment_day <= 28) {
                                    $day_to_set = min($payment_day, (int)$start_date->format('t'));
                                    $start_date->setDate((int)$start_date->format('Y'), (int)$start_date->format('m'), $day_to_set);
                                }
                            }
                            
                            $next_payment_due = $start_date->format('Y-m-d');
                        } catch (Exception $date_error) {
                            // Fallback: use payment_date if start_date is invalid (shouldn't happen)
                            error_log("Invalid start_date for payment plan ID {$payment_plan_id}: {$start_date_str}. Using payment_date. Error: " . $date_error->getMessage());
                            $payment_date = $payment['payment_date'] ?? date('Y-m-d');
                            $next_payment_due = $payment_date;
                        }
                    } else {
                        // Last resort: use payment_date if start_date is missing (shouldn't happen)
                        error_log("Missing start_date for payment plan ID {$payment_plan_id}. Using payment_date.");
                        $payment_date = $payment['payment_date'] ?? date('Y-m-d');
                        $next_payment_due = $payment_date;
                    }
                }
            }
            
            // Update payment plan (reverse the changes)
            $update_plan = $db->prepare("
                UPDATE donor_payment_plans 
                SET 
                    payments_made = ?,
                    amount_paid = ?,
                    next_payment_due = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_plan->bind_param('idssi', $new_payments_made, $new_amount_paid, $next_payment_due, $plan_status, $payment_plan_id);
            $update_plan->execute();
            $update_plan->close();
            
            // Check if plan_next_due_date column exists
            $has_donor_plan_col = $db->query("SHOW COLUMNS FROM donors LIKE 'plan_next_due_date'")->num_rows > 0;
            
            // If plan was completed and is now reactivated (by undoing final payment), update donor
            // This handles the case where undoing the last payment reactivates the plan
            // IMPORTANT: Only reactivate if donor has no active plan (active_payment_plan_id IS NULL)
            // This prevents overwriting a different active plan that may have been created since this plan was completed
            if ($plan['status'] === 'completed' && $plan_status === 'active') {
                if ($has_donor_plan_col) {
                    $update_donor = $db->prepare("
                        UPDATE donors 
                        SET has_active_plan = 1, 
                            active_payment_plan_id = ?,
                            plan_next_due_date = ?,
                            payment_status = CASE 
                                WHEN balance <= 0 THEN 'completed'
                                ELSE 'paying'
                            END
                        WHERE id = ? AND (active_payment_plan_id IS NULL OR active_payment_plan_id = ?)
                    ");
                    $update_donor->bind_param('isii', $payment_plan_id, $next_payment_due, $donor_id, $payment_plan_id);
                } else {
                    $update_donor = $db->prepare("
                        UPDATE donors 
                        SET has_active_plan = 1, 
                            active_payment_plan_id = ?,
                            payment_status = CASE 
                                WHEN balance <= 0 THEN 'completed'
                                ELSE 'paying'
                            END
                        WHERE id = ? AND (active_payment_plan_id IS NULL OR active_payment_plan_id = ?)
                    ");
                    $update_donor->bind_param('iii', $payment_plan_id, $donor_id, $payment_plan_id);
                }
                $update_donor->execute();
                $rows_affected = $update_donor->affected_rows;
                $update_donor->close();
                
                // Log if update was prevented due to different active plan
                if ($rows_affected === 0) {
                    error_log("Warning: Could not reactivate payment plan ID {$payment_plan_id} for donor ID {$donor_id} - donor has a different active plan");
                }
            } elseif ($plan_status === 'active' && $has_donor_plan_col) {
                // Update donor's next payment due date (only if column exists)
                $update_donor = $db->prepare("
                    UPDATE donors 
                    SET plan_next_due_date = ?
                    WHERE id = ? AND active_payment_plan_id = ?
                ");
                $update_donor->bind_param('sii', $next_payment_due, $donor_id, $payment_plan_id);
                $update_donor->execute();
                $update_donor->close();
            }
            } // End if ($should_reverse)
        }
    }
    
    // 6. Comprehensive Audit Log
    $log_json = json_encode([
        'action' => 'payment_undone',
        'payment_id' => $payment_id,
        'donor_id' => $donor_id,
        'pledge_id' => $payment['pledge_id'],
        'payment_plan_id' => $payment_plan_id > 0 ? $payment_plan_id : null,
        'amount' => $payment['amount'],
        'reason' => $reason,
        'undone_by' => $user_id,
        'undone_at' => date('Y-m-d H:i:s'),
        'original_state' => $original_state,
        'plan_reversed' => $plan_reversed,
        'warning' => 'This payment was previously approved and has now been reversed'
    ]);
    
    $log = $db->prepare("INSERT INTO audit_logs (user_id, entity_type, entity_id, action, after_json, source) VALUES (?, 'pledge_payment', ?, 'undo', ?, 'admin')");
    $log->bind_param('iis', $user_id, $payment_id, $log_json);
    $log->execute();
    
    $db->commit();
    
    $message = 'Payment undone and donor balance reversed';
    if ($plan_reversed) {
        $message .= '. Payment plan updates have been reversed.';
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'payment_id' => $payment_id,
        'plan_reversed' => $plan_reversed,
        'warning' => 'Financial totals' . ($plan_reversed ? ' and payment plan' : '') . ' have been recalculated'
    ]);
    
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

