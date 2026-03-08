document.addEventListener('DOMContentLoaded', () => {
    const GridSync = {
        apiUrl: null,
        pollInterval: 2000,
        currentFilter: 'total',
        latestGridData: {},
        latestSummary: null,
        lastRefreshCheck: 0,
        colors: {
            total: '#e2ca18',
            pledged: '#f59e0b',
            paid: '#22c55e',
            blocked: '#a855f7',
            available: '#cbd5e1'
        },
        filterMeta: {
            total: { label: 'Total Coverage' },
            pledged: { label: 'Pledged Coverage' },
            paid: { label: 'Paid Coverage' },
            blocked: { label: 'Blocked Area' },
            available: { label: 'Available Area' }
        },

        init() {
            this.resolveApiUrl();
            this.setupFilterControls();
            this.waitForGridCreation().then(() => {
                this.startPolling();
                this.setupRefreshSignals();
            });
        },

        resolveApiUrl() {
            try {
                const marker = '/public/projector/floor/';
                const path = window.location.pathname;
                const idx = path.indexOf(marker);
                const base = idx !== -1 ? path.substring(0, idx + 1) : '/';
                this.apiUrl = base + 'api/grid_status.php';
            } catch (error) {
                this.apiUrl = '/api/grid_status.php';
            }
        },

        waitForGridCreation() {
            return new Promise(resolve => {
                const checkInterval = setInterval(() => {
                    const lastCell = document.querySelector('[id^="G0505-"]');
                    if (lastCell) {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, 100);
            });
        },

        setupFilterControls() {
            this.filterToggleBtn = document.getElementById('filterToggleBtn');
            this.filterPanel = document.getElementById('filterPanel');
            this.filterCloseBtn = document.getElementById('filterCloseBtn');
            this.applyFilterBtn = document.getElementById('applyFilterBtn');
            this.filterInputs = Array.from(document.querySelectorAll('input[name="floorFilter"]'));

            if (!this.filterToggleBtn || !this.filterPanel || !this.applyFilterBtn) {
                return;
            }

            const openPanel = () => {
                this.filterPanel.hidden = false;
                this.filterToggleBtn.classList.add('is-open');
            };

            const closePanel = () => {
                this.filterPanel.hidden = true;
                this.filterToggleBtn.classList.remove('is-open');
            };

            this.filterToggleBtn.addEventListener('click', () => {
                if (this.filterPanel.hidden) {
                    openPanel();
                } else {
                    closePanel();
                }
            });

            this.filterCloseBtn?.addEventListener('click', closePanel);

            this.applyFilterBtn.addEventListener('click', () => {
                const selected = this.filterInputs.find(input => input.checked);
                this.currentFilter = selected ? selected.value : 'total';
                this.render();
                closePanel();
            });

            document.addEventListener('click', event => {
                if (
                    !this.filterPanel.hidden &&
                    !this.filterPanel.contains(event.target) &&
                    !this.filterToggleBtn.contains(event.target)
                ) {
                    closePanel();
                }
            });
        },

        startPolling() {
            this.fetchAndUpdate();
            setInterval(() => this.fetchAndUpdate(), this.pollInterval);

            setInterval(() => {
                const lastRefresh = localStorage.getItem('floorMapRefresh');
                if (lastRefresh && parseInt(lastRefresh, 10) > this.lastRefreshCheck) {
                    this.lastRefreshCheck = parseInt(lastRefresh, 10);
                    this.fetchAndUpdate();
                }
            }, 500);
        },

        setupRefreshSignals() {
            window.addEventListener('storage', event => {
                if (event.key === 'floorMapRefresh' && event.newValue) {
                    this.fetchAndUpdate();
                }
            });

            window.refreshFloorMap = () => {
                this.fetchAndUpdate();
            };

            window.addEventListener('focus', () => {
                const lastRefresh = localStorage.getItem('floorMapRefresh');
                if (lastRefresh && (Date.now() - parseInt(lastRefresh, 10)) < 10000) {
                    this.fetchAndUpdate();
                }
            });
        },

        async fetchAndUpdate() {
            try {
                const response = await fetch(this.apiUrl);
                if (!response.ok) {
                    throw new Error(`API request failed with status ${response.status}`);
                }

                const data = await response.json();
                if (data.success && data.data && data.data.grid_cells) {
                    this.latestGridData = data.data.grid_cells;
                    this.latestSummary = data.data.summary || null;
                    this.render();
                }
            } catch (error) {
                console.error('GridSync: Error fetching grid data:', error);
            }
        },

        getQuarterCells() {
            return Array.from(document.querySelectorAll('.grid-tile-quarter, .quarter-tile'));
        },

        resetCellStyles() {
            this.getQuarterCells().forEach(cell => {
                cell.style.transition = 'background-color 0.35s ease, opacity 0.3s ease';
                cell.style.backgroundColor = '#F5F5F5';
                cell.style.opacity = '1';
                cell.style.boxShadow = 'none';
                cell.style.filter = 'none';
                cell.classList.remove('cell-allocated', 'cell-pledged', 'cell-paid', 'cell-blocked', 'cell-available');
            });
        },

        shouldHighlightStatus(status) {
            switch (this.currentFilter) {
                case 'pledged':
                    return status === 'pledged';
                case 'paid':
                    return status === 'paid';
                case 'blocked':
                    return status === 'blocked';
                case 'total':
                default:
                    return status === 'pledged' || status === 'paid';
            }
        },

        getHighlightColor(status) {
            if (this.currentFilter === 'total') {
                return this.colors.total;
            }
            if (status === 'pledged') {
                return this.colors.pledged;
            }
            if (status === 'paid') {
                return this.colors.paid;
            }
            if (status === 'blocked') {
                return this.colors.blocked;
            }
            return this.colors.available;
        },

        updateGrid(allocatedData) {
            this.resetCellStyles();

            const allCells = this.getQuarterCells();
            const allocatedIds = new Set();

            if (this.currentFilter === 'pledged' || this.currentFilter === 'paid' || this.currentFilter === 'blocked') {
                allCells.forEach(cell => {
                    cell.style.backgroundColor = '#6b7280';
                    cell.style.opacity = '0.22';
                });
            }

            Object.values(allocatedData || {}).forEach(cells => {
                if (!Array.isArray(cells)) {
                    return;
                }

                cells.forEach(cellData => {
                    const cellElement = document.getElementById(cellData.cell_id);
                    if (!cellElement || !cellElement.matches('.grid-tile-quarter, .quarter-tile')) {
                        return;
                    }

                    allocatedIds.add(cellData.cell_id);

                    if (this.shouldHighlightStatus(cellData.status)) {
                        cellElement.style.backgroundColor = this.getHighlightColor(cellData.status);
                        cellElement.style.opacity = '1';
                        cellElement.style.boxShadow = '0 0 0 1px rgba(255,255,255,0.18) inset, 0 0 12px rgba(255,255,255,0.18)';
                        cellElement.style.filter = 'saturate(1.15) brightness(1.02)';
                        cellElement.classList.add(`cell-${cellData.status}`);
                    } else if (this.currentFilter !== 'total' && this.currentFilter !== 'available') {
                        cellElement.style.opacity = '0.16';
                    }
                });
            });

            if (this.currentFilter === 'available') {
                allCells.forEach(cell => {
                    if (!allocatedIds.has(cell.id)) {
                        cell.style.backgroundColor = '#d1d5db';
                        cell.style.boxShadow = '0 0 0 1px rgba(255,255,255,0.14) inset';
                        cell.classList.add('cell-available');
                    } else {
                        cell.style.backgroundColor = '#4b5563';
                        cell.style.opacity = '0.18';
                    }
                });
            } else {
                allCells.forEach(cell => {
                    if (!allocatedIds.has(cell.id)) {
                        cell.style.backgroundColor = '#F5F5F5';
                    }
                });
            }
        },

        getAreaSize(cellData) {
            const parsedArea = parseFloat(cellData.area_size);
            if (!Number.isNaN(parsedArea) && parsedArea > 0) {
                return parsedArea;
            }
            if (cellData.cell_type === '1x1') {
                return 1;
            }
            if (cellData.cell_type === '1x0.5') {
                return 0.5;
            }
            return 0.25;
        },

        calculateMetrics(summary, allocatedData) {
            const totalArea = parseFloat(summary?.total_area_sqm ?? 0);
            let pledgedArea = 0;
            let paidArea = 0;
            let blockedArea = 0;

            Object.values(allocatedData || {}).forEach(cells => {
                if (!Array.isArray(cells)) {
                    return;
                }

                cells.forEach(cellData => {
                    const areaSize = this.getAreaSize(cellData);
                    if (cellData.status === 'pledged') {
                        pledgedArea += areaSize;
                    } else if (cellData.status === 'paid') {
                        paidArea += areaSize;
                    } else if (cellData.status === 'blocked') {
                        blockedArea += areaSize;
                    }
                });
            });

            const coveredArea = pledgedArea + paidArea;
            const availableArea = Math.max(totalArea - coveredArea - blockedArea, 0);

            switch (this.currentFilter) {
                case 'pledged':
                    return { coveredArea: pledgedArea, totalArea };
                case 'paid':
                    return { coveredArea: paidArea, totalArea };
                case 'blocked':
                    return { coveredArea: blockedArea, totalArea };
                case 'available':
                    return { coveredArea: availableArea, totalArea };
                case 'total':
                default:
                    return { coveredArea, totalArea };
            }
        },

        updateStatsCard(summary, allocatedData) {
            if (!summary) {
                return;
            }

            const coveredAreaElement = document.getElementById('covered-area');
            const totalAreaElement = document.getElementById('total-area');
            const progressFillElement = document.getElementById('progress-fill');
            const percentageElement = document.getElementById('coverage-percentage');
            const coverageLabelElement = document.getElementById('coverage-label');

            const metrics = this.calculateMetrics(summary, allocatedData);
            const coveredArea = metrics.coveredArea;
            const totalArea = metrics.totalArea;
            const percentage = totalArea > 0 ? (coveredArea / totalArea) * 100 : 0;

            if (coveredAreaElement) {
                coveredAreaElement.textContent = coveredArea.toFixed(2);
            }

            if (totalAreaElement) {
                totalAreaElement.textContent = totalArea.toFixed(2);
            }

            if (coverageLabelElement) {
                coverageLabelElement.textContent = this.filterMeta[this.currentFilter]?.label || 'Coverage';
            }

            if (progressFillElement) {
                progressFillElement.style.width = `${percentage}%`;
                progressFillElement.style.backgroundColor = this.colors[this.currentFilter] || this.colors.total;
            }

            if (percentageElement) {
                percentageElement.textContent = `${percentage.toFixed(1)}%`;
            }
        },

        render() {
            this.updateGrid(this.latestGridData);
            this.updateStatsCard(this.latestSummary, this.latestGridData);
        }
    };

    GridSync.init();
});
