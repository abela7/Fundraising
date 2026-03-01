<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if (!isset($_SESSION['user']) && !isset($_SESSION['donor'])) {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    $db = db();
    $action = (string)($_GET['action'] ?? '');

    if ($action === '') {
        http_response_code(400);
        throw new Exception('Missing action');
    }

    if ($action === 'assign_rep') {
        if ($method !== 'POST') {
            http_response_code(405);
            throw new Exception('Method not allowed');
        }
        if (!isset($_SESSION['donor'])) {
            http_response_code(403);
            throw new Exception('Donor login required');
        }
        if (!verify_csrf(false)) {
            http_response_code(403);
            throw new Exception('Invalid security token');
        }
    } elseif ($method !== 'GET') {
        http_response_code(405);
        throw new Exception('Method not allowed');
    }

    if ($action === 'get_cities') {
        $stmt = $db->prepare("SELECT DISTINCT city FROM churches WHERE city IS NOT NULL AND city != '' ORDER BY city ASC");
        $stmt->execute();
        $result = $stmt->get_result();
        $cities = [];
        while ($row = $result->fetch_assoc()) {
            $cities[] = $row['city'];
        }
        echo json_encode(['success' => true, 'cities' => $cities]);
    } elseif ($action === 'get_churches') {
        $city = (string)($_GET['city'] ?? '');
        if ($city === '') {
            http_response_code(400);
            throw new Exception('City required');
        }

        $stmt = $db->prepare("SELECT id, name FROM churches WHERE city = ? ORDER BY name ASC");
        $stmt->bind_param('s', $city);
        $stmt->execute();
        $result = $stmt->get_result();
        $churches = [];
        while ($row = $result->fetch_assoc()) {
            $churches[] = $row;
        }
        echo json_encode(['success' => true, 'churches' => $churches]);
    } elseif ($action === 'get_representatives') {
        $church_id = (int)($_GET['church_id'] ?? 0);
        if ($church_id <= 0) {
            http_response_code(400);
            throw new Exception('Church ID required');
        }

        $stmt = $db->prepare("SELECT id, name, role, phone FROM church_representatives WHERE church_id = ? AND is_active = 1 ORDER BY name ASC");
        $stmt->bind_param('i', $church_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reps = [];
        while ($row = $result->fetch_assoc()) {
            $reps[] = $row;
        }
        echo json_encode(['success' => true, 'representatives' => $reps]);
    } elseif ($action === 'assign_rep') {
        $donor_id = (int)($_SESSION['donor']['id'] ?? 0);
        $rep_id = (int)($_POST['representative_id'] ?? 0);
        $church_id = (int)($_POST['church_id'] ?? 0);

        if ($donor_id <= 0 || $rep_id <= 0 || $church_id <= 0) {
            http_response_code(400);
            throw new Exception('Invalid selection');
        }

        // Verify representative belongs to selected church.
        $verify = $db->prepare("SELECT id FROM church_representatives WHERE id = ? AND church_id = ? AND is_active = 1");
        $verify->bind_param('ii', $rep_id, $church_id);
        $verify->execute();
        if ($verify->get_result()->num_rows === 0) {
            http_response_code(400);
            throw new Exception('Invalid representative');
        }
        $verify->close();

        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
        if ($check && $check->num_rows > 0) {
            $upd = $db->prepare("UPDATE donors SET church_id = ?, representative_id = ? WHERE id = ?");
            $upd->bind_param('iii', $church_id, $rep_id, $donor_id);
        } else {
            $upd = $db->prepare("UPDATE donors SET church_id = ? WHERE id = ?");
            $upd->bind_param('ii', $church_id, $donor_id);
        }

        if (!$upd->execute()) {
            throw new Exception('Update failed');
        }
        $upd->close();

        $_SESSION['donor']['church_id'] = $church_id;
        $_SESSION['donor']['representative_id'] = $rep_id;

        echo json_encode(['success' => true, 'message' => 'Representative assigned']);
    } else {
        http_response_code(400);
        throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Location data API error: ' . $e->getMessage());
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'Request failed']);
}

