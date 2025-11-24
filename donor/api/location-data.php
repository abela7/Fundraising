<?php
require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {
    require_login(); // Or require_donor_login()? Admin might use this too, but for now donor context.
    // Actually, let's check for either session.
    if (!isset($_SESSION['user']) && !isset($_SESSION['donor'])) {
        throw new Exception('Unauthorized');
    }

    $db = db();
    $action = $_GET['action'] ?? '';

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
        $city = $_GET['city'] ?? '';
        if (!$city) throw new Exception('City required');

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
        if (!$church_id) throw new Exception('Church ID required');

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
        // Only donors can self-assign
        if (!isset($_SESSION['donor'])) throw new Exception('Donor login required');
        
        $donor_id = (int)$_SESSION['donor']['id'];
        $rep_id = (int)($_POST['representative_id'] ?? 0);
        $church_id = (int)($_POST['church_id'] ?? 0);
        
        if (!$rep_id || !$church_id) throw new Exception('Invalid selection');
        
        // Verify rep belongs to church
        $verify = $db->prepare("SELECT id FROM church_representatives WHERE id = ? AND church_id = ? AND is_active = 1");
        $verify->bind_param('ii', $rep_id, $church_id);
        $verify->execute();
        if ($verify->get_result()->num_rows === 0) throw new Exception('Invalid representative');
        
        // Update donor
        // Check if representative_id column exists
        $check = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
        if ($check && $check->num_rows > 0) {
            $upd = $db->prepare("UPDATE donors SET church_id = ?, representative_id = ? WHERE id = ?");
            $upd->bind_param('iii', $church_id, $rep_id, $donor_id);
        } else {
            $upd = $db->prepare("UPDATE donors SET church_id = ? WHERE id = ?");
            $upd->bind_param('ii', $church_id, $donor_id);
        }
        
        if ($upd->execute()) {
            // Update session
            $_SESSION['donor']['church_id'] = $church_id;
            $_SESSION['donor']['representative_id'] = $rep_id;
            echo json_encode(['success' => true, 'message' => 'Representative assigned']);
        } else {
            throw new Exception('Update failed');
        }

    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

