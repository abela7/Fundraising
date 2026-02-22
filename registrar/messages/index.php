<?php
declare(strict_types=1);
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
$me = current_user();
if (!in_array(($me['role'] ?? ''), ['registrar','admin'], true)) { header('Location: ../error/403.php'); exit; }

$db = db();
$meId = (int)($me['id'] ?? 0);

function jres($data, int $code = 200): void {
	http_response_code($code);
	header('Content-Type: application/json');
	echo json_encode($data);
	exit;
}

// Mobile-first endpoints (same as admin, scoped here)
if (isset($_GET['action'])) {
	$action = (string)$_GET['action'];
	if ($action === 'recipients') {
		$rs = $db->prepare("SELECT id, name, role FROM users WHERE id<>? AND role IN ('admin','registrar') AND active = 1 ORDER BY role DESC, name ASC");
		$rs->bind_param('i', $meId);
		$rs->execute();
		$out = [];
		$q = $rs->get_result();
		while ($row = $q->fetch_assoc()) { $out[] = ['id'=>(int)$row['id'], 'name'=>$row['name'], 'role'=>$row['role']]; }
		jres(['recipients'=>$out]);
	}
	if ($action === 'conversations') {
		$sql = "
		SELECT m.id, m.body, m.created_at,
		  CASE WHEN m.sender_user_id=? THEN m.recipient_user_id ELSE m.sender_user_id END AS other_id,
		  u.name AS other_name,
		  (SELECT COUNT(*) FROM user_messages um
		    WHERE um.recipient_user_id=? AND um.read_at IS NULL
		      AND um.pair_min_user_id = LEAST(?, other_id)
		      AND um.pair_max_user_id = GREATEST(?, other_id)
		  ) AS unread
		FROM user_messages m
		JOIN (
		  SELECT pair_min_user_id, pair_max_user_id, MAX(id) AS last_id
		  FROM user_messages
		  WHERE sender_user_id=? OR recipient_user_id=?
		  GROUP BY pair_min_user_id, pair_max_user_id
		) last ON last.last_id = m.id
		JOIN users u ON u.id = CASE WHEN m.sender_user_id=? THEN m.recipient_user_id ELSE m.sender_user_id END
		ORDER BY m.created_at DESC";
		$stmt = $db->prepare($sql);
		$stmt->bind_param('iiiiiii', $meId, $meId, $meId, $meId, $meId, $meId, $meId);
		$stmt->execute();
		$res = $stmt->get_result();
		$out = [];
		while ($row = $res->fetch_assoc()) {
			$out[] = [
				'other_id' => (int)$row['other_id'],
				'other_name' => (string)$row['other_name'],
				'last_body' => (string)$row['body'],
				'last_time' => (string)$row['created_at'],
				'unread' => (int)$row['unread'],
			];
		}
		jres(['conversations' => $out]);
	}
	if ($action === 'messages') {
		$otherId = (int)($_GET['other_id'] ?? 0);
		$afterId = (int)($_GET['after_id'] ?? 0);
		if ($otherId <= 0) jres(['error' => 'bad_request'], 400);
		$min = min($meId, $otherId);
		$max = max($meId, $otherId);
		// Mark read
		$stmtRead = $db->prepare('UPDATE user_messages SET read_at=NOW() WHERE recipient_user_id=? AND read_at IS NULL AND pair_min_user_id=? AND pair_max_user_id=?');
		$stmtRead->bind_param('iii', $meId, $min, $max);
		$stmtRead->execute();
		// Fetch
		if ($afterId > 0) {
			$stmt = $db->prepare('SELECT id, sender_user_id, recipient_user_id, body, read_at, created_at FROM user_messages WHERE pair_min_user_id=? AND pair_max_user_id=? AND id>? ORDER BY id ASC');
			$stmt->bind_param('iii', $min, $max, $afterId);
		} else {
			$stmt = $db->prepare('SELECT id, sender_user_id, recipient_user_id, body, read_at, created_at FROM user_messages WHERE pair_min_user_id=? AND pair_max_user_id=? ORDER BY id DESC LIMIT 100');
			$stmt->bind_param('ii', $min, $max);
		}
		$stmt->execute();
		$res = $stmt->get_result();
		$rows = [];
		while ($r = $res->fetch_assoc()) {
			$rows[] = [
				'id' => (int)$r['id'],
				'body' => (string)$r['body'],
				'mine' => ((int)$r['sender_user_id'] === $meId),
				'read_at' => $r['read_at'],
				'created_at' => $r['created_at'],
			];
		}
		if ($afterId === 0) { $rows = array_reverse($rows); }
		jres(['messages' => $rows]);
	}
	if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
		verify_csrf();
		$otherId = (int)($_POST['other_id'] ?? 0);
		$body = trim((string)($_POST['body'] ?? ''));
		$clientUuid = trim((string)($_POST['client_uuid'] ?? ''));
		if ($otherId <= 0 || $otherId === $meId || $body === '') jres(['error' => 'bad_request'], 400);
		// Blocklist
		$blk = $db->prepare('SELECT 1 FROM user_blocklist WHERE (user_id=? AND blocked_user_id=?) OR (user_id=? AND blocked_user_id=?) LIMIT 1');
		$blk->bind_param('iiii', $otherId, $meId, $meId, $otherId);
		$blk->execute();
		if ($blk->get_result()->fetch_row()) jres(['error' => 'blocked'], 403);
		// Idempotency
		if ($clientUuid !== '') {
			$idc = $db->prepare('SELECT id FROM user_messages WHERE client_uuid=? LIMIT 1');
			$idc->bind_param('s', $clientUuid);
			$idc->execute();
			if ($idc->get_result()->fetch_row()) jres(['ok' => true, 'duplicate' => true]);
		}
		$min = min($meId, $otherId);
		$max = max($meId, $otherId);
		$stmt = $db->prepare('INSERT INTO user_messages (sender_user_id, recipient_user_id, pair_min_user_id, pair_max_user_id, body, client_uuid) VALUES (?,?,?,?,?,?)');
		$stmt->bind_param('iiiiss', $meId, $otherId, $min, $max, $body, $clientUuid);
		$stmt->execute();
		jres(['ok' => true, 'id' => $db->insert_id, 'created_at' => date('Y-m-d H:i:s')]);
	}
	if ($action === 'unread-count') {
		$stmt = $db->prepare('SELECT COUNT(*) as count FROM user_messages WHERE recipient_user_id = ? AND read_at IS NULL');
		$stmt->bind_param('i', $meId);
		$stmt->execute();
		$count = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
		jres(['unread_count' => $count]);
	}
	jres(['error' => 'not_found'], 404);
}

$page_title = 'Messages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - Registrar Panel</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../../assets/theme.css'); ?>">
    <link rel="stylesheet" href="../assets/registrar.css?v=<?php echo @filemtime(__DIR__ . '/../assets/registrar.css'); ?>">
    <link rel="stylesheet" href="../../admin/messages/assets/messages-modern.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="app-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        
        <div class="app-content">
            <?php include '../includes/topbar.php'; ?>
            
            <main class="main-content">
                <div class="chat-wrap">
                    <aside class="chat-list" id="chatList">
                        <div class="chat-list-header">
                            <h6 class="mb-0">Messages</h6>
                            <button class="btn btn-sm new-chat-btn" id="newMsgBtn">
                                <i class="fas fa-plus me-1"></i> New
                            </button>
                        </div>
                        <div class="chat-search">
                            <input type="text" placeholder="Search conversations..." id="searchConv">
                        </div>
                        <div class="chat-list-body" id="convContainer">
                            <div class="loading"></div>
                        </div>
                    </aside>
                    <section class="chat-thread" id="chatThread">
                        <div class="chat-thread-header">
                            <div class="thread-header-content">
                                <button class="back-btn" id="backBtn">
                                    <i class="fas fa-arrow-left"></i>
                                </button>
                                <div class="thread-avatar" id="threadAvatar">?</div>
                                <div class="thread-info">
                                    <div class="thread-name" id="threadName">Select a conversation</div>
                                    <div class="thread-status" id="threadStatus"></div>
                                </div>
                            </div>
                        </div>
                        <div class="chat-thread-body" id="messageContainer">
                            <div class="empty-state">
                                <i class="fas fa-comments"></i>
                                <h5>Select a conversation</h5>
                                <p>Choose a conversation from the list to start messaging</p>
                            </div>
                        </div>
                        <form class="chat-composer" id="sendForm" style="display:none;">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="other_id" id="otherId">
                            <input type="hidden" name="client_uuid" id="clientUuid">
                            <div class="composer-input-wrapper">
                                <textarea class="form-control" name="body" id="msgBody" rows="1" placeholder="Type a message..." required></textarea>
                            </div>
                            <button class="send-btn" id="sendBtn" type="submit">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </section>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/registrar.js"></script>
    <script src="../../shared/js/clear-message-badge.js?v=<?php echo time(); ?>"></script>
    <script src="../../admin/messages/assets/messages-modern.js?v=<?php echo time(); ?>"></script>
</body>
</html>

