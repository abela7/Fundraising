<?php
// To be included at the top of the main content area of admin pages.
// It checks for a database error message (set by resilient_db_loader.php)
// and displays a prominent, helpful alert to the user.
if (!empty($db_error_message)): ?>
    <div class="alert alert-danger m-3 shadow-sm">
        <strong><i class="fas fa-exclamation-triangle me-2"></i>Database Error:</strong>
        <?php echo htmlspecialchars($db_error_message); ?> 
        Please go to <a href="../tools/import_helper.php" class="alert-link">Tools -> Import Helper</a> to restore the database from a backup.
    </div>
<?php endif; ?>
