<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../admin/includes/resilient_db_loader.php'; // Use the resilient loader

// Use pre-loaded data, provide defaults if not available
$settings_defaults = [
    'projector_names_mode' => 'full',
    'refresh_seconds' => 10,
    'target_amount' => 100000,
    'currency_code' => 'GBP'
];
$settings = array_merge($settings_defaults, $settings);

$refresh = max(1, (int)($settings['refresh_seconds'] ?? 10));
$currency = htmlspecialchars($settings['currency_code'] ?? 'GBP', ENT_QUOTES, 'UTF-8');
$target = (float)($settings['target_amount'] ?? 100000);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php include __DIR__ . '/../../shared/noindex.php'; ?>
    <title>Live Fundraising Display</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="./assets/projector-live.css?v=<?= (int) filemtime(__DIR__ . '/assets/projector-live.css') ?>">
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
            <button type="button" class="total-card paid-card" data-filter="paid" aria-pressed="false" title="Show paid donations">
                <div class="card-header">
                    <i class="fas fa-check-circle"></i>
                    <span class="card-title">Total Paid</span>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="paidTotal">0</span>
                </div>
                <div class="card-indicator">
                    <div class="indicator-pulse"></div>
                </div>
            </button>

            <button type="button" class="total-card pledged-card" data-filter="pledge" aria-pressed="false" title="Show pledges">
                <div class="card-header">
                    <i class="fas fa-church"></i>
                    <span class="card-title">Total Pledged</span>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="pledgedTotal">0</span>
                </div>
                <div class="card-indicator">
                    <div class="indicator-pulse"></div>
                </div>
            </button>

            <button type="button" class="total-card grand-card is-filter-active" data-filter="all" aria-pressed="true" title="Show all donations">
                <div class="card-header">
                    <i class="fas fa-trophy"></i>
                    <span class="card-title">Grand Total</span>
                </div>
                <div class="card-value">
                    <span class="currency"><?= $currency ?></span>
                    <span class="amount" id="grandTotal">0</span>
                </div>
                <div class="card-indicator">
                    <div class="indicator-pulse"></div>
                </div>
            </button>

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
            <div class="contribution-more-wrap">
                <button type="button" class="contrib-load-more" id="loadMoreBtn" hidden>Load more</button>
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
        displayMode: null, // Will be set from API: amount, sqm, both
        filter: 'all',
        pageSize: 20,
        hasMore: false,
        loadingFeed: false
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[ch]));
    }

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

    function itemKey(item) {
        return `${item.type || ''}|${item.approved_at || ''}|${item.text || ''}`;
    }

    function updateLoadMoreButton() {
        const btn = document.getElementById('loadMoreBtn');
        if (!btn) return;
        btn.hidden = !state.hasMore;
        btn.disabled = state.loadingFeed;
        btn.textContent = state.loadingFeed ? 'Loading...' : 'Load more';
    }

    function setFilter(filter) {
        let next = (filter === 'paid' || filter === 'pledge') ? filter : 'all';
        if (next === state.filter && next !== 'all') {
            next = 'all';
        }
        if (next === state.filter && state.contributions.length > 0) return;
        state.filter = next;
        document.querySelectorAll('.total-card[data-filter]').forEach((card) => {
            const active = card.dataset.filter === next;
            card.classList.toggle('is-filter-active', active);
            card.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        fetchRecent('reset');
    }

    async function renderContributionItems(items, mode) {
        const container = document.getElementById('contributionsList');
        const loadingMsg = container.querySelector('.loading-message');
        if (loadingMsg) loadingMsg.remove();

        if (mode === 'reset') {
            container.querySelectorAll('.contribution-item').forEach((el) => el.remove());
        }

        if (items.length === 0 && container.querySelectorAll('.contribution-item').length === 0) {
            const empty = document.createElement('div');
            empty.className = 'loading-message';
            empty.innerHTML = '<i class="fas fa-inbox"></i><span>No contributions in this filter.</span>';
            container.appendChild(empty);
            return;
        }

        const isNearTop = container.scrollTop < 100;
        const batch = mode === 'prepend' ? [...items].reverse() : items;
        for (const item of batch) {
            const contributionEl = await createContributionElement(item);
            if (mode === 'prepend') {
                container.insertBefore(contributionEl, container.firstChild);
            } else {
                container.appendChild(contributionEl);
            }
            setTimeout(() => contributionEl.classList.add('show'), 10);
        }

        if (mode !== 'append' && (!state.userScrolled || isNearTop)) {
            container.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    async function updateContributions(items, mode) {
        const incoming = Array.isArray(items) ? items : [];
        if (mode === 'reset') {
            state.contributions = incoming;
            await renderContributionItems(incoming, 'reset');
        } else if (mode === 'append') {
            const existing = new Set(state.contributions.map(itemKey));
            const extra = incoming.filter((item) => !existing.has(itemKey(item)));
            state.contributions = state.contributions.concat(extra);
            await renderContributionItems(extra, 'append');
        } else {
            const existing = new Set(state.contributions.map(itemKey));
            const fresh = incoming.filter((item) => !existing.has(itemKey(item)));
            state.contributions = fresh.concat(state.contributions);
            await renderContributionItems(fresh, 'prepend');
            if (fresh.length > 0 && state.userScrolled) {
                showScrollHint();
            }
        }

        if (state.displayMode && state.displayMode !== 'amount' && mode === 'reset') {
            setTimeout(() => refreshAllContributions(), 100);
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
        
        const isPayment = item.type === 'paid' || (item.text || '').toLowerCase().includes('paid');
        const isPledge = item.type === 'pledge' || (item.text || '').toLowerCase().includes('pledged');
        
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
        let displayText = escapeHtml(await formatContributionText(item.text, amount));
        console.log('📝 Final display text:', displayText);
        
        // Highlight the amount or square meter info in the text and mark anonymous names
        let highlightedText = displayText;
        
        // First, mark anonymous donor names with special styling
        const nameMatch = displayText.match(/^(.*?)\s+(paid|pledged)\s+/i);
        if (nameMatch) {
            const donorName = nameMatch[1].trim();
            // Check if this is our anonymized "Kind Donor" name
            if (donorName === 'Kind Donor') {
                highlightedText = highlightedText.replace(donorName, `<span class="anonymous-name">${donorName}</span>`);
            }
        }
        
        // Then highlight amounts or square meters
        if (state.displayMode === 'amount' || state.displayMode === 'both') {
            // Highlight currency amounts
            const currencyMatch = highlightedText.match(/£[\d,]+/);
            if (currencyMatch) {
                highlightedText = highlightedText.replace(currencyMatch[0], `<span class="amount">${currencyMatch[0]}</span>`);
            }
        } else if (state.displayMode === 'sqm') {
            // Highlight square meter numbers
            const sqmMatch = highlightedText.match(/(\d+(?:\.\d+)?|[¼½¾])\s+Square\s+Meters?/i);
            if (sqmMatch) {
                highlightedText = highlightedText.replace(sqmMatch[0], `<span class="amount">${sqmMatch[0]}</span>`);
            }
        }
        
        div.innerHTML = `
            <div class="contribution-icon">
                <i class="${icon}"></i>
            </div>
            <div class="contribution-content">
                <div class="contribution-text">${highlightedText}</div>
                <div class="contribution-meta">
                    <span class="contribution-time">${formatDonationDate(item.approved_at)}</span>
                </div>
            </div>
        `;
        // NEW badge temporarily disabled
        // <div class="contribution-new">NEW</div>
        // setTimeout(() => {
        //     const newBadge = div.querySelector('.contribution-new');
        //     if (newBadge) newBadge.style.display = 'none';
        // }, 30000);
        
        return div;
    }

    function parseDonationDate(timestamp) {
        if (!timestamp) return null;
        const raw = String(timestamp).trim();
        const date = new Date(raw.includes('T') ? raw : raw.replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatDonationDate(timestamp) {
        const date = parseDonationDate(timestamp);
        if (!date) return '';
        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            timeZone: 'Europe/London'
        });
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

    async function fetchRecent(mode = 'poll') {
        if (state.loadingFeed) return;
        if (mode === 'append' && !state.hasMore) return;
        state.loadingFeed = true;
        updateLoadMoreButton();

        const offset = mode === 'append' ? state.contributions.length : 0;
        const params = new URLSearchParams({
            type: state.filter,
            limit: String(state.pageSize),
            offset: String(offset)
        });

        try {
            const response = await fetch(`../../api/recent.php?${params.toString()}`);
            if (response.ok) {
                const data = await response.json();
                const items = Array.isArray(data.items) ? data.items : [];
                if (mode === 'append') {
                    state.hasMore = !!data.has_more;
                    await updateContributions(items, 'append');
                } else if (mode === 'reset') {
                    state.hasMore = !!data.has_more;
                    await updateContributions(items, 'reset');
                } else {
                    if (typeof data.has_more === 'boolean' && state.contributions.length <= state.pageSize) {
                        state.hasMore = data.has_more;
                    }
                    await updateContributions(items, 'poll');
                }
            } else {
                console.error('API response not ok:', response.status, response.statusText);
            }
        } catch (error) {
            console.error('Error fetching recent:', error);
        } finally {
            state.loadingFeed = false;
            updateLoadMoreButton();
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
        fetchRecent('reset');
        fetchFooterMessage();
        
        // Set up polling
        setInterval(fetchTotals, config.refresh);
        setInterval(() => fetchRecent('poll'), config.refresh);
        setInterval(fetchFooterMessage, config.refresh * 2); // Check footer less frequently
        
        // Initialize admin communication
        initAdminCommunication();
        
        // Setup scroll detection
        setupScrollDetection();
        setupFeedControls();
        
        // Handle visibility change
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                fetchTotals();
                fetchRecent('poll');
                fetchFooterMessage();
            }
        });
    }

    function setupFeedControls() {
        document.querySelectorAll('.total-card[data-filter]').forEach((el) => {
            el.addEventListener('click', () => setFilter(el.dataset.filter || 'all'));
        });
        const loadMoreBtn = document.getElementById('loadMoreBtn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', () => fetchRecent('append'));
        }
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