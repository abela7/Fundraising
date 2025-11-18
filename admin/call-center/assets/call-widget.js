/**
 * Call Center Widget
 * Handles persistent call timer and quick-access donor info.
 */

const CallWidget = {
    config: {
        sessionId: 0,
        donorId: 0,
        donorName: 'Unknown',
        donorPhone: '',
        pledgeAmount: 0,
        pledgeDate: '',
        registrar: 'Unknown',
        church: 'Unknown'
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
        panel: null
    },

    init(config) {
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
            this.start();
        }
    },

    getStorageKey() {
        return `call_center_session_${this.config.sessionId}`;
    },

    loadState() {
        const stored = localStorage.getItem(this.getStorageKey());
        if (stored) {
            const data = JSON.parse(stored);
            this.state = { ...this.state, ...data };
            
            // If running, we don't change startTime. Current time - startTime is total elapsed.
            // But we need to account for paused durations.
            // Simplified model:
            // startTime: Timestamp when current segment started (or original start if never paused)
            // accumulatedTime: Total ms accumulated before current segment
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
    },

    start() {
        if (this.state.status === 'running') return;
        
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
        this.state.startTime = null; // Clear start time as we are not running
        
        this.saveState();
        this.stopTimerLoop();
        this.updateDisplay(); // Show exact time paused at
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

    getElapsedTime() {
        if (this.state.status === 'paused') {
            return this.state.accumulatedTime;
        } else if (this.state.status === 'running') {
            return this.state.accumulatedTime + (Date.now() - this.state.startTime);
        }
        return 0;
    },

    formatTime(ms) {
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
        this.updateDisplay(); // Immediate update
    },

    stopTimerLoop() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    },

    render() {
        // Remove existing if any
        const existing = document.querySelector('.call-widget-container');
        if (existing) existing.remove();

        const container = document.createElement('div');
        container.className = 'call-widget-container';
        container.innerHTML = `
            <div class="donor-info-panel" id="donorInfoPanel">
                <div class="panel-header">
                    <h3 class="panel-title"><i class="fas fa-user me-2"></i>Donor Details</h3>
                    <button class="btn-close-panel" id="btnCloseInfo"><i class="fas fa-times"></i></button>
                </div>
                <div class="panel-body">
                    <div class="info-group">
                        <div class="info-label-sm">Name</div>
                        <div class="info-value-sm highlight">${this.config.donorName}</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label-sm">Phone</div>
                        <div class="info-value-sm"><a href="tel:${this.config.donorPhone}" style="color:inherit;text-decoration:none;">${this.config.donorPhone}</a></div>
                    </div>
                    <div class="info-group">
                        <div class="info-label-sm">Pledge Amount</div>
                        <div class="info-value-sm text-danger">£${Number(this.config.pledgeAmount).toLocaleString('en-GB', {minimumFractionDigits: 2})}</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label-sm">Pledge Date</div>
                        <div class="info-value-sm">${this.config.pledgeDate}</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label-sm">Registrar</div>
                        <div class="info-value-sm">${this.config.registrar}</div>
                    </div>
                    <div class="info-group">
                        <div class="info-label-sm">Church / Location</div>
                        <div class="info-value-sm">${this.config.church}</div>
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
                </div>
            </div>
        `;

        document.body.appendChild(container);

        this.elements.container = container;
        this.elements.display = container.querySelector('#widgetTimerDisplay');
        this.elements.btnPause = container.querySelector('#btnPauseResume');
        this.elements.btnInfo = container.querySelector('#btnToggleInfo');
        this.elements.panel = container.querySelector('#donorInfoPanel');
    },

    attachEvents() {
        // Pause/Resume Toggle
        this.elements.btnPause.addEventListener('click', () => {
            if (this.state.status === 'running') {
                this.pause();
            } else {
                this.resume();
            }
        });

        // Info Toggle
        this.elements.btnInfo.addEventListener('click', () => {
            this.elements.panel.classList.toggle('active');
        });

        // Close Info
        document.getElementById('btnCloseInfo').addEventListener('click', () => {
            this.elements.panel.classList.remove('active');
        });
    },

    setPauseVisuals(isPaused) {
        const pill = document.getElementById('timerPill');
        const btn = this.elements.btnPause;
        const icon = btn.querySelector('i');
        
        if (isPaused) {
            pill.classList.add('paused');
            btn.classList.remove('btn-pause');
            btn.classList.add('btn-resume');
            btn.title = "Resume Call";
            icon.className = 'fas fa-play';
        } else {
            pill.classList.remove('paused');
            btn.classList.remove('btn-resume');
            btn.classList.add('btn-pause');
            btn.title = "Pause Call";
            icon.className = 'fas fa-pause';
        }
    }
};

