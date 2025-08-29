<?php
// Reusable Top Navigation Bar Component
require_once __DIR__ . '/../../config/db.php';
$user = current_user();
$page_title = $page_title ?? 'Dashboard';

// Get unread message count
$unread_count = 0;
$current_user_id = $user['id'] ?? 0;
if ($current_user_id > 0) {
    try {
        $db = db();
        // Check if the table exists before querying
        $table_check = $db->query("SHOW TABLES LIKE 'user_messages'");
        if ($table_check && $table_check->num_rows > 0) {
            $stmt = $db->prepare('SELECT COUNT(*) as count FROM user_messages WHERE recipient_user_id = ? AND read_at IS NULL');
            $stmt->bind_param('i', $current_user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $unread_count = (int)($result['count'] ?? 0);
            $stmt->close();
        }
    } catch (Exception $e) {
        // If the database connection fails (e.g., during setup), just default to 0.
        // This makes the UI resilient.
        $unread_count = 0;
    }
}
?>
<header class="topbar">
  <div class="topbar-left">
    <button class="topbar-toggle" onclick="toggleSidebar()">
      <i class="fas fa-bars"></i>
    </button>
    <h1 class="topbar-title"><?php echo htmlspecialchars($page_title); ?></h1>
  </div>
  
  <div class="topbar-right">
    
    
    <!-- Quick Actions -->
    <div class="topbar-item d-none d-sm-block">
      <button class="topbar-button" onclick="window.location.href='../approvals/'">
        <i class="fas fa-plus-circle"></i>
      </button>
    </div>

    <!-- Messages Shortcut -->
    <div class="topbar-item d-none d-sm-block">
      <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/messages/') !== false ? '#' : '../messages/'; ?>" 
         class="topbar-button position-relative text-decoration-none d-flex align-items-center justify-content-center" 
         title="Messages" 
         id="messagesBtn">
        <i class="fas fa-comments"></i>
        <?php if ($unread_count > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="messageNotification">
          <span id="messageCount"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
        </span>
        <?php endif; ?>
      </a>
    </div>
    
    <!-- User Menu -->
    <div class="topbar-item dropdown">
      <button class="topbar-user" data-bs-toggle="dropdown">
        <div class="user-avatar">
          <i class="fas fa-user"></i>
        </div>
        <div class="user-info d-none d-md-block">
          <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
          <span class="user-role"><?php echo ucfirst($user['role']); ?></span>
        </div>
        <i class="fas fa-chevron-down ms-2"></i>
      </button>
      <div class="dropdown-menu dropdown-menu-end user-dropdown">
        <div class="dropdown-header">
          <div class="user-avatar lg">
            <i class="fas fa-user"></i>
          </div>
          <div>
            <h6><?php echo htmlspecialchars($user['name']); ?></h6>
            <small><?php echo htmlspecialchars($user['phone']); ?></small>
          </div>
        </div>
        <div class="dropdown-divider"></div>
        <a href="../profile/" class="dropdown-item">
          <i class="fas fa-user-circle"></i> My Profile
        </a>
        <a href="../settings/" class="dropdown-item">
          <i class="fas fa-cog"></i> Settings
        </a>
        <div class="dropdown-divider"></div>
        <a href="../logout.php" class="dropdown-item text-danger">
          <i class="fas fa-sign-out-alt"></i> Logout
        </a>
      </div>
    </div>
  </div>
</header>
