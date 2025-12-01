/**
 * Call Center Widget
 * Handles persistent call timer and quick-access donor info.
 */

console.log('CallWidget: Script loaded');

const CallWidget = {
    config: {
        sessionId: 0,
        donorId: 0,
        donorName: 'Unknown',
        donorPhone: '',
        pledgeAmount: 0,
        pledgeDate: '',
        registrar: 'Unknown',
        church: 'Unknown',
        donorEmail: '',
        donorCity: '',
        baptismName: '',
        balance: 0,
        totalPaid: 0,
        preferredLanguage: 'en',
        preferredLanguageLabel: 'English',
        preferredPaymentMethod: '',
        referenceNumber: '',
        representative: '',
        representativePhone: ''
    },

    state: {
        status: 'stopped', // running, paused, stopped
        startTime: null,
        accumulatedTime: 0, // Time in ms before current run segment
        lastPauseTime: null,
        intervalId: null
    },

    elements: {
        container: null,
        display: null,
        btnPause: null,
        btnInfo: null,
        btnReset: null,
        panel: null
    },

    init(config) {
        console.log('CallWidget: Initializing with config', config);
        this.config = { ...this.config, ...config };
        this.loadState();
        this.render();
        this.attachEvents();
        
        if (this.state.status === 'running') {
            this.startTimerLoop();
        } else if (this.state.status === 'paused') {
            this.updateDisplay();
            this.setPauseVisuals(true);
        } else {
            // Auto-start if fresh session
            // We only auto-start if explicit start() is called by page, 
            // OR if we want default behavior. 
            // Current logic: Page calls .start() explicitly if needed.
        }
        console.log('CallWidget: Initialized successfully');
    },

    getStorageKey() {
        return `call_center_session_${this.config.sessionId}`;
    },

    loadState() {
        const stored = localStorage.getItem(this.getStorageKey());
        if (stored) {
            const data = JSON.parse(stored);
            this.state = { ...this.state, ...data };
        } else {
            this.resetState();
        }
    },

    saveState() {
        localStorage.setItem(this.getStorageKey(), JSON.stringify(this.state));
    },

    resetState() {
        this.state = {
            status: 'stopped',
            startTime: null,
            accumulatedTime: 0,
            lastPauseTime: null
        };
        localStorage.removeItem(this.getStorageKey());
        this.stopTimerLoop();
        this.updateDisplay();
        this.setPauseVisuals(false);
    },

    start() {
        if (this.state.status === 'running') return;
        
        console.log('CallWidget: Starting timer');
        this.state.status = 'running';
        this.state.startTime = Date.now();
        this.saveState();
        this.startTimerLoop();
        this.setPauseVisuals(false);
    },

    pause() {
        if (this.state.status !== 'running') return;
        
        const now = Date.now();
        const elapsed = now - this.state.startTime;
        
        this.state.accumulatedTime += elapsed;
        this.state.status = 'paused';
        this.state.lastPauseTime = now;
        this.state.startTime = null; 
        
        this.saveState();
        this.stopTimerLoop();
        this.updateDisplay(); 
        this.setPauseVisuals(true);
    },

    resume() {
        if (this.state.status !== 'paused') return;
        
        this.state.status = 'running';
        this.state.startTime = Date.now();
        this.saveState();
        this.startTimerLoop();
        this.setPauseVisuals(false);
    },
    
    reset() {
        if (confirm('Are you sure you want to reset the timer? This cannot be undone.')) {
            this.resetState();
            // Optionally restart
            // this.start();
        }
    },

    getElapsedTime() { // Returns milliseconds
        if (this.state.status === 'paused') {
            return this.state.accumulatedTime;
        } else if (this.state.status === 'running') {
            return this.state.accumulatedTime + (Date.now() - this.state.startTime);
        }
        return 0;
    },
    
    getDurationSeconds() {
        return Math.floor(this.getElapsedTime() / 1000);
    },

    formatTime(ms) {
        if (ms < 0) ms = 0;
        const totalSeconds = Math.floor(ms / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    },

    updateDisplay() {
        if (this.elements.display) {
            this.elements.display.textContent = this.formatTime(this.getElapsedTime());
        }
    },

    startTimerLoop() {
        this.stopTimerLoop();
        this.intervalId = setInterval(() => {
            this.updateDisplay();
        }, 1000);
        this.updateDisplay(); 
    },

    stopTimerLoop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    },

    render() {
        const existing = document.querySelector('.call-widget-container');
        if (existing) existing.remove();

        const container = document.createElement('div');
        container.className = 'call-widget-container';
        
        // Safe formatting
        let formattedAmount = '0.00';
        let formattedPaid = '0.00';
        let formattedBalance = '0.00';
        try {
            formattedAmount = Number(this.config.pledgeAmount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        } catch (e) {
            console.warn('Error formatting amount', e);
            formattedAmount = '0.00';
        }

        try {
            formattedPaid = Number(this.config.totalPaid || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        } catch (e) {
            console.warn('Error formatting paid amount', e);
            formattedPaid = '0.00';
        }

        try {
            formattedBalance = Number(this.config.balance || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        } catch (e) {
            console.warn('Error formatting balance', e);
            formattedBalance = '0.00';
        }

        container.innerHTML = `
            <div class="donor-info-panel" id="donorInfoPanel">
                <div class="panel-header">
                    <h3 class="panel-title">
                        <i class="fas fa-user me-2"></i>Donor Profile
                    </h3>
                    <button class="btn-close-panel" id="btnCloseInfo"><i class="fas fa-times"></i></button>
                </div>
                <div class="panel-tabs" id="donorInfoTabs">
                    <button class="panel-tab active" data-tab="overview">
                        <i class="fas fa-id-card"></i><span>Overview</span>
                    </button>
                    <button class="panel-tab" data-tab="contact">
                        <i class="fas fa-address-book"></i><span>Contact</span>
                    </button>
                    <button class="panel-tab" data-tab="pledge">
                        <i class="fas fa-hand-holding-heart"></i><span>Pledge</span>
                    </button>
                    <button class="panel-tab" data-tab="notes">
                        <i class="fas fa-sticky-note"></i><span>Notes</span>
                    </button>
                </div>
                <div class="panel-body">
                    <!-- Overview Tab -->
                    <div class="tab-content active" data-tab="overview">
                        <div class="info-group">
                            <div class="info-label-sm">Name</div>
                            <div class="info-value-sm highlight">${this.config.donorName}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Phone</div>
                            <div class="info-value-sm">
                                <a href="tel:${this.config.donorPhone}" style="color:inherit;text-decoration:none;">
                                    ${this.config.donorPhone || 'N/A'}
                                </a>
                            </div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">City</div>
                            <div class="info-value-sm">${this.config.donorCity || 'Unknown'}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Church / Location</div>
                            <div class="info-value-sm">${this.config.church || 'Unknown'}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Registrar</div>
                            <div class="info-value-sm">${this.config.registrar || 'Unknown'}</div>
                        </div>
                    </div>

                    <!-- Contact Tab -->
                    <div class="tab-content" data-tab="contact">
                        <div class="info-group">
                            <div class="info-label-sm">Phone</div>
                            <div class="info-value-sm">
                                <a href="tel:${this.config.donorPhone}" style="color:inherit;text-decoration:none;">
                                    ${this.config.donorPhone || 'N/A'}
                                </a>
                            </div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Email</div>
                            <div class="info-value-sm">
                                ${this.config.donorEmail 
                                    ? `<a href="mailto:${this.config.donorEmail}" style="color:inherit;text-decoration:none;">${this.config.donorEmail}</a>` 
                                    : 'Not provided'}
                            </div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Preferred Language</div>
                            <div class="info-value-sm">${this.config.preferredLanguageLabel}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Preferred Payment Method</div>
                            <div class="info-value-sm">${this.config.preferredPaymentMethod || 'Not set'}</div>
                        </div>
                    </div>

                    <!-- Pledge Tab -->
                    <div class="tab-content" data-tab="pledge">
                        <div class="info-group">
                            <div class="info-label-sm">Total Pledged</div>
                            <div class="info-value-sm text-danger">£${formattedAmount}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Total Paid</div>
                            <div class="info-value-sm text-success">£${formattedPaid}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Remaining Balance</div>
                            <div class="info-value-sm">£${formattedBalance}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Pledge Date</div>
                            <div class="info-value-sm">${this.config.pledgeDate || 'Unknown'}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Representative</div>
                            <div class="info-value-sm">
                                ${this.config.representative || 'Not assigned'}
                                ${this.config.representativePhone 
                                    ? `<br><small>${this.config.representativePhone}</small>` 
                                    : ''}
                            </div>
                        </div>
                    </div>

                    <!-- Notes / Reference Tab -->
                    <div class="tab-content" data-tab="notes">
                        <div class="info-group">
                            <div class="info-label-sm">Baptism Name</div>
                            <div class="info-value-sm">${this.config.baptismName || 'Not recorded'}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Reference Number</div>
                            <div class="info-value-sm">${this.config.referenceNumber || 'Not set'}</div>
                        </div>
                        <div class="info-group">
                            <div class="info-label-sm">Internal IDs</div>
                            <div class="info-value-sm">
                                Session: ${this.config.sessionId || '-'}<br>
                                Donor ID: ${this.config.donorId || '-'}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="call-timer-pill" id="timerPill">
                <div class="recording-indicator">
                    <div class="recording-dot"></div>
                </div>
                <div class="timer-display" id="widgetTimerDisplay">00:00</div>
                <div class="timer-controls">
                    <button class="timer-btn btn-info-toggle" id="btnToggleInfo" title="Show Donor Info">
                        <i class="fas fa-info"></i>
                    </button>
                    <button class="timer-btn btn-pause" id="btnPauseResume" title="Pause/Resume">
                        <i class="fas fa-pause"></i>
                    </button>
                    <button class="timer-btn btn-end" id="btnResetTimer" title="Reset Timer" style="background: #64748b;">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(container);

        this.elements.container = container;
        this.elements.display = container.querySelector('#widgetTimerDisplay');
        this.elements.btnPause = container.querySelector('#btnPauseResume');
        this.elements.btnInfo = container.querySelector('#btnToggleInfo');
        this.elements.btnReset = container.querySelector('#btnResetTimer');
        this.elements.panel = container.querySelector('#donorInfoPanel');
    },

    attachEvents() {
        if (this.elements.btnPause) {
            this.elements.btnPause.addEventListener('click', () => {
                if (this.state.status === 'running') {
                    this.pause();
                } else {
                    this.resume();
                }
            });
        }

        if (this.elements.btnInfo) {
            this.elements.btnInfo.addEventListener('click', () => {
                if (this.elements.panel) {
                    this.elements.panel.classList.toggle('active');
                }
            });
        }

        const closeBtn = document.getElementById('btnCloseInfo');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                if (this.elements.panel) {
                    this.elements.panel.classList.remove('active');
                }
            });
        }

        // Tab switching inside donor info panel
        if (this.elements.panel) {
            const tabs = this.elements.panel.querySelectorAll('.panel-tab');
            const contents = this.elements.panel.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const target = tab.getAttribute('data-tab');

                    tabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');

                    contents.forEach(c => {
                        if (c.getAttribute('data-tab') === target) {
                            c.classList.add('active');
                        } else {
                            c.classList.remove('active');
                        }
                    });
                });
            });
        }
        
        if (this.elements.btnReset) {
            this.elements.btnReset.addEventListener('click', () => {
                this.reset();
            });
        }
    },
    
    setPauseVisuals(isPaused) {
        const pill = document.getElementById('timerPill');
        const btn = this.elements.btnPause;
        if (!btn || !pill) return;
        
        const icon = btn.querySelector('i');
        
        if (isPaused) {
            pill.classList.add('paused');
            btn.classList.remove('btn-pause');
            btn.classList.add('btn-resume');
            btn.title = "Resume Call";
            if (icon) icon.className = 'fas fa-play';
        } else {
            pill.classList.remove('paused');
            btn.classList.remove('btn-resume');
            btn.classList.add('btn-pause');
            btn.title = "Pause Call";
            if (icon) icon.className = 'fas fa-pause';
        }
    }
};
