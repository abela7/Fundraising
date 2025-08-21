<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';

$settings = db()->query('SELECT projector_names_mode, refresh_seconds, target_amount, currency_code FROM settings WHERE id=1')->fetch_assoc();
if (!$settings) {
    // Use defaults if no settings found
    $settings = [
        'projector_names_mode' => 'full',
        'refresh_seconds' => 10,
        'target_amount' => 100000,
        'currency_code' => 'GBP'
    ];
}
$refresh = max(1, (int)$settings['refresh_seconds']);
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$target = (float)$settings['target_amount'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Live Fundraising Display</title>
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
        <span class="label">Fullscreen</span>
    </button>
    
    
    <!-- Fixed Top Progress Bar -->
    <header class="progress-header">
        <div class="progress-container">
            <div class="progress-info">
                <h1 class="campaign-title">Live Fundraising Campaign</h1>
                <div class="progress-stats">
                    <span class="progress-current"><?= $currency ?> <span id="progressCurrent">0</span></span>
                    <span class="progress-separator">of</span>
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
                    <h3>Total Paid</h3>
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
                    <h3>Total Pledged</h3>
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
                    <h3>Grand Total</h3>
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
                    LIVE
    </div>
    </div>
        </aside>

        <!-- Scrollable Right Panel - Recent Contributions -->
        <section class="contributions-panel">

            <div class="contributions-list" id="contributionsList">
                <div class="loading-message">
                    <i class="fas fa-spinner fa-spin"></i>
                    <span>Waiting for contributions...</span>
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
        target: <?= $target ?>
    };

    // State
    let state = {
        paid: 0,
        pledged: 0,
        grand: 0,
        progress: 0,
        contributions: [],
        userScrolled: false,
        scrollTimeout: null,
        isUpdating: false,
        displayMode: null // Will be set from API: amount, sqm, both
    };

    // Format currency
    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(amount);
    }

    // Update clock
    function updateClock() {
        const now = new Date();
        const time = now.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
        document.getElementById('clock').textContent = time;
    }

    // Animate number
    function animateNumber(element, start, end, duration = 1000) {
        const startTime = performance.now();
        const startValue = parseFloat(start) || 0;
        const endValue = parseFloat(end) || 0;
        
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function
            const easeOutCubic = 1 - Math.pow(1 - progress, 3);
            const current = startValue + (endValue - startValue) * easeOutCubic;
            
            element.textContent = formatCurrency(current);
            
            if (progress < 1) {
                requestAnimationFrame(update);
            }
        }
        
        requestAnimationFrame(update);
    }

    // Update totals
    function updateTotals(data) {
        // Check if display mode changed
        const oldDisplayMode = state.displayMode;
        if (data.projector_display_mode) {
            state.displayMode = data.projector_display_mode;
        }
        
        console.log('Display mode update - Old:', oldDisplayMode, 'New:', state.displayMode, 'Has contributions:', state.contributions.length);
        
        // If display mode changed, refresh all existing contributions
        // Also refresh on first load (when oldDisplayMode is null)
        if ((oldDisplayMode !== state.displayMode) && state.contributions.length > 0) {
            console.log('Display mode changed - refreshing all contributions');
            refreshAllContributions();
        }
        
        // Update paid
        if (data.paid_total !== state.paid) {
            const paidElement = document.getElementById('paidTotal');
            animateNumber(paidElement, state.paid, data.paid_total);
            if (data.paid_total > state.paid) {
                flashCard('paid-card');
            }
        }
        
        // Update pledged
        if (data.pledged_total !== state.pledged) {
            const pledgedElement = document.getElementById('pledgedTotal');
            animateNumber(pledgedElement, state.pledged, data.pledged_total);
            if (data.pledged_total > state.pledged) {
                flashCard('pledged-card');
            }
        }
        
        // Update grand total
        if (data.grand_total !== state.grand) {
            const grandElement = document.getElementById('grandTotal');
            animateNumber(grandElement, state.grand, data.grand_total);
            document.getElementById('progressCurrent').textContent = formatCurrency(data.grand_total);
            if (data.grand_total > state.grand) {
                flashCard('grand-card');
            }
        }
        
        // Update progress
        updateProgressBar(data.progress_pct);
        
        // Update state
        state.paid = data.paid_total;
        state.pledged = data.pledged_total;
        state.grand = data.grand_total;
        state.progress = data.progress_pct;
        
        // Check milestones
        checkMilestones(data.progress_pct);
    }

    // Flash card on update
    function flashCard(cardClass) {
        const card = document.querySelector(`.${cardClass}`);
        card.classList.add('flash');
        setTimeout(() => card.classList.remove('flash'), 1000);
    }

    // Update progress bar
    function updateProgressBar(percent) {
        const progressBar = document.getElementById('progressBar');
        const progressPercent = document.getElementById('progressPercent');
        
        progressBar.style.width = percent + '%';
        progressPercent.textContent = Math.round(percent) + '%';
        
        // Add celebration if 100%
        if (percent >= 100 && !progressBar.classList.contains('complete')) {
            progressBar.classList.add('complete');
            triggerCelebration();
        }
    }

    // Check milestones
    function checkMilestones(percent) {
        const milestones = [25, 50, 75, 100];
        milestones.forEach(milestone => {
            if (percent >= milestone && state.progress < milestone) {
                showAnnouncement(`🎉 ${milestone}% Milestone Reached! 🎉`, 'milestone', 5000);
            }
        });
    }

    // Update contributions list
    function updateContributions(items) {
        const container = document.getElementById('contributionsList');
        const isNearTop = container.scrollTop < 100;
        
        // Check if this is the first time we're loading contributions and display mode is not 'amount'
        const isFirstLoad = state.contributions.length === 0 && items.length > 0;
        const needsRefresh = isFirstLoad && state.displayMode && state.displayMode !== 'amount';
        
        // Find new contributions
        const newItems = items.filter(item => 
            !state.contributions.find(c => c.text === item.text && c.approved_at === item.approved_at)
        );
        
        if (newItems.length > 0) {
            // Remove loading message if exists
            const loadingMsg = container.querySelector('.loading-message');
            if (loadingMsg) loadingMsg.remove();
            
            // Add new items at the top (handle async)
            newItems.reverse().forEach(async item => {
                const contributionEl = await createContributionElement(item);
                container.insertBefore(contributionEl, container.firstChild);
                
                // Animate in
                setTimeout(() => contributionEl.classList.add('show'), 10);
            });
            
            // Keep only last 50 items
            while (container.children.length > 50) {
                container.removeChild(container.lastChild);
            }
            
            // Smart auto-scroll: only if user is near top or hasn't manually scrolled recently
            if (!state.userScrolled || isNearTop) {
                container.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
                
                // Show scroll hint if user has scrolled down
                if (state.userScrolled && !isNearTop) {
                    showScrollHint();
                }
            }
        }
        
        state.contributions = items;
        
        // If this is first load and we need to apply non-amount formatting, refresh all
        if (needsRefresh) {
            console.log('First load with display mode:', state.displayMode, '- refreshing all contributions');
            setTimeout(() => refreshAllContributions(), 100); // Small delay to ensure DOM is ready
        }
    }

    // Refresh all existing contributions with new display mode
    async function refreshAllContributions() {
        const container = document.getElementById('contributionsList');
        const existingItems = Array.from(container.children).filter(el => el.classList.contains('contribution-item'));
        
        // Clear existing contributions
        existingItems.forEach(item => item.remove());
        
        // Re-create all contributions with new display mode
        for (const item of state.contributions) {
            const contributionEl = await createContributionElement(item);
            container.appendChild(contributionEl);
            
            // Add show class for animation
            setTimeout(() => contributionEl.classList.add('show'), 10);
        }
        
        // Scroll to top to show refreshed content
        container.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Format contribution text based on display mode
    async function formatContributionText(originalText, amount) {
        console.log('🔄 formatContributionText called - Mode:', state.displayMode, 'Amount:', amount, 'Original:', originalText);
        
        if (state.displayMode === 'amount') {
            // Default: Show amount only (current behavior)
            console.log('✅ Using amount mode - returning original text');
            return originalText;
        }
        
        if (state.displayMode === 'sqm' || state.displayMode === 'both') {
            console.log('🔢 Using sqm/both mode - fetching calculation...');
            try {
                // Get square meter calculation from API
                const apiUrl = `../../api/calculate_sqm.php?amount=${amount}`;
                console.log('📡 Fetching:', apiUrl);
                const response = await fetch(apiUrl);
                console.log('📥 API Response status:', response.status);
                
                if (response.ok) {
                    const data = await response.json();
                    console.log('📊 API Data:', data);
                    
                    if (data.success) {
                        // Extract donor name and action from original text  
                        const nameMatch = originalText.match(/^(.+?)\s+(paid|pledged)\s+/i);
                        console.log('🔍 Name match:', nameMatch);
                        
                        if (nameMatch) {
                            const donorName = nameMatch[1].trim();
                            const action = nameMatch[2].toLowerCase();
                            
                            let newText = '';
                            if (state.displayMode === 'sqm') {
                                // Show square meters only
                                newText = `${donorName} ${action} ${data.sqm_display}`;
                            } else { // both
                                // Show both square meters and amount
                                const amountFormatted = `£${amount}`;
                                newText = `${donorName} ${action} ${data.sqm_display} (${amountFormatted})`;
                            }
                            
                            console.log('✨ Formatted text:', newText);
                            return newText;
                        } else {
                            console.log('❌ Could not extract donor name from text');
                        }
                    } else {
                        console.log('❌ API returned success: false');
                    }
                } else {
                    console.log('❌ API request failed with status:', response.status);
                }
            } catch (error) {
                console.error('💥 Error calculating square meters:', error);
            }
        }
        
        // Fallback to original text
        console.log('🔙 Falling back to original text');
        return originalText;
    }

    // Create contribution element
    async function createContributionElement(item) {
        console.log('🎨 createContributionElement called for:', item.text);
    const div = document.createElement('div');
        
        // Determine type from the text content
        const isPayment = item.text.toLowerCase().includes('paid');
        const isPledge = item.text.toLowerCase().includes('pledged');
        
        // Add appropriate class and icon
        let className = 'contribution-item';
        let icon = 'fas fa-hands-helping'; // Default charity icon
        
        if (isPayment) {
            className += ' payment';
            icon = 'fas fa-hand-holding-usd';
        } else if (isPledge) {
            className += ' pledge';
            icon = 'fas fa-church';
        }
        
        div.className = className;
        
        // Extract amount from the original text
        const amountMatch = item.text.match(/GBP\s+([\d,]+)|£([\d,]+)/);
        const amount = amountMatch ? parseFloat((amountMatch[1] || amountMatch[2]).replace(/,/g, '')) : 0;
        console.log('💰 Extracted amount:', amount, 'from match:', amountMatch, 'original text:', item.text);
        
        // Format text based on display mode
        console.log('🔄 About to call formatContributionText...');
        let displayText = await formatContributionText(item.text, amount);
        console.log('📝 Final display text:', displayText);
        
        // Highlight the amount or square meter info in the text
        let highlightedText = displayText;
        if (state.displayMode === 'amount' || state.displayMode === 'both') {
            // Highlight currency amounts
            const currencyMatch = displayText.match(/£[\d,]+/);
            if (currencyMatch) {
                highlightedText = displayText.replace(currencyMatch[0], `<span class="amount">${currencyMatch[0]}</span>`);
            }
        } else if (state.displayMode === 'sqm') {
            // Highlight square meter numbers
            const sqmMatch = displayText.match(/(\d+(?:\.\d+)?|[¼½¾])\s+Square\s+Meters?/i);
            if (sqmMatch) {
                highlightedText = displayText.replace(sqmMatch[0], `<span class="amount">${sqmMatch[0]}</span>`);
            }
        }
        
        div.innerHTML = `
            <div class="contribution-icon">
                <i class="${icon}"></i>
            </div>
            <div class="contribution-content">
                <div class="contribution-text">${highlightedText}</div>
                <div class="contribution-time">${formatTime(item.approved_at)}</div>
            </div>
            <div class="contribution-new">NEW</div>
        `;
        
        // Remove NEW badge after 30 seconds
        setTimeout(() => {
            const newBadge = div.querySelector('.contribution-new');
            if (newBadge) newBadge.style.display = 'none';
        }, 30000);
        
        return div;
    }

    // Format time
    function formatTime(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return date.toLocaleDateString();
    }

    // Fetch totals
    async function fetchTotals() {
        if (state.isUpdating) return;
        state.isUpdating = true;
        
        try {
            const response = await fetch(`../../api/totals.php`);
            if (response.ok) {
                const data = await response.json();
                updateTotals(data);
            }
        } catch (error) {
            console.error('Error fetching totals:', error);
        } finally {
            state.isUpdating = false;
        }
    }

    // Fetch recent contributions
    async function fetchRecent() {
        try {
            const response = await fetch(`../../api/recent.php`);
            if (response.ok) {
                const data = await response.json();
                console.log('Recent API response:', data);
                updateContributions(data.items);
            } else {
                console.error('API response not ok:', response.status, response.statusText);
            }
        } catch (error) {
            console.error('Error fetching recent:', error);
        }
    }

    // Fetch footer message and visibility from database
    async function fetchFooterMessage() {
        try {
            const response = await fetch(`../../api/footer.php`);
            if (response.ok) {
                const data = await response.json();
                const footer = document.getElementById('messageFooter');
                
                if (data.is_visible) {
                    // Show footer and update message
                    footer.style.display = 'flex';
                    if (data.message) {
                        document.getElementById('footerMessage').textContent = data.message;
                    }
                } else {
                    // Hide footer completely
                    footer.style.display = 'none';
                }
            }
        } catch (error) {
            console.error('Error fetching footer:', error);
        }
    }

    // Show announcement
    function showAnnouncement(message, type = 'info', duration = 5000) {
        const overlay = document.getElementById('announcementOverlay');
        const text = document.getElementById('announcementText');
        
        text.textContent = message;
        overlay.className = `announcement-overlay show ${type}`;
        
        setTimeout(() => {
            overlay.classList.remove('show');
        }, duration);
    }

    // Trigger celebration
    function triggerCelebration() {
        const container = document.getElementById('effectsContainer');
        container.innerHTML = '<div class="celebration-text">🎉 GOAL ACHIEVED! 🎉</div>';
        
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

    // Simple function for any future manual effects (kept minimal)
    function initAdminCommunication() {
        // Removed complex communication - footer updates via database now
        // This function kept for potential future simple enhancements
    }

    // Show scroll hint
    function showScrollHint() {
        const container = document.getElementById('contributionsList');
        
        // Create hint element if it doesn't exist
        let hint = container.querySelector('.scroll-hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'scroll-hint';
            hint.innerHTML = `
                <i class="fas fa-arrow-up"></i>
                <span>New contributions above</span>
            `;
            hint.addEventListener('click', () => {
                container.scrollTo({ top: 0, behavior: 'smooth' });
                hint.style.display = 'none';
            });
            container.appendChild(hint);
        }
        
        hint.style.display = 'flex';
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (hint) hint.style.display = 'none';
        }, 5000);
    }
    
    // Handle scroll detection
    function setupScrollDetection() {
        const container = document.getElementById('contributionsList');
        let scrollTimer = null;
        
        container.addEventListener('scroll', () => {
            state.userScrolled = true;
            
            // Clear previous timer
            if (scrollTimer) clearTimeout(scrollTimer);
            
            // Reset scroll state after 10 seconds of no scrolling
            scrollTimer = setTimeout(() => {
                if (container.scrollTop < 50) {
                    state.userScrolled = false;
                }
            }, 10000);
            
            // Hide scroll hint if user scrolls to top
            const hint = container.querySelector('.scroll-hint');
            if (hint && container.scrollTop < 50) {
                hint.style.display = 'none';
            }
        });
    }

    // Initialize
    function init() {
        // Update clock
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

    // Start the application
    init();

    // Fullscreen support
    const fsBtn = document.getElementById('fullscreenBtn');
    let fsHideTimer = null;

    function isFullscreen() {
        return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
    }

    async function toggleFullscreen() {
        try {
            if (!isFullscreen()) {
                const el = document.documentElement;
                if (el.requestFullscreen) await el.requestFullscreen();
                else if (el.webkitRequestFullscreen) await el.webkitRequestFullscreen();
                else if (el.msRequestFullscreen) await el.msRequestFullscreen();
                fsBtn.querySelector('i').className = 'fas fa-compress';
                fsBtn.querySelector('.label').textContent = 'Exit';
            } else {
                if (document.exitFullscreen) await document.exitFullscreen();
                else if (document.webkitExitFullscreen) await document.webkitExitFullscreen();
                else if (document.msExitFullscreen) await document.msExitFullscreen();
                fsBtn.querySelector('i').className = 'fas fa-expand';
                fsBtn.querySelector('.label').textContent = 'Fullscreen';
            }
        } catch (e) {
            console.error('Fullscreen error:', e);
        }
    }

    fsBtn.addEventListener('click', toggleFullscreen);

    // Keyboard shortcut: F to toggle fullscreen
    document.addEventListener('keydown', (e) => {
        if (e.key.toLowerCase() === 'f') {
            e.preventDefault();
            toggleFullscreen();
        }
    });

    // Double-click anywhere to toggle fullscreen
    document.addEventListener('dblclick', (e) => {
        // Ignore double-clicks on links/buttons/inputs
        if (e.target.closest('a,button,input,textarea,select')) return;
        toggleFullscreen();
    });

    // Auto-hide fullscreen button after 3s of inactivity
    function scheduleFsHide() {
        if (fsHideTimer) clearTimeout(fsHideTimer);
        fsBtn.classList.remove('hidden');
        fsHideTimer = setTimeout(() => fsBtn.classList.add('hidden'), 3000);
    }
    ['mousemove','touchstart','keydown'].forEach(evt => document.addEventListener(evt, scheduleFsHide));
    scheduleFsHide();
</script>
</body>
</html>