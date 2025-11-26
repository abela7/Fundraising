/**
 * Financial Dashboard - Chart.js Visualizations
 * 
 * Mobile-first, responsive charts with auto-refresh capability.
 * 100% data-driven from the API endpoint.
 */

(function() {
    'use strict';

    // ============================================
    // Configuration
    // ============================================
    const CONFIG = {
        apiEndpoint: 'api/financial-data.php',
        refreshInterval: 60000, // 1 minute
        chartColors: {
            primary: '#2563eb',
            primaryLight: '#3b82f6',
            success: '#10b981',
            successLight: '#34d399',
            warning: '#f59e0b',
            warningLight: '#fbbf24',
            danger: '#ef4444',
            dangerLight: '#f87171',
            purple: '#8b5cf6',
            purpleLight: '#a78bfa',
            pink: '#ec4899',
            pinkLight: '#f472b6',
            cyan: '#06b6d4',
            cyanLight: '#22d3ee',
            orange: '#f97316',
            orangeLight: '#fb923c',
            slate: '#64748b',
            slateLight: '#94a3b8'
        },
        chartPalette: [
            '#2563eb', '#10b981', '#f59e0b', '#ef4444', 
            '#8b5cf6', '#ec4899', '#06b6d4', '#f97316',
            '#64748b', '#84cc16'
        ]
    };

    // ============================================
    // State Management
    // ============================================
    let state = {
        data: null,
        charts: {},
        dateRange: 'all',
        fromDate: null,
        toDate: null,
        isLoading: false,
        refreshTimer: null,
        autoRefresh: true
    };

    // ============================================
    // Chart.js Global Configuration
    // ============================================
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#64748b';
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.plugins.legend.position = 'bottom';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.padding = 16;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.9)';
    Chart.defaults.plugins.tooltip.titleColor = '#fff';
    Chart.defaults.plugins.tooltip.bodyColor = '#e2e8f0';
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.padding = 12;

    // ============================================
    // Utility Functions
    // ============================================
    function formatCurrency(value, currency = '£') {
        if (value >= 1000000) {
            return currency + (value / 1000000).toFixed(1) + 'M';
        }
        if (value >= 1000) {
            return currency + (value / 1000).toFixed(1) + 'K';
        }
        return currency + value.toFixed(0);
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('en-GB').format(value);
    }

    function formatCurrencyFull(value, currency = '£') {
        return currency + new Intl.NumberFormat('en-GB', { 
            minimumFractionDigits: 2, 
            maximumFractionDigits: 2 
        }).format(value);
    }

    function showLoading(container) {
        container.innerHTML = `
            <div class="fd-loading">
                <div class="fd-spinner"></div>
                <div class="fd-loading-text">Loading data...</div>
            </div>
        `;
    }

    function showError(container, message) {
        container.innerHTML = `
            <div class="fd-empty">
                <div class="fd-empty-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="fd-empty-text">${message}</div>
            </div>
        `;
    }

    function destroyChart(chartId) {
        if (state.charts[chartId]) {
            state.charts[chartId].destroy();
            delete state.charts[chartId];
        }
    }

    // ============================================
    // API Functions
    // ============================================
    async function fetchData(section = 'all') {
        const params = new URLSearchParams({ section });
        
        if (state.fromDate && state.toDate) {
            params.append('from', state.fromDate);
            params.append('to', state.toDate);
        }

        try {
            const response = await fetch(`${CONFIG.apiEndpoint}?${params}`);
            if (!response.ok) throw new Error('Failed to fetch data');
            return await response.json();
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    }

    // ============================================
    // KPI Cards
    // ============================================
    function updateKPIs(data) {
        const summary = data.summary;
        const currency = summary.currency === 'GBP' ? '£' : summary.currency;

        // Total Paid
        updateKPICard('kpi-paid', formatCurrency(summary.total_paid, currency), 
            `${formatNumber(summary.payment_count)} payments`);

        // Total Pledged
        updateKPICard('kpi-pledged', formatCurrency(summary.total_pledged, currency), 
            `${formatNumber(summary.pledge_count)} pledges`);

        // Outstanding Balance
        updateKPICard('kpi-outstanding', formatCurrency(summary.outstanding_balance, currency), 
            'Remaining to collect');

        // Grand Total
        updateKPICard('kpi-total', formatCurrency(summary.grand_total, currency), 
            `Target: ${formatCurrency(summary.target_amount, currency)}`);

        // Donor Count
        updateKPICard('kpi-donors', formatNumber(summary.donor_count), 
            `${formatNumber(summary.active_payment_plans)} active plans`);

        // Collection Rate
        updateKPICard('kpi-rate', summary.collection_rate + '%', 
            'Collection efficiency');

        // Update progress bar
        updateProgressBar(summary.target_progress, summary.grand_total, summary.target_amount, currency);

        // Update timestamp
        const updatedEl = document.querySelector('.fd-updated');
        if (updatedEl) {
            updatedEl.textContent = 'Last updated: ' + new Date().toLocaleTimeString('en-GB');
        }
    }

    function updateKPICard(id, value, subtext) {
        const card = document.getElementById(id);
        if (!card) return;

        const valueEl = card.querySelector('.fd-kpi-value');
        const subEl = card.querySelector('.fd-kpi-sub');

        if (valueEl) valueEl.textContent = value;
        if (subEl) subEl.textContent = subtext;

        // Remove skeleton
        card.querySelectorAll('.fd-skeleton').forEach(el => el.remove());
    }

    function updateProgressBar(progress, current, target, currency) {
        const fill = document.querySelector('.fd-progress-fill');
        const value = document.querySelector('.fd-progress-value');
        const currentLabel = document.getElementById('progress-current');
        const targetLabel = document.getElementById('progress-target');

        if (fill) fill.style.width = Math.min(progress, 100) + '%';
        if (value) value.textContent = progress.toFixed(1) + '%';
        if (currentLabel) currentLabel.textContent = formatCurrencyFull(current, currency);
        if (targetLabel) targetLabel.textContent = formatCurrencyFull(target, currency);
    }

    // ============================================
    // Chart: Monthly Trends (Line)
    // ============================================
    function renderMonthlyTrendsChart(data) {
        const container = document.getElementById('chart-monthly-trends');
        if (!container) return;

        destroyChart('monthlyTrends');

        const ctx = container.getContext('2d');
        const monthlyData = data.monthly_trends || [];

        if (monthlyData.length === 0) {
            showError(container.parentElement, 'No trend data available');
            return;
        }

        state.charts.monthlyTrends = new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthlyData.map(d => d.month_label),
                datasets: [
                    {
                        label: 'Pledges',
                        data: monthlyData.map(d => d.pledges),
                        borderColor: CONFIG.chartColors.primary,
                        backgroundColor: 'rgba(37, 99, 235, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Payments',
                        data: monthlyData.map(d => d.payments),
                        borderColor: CONFIG.chartColors.success,
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + formatCurrencyFull(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Daily Trends (Bar)
    // ============================================
    function renderDailyTrendsChart(data) {
        const container = document.getElementById('chart-daily-trends');
        if (!container) return;

        destroyChart('dailyTrends');

        const ctx = container.getContext('2d');
        const dailyData = data.daily_trends || [];

        state.charts.dailyTrends = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: dailyData.map(d => d.date_label),
                datasets: [
                    {
                        label: 'Pledges',
                        data: dailyData.map(d => d.pledges),
                        backgroundColor: CONFIG.chartColors.primary,
                        borderRadius: 4,
                        maxBarThickness: 12
                    },
                    {
                        label: 'Payments',
                        data: dailyData.map(d => d.payments),
                        backgroundColor: CONFIG.chartColors.success,
                        borderRadius: 4,
                        maxBarThickness: 12
                    }
                ]
            },
            options: {
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + formatCurrencyFull(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Payment Methods (Doughnut)
    // ============================================
    function renderPaymentMethodsChart(data) {
        const container = document.getElementById('chart-payment-methods');
        if (!container) return;

        destroyChart('paymentMethods');

        const ctx = container.getContext('2d');
        const methods = data.payment_methods || [];

        if (methods.length === 0) {
            showError(container.parentElement, 'No payment data available');
            return;
        }

        state.charts.paymentMethods = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: methods.map(m => m.method),
                datasets: [{
                    data: methods.map(m => m.total),
                    backgroundColor: CONFIG.chartPalette.slice(0, methods.length),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 8
                }]
            },
            options: {
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.raw / total) * 100).toFixed(1);
                                return `${context.label}: ${formatCurrencyFull(context.raw)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Package Distribution (Bar)
    // ============================================
    function renderPackagesChart(data) {
        const container = document.getElementById('chart-packages');
        if (!container) return;

        destroyChart('packages');

        const ctx = container.getContext('2d');
        const packages = data.packages || [];

        if (packages.length === 0) {
            showError(container.parentElement, 'No package data available');
            return;
        }

        state.charts.packages = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: packages.map(p => p.label),
                datasets: [{
                    label: 'Total Amount',
                    data: packages.map(p => p.total),
                    backgroundColor: CONFIG.chartPalette,
                    borderRadius: 6,
                    maxBarThickness: 50
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const pkg = packages[context.dataIndex];
                                return [
                                    `Amount: ${formatCurrencyFull(context.raw)}`,
                                    `Count: ${pkg.count} pledges`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Pledge Status (Pie)
    // ============================================
    function renderPledgeStatusChart(data) {
        const container = document.getElementById('chart-pledge-status');
        if (!container) return;

        destroyChart('pledgeStatus');

        const ctx = container.getContext('2d');
        const statuses = data.pledge_status || [];

        const statusColors = {
            'Approved': CONFIG.chartColors.success,
            'Pending': CONFIG.chartColors.warning,
            'Rejected': CONFIG.chartColors.danger,
            'Cancelled': CONFIG.chartColors.slate
        };

        state.charts.pledgeStatus = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: statuses.map(s => s.status),
                datasets: [{
                    data: statuses.map(s => s.count),
                    backgroundColor: statuses.map(s => statusColors[s.status] || CONFIG.chartColors.slate),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const status = statuses[context.dataIndex];
                                return [
                                    `${context.label}: ${status.count} pledges`,
                                    `Total: ${formatCurrencyFull(status.total)}`
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Donor Payment Status (Doughnut)
    // ============================================
    function renderDonorStatusChart(data) {
        const container = document.getElementById('chart-donor-status');
        if (!container) return;

        destroyChart('donorStatus');

        const ctx = container.getContext('2d');
        const statuses = data.donor_status || [];

        const statusColors = {
            'Completed': CONFIG.chartColors.success,
            'Paying': CONFIG.chartColors.primary,
            'Not started': CONFIG.chartColors.warning,
            'Overdue': CONFIG.chartColors.danger,
            'No pledge': CONFIG.chartColors.slate,
            'Defaulted': CONFIG.chartColors.pink
        };

        state.charts.donorStatus = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: statuses.map(s => s.status),
                datasets: [{
                    data: statuses.map(s => s.count),
                    backgroundColor: statuses.map(s => statusColors[s.status] || CONFIG.chartColors.slate),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 8
                }]
            },
            options: {
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const status = statuses[context.dataIndex];
                                return [
                                    `${context.label}: ${status.count} donors`,
                                    `Pledged: ${formatCurrencyFull(status.pledged)}`,
                                    `Paid: ${formatCurrencyFull(status.paid)}`
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Grid Allocation (Doughnut)
    // ============================================
    function renderGridStatusChart(data) {
        const container = document.getElementById('chart-grid-status');
        if (!container) return;

        destroyChart('gridStatus');

        const ctx = container.getContext('2d');
        const gridData = data.grid_status || [];

        if (gridData.length === 0) {
            showError(container.parentElement, 'No grid data available');
            return;
        }

        const statusColors = {
            'Available': CONFIG.chartColors.slate,
            'Pledged': CONFIG.chartColors.warning,
            'Paid': CONFIG.chartColors.success,
            'Blocked': CONFIG.chartColors.danger
        };

        state.charts.gridStatus = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: gridData.map(g => g.status),
                datasets: [{
                    data: gridData.map(g => g.count),
                    backgroundColor: gridData.map(g => statusColors[g.status] || CONFIG.chartColors.slate),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 8
                }]
            },
            options: {
                cutout: '60%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 12,
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const grid = gridData[context.dataIndex];
                                return [
                                    `${context.label}: ${grid.count} cells`,
                                    `Area: ${grid.area.toFixed(2)} m²`
                                ];
                            }
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Chart: Payment Plans (Bar)
    // ============================================
    function renderPaymentPlansChart(data) {
        const container = document.getElementById('chart-payment-plans');
        if (!container) return;

        destroyChart('paymentPlans');

        const ctx = container.getContext('2d');
        const plans = data.payment_plans || [];

        if (plans.length === 0) {
            showError(container.parentElement, 'No payment plan data available');
            return;
        }

        const statusColors = {
            'Active': CONFIG.chartColors.primary,
            'Completed': CONFIG.chartColors.success,
            'Paused': CONFIG.chartColors.warning,
            'Defaulted': CONFIG.chartColors.danger,
            'Cancelled': CONFIG.chartColors.slate
        };

        state.charts.paymentPlans = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: plans.map(p => p.status),
                datasets: [{
                    label: 'Plan Count',
                    data: plans.map(p => p.count),
                    backgroundColor: plans.map(p => statusColors[p.status] || CONFIG.chartColors.slate),
                    borderRadius: 6,
                    maxBarThickness: 60
                }]
            },
            options: {
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const plan = plans[context.dataIndex];
                                return [
                                    `Count: ${plan.count} plans`,
                                    `Total Amount: ${formatCurrencyFull(plan.total_amount)}`,
                                    `Amount Paid: ${formatCurrencyFull(plan.amount_paid)}`
                                ];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }

    // ============================================
    // Tables: Top Donors
    // ============================================
    function renderTopDonorsTable(data) {
        const container = document.getElementById('table-top-donors');
        if (!container) return;

        const donors = data.top_donors || [];

        if (donors.length === 0) {
            container.innerHTML = '<div class="fd-empty"><div class="fd-empty-text">No donor data available</div></div>';
            return;
        }

        const statusBadge = (status) => {
            const map = {
                'Completed': 'fd-badge-success',
                'Paying': 'fd-badge-primary',
                'Not started': 'fd-badge-warning',
                'Overdue': 'fd-badge-danger'
            };
            return map[status] || 'fd-badge-muted';
        };

        container.innerHTML = `
            <div class="fd-table-wrapper">
                <table class="fd-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Donor</th>
                            <th>Pledged</th>
                            <th>Paid</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${donors.map((d, i) => `
                            <tr>
                                <td>${i + 1}</td>
                                <td>
                                    <div style="font-weight:500">${d.name || 'Anonymous'}</div>
                                    <div class="fd-text-muted" style="font-size:0.75rem">${d.phone}</div>
                                </td>
                                <td class="fd-font-mono">${formatCurrencyFull(d.total_pledged)}</td>
                                <td class="fd-font-mono fd-text-success">${formatCurrencyFull(d.total_paid)}</td>
                                <td><span class="fd-badge ${statusBadge(d.status)}">${d.status}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ============================================
    // Tables: Recent Transactions
    // ============================================
    function renderRecentTransactionsTable(data) {
        const container = document.getElementById('table-recent');
        if (!container) return;

        const transactions = data.recent_transactions || [];

        if (transactions.length === 0) {
            container.innerHTML = '<div class="fd-empty"><div class="fd-empty-text">No recent transactions</div></div>';
            return;
        }

        const typeBadge = (type) => {
            const map = {
                'pledge': 'fd-badge-primary',
                'payment': 'fd-badge-success',
                'pledge_payment': 'fd-badge-success'
            };
            return map[type] || 'fd-badge-muted';
        };

        const typeLabel = (type) => {
            const map = {
                'pledge': 'Pledge',
                'payment': 'Payment',
                'pledge_payment': 'Installment'
            };
            return map[type] || type;
        };

        const statusBadge = (status) => {
            const map = {
                'approved': 'fd-badge-success',
                'confirmed': 'fd-badge-success',
                'pending': 'fd-badge-warning',
                'rejected': 'fd-badge-danger',
                'voided': 'fd-badge-danger'
            };
            return map[status] || 'fd-badge-muted';
        };

        container.innerHTML = `
            <div class="fd-table-wrapper">
                <table class="fd-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Donor</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${transactions.map(t => `
                            <tr>
                                <td><span class="fd-badge ${typeBadge(t.type)}">${typeLabel(t.type)}</span></td>
                                <td>${t.donor_name || 'Anonymous'}</td>
                                <td class="fd-font-mono">${formatCurrencyFull(parseFloat(t.amount))}</td>
                                <td><span class="fd-badge ${statusBadge(t.status)}">${t.status}</span></td>
                                <td class="fd-text-muted">${new Date(t.date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ============================================
    // Tables: Church Distribution
    // ============================================
    function renderChurchTable(data) {
        const container = document.getElementById('table-churches');
        if (!container) return;

        const churches = data.church_distribution || [];

        if (churches.length === 0) {
            container.innerHTML = '<div class="fd-empty"><div class="fd-empty-text">No church data available</div></div>';
            return;
        }

        container.innerHTML = `
            <div class="fd-table-wrapper">
                <table class="fd-table">
                    <thead>
                        <tr>
                            <th>Church</th>
                            <th>Donors</th>
                            <th>Pledged</th>
                            <th>Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${churches.map(c => `
                            <tr>
                                <td>
                                    <div style="font-weight:500">${c.name}</div>
                                    <div class="fd-text-muted" style="font-size:0.75rem">${c.city || ''}</div>
                                </td>
                                <td>${c.donor_count}</td>
                                <td class="fd-font-mono">${formatCurrencyFull(c.total_pledged)}</td>
                                <td class="fd-font-mono fd-text-success">${formatCurrencyFull(c.total_paid)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ============================================
    // Main Render Functions
    // ============================================
    async function loadDashboard() {
        if (state.isLoading) return;
        state.isLoading = true;

        try {
            const data = await fetchData('all');
            state.data = data;

            // Update KPIs
            updateKPIs(data);

            // Render all charts
            renderMonthlyTrendsChart(data);
            renderDailyTrendsChart(data);
            renderPaymentMethodsChart(data);
            renderPackagesChart(data);
            renderPledgeStatusChart(data);
            renderDonorStatusChart(data);
            renderGridStatusChart(data);
            renderPaymentPlansChart(data);

            // Render tables
            renderTopDonorsTable(data);
            renderRecentTransactionsTable(data);
            renderChurchTable(data);

        } catch (error) {
            console.error('Failed to load dashboard:', error);
        } finally {
            state.isLoading = false;
        }
    }

    // ============================================
    // Date Filter Handlers
    // ============================================
    function setDateRange(range) {
        state.dateRange = range;
        
        // Update button states
        document.querySelectorAll('.fd-filter-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.range === range);
        });

        // Calculate dates based on range
        const today = new Date();
        let fromDate = null;
        let toDate = new Date().toISOString().split('T')[0];

        switch (range) {
            case 'today':
                fromDate = toDate;
                break;
            case 'week':
                const startOfWeek = new Date(today);
                startOfWeek.setDate(today.getDate() - today.getDay() + 1);
                fromDate = startOfWeek.toISOString().split('T')[0];
                break;
            case 'month':
                fromDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                break;
            case 'quarter':
                const quarter = Math.floor(today.getMonth() / 3);
                fromDate = new Date(today.getFullYear(), quarter * 3, 1).toISOString().split('T')[0];
                break;
            case 'year':
                fromDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                break;
            case 'all':
            default:
                fromDate = null;
                toDate = null;
                break;
        }

        state.fromDate = fromDate;
        state.toDate = toDate;

        // Update custom date inputs
        if (fromDate) {
            document.getElementById('date-from').value = fromDate;
            document.getElementById('date-to').value = toDate;
        }

        loadDashboard();
    }

    function applyCustomDates() {
        const from = document.getElementById('date-from').value;
        const to = document.getElementById('date-to').value;

        if (from && to) {
            state.fromDate = from;
            state.toDate = to;
            state.dateRange = 'custom';

            // Clear active state from preset buttons
            document.querySelectorAll('.fd-filter-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            loadDashboard();
        }
    }

    // ============================================
    // Auto Refresh
    // ============================================
    function startAutoRefresh() {
        if (state.refreshTimer) {
            clearInterval(state.refreshTimer);
        }
        
        if (state.autoRefresh) {
            state.refreshTimer = setInterval(loadDashboard, CONFIG.refreshInterval);
        }
    }

    function toggleAutoRefresh() {
        state.autoRefresh = !state.autoRefresh;
        const btn = document.getElementById('btn-auto-refresh');
        if (btn) {
            btn.classList.toggle('active', state.autoRefresh);
            btn.innerHTML = state.autoRefresh 
                ? '<i class="fas fa-sync-alt fa-spin"></i> Auto'
                : '<i class="fas fa-sync-alt"></i> Auto';
        }
        startAutoRefresh();
    }

    // ============================================
    // Export Functions
    // ============================================
    function exportData(format) {
        const params = new URLSearchParams({
            report: 'financial',
            format: format
        });

        if (state.fromDate && state.toDate) {
            params.append('date', 'custom');
            params.append('from', state.fromDate);
            params.append('to', state.toDate);
        }

        window.location.href = `index.php?${params}`;
    }

    // ============================================
    // Initialization
    // ============================================
    function init() {
        // Bind filter buttons
        document.querySelectorAll('.fd-filter-btn[data-range]').forEach(btn => {
            btn.addEventListener('click', () => setDateRange(btn.dataset.range));
        });

        // Bind custom date apply
        const applyBtn = document.getElementById('btn-apply-dates');
        if (applyBtn) {
            applyBtn.addEventListener('click', applyCustomDates);
        }

        // Bind refresh button
        const refreshBtn = document.getElementById('btn-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', loadDashboard);
        }

        // Bind auto-refresh toggle
        const autoRefreshBtn = document.getElementById('btn-auto-refresh');
        if (autoRefreshBtn) {
            autoRefreshBtn.addEventListener('click', toggleAutoRefresh);
        }

        // Bind export buttons
        document.querySelectorAll('[data-export]').forEach(btn => {
            btn.addEventListener('click', () => exportData(btn.dataset.export));
        });

        // Load initial data
        loadDashboard();

        // Start auto-refresh
        startAutoRefresh();
    }

    // ============================================
    // DOM Ready
    // ============================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose for debugging
    window.FinancialDashboard = {
        state,
        loadDashboard,
        setDateRange
    };

})();

