<?php
/**
 * Send SMS via AJAX
 * 
 * Handles SMS sending from donor profile page
 */

declare(strict_types=1);

// #region agent log
file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
    'sessionId' => 'debug-session',
    'runId' => 'run1',
    'hypothesisId' => 'A',
    'location' => 'send-ajax.php:1',
    'message' => 'Script started',
    'data' => ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'],
    'timestamp' => time() * 1000
]) . "\n", FILE_APPEND);
// #endregion

// Suppress any output that might break JSON
ob_start();

// Set JSON header early
header('Content-Type: application/json');

try {
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'B',
        'location' => 'send-ajax.php:20',
        'message' => 'Loading dependencies',
        'data' => [],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../services/MessagingHelper.php';
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'C',
        'location' => 'send-ajax.php:28',
        'message' => 'Checking authentication',
        'data' => [],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    require_login();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'D',
        'location' => 'send-ajax.php:40',
        'message' => 'Parsing POST data',
        'data' => [
            'has_donor_id' => isset($_POST['donor_id']),
            'has_phone' => isset($_POST['phone']),
            'has_message' => isset($_POST['message']),
            'donor_id' => $_POST['donor_id'] ?? null,
            'phone' => isset($_POST['phone']) ? substr($_POST['phone'], 0, 20) : null,
            'message_length' => isset($_POST['message']) ? strlen($_POST['message']) : 0
        ],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    $donor_id = isset($_POST['donor_id']) ? (int)$_POST['donor_id'] : 0;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $donor_name = isset($_POST['donor_name']) ? trim($_POST['donor_name']) : '';
    
    if (empty($phone)) {
        throw new Exception('Phone number is required');
    }
    
    if (empty($message)) {
        throw new Exception('Message is required');
    }
    
    if (strlen($message) > 1600) {
        throw new Exception('Message too long (max 1600 characters)');
    }
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'E',
        'location' => 'send-ajax.php:65',
        'message' => 'Initializing database and messaging',
        'data' => ['donor_id' => $donor_id],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    $db = db();
    $current_user = current_user();
    
    // Initialize messaging helper
    $msg = new MessagingHelper($db);
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'F',
        'location' => 'send-ajax.php:78',
        'message' => 'Sending message',
        'data' => [
            'donor_id' => $donor_id,
            'has_donor_id' => $donor_id > 0,
            'message_length' => strlen($message)
        ],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    // Send the SMS - Fixed method call
    if ($donor_id > 0) {
        // Send to donor (will log with donor_id)
        // Correct signature: sendToDonor(donorId, message, channel, sourceType)
        $result = $msg->sendToDonor($donor_id, $message, 'sms', 'donor_profile');
    } else {
        // Send direct (without donor association)
        // Correct signature: sendDirect(phoneNumber, message, channel, donorId, sourceType)
        $result = $msg->sendDirect($phone, $message, 'sms', null, 'donor_profile');
    }
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'G',
        'location' => 'send-ajax.php:95',
        'message' => 'Got result from messaging helper',
        'data' => [
            'success' => $result['success'] ?? false,
            'has_error' => isset($result['error']),
            'error' => $result['error'] ?? null,
            'channel' => $result['channel'] ?? null,
            'has_message_id' => isset($result['message_id'])
        ],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    // Clear any output buffer
    ob_clean();
    
    if ($result['success']) {
        $response = [
            'success' => true,
            'message' => 'SMS sent successfully',
            'channel' => $result['channel'] ?? 'sms',
            'message_id' => $result['message_id'] ?? null
        ];
        
        // #region agent log
        file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'H',
            'location' => 'send-ajax.php:115',
            'message' => 'Sending success response',
            'data' => $response,
            'timestamp' => time() * 1000
        ]) . "\n", FILE_APPEND);
        // #endregion
        
        echo json_encode($response);
    } else {
        throw new Exception($result['error'] ?? 'Failed to send SMS');
    }
    
} catch (Exception $e) {
    // Clear any output buffer
    ob_clean();
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'I',
        'location' => 'send-ajax.php:130',
        'message' => 'Exception caught',
        'data' => [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    // Clear any output buffer
    ob_clean();
    
    // #region agent log
    file_put_contents(__DIR__ . '/../../../.cursor/debug.log', json_encode([
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'J',
        'location' => 'send-ajax.php:150',
        'message' => 'Throwable caught',
        'data' => [
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ],
        'timestamp' => time() * 1000
    ]) . "\n", FILE_APPEND);
    // #endregion
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}

// End output buffering
ob_end_flush();
