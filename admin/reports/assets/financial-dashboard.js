/**
 * Financial Dashboard - Chart.js Visualizations
 * Mobile-optimized, touch-friendly charts
 */

(function() {
    'use strict';
    
    // Configuration from PHP
    const config = window.dashboardConfig || { currency: '£', apiUrl: 'api/financial-data.php' };
    
    // Chart instances storage
    let charts = {};
    
    // Color palette
    const colors = {
        green: '#3fb950',
        blue: '#58a6ff',
        gold: '#d29922',
        purple: '#a371f7',
        cyan: '#39c5cf',
        red: '#f85149',
        gray: '#8b949e',
        greenDim: 'rgba(63, 185, 80, 0.2)',
        blueDim: 'rgba(88, 166, 255, 0.2)',
        goldDim: 'rgba(210, 153, 34, 0.2)',
        purpleDim: 'rgba(163, 113, 247, 0.2)'
    };
    
    // Chart.js global defaults for dark theme
    Chart.defaults.color = '#8b949e';
    Chart.defaults.borderColor = '#30363d';
    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif';
    Chart.defaults.font.size = 11;
    
    /**
     * Format currency
     */
    function formatCurrency(value) {
        if (value >= 1000000) {
            return config.currency + (value / 1000000).toFixed(1) + 'M';
        } else if (value >= 1000) {
            return config.currency + (value / 1000).toFixed(1) + 'K';
        }
        return config.currency + value.toFixed(0);
    }
    
    /**
     * Format number
     */
    function formatNumber(value) {
        return new Intl.NumberFormat('en-GB').format(value);
    }
    
    /**
     * Initialize Payment Trend Chart
     */
    function initTrendChart(data) {
        const ctx = document.getElementById('paymentTrendChart');
        if (!ctx) return;
        
        if (charts.trend) charts.trend.destroy();
        
        charts.trend = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Payments',
                    data: data.data,
                    borderColor: colors.green,
                    backgroundColor: colors.greenDim,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 0,
                    pointHoverRadius: 6,
                    pointHoverBackgroundColor: colors.green,
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#161b22',
                        borderColor: '#30363d',
                        borderWidth: 1,
                        titleColor: '#f0f6fc',
                        bodyColor: '#c9d1d9',
                        padding: 12,
                        displayColors: false,
                        callbacks: {
                            label: function(context) {
                                return formatCurrency(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 7
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#21262d'
                        },
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Initialize Payment Methods Pie Chart
     */
    function initMethodsChart(data) {
        const ctx = document.getElementById('paymentMethodsChart');
        if (!ctx) return;
        
        if (charts.methods) charts.methods.destroy();
        
        const chartColors = [colors.green, colors.blue, colors.purple, colors.gray];
        
        charts.methods = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: chartColors,
                    borderColor: '#161b22',
                    borderWidth: 2,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#161b22',
                        borderColor: '#30363d',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percent = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + formatCurrency(context.raw) + ' (' + percent + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        // Custom legend
        updateLegend('methodsLegend', data.labels, chartColors, data.data);
    }
    
    /**
     * Initialize Income Source Chart
     */
    function initSourceChart(data) {
        const ctx = document.getElementById('incomeSourceChart');
        if (!ctx) return;
        
        if (charts.source) charts.source.destroy();
        
        const chartColors = [colors.blue, colors.gold];
        
        charts.source = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: chartColors,
                    borderColor: '#161b22',
                    borderWidth: 2,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#161b22',
                        borderColor: '#30363d',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.label + ': ' + formatCurrency(context.raw);
                            }
                        }
                    }
                }
            }
        });
        
        updateLegend('sourceLegend', data.labels, chartColors, data.data);
    }
    
    /**
     * Initialize Monthly Chart
     */
    function initMonthlyChart(data) {
        const ctx = document.getElementById('monthlyChart');
        if (!ctx) return;
        
        if (charts.monthly) charts.monthly.destroy();
        
        charts.monthly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Collected',
                    data: data.payments,
                    backgroundColor: colors.green,
                    borderRadius: 4,
                    barPercentage: 0.6
                }, {
                    label: 'Pledged',
                    data: data.pledges,
                    backgroundColor: colors.goldDim,
                    borderColor: colors.gold,
                    borderWidth: 1,
                    borderRadius: 4,
                    barPercentage: 0.6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'rect'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#161b22',
                        borderColor: '#30363d',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + formatCurrency(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#21262d'
                        },
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Initialize Pledge vs Payment Chart
     */
    function initPledgePaymentChart(data) {
        const ctx = document.getElementById('pledgeVsPaymentChart');
        if (!ctx) return;
        
        if (charts.pledgePayment) charts.pledgePayment.destroy();
        
        const chartColors = [colors.green, colors.gold];
        
        charts.pledgePayment = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: chartColors,
                    borderColor: '#161b22',
                    borderWidth: 2,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#161b22',
                        borderColor: '#30363d',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percent = total > 0 ? ((context.raw / total) * 100).toFixed(1) : 0;
                                return context.label + ': ' + formatCurrency(context.raw) + ' (' + percent + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        updateLegend('pledgePaymentLegend', data.labels, chartColors, data.data);
    }
    
    /**
     * Initialize Weekly Pattern Chart
     */
    function initWeeklyChart(data) {
        const ctx = document.getElementById('weeklyPatternChart');
        if (!ctx) return;
        
        if (charts.weekly) charts.weekly.destroy();
        
        charts.weekly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    backgroundColor: data.data.map((val, idx) => {
                        const max = Math.max(...data.data);
                        const intensity = max > 0 ? val / max : 0;
                        return `rgba(88, 166, 255, ${0.3 + intensity * 0.7})`;
                    }),
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#161b22',
                        borderColor: '#30363d',
                        borderWidth: 1,
                        padding: 12,
                        callbacks: {
                            label: function(context) {
                                return formatCurrency(context.raw);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#21262d'
                        },
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            }
                        }
                    }
                }
            }
        });
    }
    
    /**
     * Update Custom Legend
     */
    function updateLegend(elementId, labels, colors, values) {
        const container = document.getElementById(elementId);
        if (!container) return;
        
        const total = values.reduce((a, b) => a + b, 0);
        
        container.innerHTML = labels.map((label, i) => {
            const percent = total > 0 ? ((values[i] / total) * 100).toFixed(0) : 0;
            return `
                <span class="legend-item">
                    <span class="legend-dot" style="background: ${colors[i]}"></span>
                    ${label}: ${percent}%
                </span>
            `;
        }).join('');
    }
    
    /**
     * Update KPI Values
     */
    function updateKPIs(kpis) {
        const updates = {
            'kpiGrandTotal': formatCurrency(kpis.grandTotal),
            'kpiGoalProgress': kpis.goalProgress + '%',
            'kpiTotalPaid': formatCurrency(kpis.totalPaid),
            'kpiPaymentCount': formatNumber(kpis.paymentCount) + ' payments',
            'kpiOutstanding': formatCurrency(kpis.outstanding),
            'kpiCollectionRate': kpis.collectionRate + '%',
            'kpiDonorCount': formatNumber(kpis.donorCount),
            'kpiAvgPayment': formatCurrency(kpis.avgPayment),
            'statInstant': formatCurrency(kpis.instantPayments),
            'statPledgePay': formatCurrency(kpis.pledgePayments),
            'lastUpdated': new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
        };
        
        Object.entries(updates).forEach(([id, value]) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        });
        
        // Update collection rate progress bar
        const progressBar = document.getElementById('kpiCollectionBar');
        if (progressBar) {
            progressBar.style.width = Math.min(kpis.collectionRate, 100) + '%';
        }
    }
    
    /**
     * Fetch Dashboard Data
     */
    async function fetchDashboardData(range = 'all') {
        try {
            const response = await fetch(config.apiUrl + '?range=' + range);
            if (!response.ok) throw new Error('Network response was not ok');
            return await response.json();
        } catch (error) {
            console.error('Failed to fetch dashboard data:', error);
            return null;
        }
    }
    
    /**
     * Refresh All Data
     */
    async function refreshAllData() {
        const refreshBtn = document.getElementById('refreshBtn');
        const dateRange = document.getElementById('dateRange');
        const range = dateRange ? dateRange.value : 'all';
        
        // Add loading state
        if (refreshBtn) {
            refreshBtn.classList.add('loading');
            refreshBtn.querySelector('i').classList.add('spin');
        }
        
        document.querySelectorAll('.chart-card').forEach(card => {
            card.classList.add('loading');
        });
        
        const data = await fetchDashboardData(range);
        
        // Remove loading state
        if (refreshBtn) {
            refreshBtn.classList.remove('loading');
            refreshBtn.querySelector('i').classList.remove('spin');
        }
        
        document.querySelectorAll('.chart-card').forEach(card => {
            card.classList.remove('loading');
        });
        
        if (data && data.success) {
            updateKPIs(data.kpis);
            initTrendChart(data.charts.trend);
            initMethodsChart(data.charts.methods);
            initSourceChart(data.charts.sources);
            initMonthlyChart(data.charts.monthly);
            initPledgePaymentChart(data.charts.pledgeVsPayment);
            initWeeklyChart(data.charts.weekly);
        }
    }
    
    /**
     * Initialize Dashboard
     */
    function initDashboard() {
        // Initial data load
        refreshAllData();
        
        // Date range change handler
        const dateRange = document.getElementById('dateRange');
        if (dateRange) {
            dateRange.addEventListener('change', function() {
                refreshAllData();
            });
        }
        
        // Auto-refresh every 5 minutes
        setInterval(refreshAllData, 5 * 60 * 1000);
    }
    
    // Expose refresh function globally
    window.refreshAllData = refreshAllData;
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDashboard);
    } else {
        initDashboard();
    }
    
})();

