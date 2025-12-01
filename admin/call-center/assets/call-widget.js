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
        donorEmail: '',
        donorCity: '',
        baptismName: '',
        donorType: 'pledge',
        totalPledged: 0,
        totalPaid: 0,
        balance: 0,
        paymentStatus: 'no_pledge',
        preferredLanguage: 'en',
        preferredPaymentMethod: 'bank_transfer',
        source: 'public_form',
        donorCreatedAt: '',
        adminNotes: '',
        flaggedForFollowup: false,
        followupPriority: 'medium',
        pledgeAmount: 0,
        pledgeDate: '',
        pledgeNotes: '',
        registrar: 'Unknown',
        church: 'Unknown',
        churchCity: '',
        representative: '',
        representativePhone: '',
        payments: [],
        paymentPlan: null
    },
    
    currentTab: 0,
    tabs: ['overview', 'contact', 'financial', 'payments', 'plan', 'notes'],

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
        panel: null,
        modal: null,
        tabContent: null,
        tabNav: null,
        prevBtn: null,
        nextBtn: null
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
        try {
            formattedAmount = Number(this.config.pledgeAmount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        } catch (e) {
            console.warn('Error formatting amount', e);
            formattedAmount = '0.00';
        }

        container.innerHTML = `
            <!-- Donor Info Modal -->
            <div class="donor-info-modal" id="donorInfoModal">
                <div class="modal-backdrop" id="modalBackdrop"></div>
                <div class="modal-content-wrapper">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3 class="modal-title">
                                <i class="fas fa-user-circle me-2"></i>${this.config.donorName}
                            </h3>
                            <button class="btn-close-modal" id="btnCloseModal">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <!-- Tab Navigation -->
                        <div class="modal-tabs-nav" id="tabNav">
                            <button class="tab-nav-btn active" data-tab="overview">
                                <i class="fas fa-home"></i> <span>Overview</span>
                            </button>
                            <button class="tab-nav-btn" data-tab="contact">
                                <i class="fas fa-address-card"></i> <span>Contact</span>
                            </button>
                            <button class="tab-nav-btn" data-tab="financial">
                                <i class="fas fa-pound-sign"></i> <span>Financial</span>
                            </button>
                            <button class="tab-nav-btn" data-tab="payments">
                                <i class="fas fa-receipt"></i> <span>Payments</span>
                            </button>
                            <button class="tab-nav-btn" data-tab="plan">
                                <i class="fas fa-calendar-alt"></i> <span>Plan</span>
                            </button>
                            <button class="tab-nav-btn" data-tab="notes">
                                <i class="fas fa-sticky-note"></i> <span>Notes</span>
                            </button>
                        </div>
                        
                        <!-- Tab Content Container -->
                        <div class="modal-body" id="tabContent">
                            <!-- Tabs will be rendered here -->
                        </div>
                        
                        <!-- Navigation Arrows -->
                        <div class="modal-nav-arrows">
                            <button class="nav-arrow prev" id="prevTab" title="Previous Tab">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="nav-arrow next" id="nextTab" title="Next Tab">
                                <i class="fas fa-chevron-right"></i>
                            </button>
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
        this.elements.modal = container.querySelector('#donorInfoModal');
        this.elements.tabContent = container.querySelector('#tabContent');
        this.elements.tabNav = container.querySelector('#tabNav');
        this.elements.prevBtn = container.querySelector('#prevTab');
        this.elements.nextBtn = container.querySelector('#nextTab');
        
        // Render tab content
        this.renderTabs();
    },
    
    renderTabs() {
        if (!this.elements.tabContent) return;
        
        const tabs = {
            overview: this.renderOverviewTab(),
            contact: this.renderContactTab(),
            financial: this.renderFinancialTab(),
            payments: this.renderPaymentsTab(),
            plan: this.renderPlanTab(),
            notes: this.renderNotesTab()
        };
        
        this.elements.tabContent.innerHTML = Object.keys(tabs).map(tabName => `
            <div class="tab-pane ${tabName === 'overview' ? 'active' : ''}" id="tab-${tabName}">
                ${tabs[tabName]}
            </div>
        `).join('');
    },
    
    renderOverviewTab() {
        const formattedPledged = Number(this.config.totalPledged || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const formattedPaid = Number(this.config.totalPaid || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const formattedBalance = Number(this.config.balance || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const formattedPledge = Number(this.config.pledgeAmount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        
        const statusColors = {
            'completed': 'success',
            'paying': 'primary',
            'overdue': 'danger',
            'not_started': 'warning',
            'no_pledge': 'secondary'
        };
        const statusColor = statusColors[this.config.paymentStatus] || 'secondary';
        
        return `
            <div class="tab-content-inner">
                <div class="info-section">
                    <h5><i class="fas fa-user me-2"></i>Basic Information</h5>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Donor ID</span>
                            <span class="info-value">#${this.config.donorId}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Name</span>
                            <span class="info-value highlight">${this.config.donorName}</span>
                        </div>
                        ${this.config.baptismName ? `
                        <div class="info-item">
                            <span class="info-label">Baptism Name</span>
                            <span class="info-value">${this.config.baptismName}</span>
                        </div>
                        ` : ''}
                        <div class="info-item">
                            <span class="info-label">Type</span>
                            <span class="info-value">
                                <span class="badge bg-${this.config.donorType === 'pledge' ? 'warning' : 'success'}">
                                    ${this.config.donorType === 'pledge' ? 'Pledge Donor' : 'Immediate Payer'}
                                </span>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Payment Status</span>
                            <span class="info-value">
                                <span class="badge bg-${statusColor}">
                                    ${(this.config.paymentStatus || 'no_pledge').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                </span>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Registered</span>
                            <span class="info-value">${this.config.donorCreatedAt || 'Unknown'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5><i class="fas fa-pound-sign me-2"></i>Financial Summary</h5>
                    <div class="info-grid">
                        <div class="info-item highlight-box">
                            <span class="info-label">Total Pledged</span>
                            <span class="info-value text-primary">£${formattedPledged}</span>
                        </div>
                        <div class="info-item highlight-box">
                            <span class="info-label">Total Paid</span>
                            <span class="info-value text-success">£${formattedPaid}</span>
                        </div>
                        <div class="info-item highlight-box">
                            <span class="info-label">Remaining Balance</span>
                            <span class="info-value text-danger">£${formattedBalance}</span>
                        </div>
                        ${this.config.pledgeAmount > 0 ? `
                        <div class="info-item">
                            <span class="info-label">Current Pledge</span>
                            <span class="info-value">£${formattedPledge}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Pledge Date</span>
                            <span class="info-value">${this.config.pledgeDate}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    },
    
    renderContactTab() {
        return `
            <div class="tab-content-inner">
                <div class="info-section">
                    <h5><i class="fas fa-phone me-2"></i>Contact Information</h5>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value">
                                <a href="tel:${this.config.donorPhone}" class="contact-link">
                                    <i class="fas fa-phone me-1"></i>${this.config.donorPhone}
                                </a>
                            </span>
                        </div>
                        ${this.config.donorEmail ? `
                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value">
                                <a href="mailto:${this.config.donorEmail}" class="contact-link">
                                    <i class="fas fa-envelope me-1"></i>${this.config.donorEmail}
                                </a>
                            </span>
                        </div>
                        ` : ''}
                        ${this.config.donorCity ? `
                        <div class="info-item">
                            <span class="info-label">City</span>
                            <span class="info-value">${this.config.donorCity}</span>
                        </div>
                        ` : ''}
                        <div class="info-item">
                            <span class="info-label">Preferred Language</span>
                            <span class="info-value">
                                <span class="badge bg-info">${(this.config.preferredLanguage || 'en').toUpperCase()}</span>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h5><i class="fas fa-church me-2"></i>Church & Representative</h5>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Church</span>
                            <span class="info-value">${this.config.church || 'Not assigned'}</span>
                        </div>
                        ${this.config.churchCity ? `
                        <div class="info-item">
                            <span class="info-label">Church City</span>
                            <span class="info-value">${this.config.churchCity}</span>
                        </div>
                        ` : ''}
                        ${this.config.representative ? `
                        <div class="info-item">
                            <span class="info-label">Representative</span>
                            <span class="info-value">${this.config.representative}</span>
                        </div>
                        ` : ''}
                        ${this.config.representativePhone ? `
                        <div class="info-item">
                            <span class="info-label">Rep. Phone</span>
                            <span class="info-value">
                                <a href="tel:${this.config.representativePhone}" class="contact-link">
                                    <i class="fas fa-phone me-1"></i>${this.config.representativePhone}
                                </a>
                            </span>
                        </div>
                        ` : ''}
                        <div class="info-item">
                            <span class="info-label">Registrar</span>
                            <span class="info-value">${this.config.registrar}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },
    
    renderFinancialTab() {
        const formattedPledged = Number(this.config.totalPledged || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const formattedPaid = Number(this.config.totalPaid || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const formattedBalance = Number(this.config.balance || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const paymentMethod = (this.config.preferredPaymentMethod || 'bank_transfer').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        const source = (this.config.source || 'public_form').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        
        return `
            <div class="tab-content-inner">
                <div class="info-section">
                    <h5><i class="fas fa-chart-line me-2"></i>Financial Overview</h5>
                    <div class="info-grid">
                        <div class="info-item highlight-box success">
                            <span class="info-label">Total Pledged</span>
                            <span class="info-value">£${formattedPledged}</span>
                        </div>
                        <div class="info-item highlight-box primary">
                            <span class="info-label">Total Paid</span>
                            <span class="info-value">£${formattedPaid}</span>
                        </div>
                        <div class="info-item highlight-box danger">
                            <span class="info-label">Outstanding Balance</span>
                            <span class="info-value">£${formattedBalance}</span>
                        </div>
                        ${this.config.totalPledged > 0 ? `
                        <div class="info-item">
                            <span class="info-label">Payment Progress</span>
                            <span class="info-value">
                                <div class="progress" style="height: 8px; margin-top: 4px;">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: ${(this.config.totalPaid / this.config.totalPledged * 100).toFixed(1)}%"
                                         aria-valuenow="${(this.config.totalPaid / this.config.totalPledged * 100).toFixed(1)}" 
                                         aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <small class="text-muted">${((this.config.totalPaid / this.config.totalPledged) * 100).toFixed(1)}% paid</small>
                            </span>
                        </div>
                        ` : ''}
                    </div>
                </div>
                
                <div class="info-section">
                    <h5><i class="fas fa-cog me-2"></i>Payment Preferences</h5>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Preferred Method</span>
                            <span class="info-value">${paymentMethod}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Source</span>
                            <span class="info-value">${source}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },
    
    renderPaymentsTab() {
        if (!this.config.payments || this.config.payments.length === 0) {
            return `
                <div class="tab-content-inner">
                    <div class="empty-state">
                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No payment history available</p>
                    </div>
                </div>
            `;
        }
        
        const paymentsHtml = this.config.payments.map(payment => {
            const amount = Number(payment.amount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
            const date = payment.payment_date ? new Date(payment.payment_date).toLocaleDateString('en-GB', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            }) : 'Unknown';
            const method = (payment.payment_method || 'unknown').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            const statusColor = payment.status === 'approved' ? 'success' : payment.status === 'pending' ? 'warning' : 'secondary';
            
            return `
                <div class="payment-item">
                    <div class="payment-header">
                        <span class="payment-amount">£${amount}</span>
                        <span class="badge bg-${statusColor}">${(payment.status || 'unknown').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</span>
                    </div>
                    <div class="payment-details">
                        <span><i class="fas fa-calendar me-1"></i>${date}</span>
                        <span><i class="fas fa-money-bill-wave me-1"></i>${method}</span>
                    </div>
                    ${payment.notes ? `<div class="payment-notes"><small class="text-muted">${payment.notes}</small></div>` : ''}
                </div>
            `;
        }).join('');
        
        return `
            <div class="tab-content-inner">
                <div class="info-section">
                    <h5><i class="fas fa-history me-2"></i>Payment History (${this.config.payments.length})</h5>
                    <div class="payments-list">
                        ${paymentsHtml}
                    </div>
                </div>
            </div>
        `;
    },
    
    renderPlanTab() {
        if (!this.config.paymentPlan) {
            return `
                <div class="tab-content-inner">
                    <div class="empty-state">
                        <i class="fas fa-calendar-alt fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No active payment plan</p>
                    </div>
                </div>
            `;
        }
        
        const plan = this.config.paymentPlan;
        const totalAmount = Number(plan.total_amount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const monthlyAmount = Number(plan.monthly_amount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const paidAmount = Number(plan.paid_amount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const remainingAmount = Number(plan.remaining_amount || 0).toLocaleString('en-GB', {minimumFractionDigits: 2});
        const startDate = plan.start_date ? new Date(plan.start_date).toLocaleDateString('en-GB', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }) : 'Not set';
        const endDate = plan.end_date ? new Date(plan.end_date).toLocaleDateString('en-GB', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }) : 'Not set';
        
        return `
            <div class="tab-content-inner">
                <div class="info-section">
                    <h5><i class="fas fa-calendar-check me-2"></i>Active Payment Plan</h5>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Plan Name</span>
                            <span class="info-value">${plan.template_name || 'Custom Plan'}</span>
                        </div>
                        <div class="info-item highlight-box">
                            <span class="info-label">Total Amount</span>
                            <span class="info-value">£${totalAmount}</span>
                        </div>
                        <div class="info-item highlight-box">
                            <span class="info-label">Monthly Amount</span>
                            <span class="info-value">£${monthlyAmount}</span>
                        </div>
                        <div class="info-item highlight-box success">
                            <span class="info-label">Paid Amount</span>
                            <span class="info-value">£${paidAmount}</span>
                        </div>
                        <div class="info-item highlight-box danger">
                            <span class="info-label">Remaining</span>
                            <span class="info-value">£${remainingAmount}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Duration</span>
                            <span class="info-value">${plan.duration_months || 0} months</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Start Date</span>
                            <span class="info-value">${startDate}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">End Date</span>
                            <span class="info-value">${endDate}</span>
                        </div>
                        ${plan.total_amount > 0 ? `
                        <div class="info-item">
                            <span class="info-label">Progress</span>
                            <span class="info-value">
                                <div class="progress" style="height: 8px; margin-top: 4px;">
                                    <div class="progress-bar" role="progressbar" 
                                         style="width: ${((plan.paid_amount / plan.total_amount) * 100).toFixed(1)}%"
                                         aria-valuenow="${((plan.paid_amount / plan.total_amount) * 100).toFixed(1)}" 
                                         aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <small class="text-muted">${((plan.paid_amount / plan.total_amount) * 100).toFixed(1)}% complete</small>
                            </span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
    },
    
    renderNotesTab() {
        const hasNotes = this.config.adminNotes || this.config.pledgeNotes;
        const flagged = this.config.flaggedForFollowup;
        const priority = this.config.followupPriority || 'medium';
        const priorityColors = {
            'low': 'info',
            'medium': 'warning',
            'high': 'danger',
            'urgent': 'danger'
        };
        
        return `
            <div class="tab-content-inner">
                ${flagged ? `
                <div class="alert alert-${priorityColors[priority]} mb-3">
                    <i class="fas fa-flag me-2"></i>
                    <strong>Flagged for Follow-up</strong> - Priority: 
                    <span class="badge bg-${priorityColors[priority]}">${priority.toUpperCase()}</span>
                </div>
                ` : ''}
                
                <div class="info-section">
                    <h5><i class="fas fa-sticky-note me-2"></i>Admin Notes</h5>
                    <div class="notes-content">
                        ${this.config.adminNotes ? `
                            <div class="note-item">
                                <div class="note-text">${this.config.adminNotes.replace(/\n/g, '<br>')}</div>
                            </div>
                        ` : `
                            <p class="text-muted">No admin notes</p>
                        `}
                    </div>
                </div>
                
                ${this.config.pledgeNotes ? `
                <div class="info-section">
                    <h5><i class="fas fa-file-alt me-2"></i>Pledge Notes</h5>
                    <div class="notes-content">
                        <div class="note-item">
                            <div class="note-text">${this.config.pledgeNotes.replace(/\n/g, '<br>')}</div>
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>
        `;
    },
    
    switchTab(direction) {
        if (direction === 'next') {
            this.currentTab = (this.currentTab + 1) % this.tabs.length;
        } else if (direction === 'prev') {
            this.currentTab = (this.currentTab - 1 + this.tabs.length) % this.tabs.length;
        }
        this.showTab(this.tabs[this.currentTab]);
    },
    
    showTab(tabName) {
        // Update tab index
        this.currentTab = this.tabs.indexOf(tabName);
        
        // Update tab buttons
        if (this.elements.tabNav) {
            const buttons = this.elements.tabNav.querySelectorAll('.tab-nav-btn');
            buttons.forEach((btn, index) => {
                if (this.tabs[index] === tabName) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        
        // Update tab panes
        if (this.elements.tabContent) {
            const panes = this.elements.tabContent.querySelectorAll('.tab-pane');
            panes.forEach(pane => {
                if (pane.id === `tab-${tabName}`) {
                    pane.classList.add('active');
                } else {
                    pane.classList.remove('active');
                }
            });
        }
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

        // Open/close modal
        if (this.elements.btnInfo) {
            this.elements.btnInfo.addEventListener('click', () => {
                if (this.elements.modal) {
                    this.elements.modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });
        }

        const closeBtn = document.getElementById('btnCloseModal');
        const backdrop = document.getElementById('modalBackdrop');
        
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                this.closeModal();
            });
        }
        
        if (backdrop) {
            backdrop.addEventListener('click', () => {
                this.closeModal();
            });
        }
        
        // Tab navigation buttons
        if (this.elements.tabNav) {
            const tabButtons = this.elements.tabNav.querySelectorAll('.tab-nav-btn');
            tabButtons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const tabName = btn.getAttribute('data-tab');
                    this.showTab(tabName);
                });
            });
        }
        
        // Arrow navigation
        if (this.elements.prevBtn) {
            this.elements.prevBtn.addEventListener('click', () => {
                this.switchTab('prev');
            });
        }
        
        if (this.elements.nextBtn) {
            this.elements.nextBtn.addEventListener('click', () => {
                this.switchTab('next');
            });
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (this.elements.modal && this.elements.modal.classList.contains('active')) {
                if (e.key === 'Escape') {
                    this.closeModal();
                } else if (e.key === 'ArrowLeft') {
                    this.switchTab('prev');
                } else if (e.key === 'ArrowRight') {
                    this.switchTab('next');
                }
            }
        });
        
        if (this.elements.btnReset) {
            this.elements.btnReset.addEventListener('click', () => {
                this.reset();
            });
        }
    },
    
    closeModal() {
        if (this.elements.modal) {
            this.elements.modal.classList.remove('active');
            document.body.style.overflow = '';
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
