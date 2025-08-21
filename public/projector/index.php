<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';

$settings = db()->query('SELECT projector_names_mode, refresh_seconds, target_amount, currency_code, projector_language FROM settings WHERE id=1')->fetch_assoc();
if (!$settings) {
    // Use defaults if no settings found
    $settings = [
        'projector_names_mode' => 'full',
        'refresh_seconds' => 10,
        'target_amount' => 100000,
        'currency_code' => 'GBP',
        'projector_language' => 'en'
    ];
}
$refresh = max(1, (int)$settings['refresh_seconds']);
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$target = (float)$settings['target_amount'];
$language = $settings['projector_language'] ?? 'en';
?>
<!DOCTYPE html>
<html lang="<?= $language === 'am' ? 'am' : 'en' ?>">
<head>
  <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title id="pageTitle">Live Fundraising Display</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="./assets/projector-live.css">
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link rel="alternate icon" href="../../favicon.ico">
</head>
<body>
    <button class="fullscreen-btn" id="fullscreenBtn" title="Toggle Fullscreen (F)">
        <i class="fas fa-expand"></i>
        <span class="label" data-translate="fullscreen">Fullscreen</span>
    </button>
    
    <!-- Fixed Top Progress Bar -->
    <header class="progress-header">
        <div class="progress-container">
            <div class="progress-info">
                <h1 class="campaign-title" data-translate="campaign_title">Live Fundraising Campaign</h1>
                <div class="progress-stats">
                    <span class="progress-current"><?= $currency ?> <span id="progressCurrent">0</span></span>
                    <span class="progress-separator" data-translate="progress_of">of</span>
                    <span class="progress-target"><?= $currency ?> <?= number_format($target, 0) ?></span>
                </div>
            </div>
            <div class="progress-bar-container">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressBar"></div>
                    <div class="progress-shimmer"></div>
                </div>
                <div class="progress-percentage" id="progressPercent">0%</div>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Fixed Left Panel - Totals -->
        <aside class="totals-panel">
            <div class="total-card paid-card">
                <div class="card-header">
                    <i class="fas fa-check-circle"></i>
                    <h3 data-translate="total_paid">Total Paid</h3>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="paidTotal">0</span>
                </div>
                <div class="card-indicator">
                    <div class="indicator-pulse"></div>
                </div>
            </div>

            <div class="total-card pledged-card">
                <div class="card-header">
                    <i class="fas fa-church"></i>
                    <h3 data-translate="total_pledged">Total Pledged</h3>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="pledgedTotal">0</span>
                </div>
                <div class="card-indicator">
                    <div class="indicator-pulse"></div>
                </div>
            </div>

            <div class="total-card grand-card">
                <div class="card-header">
                    <i class="fas fa-trophy"></i>
                    <h3 data-translate="grand_total">Grand Total</h3>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="grandTotal">0</span>
                </div>
                <div class="card-indicator">
                    <div class="indicator-pulse"></div>
                </div>
            </div>

            <!-- Live Clock -->
            <div class="live-info">
                <div class="live-clock">
                    <i class="fas fa-clock"></i>
                    <span id="clock">00:00:00</span>
                </div>
                <div class="live-status">
                    <span class="status-dot"></span>
                    <span data-translate="live_status">LIVE</span>
                </div>
            </div>
        </aside>

        <!-- Scrollable Right Panel - Recent Contributions -->
        <section class="contributions-panel">
            <div class="contributions-list" id="contributionsList">
                <div class="loading-message">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span data-translate="waiting_contributions">Waiting for contributions...</span>
                </div>
            </div>
        </section>
    </main>

    <!-- Professional News Ticker Footer -->
    <footer class="message-footer" id="messageFooter">
        <div class="footer-content">
            <div class="ticker-content">
                <i class="fas fa-info-circle"></i>
                <span id="footerMessage">Loading...</span>
            </div>
        </div>
    </footer>

    <!-- Special Effects Container -->
    <div class="effects-container" id="effectsContainer"></div>

    <!-- Announcement Overlay -->
    <div class="announcement-overlay" id="announcementOverlay">
        <div class="announcement-content">
            <div class="announcement-icon">
                <i class="fas fa-bullhorn"></i>
            </div>
            <div class="announcement-text" id="announcementText"></div>
  </div>
</div>

<script>
    // Configuration
    const config = {
        refresh: <?= (int)$refresh ?> * 1000,
        currency: <?= json_encode($currency) ?>,
        target: <?= $target ?>,
        language: <?= json_encode($language) ?>
    };

    // Translation system
    let translations = {};
    
    // Load translations
    async function loadTranslations() {
        const lang = config.language || 'en';
        try {
            const response = await fetch(`translations-${lang}.json?v=${new Date().getTime()}`); // Append timestamp to bypass cache
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            translations = await response.json();
            applyTranslations();
        } catch (error) {
            console.error('Error loading translations:', error);
        }
    }
    
    // Apply translations to elements
    function applyTranslations() {
        // Update page title
        document.title = t('page_title');
        document.getElementById('pageTitle').textContent = t('page_title');
        
        // Update all elements with data-translate attribute
        document.querySelectorAll('[data-translate]').forEach(element => {
            const key = element.getAttribute('data-translate');
            element.textContent = t(key);
        });
        
        // Update fullscreen button title
        document.getElementById('fullscreenBtn').title = t('toggle_fullscreen');
    }
    
    // Translation function
    function t(key, replacements = {}) {
        let text = translations[key] || key;
        
        // Handle replacements like {{percent}}
        Object.keys(replacements).forEach(placeholder => {
            text = text.replace(new RegExp(`{{${placeholder}}}`, 'g'), replacements[placeholder]);
        });
        
        return text;
    }
    
    // Update existing functions to use translations
    function formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return t('just_now');
        if (diff < 3600) return Math.floor(diff / 60) + t('minutes_ago');
        if (diff < 86400) return Math.floor(diff / 3600) + t('hours_ago');
        return date.toLocaleDateString();
    }
    
    function checkMilestones(percent) {
        const milestones = [25, 50, 75, 100];
        milestones.forEach(milestone => {
            if (percent >= milestone && state.progress < milestone) {
                showAnnouncement(t('celebration_milestone', {percent: milestone}), 'milestone', 5000);
            }
        });
    }
    
    function triggerCelebration() {
        const container = document.getElementById('effectsContainer');
        container.innerHTML = `<div class="celebration-text">${t('celebration_goal')}</div>`;
        
        // Create confetti
        for (let i = 0; i < 100; i++) {
            const confetti = document.createElement('div');
            confetti.className = 'confetti';
            confetti.style.left = Math.random() * 100 + '%';
            confetti.style.animationDelay = Math.random() * 3 + 's';
            confetti.style.backgroundColor = ['#FFD700', '#FF69B4', '#00CED1', '#32CD32'][Math.floor(Math.random() * 4)];
            container.appendChild(confetti);
        }
        
        setTimeout(() => {
            container.innerHTML = '';
        }, 5000);
    }
    
    // Update formatContributionText to handle translations
    async function formatContributionText(originalText, amount) {
        // ... existing logic ...
        
        if (state.displayMode === 'sqm' || state.displayMode === 'both') {
            try {
                const apiUrl = `../../api/calculate_sqm.php?amount=${amount}`;
                const response = await fetch(apiUrl);
                
                if (response.ok) {
                    const data = await response.json();
                    
                    if (data.success) {
                        const nameMatch = originalText.match(/^(.+?)\s+(paid|pledged)\s+/i);
                        
                        if (nameMatch) {
                            const donorName = nameMatch[1].trim();
                            const action = nameMatch[2].toLowerCase();
                            const translatedAction = t(action);
                            
                            let newText = '';
                            if (state.displayMode === 'sqm') {
                                // Use translated "Square Meter(s)"
                                const sqmText = data.sqm_display.replace(/Square Meters?/i, 
                                    data.sqm_display.includes('1 Square Meter') && !data.sqm_display.includes('1.') ? 
                                    t('square_meter') : t('square_meters'));
                                newText = `${donorName} ${translatedAction} ${sqmText}`;
                            } else { // both
                                const sqmText = data.sqm_display.replace(/Square Meters?/i, 
                                    data.sqm_display.includes('1 Square Meter') && !data.sqm_display.includes('1.') ? 
                                    t('square_meter') : t('square_meters'));
                                const amountFormatted = `£${amount}`;
                                newText = `${donorName} ${translatedAction} ${sqmText} (${amountFormatted})`;
                            }
                            
                            return newText;
                        }
                    }
                }
            } catch (error) {
                console.error('Error calculating square meters:', error);
            }
        }
        
        return originalText;
    }
    
    // Update showScrollHint function
    function showScrollHint() {
        const container = document.getElementById('contributionsList');
        
        let hint = container.querySelector('.scroll-hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'scroll-hint';
            hint.innerHTML = `
                <i class="fas fa-arrow-up"></i>
                <span>${t('new_contributions_above')}</span>
            `;
            hint.addEventListener('click', () => {
                container.scrollTo({ top: 0, behavior: 'smooth' });
                hint.style.display = 'none';
            });
            container.appendChild(hint);
        }
        
        hint.style.display = 'flex';
        
        setTimeout(() => {
            if (hint) hint.style.display = 'none';
        }, 5000);
    }
    
    // Update fullscreen button text
    function updateFullscreenButton() {
        const fsBtn = document.getElementById('fullscreenBtn');
        const isFs = isFullscreen();
        
        fsBtn.querySelector('i').className = isFs ? 'fas fa-compress' : 'fas fa-expand';
        fsBtn.querySelector('.label').textContent = isFs ? t('exit_fullscreen') : t('fullscreen');
    }
    
    // Update toggleFullscreen function
    async function toggleFullscreen() {
        try {
            if (!isFullscreen()) {
                const el = document.documentElement;
                if (el.requestFullscreen) await el.requestFullscreen();
                else if (el.webkitRequestFullscreen) await el.webkitRequestFullscreen();
                else if (el.msRequestFullscreen) await el.msRequestFullscreen();
            } else {
                if (document.exitFullscreen) await document.exitFullscreen();
                else if (document.webkitExitFullscreen) await document.webkitExitFullscreen();
                else if (document.msExitFullscreen) await document.msExitFullscreen();
            }
            updateFullscreenButton();
        } catch (e) {
            console.error('Fullscreen error:', e);
        }
    }
    
    // Main initialization function
    async function init() {
        await loadTranslations(); // Load translations first
        
        // Set intervals
        setInterval(updateClock, 1000);
        updateClock();
        
        // Fetch initial data
        fetchTotals();
        fetchRecent();
        fetchFooterMessage();
        
        // Set up polling
        setInterval(fetchTotals, config.refresh);
        setInterval(fetchRecent, config.refresh);
        setInterval(fetchFooterMessage, config.refresh * 2); // Check footer less frequently
        
        // Initialize admin communication
        initAdminCommunication();
        
        // Setup scroll detection
        setupScrollDetection();
        
        // Handle visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                fetchTotals();
                fetchRecent();
                fetchFooterMessage();
            }
        });
    }
    
    // ... rest of existing JavaScript ...
</script>
</body>
</html>