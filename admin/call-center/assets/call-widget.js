/**
 * Call Center Widget
 * Handles persistent call timer and quick-access donor info via AJAX API.
 */

console.log('CallWidget: Script loaded');

const CallWidget = {
    config: {
        sessionId: 0,
        donorId: 0,
        donorName: 'Unknown',
        donorPhone: ''
    },

    state: {
        status: 'stopped', // running, paused, stopped
        startTime: null,
        accumulatedTime: 0,
        lastPauseTime: null,
        intervalId: null,
        donorData: null,       // Full donor data from API
        dataLoaded: false,
        dataLoading: false
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
            // Only restore timer state, not donor data
            this.state.status = data.status || 'stopped';
            this.state.startTime = data.startTime;
            this.state.accumulatedTime = data.accumulatedTime || 0;
            this.state.lastPauseTime = data.lastPauseTime;
        } else {
            this.resetState();
        }
    },

    saveState() {
        localStorage.setItem(this.getStorageKey(), JSON.stringify({
            status: this.state.status,
            startTime: this.state.startTime,
            accumulatedTime: this.state.accumulatedTime,
            lastPauseTime: this.state.lastPauseTime
        }));
    },

    resetState() {
        this.state.status = 'stopped';
        this.state.startTime = null;
        this.state.accumulatedTime = 0;
        this.state.lastPauseTime = null;
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
        }
    },

    getElapsedTime() {
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
                    <button class="panel-tab" data-tab="financial">
                        <i class="fas fa-pound-sign"></i><span>Financial</span>
                    </button>
                    <button class="panel-tab" data-tab="payments">
                        <i class="fas fa-credit-card"></i><span>Payments</span>
                    </button>
                    <button class="panel-tab" data-tab="calls">
                        <i class="fas fa-phone-alt"></i><span>Calls</span>
                    </button>
                    <button class="panel-tab" data-tab="notes">
                        <i class="fas fa-sticky-note"></i><span>Notes</span>
                    </button>
                </div>
                <div class="panel-body" id="panelBody">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                        <p class="mt-2 text-muted">Loading donor information...</p>
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
                    const isOpening = !this.elements.panel.classList.contains('active');
                    this.elements.panel.classList.toggle('active');
                    
                    // Load data when opening for the first time
                    if (isOpening && !this.state.dataLoaded && !this.state.dataLoading) {
                        this.loadDonorData();
                    }
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

        // Tab switching
        if (this.elements.panel) {
            this.elements.panel.addEventListener('click', (e) => {
                const tab = e.target.closest('.panel-tab');
                if (!tab) return;
                
                const target = tab.getAttribute('data-tab');
                const tabs = this.elements.panel.querySelectorAll('.panel-tab');
                const contents = this.elements.panel.querySelectorAll('.tab-content');

                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');

                contents.forEach(c => {
                    c.classList.toggle('active', c.getAttribute('data-tab') === target);
                });
            });
        }
        
        if (this.elements.btnReset) {
            this.elements.btnReset.addEventListener('click', () => {
                this.reset();
            });
        }
    },

    async loadDonorData() {
        if (!this.config.donorId) {
            console.warn('CallWidget: No donor ID');
            return;
        }

        this.state.dataLoading = true;
        const panelBody = document.getElementById('panelBody');
        
        try {
            const response = await fetch(`api/get-donor-profile.php?donor_id=${this.config.donorId}`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Failed to load donor data');
            }
            
            this.state.donorData = data;
            this.state.dataLoaded = true;
            this.renderPanelContent();
            
        } catch (error) {
            console.error('CallWidget: Error loading donor data', error);
            panelBody.innerHTML = `
                <div class="text-center py-4 text-danger">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                    <p class="mt-2">Error loading donor data</p>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="CallWidget.loadDonorData()">
                        <i class="fas fa-redo me-1"></i>Retry
                    </button>
                </div>
            `;
        } finally {
            this.state.dataLoading = false;
        }
    },

    renderPanelContent() {
        const panelBody = document.getElementById('panelBody');
        if (!panelBody || !this.state.donorData) return;

        const d = this.state.donorData.donor;
        const rep = this.state.donorData.representative;
        const pledges = this.state.donorData.pledges || [];
        const payments = this.state.donorData.payments || [];
        const calls = this.state.donorData.calls || [];
        const stats = this.state.donorData.stats || {};

        // Format currency
        const fmt = (val) => Number(val || 0).toLocaleString('en-GB', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        // Format date
        const fmtDate = (dateStr) => {
            if (!dateStr) return 'N/A';
            try {
                return new Date(dateStr).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
            } catch {
                return dateStr;
            }
        };

        // Format outcome
        const fmtOutcome = (outcome) => {
            if (!outcome) return 'N/A';
            return outcome.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        };

        panelBody.innerHTML = `
            <!-- Overview Tab -->
            <div class="tab-content active" data-tab="overview">
                <div class="info-group">
                    <div class="info-label-sm">Name</div>
                    <div class="info-value-sm highlight">${d.name || 'Unknown'}</div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Phone</div>
                    <div class="info-value-sm">
                        <a href="tel:${d.phone}" style="color:inherit;text-decoration:none;">
                            <i class="fas fa-phone me-1"></i>${d.phone || 'N/A'}
                        </a>
                    </div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Email</div>
                    <div class="info-value-sm">
                        ${d.email 
                            ? `<a href="mailto:${d.email}" style="color:inherit;text-decoration:none;"><i class="fas fa-envelope me-1"></i>${d.email}</a>` 
                            : '<span class="text-muted">Not provided</span>'}
                    </div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">City</div>
                    <div class="info-value-sm">${d.city || '<span class="text-muted">Unknown</span>'}</div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Baptism Name</div>
                    <div class="info-value-sm">${d.baptism_name || '<span class="text-muted">Not recorded</span>'}</div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Church</div>
                    <div class="info-value-sm">${d.church_name || '<span class="text-muted">Not assigned</span>'}${d.church_city ? ` <small class="text-muted">(${d.church_city})</small>` : ''}</div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Registrar</div>
                    <div class="info-value-sm">${d.registrar_name || 'Unknown'}</div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Preferred Language</div>
                    <div class="info-value-sm">${d.preferred_language_label || 'English'}</div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Preferred Payment</div>
                    <div class="info-value-sm">${d.preferred_payment_method_label || '<span class="text-muted">Not set</span>'}</div>
                </div>
                ${rep ? `
                <div class="info-group">
                    <div class="info-label-sm">Representative</div>
                    <div class="info-value-sm">
                        ${rep.name}${rep.role ? ` <small class="text-muted">(${rep.role})</small>` : ''}
                        ${rep.phone ? `<br><a href="tel:${rep.phone}" style="color:inherit;"><small>${rep.phone}</small></a>` : ''}
                    </div>
                </div>
                ` : ''}
                <div class="info-group">
                    <div class="info-label-sm">Reference Number</div>
                    <div class="info-value-sm"><code>${d.reference_number || d.id}</code></div>
                </div>
                <div class="info-group">
                    <div class="info-label-sm">Donor Since</div>
                    <div class="info-value-sm">${fmtDate(d.created_at)}</div>
                </div>
            </div>

            <!-- Financial Tab -->
            <div class="tab-content" data-tab="financial">
                <div class="financial-summary mb-3">
                    <div class="fin-row">
                        <span class="fin-label">Total Pledged</span>
                        <span class="fin-value text-primary fw-bold">£${fmt(d.total_pledged)}</span>
                    </div>
                    <div class="fin-row">
                        <span class="fin-label">Total Paid</span>
                        <span class="fin-value text-success">£${fmt(d.total_paid)}</span>
                    </div>
                    <div class="fin-row fin-balance">
                        <span class="fin-label">Remaining Balance</span>
                        <span class="fin-value ${d.balance > 0 ? 'text-danger' : 'text-success'} fw-bold">£${fmt(d.balance)}</span>
                    </div>
                </div>
                
                <div class="info-group">
                    <div class="info-label-sm">Payment Status</div>
                    <div class="info-value-sm">
                        <span class="badge ${this.getStatusBadgeClass(d.payment_status)}">${this.formatStatus(d.payment_status)}</span>
                    </div>
                </div>
                
                <div class="info-group">
                    <div class="info-label-sm">Donor Type</div>
                    <div class="info-value-sm">${d.donor_type === 'pledge' ? 'Pledge Donor' : 'Immediate Payment'}</div>
                </div>
                
                <h6 class="mt-3 mb-2 text-muted"><i class="fas fa-file-invoice me-1"></i>Pledges (${pledges.length})</h6>
                ${pledges.length > 0 ? `
                    <div class="mini-list">
                        ${pledges.map(p => `
                            <div class="mini-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">£${fmt(p.amount)}</span>
                                    <span class="badge ${p.status === 'approved' ? 'bg-success' : 'bg-secondary'}">${p.status}</span>
                                </div>
                                <small class="text-muted">${fmtDate(p.created_at)} • ${p.registrar_name || 'Unknown'}</small>
                            </div>
                        `).join('')}
                    </div>
                ` : '<p class="text-muted small">No pledges recorded</p>'}
            </div>

            <!-- Payments Tab -->
            <div class="tab-content" data-tab="payments">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Payment History</h6>
                    <span class="badge bg-secondary">${payments.length} payments</span>
                </div>
                
                ${payments.length > 0 ? `
                    <div class="mini-list">
                        ${payments.map(p => `
                            <div class="mini-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold text-success">£${fmt(p.amount)}</span>
                                    <span class="badge ${p.status === 'approved' || p.status === 'confirmed' ? 'bg-success' : 'bg-warning'}">${p.status}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">${fmtDate(p.payment_date)}</small>
                                    <small class="text-muted">${p.payment_method || 'N/A'}</small>
                                </div>
                                ${p.reference ? `<small class="text-muted">Ref: ${p.reference}</small>` : ''}
                            </div>
                        `).join('')}
                    </div>
                ` : `
                    <div class="text-center py-3 text-muted">
                        <i class="fas fa-receipt fa-2x mb-2"></i>
                        <p class="mb-0">No payments recorded</p>
                    </div>
                `}
            </div>

            <!-- Calls Tab -->
            <div class="tab-content" data-tab="calls">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Call History</h6>
                    <span class="badge bg-secondary">${calls.length} calls</span>
                </div>
                
                ${calls.length > 0 ? `
                    <div class="mini-list">
                        ${calls.map(c => `
                            <div class="mini-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fw-bold">${c.agent_name || 'Agent'}</span>
                                    <span class="badge ${this.getOutcomeBadgeClass(c.outcome)}">${fmtOutcome(c.outcome) || 'In Progress'}</span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">${fmtDate(c.call_started_at)}</small>
                                    <small class="text-muted">${c.duration_seconds ? Math.floor(c.duration_seconds / 60) + 'm ' + (c.duration_seconds % 60) + 's' : 'N/A'}</small>
                                </div>
                                ${c.notes ? `<small class="text-muted d-block mt-1" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${c.notes}</small>` : ''}
                            </div>
                        `).join('')}
                    </div>
                ` : `
                    <div class="text-center py-3 text-muted">
                        <i class="fas fa-phone-slash fa-2x mb-2"></i>
                        <p class="mb-0">No previous calls</p>
                    </div>
                `}
            </div>

            <!-- Notes Tab -->
            <div class="tab-content" data-tab="notes">
                <div class="info-group">
                    <div class="info-label-sm">Admin Notes</div>
                    <div class="info-value-sm">${d.admin_notes || '<span class="text-muted">No notes</span>'}</div>
                </div>
                
                <div class="info-group">
                    <div class="info-label-sm">Flagged for Follow-up</div>
                    <div class="info-value-sm">
                        ${d.flagged_for_followup 
                            ? `<span class="badge bg-warning"><i class="fas fa-flag me-1"></i>Yes - ${d.followup_priority} priority</span>` 
                            : '<span class="text-muted">No</span>'}
                    </div>
                </div>
                
                <div class="info-group">
                    <div class="info-label-sm">Communication Preferences</div>
                    <div class="info-value-sm">
                        <span class="${d.sms_opt_in ? 'text-success' : 'text-muted'}">
                            <i class="fas fa-${d.sms_opt_in ? 'check' : 'times'} me-1"></i>SMS
                        </span>
                        <span class="mx-2">|</span>
                        <span class="${d.email_opt_in ? 'text-success' : 'text-muted'}">
                            <i class="fas fa-${d.email_opt_in ? 'check' : 'times'} me-1"></i>Email
                        </span>
                    </div>
                </div>
                
                <div class="info-group">
                    <div class="info-label-sm">Internal IDs</div>
                    <div class="info-value-sm">
                        <code>Session: ${this.config.sessionId}</code><br>
                        <code>Donor: ${d.id}</code>
                    </div>
                </div>
                
                <div class="mt-3">
                    <a href="../donor-management/view-donor.php?id=${d.id}" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                        <i class="fas fa-external-link-alt me-1"></i>Open Full Profile
                    </a>
                </div>
            </div>
        `;
    },

    getStatusBadgeClass(status) {
        const classes = {
            'completed': 'bg-success',
            'paying': 'bg-primary',
            'overdue': 'bg-danger',
            'not_started': 'bg-secondary',
            'no_pledge': 'bg-light text-dark',
            'defaulted': 'bg-danger'
        };
        return classes[status] || 'bg-secondary';
    },

    formatStatus(status) {
        if (!status) return 'Unknown';
        return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    },

    getOutcomeBadgeClass(outcome) {
        if (!outcome) return 'bg-info';
        if (outcome.includes('success') || outcome.includes('agreed') || outcome.includes('paid')) return 'bg-success';
        if (outcome.includes('refused') || outcome.includes('hostile') || outcome.includes('no_answer')) return 'bg-danger';
        if (outcome.includes('callback') || outcome.includes('interested')) return 'bg-warning';
        return 'bg-secondary';
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
