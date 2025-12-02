<?php
/**
 * Test IVR Recording - Diagnostic Tool
 * 
 * Check if recording is accessible and in correct format
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../shared/auth.php';

try {
    $db = db();
    
    $recordingKey = $_GET['key'] ?? 'welcome_main';
    
    // Get the recording
    $stmt = $db->prepare("SELECT * FROM ivr_voice_recordings WHERE recording_key = ?");
    $stmt->bind_param('s', $recordingKey);
    $stmt->execute();
    $recording = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$recording) {
        throw new Exception("Recording not found: $recordingKey");
    }
    
    $diagnostics = [
        'recording_key' => $recordingKey,
        'title' => $recording['title'],
        'use_recording' => (bool)$recording['use_recording'],
        'file_path' => $recording['file_path'],
        'file_url' => $recording['file_url'],
        'mime_type' => $recording['mime_type'],
        'duration_seconds' => $recording['duration_seconds'],
        'file_size' => $recording['file_size'],
        'checks' => []
    ];
    
    // Check 1: File exists
    if ($recording['file_path']) {
        $fileExists = file_exists($recording['file_path']);
        $diagnostics['checks']['file_exists'] = $fileExists;
        
        if ($fileExists) {
            $diagnostics['checks']['actual_file_size'] = filesize($recording['file_path']);
            $diagnostics['checks']['file_readable'] = is_readable($recording['file_path']);
            
            // Check file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $actualMime = finfo_file($finfo, $recording['file_path']);
            finfo_close($finfo);
            $diagnostics['checks']['actual_mime_type'] = $actualMime;
            
            // Check if it's a supported format for Twilio
            $supportedFormats = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave'];
            $diagnostics['checks']['twilio_compatible'] = in_array($actualMime, $supportedFormats);
            
            if (!in_array($actualMime, $supportedFormats)) {
                $diagnostics['checks']['format_warning'] = "Twilio prefers MP3 or WAV. Current format ($actualMime) may not play correctly.";
            }
        }
    } else {
        $diagnostics['checks']['file_exists'] = false;
        $diagnostics['checks']['warning'] = 'No file path set';
    }
    
    // Check 2: URL accessible
    if ($recording['file_url']) {
        $headers = @get_headers($recording['file_url']);
        $diagnostics['checks']['url_accessible'] = $headers && strpos($headers[0], '200') !== false;
        $diagnostics['checks']['url_response'] = $headers ? $headers[0] : 'No response';
    }
    
    // Check 3: Generate what TwiML would be produced
    $diagnostics['twiml_output'] = '';
    if ($recording['use_recording'] && $recording['file_url']) {
        $diagnostics['twiml_output'] = '<Play>' . htmlspecialchars($recording['file_url']) . '</Play>';
        $diagnostics['mode'] = 'RECORDING';
    } else {
        $diagnostics['twiml_output'] = '<Say voice="' . ($recording['fallback_voice'] ?? 'Google.en-GB-Neural2-B') . '">' . htmlspecialchars($recording['fallback_text'] ?? '') . '</Say>';
        $diagnostics['mode'] = 'TTS';
    }
    
    // Recommendation
    if (isset($diagnostics['checks']['twilio_compatible']) && !$diagnostics['checks']['twilio_compatible']) {
        $diagnostics['recommendation'] = 'Convert the audio file to MP3 format for better Twilio compatibility. WebM format often causes distorted playback.';
        $diagnostics['action_needed'] = true;
    } else {
        $diagnostics['recommendation'] = 'Recording appears to be correctly configured.';
        $diagnostics['action_needed'] = false;
    }
    
    echo json_encode($diagnostics, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

