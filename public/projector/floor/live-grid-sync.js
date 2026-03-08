/**
 * Live Grid Synchronization Script
 *
 * This script connects the visual floor plan grid with the live database status.
 * It is designed to be clean, professional, and decoupled from the grid generation logic.
 *
 * Responsibilities:
 * 1. Fetch allocation data from the API endpoint.
 * 2. Reset the visual state of all cells.
 * 3. Apply 'pledged' (orange) and 'paid' (green) styles to the correct cells.
 * 4. Poll for live updates at a regular interval.
 * 5. Provide clear console logs for debugging.
 */
document.addEventListener('DOMContentLoaded', () => {
    const GridSync = {
        apiUrl: null,
        pollInterval: 2000,
        gridReady: false,
        currentFilter: 'total',
        latestGridData: {},
        latestSummary: null,
        lastRefreshCheck: 0,
        colors: {
            pledged: '#f59e0b',
            paid: '#22c55e',
            blocked: '#a855f7',
            available: '#38bdf8'
        },
        filterMeta: {
            total: { label: 'Total Coverage' },
            pledged: { label: 'Pledged Coverage' },
            paid: { label: 'Paid Coverage' },
            blocked: { label: 'Blocked Area' },
            available: { label: 'Available Area' }
        },

        init() {
            console.log('GridSync: Initializing...');
            this.resolveApiUrl();
            console.log('GridSync: Using API URL ->', this.apiUrl);
            this.waitForGridCreation().then(() => {
                console.log('GridSync: Grid is ready. Starting synchronization.');
                this.gridReady = true;
                this.setupFilterControls();
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
                console.log('GridSync: Applied filter ->', this.currentFilter);
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
                    console.log('GridSync: Aggressive polling detected localStorage change.');
                    this.lastRefreshCheck = parseInt(lastRefresh, 10);
                    this.fetchAndUpdate();
                }
            }, 500);
        },

        setupRefreshSignals() {
            window.addEventListener('storage', event => {
                console.log('GridSync: Storage event detected', event.key, event.newValue);
                if (event.key === 'floorMapRefresh' && event.newValue) {
                    console.log('GridSync: Immediate refresh triggered by admin action.');
                    this.fetchAndUpdate();
                }
            });

            window.refreshFloorMap = () => {
                console.log('GridSync: Direct refresh triggered');
                this.fetchAndUpdate();
            };

            window.addEventListener('focus', () => {
                const lastRefresh = localStorage.getItem('floorMapRefresh');
                if (lastRefresh && (Date.now() - parseInt(lastRefresh, 10)) < 10000) {
                    console.log('GridSync: Refresh on focus due to recent admin action');
                    this.fetchAndUpdate();
                }
            });

            console.log('GridSync: Refresh signals setup complete');
        },

        async fetchAndUpdate() {
            console.log('GridSync: Fetching latest grid status...');

            try {
                if (!this.apiUrl) {
                    this.resolveApiUrl();
                }

                const response = await fetch(this.apiUrl);
                if (!response.ok) {
                    throw new Error(`API request failed with status ${response.status}`);
                }

                const data = await response.json();

                if (data.success && data.data && data.data.grid_cells) {
                    this.latestGridData = data.data.grid_cells;
                    this.latestSummary = data.data.summary || null;
                    console.log(`GridSync: Received ${Object.keys(this.latestGridData).length} rectangles with allocations.`);
                    this.render();
                } else {
                    console.error('GridSync: API response was not successful or grid data is missing.', data);
                }
            } catch (error) {
                console.error('GridSync: Error fetching or parsing data:', error);
            }
        },

        render() {
            this.updateGrid(this.latestGridData);
            this.updateStatsCard(this.latestSummary, this.latestGridData);
        },

        getQuarterCells() {
            return Array.from(document.querySelectorAll('.grid-tile-quarter, .quarter-tile'));
        },

        resetCellStyles() {
            this.getQuarterCells().forEach(cell => {
                cell.style.transition = 'background-color 0.5s ease, opacity 0.3s ease';
                cell.style.backgroundColor = '';
                cell.style.opacity = '';
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

        colorForStatus(status) {
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
            console.time('GridSync: Update Duration');
            this.resetCellStyles();

            const quarterCells = this.getQuarterCells();
            const allocatedIds = new Set();
            let highlightedCount = 0;

            for (const rectangleId in allocatedData) {
                const cells = allocatedData[rectangleId];
                if (!Array.isArray(cells)) {
                    continue;
                }

                cells.forEach(cellData => {
                    const cellElement = document.getElementById(cellData.cell_id);
                    if (!cellElement || !cellElement.matches('.grid-tile-quarter, .quarter-tile')) {
                        return;
                    }

                    allocatedIds.add(cellData.cell_id);

                    if (this.shouldHighlightStatus(cellData.status)) {
                        cellElement.style.backgroundColor = this.colorForStatus(cellData.status);
                        cellElement.style.opacity = '0.95';
                        cellElement.classList.add(`cell-${cellData.status}`);
                        highlightedCount++;
                    }
                });
            }

            if (this.currentFilter === 'available') {
                quarterCells.forEach(cell => {
                    if (!allocatedIds.has(cell.id)) {
                        cell.style.backgroundColor = this.colors.available;
                        cell.style.opacity = '0.85';
                        cell.classList.add('cell-available');
                        highlightedCount++;
                    }
                });
            }

            console.log(`GridSync: Applied "${this.currentFilter}" filter to ${highlightedCount} cells`);
            console.timeEnd('GridSync: Update Duration');
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

            const totalCoveredArea = pledgedArea + paidArea;
            const availableArea = Math.max(totalArea - totalCoveredArea - blockedArea, 0);

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
                    return { coveredArea: totalCoveredArea, totalArea };
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
            const filterLabel = this.filterMeta[this.currentFilter]?.label || 'Coverage';

            if (coveredAreaElement) {
                coveredAreaElement.textContent = coveredArea.toFixed(2);
            }

            if (totalAreaElement) {
                totalAreaElement.textContent = totalArea.toFixed(2);
            }

            if (coverageLabelElement) {
                coverageLabelElement.textContent = filterLabel;
            }

            if (progressFillElement) {
                progressFillElement.style.width = `${percentage}%`;
                progressFillElement.style.backgroundColor = this.currentFilter === 'paid'
                    ? this.colors.paid
                    : this.currentFilter === 'pledged'
                        ? this.colors.pledged
                        : this.currentFilter === 'blocked'
                            ? this.colors.blocked
                            : this.currentFilter === 'available'
                                ? this.colors.available
                                : '#e2ca18';
            }

            if (percentageElement) {
                percentageElement.textContent = `${percentage.toFixed(1)}%`;
            }

            console.log(`GridSync: Stats updated - ${coveredArea.toFixed(2)}m² of ${totalArea.toFixed(2)}m² (${this.currentFilter})`);
        }
    };

    GridSync.init();
});
