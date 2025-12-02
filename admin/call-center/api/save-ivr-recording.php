<?php
/**
 * Save IVR Voice Recording API
 * 
 * Handles saving voice recordings from browser MediaRecorder
 */

declare(strict_types=1);

header('Content-Type: application/json');

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../shared/auth.php';
    require_login();
    
    $db = db();
    
    // Get parameters
    $recordingId = (int)($_POST['recording_id'] ?? 0);
    $useRecording = (int)($_POST['use_recording'] ?? 0);
    $fallbackText = $_POST['fallback_text'] ?? '';
    
    if ($recordingId <= 0) {
        throw new Exception('Invalid recording ID');
    }
    
    // Get existing recording info
    $stmt = $db->prepare("SELECT * FROM ivr_voice_recordings WHERE id = ?");
    $stmt->bind_param('i', $recordingId);
    $stmt->execute();
    $recording = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$recording) {
        throw new Exception('Recording not found');
    }
    
    $updateFields = [
        'use_recording' => $useRecording,
        'fallback_text' => $fallbackText,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Handle audio file upload
    $formatNote = '';
    
    if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $audioFile = $_FILES['audio'];
        $isMp3Upload = ($_POST['is_mp3_upload'] ?? '0') === '1';
        
        // Create uploads directory if needed
        $uploadDir = __DIR__ . '/../../../uploads/ivr-recordings/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $timestamp = time();
        $key = $recording['recording_key'];
        
        // Determine file extension from upload
        $originalName = $audioFile['name'];
        $originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // If it's an MP3/WAV upload, keep the format
        if ($isMp3Upload && in_array($originalExt, ['mp3', 'wav'])) {
            $extension = $originalExt;
            $filename = "{$key}_{$timestamp}.{$extension}";
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file directly
            if (!move_uploaded_file($audioFile['tmp_name'], $filepath)) {
                throw new Exception('Failed to save audio file');
            }
            
            $formatNote = 'MP3/WAV file uploaded successfully - optimal for Twilio!';
            
        } else {
            // Browser recording (WebM) - try to convert to MP3
            $extension = 'webm';
            $filename = "{$key}_{$timestamp}.{$extension}";
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($audioFile['tmp_name'], $filepath)) {
                throw new Exception('Failed to save audio file');
            }
            
            // Try to convert to MP3 using ffmpeg if available
            $mp3Filename = "{$key}_{$timestamp}.mp3";
            $mp3Filepath = $uploadDir . $mp3Filename;
            
            $ffmpegPath = findFFmpeg();
            $conversionSuccess = false;
            
            if ($ffmpegPath) {
                // Convert WebM to MP3 for Twilio compatibility
                $cmd = escapeshellcmd($ffmpegPath) . ' -y -i ' . escapeshellarg($filepath) . 
                       ' -acodec libmp3lame -ab 128k -ar 8000 -ac 1 ' . escapeshellarg($mp3Filepath) . ' 2>&1';
                exec($cmd, $output, $returnCode);
                
                error_log("FFmpeg conversion: " . implode("\n", $output));
                
                if ($returnCode === 0 && file_exists($mp3Filepath) && filesize($mp3Filepath) > 0) {
                    // Use MP3 instead
                    @unlink($filepath);
                    $filepath = $mp3Filepath;
                    $filename = $mp3Filename;
                    $extension = 'mp3';
                    $conversionSuccess = true;
                    $formatNote = 'Recording converted to MP3 for Twilio compatibility.';
                }
            }
            
            if (!$conversionSuccess) {
                $formatNote = 'WARNING: Recording saved as WebM format. Twilio may not play this correctly. For best results, upload an MP3 file instead.';
                error_log("FFmpeg not available or conversion failed. File saved as WebM.");
            }
        }
        
        // Get audio duration
        $duration = getAudioDuration($filepath);
        
        // Build URL - ensure it's the full public URL
        $fileUrl = 'https://donate.abuneteklehaymanot.org/uploads/ivr-recordings/' . $filename;
        
        // Update fields
        $updateFields['file_path'] = $filepath;
        $updateFields['file_url'] = $fileUrl;
        $updateFields['file_size'] = filesize($filepath);
        $updateFields['duration_seconds'] = $duration;
        $updateFields['mime_type'] = ($extension === 'mp3') ? 'audio/mpeg' : (($extension === 'wav') ? 'audio/wav' : 'audio/webm');
        $updateFields['recorded_by'] = $_SESSION['user_id'] ?? null;
        $updateFields['use_recording'] = 1; // Auto-enable when recording
        
        // Delete old file if exists
        if ($recording['file_path'] && file_exists($recording['file_path'])) {
            @unlink($recording['file_path']);
        }
        
        // Save version history
        saveRecordingVersion($db, $recordingId, $filepath, $fileUrl, $duration);
    }
    
    // Build UPDATE query
    $setParts = [];
    $types = '';
    $values = [];
    
    foreach ($updateFields as $field => $value) {
        $setParts[] = "`$field` = ?";
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
        $values[] = $value;
    }
    
    $values[] = $recordingId;
    $types .= 'i';
    
    $sql = "UPDATE ivr_voice_recordings SET " . implode(', ', $setParts) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update recording: ' . $stmt->error);
    }
    
    // Log activity
    logRecordingActivity($db, $recordingId, isset($_FILES['audio']) ? 'recorded' : 'updated');
    
    echo json_encode([
        'success' => true,
        'message' => 'Recording saved successfully',
        'recording_id' => $recordingId,
        'file_url' => $updateFields['file_url'] ?? $recording['file_url'],
        'format_note' => $formatNote ?? null
    ]);
    
} catch (Throwable $e) {
    error_log("Save IVR Recording Error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Find FFmpeg executable
 */
function findFFmpeg(): ?string {
    $paths = [
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        'C:\\ffmpeg\\bin\\ffmpeg.exe',
        'ffmpeg'
    ];
    
    foreach ($paths as $path) {
        if (is_executable($path)) {
            return $path;
        }
        // Try which/where command
        $cmd = (PHP_OS_FAMILY === 'Windows') ? 'where' : 'which';
        $result = shell_exec("$cmd ffmpeg 2>/dev/null");
        if ($result) {
            return trim($result);
        }
    }
    
    return null;
}

/**
 * Get audio duration using ffprobe or file size estimation
 */
function getAudioDuration(string $filepath): float {
    // Try ffprobe first
    $ffprobePath = str_replace('ffmpeg', 'ffprobe', findFFmpeg() ?? '');
    if ($ffprobePath && is_executable($ffprobePath)) {
        $cmd = escapeshellcmd($ffprobePath) . 
               ' -i ' . escapeshellarg($filepath) . 
               ' -show_entries format=duration -v quiet -of csv="p=0"';
        $duration = trim(shell_exec($cmd) ?? '');
        if (is_numeric($duration)) {
            return (float)$duration;
        }
    }
    
    // Estimate based on file size (rough approximation)
    $filesize = filesize($filepath);
    // Assume ~16KB per second for webm/mp3
    return round($filesize / 16000, 2);
}

/**
 * Save recording version for history
 */
function saveRecordingVersion($db, int $recordingId, string $filepath, string $fileUrl, float $duration): void {
    try {
        // Get next version number
        $stmt = $db->prepare("SELECT MAX(version_number) as max_ver FROM ivr_recording_versions WHERE recording_id = ?");
        $stmt->bind_param('i', $recordingId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $nextVersion = ($result['max_ver'] ?? 0) + 1;
        $stmt->close();
        
        $userId = $_SESSION['user_id'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO ivr_recording_versions (recording_id, version_number, file_path, file_url, duration_seconds, recorded_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iissdi', $recordingId, $nextVersion, $filepath, $fileUrl, $duration, $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Version save error: " . $e->getMessage());
    }
}

/**
 * Log recording activity
 */
function logRecordingActivity($db, int $recordingId, string $action): void {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $stmt = $db->prepare("
            INSERT INTO ivr_recording_logs (recording_id, action, user_id, ip_address)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('isis', $recordingId, $action, $userId, $ip);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Log activity error: " . $e->getMessage());
    }
}

/**
 * Get base URL
 */
function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'donate.abuneteklehaymanot.org';
    return $protocol . '://' . $host . '/';
}

