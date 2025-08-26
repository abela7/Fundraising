<?php
declare(strict_types=1);

/**
 * Bot Detection System for Donation Forms
 * Detects automated submissions and suspicious behavior
 */
class BotDetector {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Analyze submission for bot-like behavior
     * Returns: ['is_bot' => bool, 'confidence' => float, 'reasons' => array]
     */
    public function analyzeSubmission(array $postData, array $serverData): array {
        $reasons = [];
        $botScore = 0;
        
        // Check honeypot fields
        $honeypotScore = $this->checkHoneypots($postData);
        $botScore += $honeypotScore['score'];
        if ($honeypotScore['triggered']) {
            $reasons[] = 'Honeypot field filled';
        }
        
        // Check submission timing
        $timingScore = $this->checkSubmissionTiming($postData);
        $botScore += $timingScore['score'];
        if ($timingScore['suspicious']) {
            $reasons[] = $timingScore['reason'];
        }
        
        // Check user agent
        $uaScore = $this->checkUserAgent($serverData);
        $botScore += $uaScore['score'];
        if ($uaScore['suspicious']) {
            $reasons[] = $uaScore['reason'];
        }
        
        // Check for missing browser features
        $browserScore = $this->checkBrowserFeatures($postData);
        $botScore += $browserScore['score'];
        if ($browserScore['suspicious']) {
            $reasons[] = $browserScore['reason'];
        }
        
        // Check form interaction patterns
        $interactionScore = $this->checkInteractionPatterns($postData);
        $botScore += $interactionScore['score'];
        if ($interactionScore['suspicious']) {
            $reasons[] = $interactionScore['reason'];
        }
        
        $confidence = min(1.0, $botScore / 100); // Normalize to 0-1
        $isBot = $confidence >= 0.7; // 70% confidence threshold
        
        return [
            'is_bot' => $isBot,
            'confidence' => $confidence,
            'score' => $botScore,
            'reasons' => $reasons
        ];
    }
    
    /**
     * Check honeypot fields (invisible fields that bots often fill)
     */
    private function checkHoneypots(array $postData): array {
        $honeypotFields = [
            'website',      // Common honeypot name
            'url',          // Another common one
            'email_check',  // Fake email field
            'address_2',    // Hidden address field
            'company'       // Hidden company field
        ];
        
        $triggered = false;
        $score = 0;
        
        foreach ($honeypotFields as $field) {
            if (isset($postData[$field]) && !empty(trim($postData[$field]))) {
                $triggered = true;
                $score += 50; // High bot score for honeypot
                break;
            }
        }
        
        return [
            'triggered' => $triggered,
            'score' => $score
        ];
    }
    
    /**
     * Check submission timing patterns
     */
    private function checkSubmissionTiming(array $postData): array {
        $suspicious = false;
        $reason = '';
        $score = 0;
        
        // Check if form was submitted too quickly
        if (isset($postData['form_loaded_at'])) {
            $loadTime = (int)$postData['form_loaded_at'];
            $currentTime = time();
            $fillTime = $currentTime - $loadTime;
            
            if ($fillTime < 5) {
                // Form filled in less than 5 seconds - very suspicious
                $suspicious = true;
                $reason = 'Form submitted too quickly';
                $score = 40;
            } elseif ($fillTime < 10) {
                // Form filled in less than 10 seconds - somewhat suspicious
                $suspicious = true;
                $reason = 'Form submitted very quickly';
                $score = 20;
            }
        }
        
        // Check for precise timing patterns (bots often have exact timings)
        if (isset($postData['interaction_timing'])) {
            $timings = json_decode($postData['interaction_timing'], true);
            if (is_array($timings) && $this->hasRoboticTiming($timings)) {
                $suspicious = true;
                $reason = 'Robotic timing patterns detected';
                $score += 30;
            }
        }
        
        return [
            'suspicious' => $suspicious,
            'reason' => $reason,
            'score' => $score
        ];
    }
    
    /**
     * Check user agent for bot signatures
     */
    private function checkUserAgent(array $serverData): array {
        $userAgent = $serverData['HTTP_USER_AGENT'] ?? '';
        $suspicious = false;
        $reason = '';
        $score = 0;
        
        // Common bot signatures
        $botSignatures = [
            'curl', 'wget', 'python', 'bot', 'crawler', 'spider',
            'scraper', 'automated', 'headless', 'phantom'
        ];
        
        $lowerUA = strtolower($userAgent);
        foreach ($botSignatures as $signature) {
            if (strpos($lowerUA, $signature) !== false) {
                $suspicious = true;
                $reason = 'Bot-like user agent detected';
                $score = 60;
                break;
            }
        }
        
        // Empty or very short user agent
        if (empty($userAgent) || strlen($userAgent) < 20) {
            $suspicious = true;
            $reason = 'Missing or suspicious user agent';
            $score = 30;
        }
        
        return [
            'suspicious' => $suspicious,
            'reason' => $reason,
            'score' => $score
        ];
    }
    
    /**
     * Check for missing browser features that bots often lack
     */
    private function checkBrowserFeatures(array $postData): array {
        $suspicious = false;
        $reason = '';
        $score = 0;
        
        // Check for JavaScript execution proof
        if (!isset($postData['js_enabled']) || $postData['js_enabled'] !== '1') {
            $suspicious = true;
            $reason = 'JavaScript not properly executed';
            $score += 25;
        }
        
        // Check for screen resolution (set by JS)
        if (!isset($postData['screen_resolution']) || empty($postData['screen_resolution'])) {
            $suspicious = true;
            $reason = 'Missing browser screen data';
            $score += 15;
        }
        
        // Check for timezone data (set by JS)
        if (!isset($postData['timezone']) || empty($postData['timezone'])) {
            $suspicious = true;
            $reason = 'Missing timezone data';
            $score += 10;
        }
        
        return [
            'suspicious' => $suspicious,
            'reason' => $reason,
            'score' => $score
        ];
    }
    
    /**
     * Check form interaction patterns
     */
    private function checkInteractionPatterns(array $postData): array {
        $suspicious = false;
        $reason = '';
        $score = 0;
        
        // Check for focus/blur events tracking
        if (isset($postData['form_interactions'])) {
            $interactions = json_decode($postData['form_interactions'], true);
            if (is_array($interactions)) {
                
                // No interactions recorded - suspicious
                if (empty($interactions)) {
                    $suspicious = true;
                    $reason = 'No form interactions recorded';
                    $score += 20;
                }
                
                // Check for unnatural interaction patterns
                if ($this->hasUnaturalInteractions($interactions)) {
                    $suspicious = true;
                    $reason = 'Unnatural interaction patterns';
                    $score += 25;
                }
            }
        }
        
        // Check for mouse movement data
        if (!isset($postData['mouse_moved']) || $postData['mouse_moved'] !== '1') {
            $suspicious = true;
            $reason = 'No mouse movement detected';
            $score += 15;
        }
        
        return [
            'suspicious' => $suspicious,
            'reason' => $reason,
            'score' => $score
        ];
    }
    
    /**
     * Detect robotic timing patterns
     */
    private function hasRoboticTiming(array $timings): bool {
        if (count($timings) < 3) return false;
        
        $intervals = [];
        for ($i = 1; $i < count($timings); $i++) {
            $intervals[] = $timings[$i] - $timings[$i-1];
        }
        
        // Check for identical intervals (robotic)
        $uniqueIntervals = array_unique($intervals);
        if (count($uniqueIntervals) === 1) {
            return true; // All intervals identical = robotic
        }
        
        // Check for very precise timing (millisecond precision is suspicious)
        foreach ($intervals as $interval) {
            if ($interval > 0 && $interval < 100 && $interval % 10 === 0) {
                return true; // Too precise timing
            }
        }
        
        return false;
    }
    
    /**
     * Detect unnatural form interactions
     */
    private function hasUnaturalInteractions(array $interactions): bool {
        // Check for lack of focus events
        $focusEvents = array_filter($interactions, function($event) {
            return isset($event['type']) && $event['type'] === 'focus';
        });
        
        if (empty($focusEvents)) {
            return true; // No focus events is unnatural
        }
        
        // Check for perfect sequential field filling (no backtracking)
        $fieldOrder = [];
        foreach ($interactions as $event) {
            if (isset($event['field']) && $event['type'] === 'focus') {
                $fieldOrder[] = $event['field'];
            }
        }
        
        // If fields were focused in perfect order with no repeats, it's suspicious
        if (count($fieldOrder) === count(array_unique($fieldOrder))) {
            // Perfect sequential order is somewhat suspicious for long forms
            return count($fieldOrder) > 5;
        }
        
        return false;
    }
    
    /**
     * Generate honeypot fields HTML
     */
    public static function generateHoneypotFields(): string {
        return '
        <!-- Honeypot fields - hidden from users but visible to bots -->
        <div style="position: absolute; left: -9999px; top: -9999px; visibility: hidden;" aria-hidden="true">
            <input type="text" name="website" tabindex="-1" autocomplete="off">
            <input type="email" name="email_check" tabindex="-1" autocomplete="off">
            <input type="text" name="company" tabindex="-1" autocomplete="off">
            <input type="text" name="address_2" tabindex="-1" autocomplete="off">
        </div>';
    }
    
    /**
     * Generate JavaScript for bot detection
     */
    public static function generateDetectionScript(): string {
        return "
        <script>
        (function() {
            // Track form load time
            const formLoadTime = Math.floor(Date.now() / 1000);
            const formLoadInput = document.createElement('input');
            formLoadInput.type = 'hidden';
            formLoadInput.name = 'form_loaded_at';
            formLoadInput.value = formLoadTime;
            document.body.appendChild(formLoadInput);
            
            // Prove JavaScript is enabled
            const jsInput = document.createElement('input');
            jsInput.type = 'hidden';
            jsInput.name = 'js_enabled';
            jsInput.value = '1';
            document.body.appendChild(jsInput);
            
            // Capture screen resolution
            const screenInput = document.createElement('input');
            screenInput.type = 'hidden';
            screenInput.name = 'screen_resolution';
            screenInput.value = screen.width + 'x' + screen.height;
            document.body.appendChild(screenInput);
            
            // Capture timezone
            const timezoneInput = document.createElement('input');
            timezoneInput.type = 'hidden';
            timezoneInput.name = 'timezone';
            timezoneInput.value = Intl.DateTimeFormat().resolvedOptions().timeZone;
            document.body.appendChild(timezoneInput);
            
            // Track mouse movement
            let mouseMoved = false;
            document.addEventListener('mousemove', function() {
                if (!mouseMoved) {
                    mouseMoved = true;
                    const mouseInput = document.createElement('input');
                    mouseInput.type = 'hidden';
                    mouseInput.name = 'mouse_moved';
                    mouseInput.value = '1';
                    document.body.appendChild(mouseInput);
                }
            });
            
            // Track form interactions
            const interactions = [];
            const form = document.querySelector('form');
            if (form) {
                const inputs = form.querySelectorAll('input, textarea, select');
                inputs.forEach(input => {
                    input.addEventListener('focus', function() {
                        interactions.push({
                            type: 'focus',
                            field: this.name || this.id,
                            time: Date.now()
                        });
                    });
                    
                    input.addEventListener('blur', function() {
                        interactions.push({
                            type: 'blur',
                            field: this.name || this.id,
                            time: Date.now()
                        });
                    });
                });
                
                // Add interactions data before submit
                form.addEventListener('submit', function() {
                    const interactionsInput = document.createElement('input');
                    interactionsInput.type = 'hidden';
                    interactionsInput.name = 'form_interactions';
                    interactionsInput.value = JSON.stringify(interactions);
                    this.appendChild(interactionsInput);
                });
            }
        })();
        </script>";
    }
}
?>
