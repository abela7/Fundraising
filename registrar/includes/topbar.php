<?php
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';

// Get user initials for avatar
$user_initials = '';
$display_name = current_user()['name'] ?? 'User';
$names = preg_split('/\s+/', trim($display_name));
foreach ($names as $name) {
    if ($name !== '') {
        $user_initials .= strtoupper(substr($name, 0, 1));
    }
}
$user_initials = substr($user_initials ?: 'U', 0, 2);

// Get unread message count
$unread_count = 0;
$current_user_id = current_user()['id'] ?? 0;
if ($current_user_id > 0) {
    $db = db();
    $stmt = $db->prepare('SELECT COUNT(*) as count FROM user_messages WHERE recipient_user_id = ? AND read_at IS NULL');
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $unread_count = (int)($result['count'] ?? 0);
    $stmt->close();
}
?>

<!-- Topbar -->
<header class="topbar">
    <div class="topbar-inner">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <button class="sidebar-toggle desktop-sidebar-toggle" id="desktopSidebarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="topbar-title d-none d-md-block">
                <?php
                // Dynamic page title
                $page_titles = [
                    'index' => 'New Registration',
                    'search' => 'Search & Update',
                    'my-registrations' => 'My Registrations',
                    'statistics' => 'Statistics'
                ];
                $current = basename($_SERVER['PHP_SELF'], '.php');
                
                // Check if we're in messages directory
                if (strpos($_SERVER['REQUEST_URI'], '/messages/') !== false) {
                    echo 'Messages';
                } else {
                    echo $page_titles[$current] ?? 'Registrar Panel';
                }
                ?>
            </h1>
        </div>
        
        <div class="topbar-right">
            <!-- Messages Icon with Notification -->
            <div class="d-flex align-items-center me-3">
                <?php 
                    $current_uri = $_SERVER['REQUEST_URI'] ?? '';
                    $messagesHref = (strpos($current_uri, '/registrar/messages/') !== false)
                        ? '#'
                        : './messages/';
                ?>
                <a href="<?php echo $messagesHref; ?>" 
                   class="btn btn-light border position-relative text-decoration-none" 
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
            <div class="user-menu dropdown">
                <button class="user-menu-toggle dropdown-toggle" data-bs-toggle="dropdown">
                    <div class="user-avatar"><?php echo $user_initials; ?></div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($display_name); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars(current_user()['role'] ?? 'Registrar'); ?></div>
                    </div>
                </button>
                
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/registrar/') !== false ? './profile.php' : '../registrar/profile.php'; ?>">
                            <i class="fas fa-user me-2"></i>My Profile
                        </a>
                    </li>
                    <li>
                        <a class="dropdown-item" href="./statistics.php">
                            <i class="fas fa-chart-bar me-2"></i>My Stats
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger" href="./logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>
