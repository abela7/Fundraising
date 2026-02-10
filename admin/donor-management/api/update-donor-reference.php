<?php
/**
 * Update the 4-digit donor reference stored in pledges.notes OR payments.reference.
 *
 * The reference is a 4-digit number that can be stored in:
 *   - pledges.notes (embedded text, e.g., "1234" or "REF: 1234")
 *   - payments.reference (or transaction_ref field for instant payments)
 *
 * This endpoint:
 *   1. Validates the new reference (must be exactly 4 digits).
 *   2. Locates the correct source record (pledge or payment).
 *   3. Updates the reference in the appropriate field.
 *   4. Logs the change via audit_helper.
 *
 * POST JSON: { donor_id, source_type, source_id, new_reference }
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../shared/audit_helper.php';
require_once __DIR__ . '/../../../config/db.php';

require_login();
require_admin();

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $donor_id = isset($input['donor_id']) ? (int)$input['donor_id'] : 0;
    $source_type = trim((string)($input['source_type'] ?? ''));
    $source_id = isset($input['source_id']) ? (int)$input['source_id'] : 0;
    $new_ref = trim((string)($input['new_reference'] ?? ''));

    // Validate reference: exactly 4 digits
    if (!preg_match('/^\d{4}$/', $new_ref)) {
        throw new Exception('Reference must be exactly 4 digits (e.g. 1307).');
    }

    if ($donor_id <= 0) {
        throw new Exception('Invalid donor ID.');
    }

    // Default to 'payment' if no source type provided
    if ($source_type === '' || $source_type === 'null') {
        $source_type = 'payment';
    }

    if (!in_array($source_type, ['pledge', 'payment'], true)) {
        throw new Exception('Invalid source type. Must be "pledge" or "payment".');
    }

    $db = db();

    // Auto-find a source if none provided
    if ($source_id <= 0) {
        if ($source_type === 'pledge') {
            $find_stmt = $db->prepare("
                SELECT id FROM pledges
                WHERE donor_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $find_stmt->bind_param('i', $donor_id);
            $find_stmt->execute();
            $found = $find_stmt->get_result()->fetch_assoc();
            $find_stmt->close();
            
            if (!$found) {
                throw new Exception('No pledge found for this donor.');
            }
            $source_id = (int)$found['id'];
        } else {
            // Find most recent instant payment by donor_id OR donor_phone
            $donor_check = $db->prepare("SELECT phone FROM donors WHERE id = ?");
            $donor_check->bind_param('i', $donor_id);
            $donor_check->execute();
            $donor_phone_result = $donor_check->get_result()->fetch_assoc();
            $donor_check->close();

            if (!$donor_phone_result) {
                throw new Exception('Donor not found.');
            }

            $donor_phone = $donor_phone_result['phone'];

            $find_stmt = $db->prepare("
                SELECT id FROM payments
                WHERE donor_id = ? OR donor_phone = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $find_stmt->bind_param('is', $donor_id, $donor_phone);
            $find_stmt->execute();
            $found = $find_stmt->get_result()->fetch_assoc();
            $find_stmt->close();
            
            // If no payment exists, create a minimal one to store the reference
            if (!$found) {
                $payment_columns = [];
                $col_query = $db->query("SHOW COLUMNS FROM payments");
                while ($col = $col_query->fetch_assoc()) {
                    $payment_columns[] = $col['Field'];
                }
                $ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';

                $create_stmt = $db->prepare("
                    INSERT INTO payments (donor_id, donor_phone, {$ref_col}, amount, status, created_at)
                    VALUES (?, ?, ?, 0, 'pending', NOW())
                ");
                $create_stmt->bind_param('iss', $donor_id, $donor_phone, $new_ref);
                
                if (!$create_stmt->execute()) {
                    throw new Exception('Failed to create payment record: ' . $create_stmt->error);
                }
                $source_id = (int)$create_stmt->insert_id;
                $create_stmt->close();

                // Log the creation
                log_audit(
                    $db,
                    'create',
                    'payment',
                    $source_id,
                    [],
                    [$ref_col => $new_ref, 'donor_id' => $donor_id, 'amount' => 0, 'status' => 'pending'],
                    'admin_portal',
                    (int)($_SESSION['user']['id'] ?? 0)
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Reference created: ' . $new_ref,
                    'new_reference' => $new_ref,
                    'source_type' => 'payment',
                    'source_id' => $source_id,
                    'field_name' => $ref_col,
                    'old_value' => '',
                    'new_value' => $new_ref,
                    'created_new_record' => true
                ]);
                exit;
            }
            $source_id = (int)$found['id'];
        }
    }

    $old_value = '';
    $new_value = '';
    $field_name = '';

    if ($source_type === 'pledge') {
        // Update pledge notes
        $stmt = $db->prepare("
            SELECT id, donor_id, notes
            FROM pledges
            WHERE id = ? AND donor_id = ?
        ");
        $stmt->bind_param('ii', $source_id, $donor_id);
        $stmt->execute();
        $pledge = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$pledge) {
            throw new Exception('Pledge not found or does not belong to this donor.');
        }

        $old_notes = $pledge['notes'] ?? '';
        $field_name = 'notes';

        if ($old_notes === '' || $old_notes === null) {
            // No existing notes — just set the reference
            $new_notes = $new_ref;
        } elseif (preg_match('/\b\d{4}\b/', $old_notes)) {
            // Replace the FIRST 4-digit number in the notes with the new one
            $new_notes = preg_replace('/\b\d{4}\b/', $new_ref, $old_notes, 1);
        } else {
            // Notes exist but contain no 4-digit number — prepend the reference
            $new_notes = $new_ref . ' ' . $old_notes;
        }

        $update_stmt = $db->prepare("UPDATE pledges SET notes = ? WHERE id = ?");
        $update_stmt->bind_param('si', $new_notes, $source_id);

        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update pledge notes: ' . $update_stmt->error);
        }
        $update_stmt->close();

        $old_value = $old_notes;
        $new_value = $new_notes;

        // Audit log
        log_audit(
            $db,
            'update',
            'pledge',
            $source_id,
            ['notes' => $old_value, 'reference_updated' => true],
            ['notes' => $new_value, 'new_reference' => $new_ref],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );

    } else {
        // Update payments.reference (or transaction_ref)
        // Check which column exists
        $payment_columns = [];
        $col_query = $db->query("SHOW COLUMNS FROM payments");
        while ($col = $col_query->fetch_assoc()) {
            $payment_columns[] = $col['Field'];
        }
        $ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';

        $stmt = $db->prepare("
            SELECT id, donor_id, donor_phone, {$ref_col} as reference
            FROM payments
            WHERE id = ?
        ");
        $stmt->bind_param('i', $source_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment) {
            throw new Exception('Payment not found.');
        }

        // Verify ownership via donor_id OR donor_phone
        $donor_check = $db->prepare("SELECT phone FROM donors WHERE id = ?");
        $donor_check->bind_param('i', $donor_id);
        $donor_check->execute();
        $donor_phone_result = $donor_check->get_result()->fetch_assoc();
        $donor_check->close();

        if (!$donor_phone_result) {
            throw new Exception('Donor not found.');
        }

        $donor_phone = $donor_phone_result['phone'];
        $payment_donor_id = $payment['donor_id'] ?? null;
        $payment_donor_phone = $payment['donor_phone'] ?? '';

        if ($payment_donor_id != $donor_id && $payment_donor_phone !== $donor_phone) {
            throw new Exception('Payment does not belong to this donor.');
        }

        $old_ref = $payment['reference'] ?? '';
        $field_name = $ref_col;

        if ($old_ref === '' || $old_ref === null) {
            // No existing reference — just set the new one
            $new_ref_value = $new_ref;
        } elseif (preg_match('/\b\d{4}\b/', $old_ref)) {
            // Replace the FIRST 4-digit number with the new one
            $new_ref_value = preg_replace('/\b\d{4}\b/', $new_ref, $old_ref, 1);
        } else {
            // Existing reference but no 4-digit number — prepend
            $new_ref_value = $new_ref . ' ' . $old_ref;
        }

        $update_stmt = $db->prepare("UPDATE payments SET {$ref_col} = ? WHERE id = ?");
        $update_stmt->bind_param('si', $new_ref_value, $source_id);

        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update payment reference: ' . $update_stmt->error);
        }
        $update_stmt->close();

        $old_value = $old_ref;
        $new_value = $new_ref_value;

        // Audit log
        log_audit(
            $db,
            'update',
            'payment',
            $source_id,
            [$ref_col => $old_value, 'reference_updated' => true],
            [$ref_col => $new_value, 'new_reference' => $new_ref],
            'admin_portal',
            (int)($_SESSION['user']['id'] ?? 0)
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Reference updated to ' . $new_ref,
        'new_reference' => $new_ref,
        'source_type' => $source_type,
        'source_id' => $source_id,
        'field_name' => $field_name,
        'old_value' => $old_value,
        'new_value' => $new_value
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
