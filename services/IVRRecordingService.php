<?php
/**
 * IVR Recording Service
 * 
 * Helps IVR files use recorded audio or fall back to TTS
 */

declare(strict_types=1);

class IVRRecordingService
{
    private $db;
    private array $cache = [];
    private string $defaultVoice = 'Google.en-GB-Neural2-B';
    
    public function __construct($db)
    {
        $this->db = $db;
        $this->loadRecordings();
    }
    
    /**
     * Load all active recordings into cache
     */
    private function loadRecordings(): void
    {
        try {
            $result = $this->db->query("
                SELECT recording_key, file_url, fallback_text, fallback_voice, use_recording, is_active
                FROM ivr_voice_recordings 
                WHERE is_active = 1
            ");
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $this->cache[$row['recording_key']] = $row;
                }
            }
        } catch (Exception $e) {
            error_log("IVR Recording load error: " . $e->getMessage());
        }
    }
    
    /**
     * Get TwiML for a recording key
     * Returns <Play> if recording exists, <Say> with TTS otherwise
     * 
     * @param string $key Recording key
     * @param string|null $fallbackText Override fallback text
     * @param array $replacements Text replacements for dynamic content like {name}
     * @return string TwiML string
     */
    public function getTwiML(string $key, ?string $fallbackText = null, array $replacements = []): string
    {
        $recording = $this->cache[$key] ?? null;
        
        // If recording exists and is enabled, use Play
        if ($recording && $recording['use_recording'] && !empty($recording['file_url'])) {
            $this->incrementPlayCount($key);
            return '<Play>' . htmlspecialchars($recording['file_url']) . '</Play>';
        }
        
        // Otherwise use TTS
        $text = $fallbackText ?? ($recording['fallback_text'] ?? '');
        $voice = $recording['fallback_voice'] ?? $this->defaultVoice;
        
        // Apply replacements
        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', $value, $text);
        }
        
        if (empty($text)) {
            return ''; // No content
        }
        
        return '<Say voice="' . htmlspecialchars($voice) . '">' . htmlspecialchars($text) . '</Say>';
    }
    
    /**
     * Get just the URL for a recording (for custom handling)
     */
    public function getRecordingUrl(string $key): ?string
    {
        $recording = $this->cache[$key] ?? null;
        
        if ($recording && $recording['use_recording'] && !empty($recording['file_url'])) {
            return $recording['file_url'];
        }
        
        return null;
    }
    
    /**
     * Check if a recording exists and is enabled
     */
    public function hasRecording(string $key): bool
    {
        $recording = $this->cache[$key] ?? null;
        return $recording && $recording['use_recording'] && !empty($recording['file_url']);
    }
    
    /**
     * Get fallback text for a key
     */
    public function getFallbackText(string $key): string
    {
        return $this->cache[$key]['fallback_text'] ?? '';
    }
    
    /**
     * Get voice setting for a key
     */
    public function getVoice(string $key): string
    {
        return $this->cache[$key]['fallback_voice'] ?? $this->defaultVoice;
    }
    
    /**
     * Increment play count for analytics
     */
    private function incrementPlayCount(string $key): void
    {
        try {
            $this->db->query("
                UPDATE ivr_voice_recordings 
                SET play_count = play_count + 1, last_played_at = NOW() 
                WHERE recording_key = '" . $this->db->real_escape_string($key) . "'
            ");
        } catch (Exception $e) {
            // Silently fail - not critical
        }
    }
    
    /**
     * Helper: Generate Say TwiML with voice
     */
    public function say(string $text, ?string $voice = null): string
    {
        $voice = $voice ?? $this->defaultVoice;
        return '<Say voice="' . htmlspecialchars($voice) . '">' . htmlspecialchars($text) . '</Say>';
    }
    
    /**
     * Helper: Generate Pause TwiML
     */
    public function pause(int $seconds = 1): string
    {
        return '<Pause length="' . $seconds . '"/>';
    }
    
    /**
     * Create instance from database
     */
    public static function create($db): self
    {
        return new self($db);
    }
}

