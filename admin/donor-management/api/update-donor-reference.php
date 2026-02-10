<?php
/**
 * Update the 4-digit donor reference stored in pledges.notes.
 *
 * The reference is a 4-digit number embedded in the notes field of a
 * specific pledge row.  This endpoint:
 *   1. Validates the new reference (must be exactly 4 digits).
 *   2. Locates the correct pledge (by pledge_id + donor_id).
 *   3. Replaces (or appends) the 4-digit reference in the notes text.
 *   4. Logs the change via audit_helper.
 *
 * POST JSON: { donor_id, pledge_id, new_reference }
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
    $pledge_id = isset($input['pledge_id']) ? (int)$input['pledge_id'] : 0;
    $new_ref = trim((string)($input['new_reference'] ?? ''));

    // Validate reference: exactly 4 digits
    if (!preg_match('/^\d{4}$/', $new_ref)) {
        throw new Exception('Reference must be exactly 4 digits (e.g. 1307).');
    }

    if ($donor_id <= 0) {
        throw new Exception('Invalid donor ID.');
    }

    $db = db();

    // If no pledge_id provided, find the most recent pledge for this donor
    if ($pledge_id <= 0) {
        $find_stmt = $db->prepare("
            SELECT id, notes
            FROM pledges
            WHERE donor_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $find_stmt->bind_param('i', $donor_id);
        $find_stmt->execute();
        $found = $find_stmt->get_result()->fetch_assoc();
        $find_stmt->close();

        if (!$found) {
            throw new Exception('No pledge found for this donor. Cannot store a reference.');
        }

        $pledge_id = (int)$found['id'];
    }

    // Fetch the target pledge and verify ownership
    $stmt = $db->prepare("
        SELECT id, donor_id, notes
        FROM pledges
        WHERE id = ? AND donor_id = ?
    ");
    $stmt->bind_param('ii', $pledge_id, $donor_id);
    $stmt->execute();
    $pledge = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pledge) {
        throw new Exception('Pledge not found or does not belong to this donor.');
    }

    $old_notes = $pledge['notes'] ?? '';
    $new_notes = '';

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

    // Update the pledge notes
    $update_stmt = $db->prepare("UPDATE pledges SET notes = ? WHERE id = ?");
    $update_stmt->bind_param('si', $new_notes, $pledge_id);

    if (!$update_stmt->execute()) {
        throw new Exception('Failed to update pledge notes: ' . $update_stmt->error);
    }
    $update_stmt->close();

    // Audit log
    log_audit(
        $db,
        'update',
        'pledge',
        $pledge_id,
        ['notes' => $old_notes, 'reference' => null],
        ['notes' => $new_notes, 'reference' => $new_ref],
        'admin_portal',
        (int)($_SESSION['user']['id'] ?? 0)
    );

    echo json_encode([
        'success' => true,
        'message' => 'Reference updated to ' . $new_ref,
        'new_reference' => $new_ref,
        'pledge_id' => $pledge_id,
        'old_notes' => $old_notes,
        'new_notes' => $new_notes
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
