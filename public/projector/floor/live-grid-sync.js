document.addEventListener('DOMContentLoaded', () => {
    const GridSync = {
        apiUrl: null,
        pollInterval: 2000,
        currentFilter: 'total',
        latestGridData: {},
        latestSummary: null,
        lastRefreshCheck: 0,
        rectangleOrder: ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
        rectangleTotals: {
            A: 108,
            B: 9,
            C: 16,
            D: 120,
            E: 120,
            F: 20,
            G: 120
        },
        filterMeta: {
            total: {
                label: 'Total Coverage',
                hero: 'Floor Coverage',
                color: '#e2ca18',
                amountLabel: 'covered value'
            },
            pledged: {
                label: 'Pledged View',
                hero: 'Pledged Report',
                color: '#f59e0b',
                amountLabel: 'pledged value'
            },
            paid: {
                label: 'Paid View',
                hero: 'Paid Report',
                color: '#22c55e',
                amountLabel: 'paid value'
            },
            blocked: {
                label: 'Blocked View',
                hero: 'Blocked Report',
                color: '#a855f7',
                amountLabel: 'blocked value'
            },
            available: {
                label: 'Available View',
                hero: 'Open Area Report',
                color: '#38bdf8',
                amountLabel: 'open value'
            }
        },

        init() {
            console.log('GridSync: Initializing block report mode...');
            this.resolveApiUrl();
            this.setupFilterControls();

            this.waitForGridCreation().then(() => {
                this.ensureBlockReports();
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
                console.error('GridSync: Error fetching block report data:', error);
            }
        },

        ensureBlockReports() {
            this.rectangleOrder.forEach(rectangleId => {
                const shape = document.querySelector(`.shape.${rectangleId}`);
                if (!shape || shape.querySelector('.shape-report')) {
                    return;
                }

                const report = document.createElement('div');
                report.className = 'shape-report';
                report.innerHTML = `
                    <div class="shape-report-head">
                        <div class="shape-report-tag">
                            <span class="shape-report-block">Block ${rectangleId}</span>
                        </div>
                        <div class="shape-report-share" data-role="share">0%</div>
                    </div>
                    <div class="shape-report-body">
                        <div class="shape-report-value" data-role="value">0.00m²</div>
                        <div class="shape-report-meta" data-role="meta">Waiting for live report...</div>
                        <div class="shape-report-progress">
                            <div class="shape-report-progress-fill"></div>
                        </div>
                    </div>
                `;
                shape.appendChild(report);
            });
        },

        getAllGridTiles() {
            return Array.from(document.querySelectorAll('.grid-tile-quarter, .quarter-tile'));
        },

        softenGridTexture() {
            this.getAllGridTiles().forEach(tile => {
                tile.style.backgroundColor = '';
                tile.style.opacity = '0.32';
            });
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

        calculateRectangleMetrics() {
            const metrics = {};

            this.rectangleOrder.forEach(rectangleId => {
                const totalArea = this.rectangleTotals[rectangleId];
                metrics[rectangleId] = {
                    totalArea,
                    pledgedArea: 0,
                    paidArea: 0,
                    blockedArea: 0,
                    availableArea: totalArea,
                    selectedArea: 0,
                    progressPct: 0,
                    value: 0
                };
            });

            Object.entries(this.latestGridData || {}).forEach(([rectangleId, cells]) => {
                if (!metrics[rectangleId] || !Array.isArray(cells)) {
                    return;
                }

                cells.forEach(cellData => {
                    const areaSize = this.getAreaSize(cellData);
                    if (cellData.status === 'pledged') {
                        metrics[rectangleId].pledgedArea += areaSize;
                    } else if (cellData.status === 'paid') {
                        metrics[rectangleId].paidArea += areaSize;
                    } else if (cellData.status === 'blocked') {
                        metrics[rectangleId].blockedArea += areaSize;
                    }
                });
            });

            this.rectangleOrder.forEach(rectangleId => {
                const block = metrics[rectangleId];
                const coveredArea = block.pledgedArea + block.paidArea;
                block.availableArea = Math.max(block.totalArea - coveredArea - block.blockedArea, 0);

                if (this.currentFilter === 'pledged') {
                    block.selectedArea = block.pledgedArea;
                } else if (this.currentFilter === 'paid') {
                    block.selectedArea = block.paidArea;
                } else if (this.currentFilter === 'blocked') {
                    block.selectedArea = block.blockedArea;
                } else if (this.currentFilter === 'available') {
                    block.selectedArea = block.availableArea;
                } else {
                    block.selectedArea = coveredArea;
                }

                block.progressPct = block.totalArea > 0
                    ? (block.selectedArea / block.totalArea) * 100
                    : 0;
                block.value = block.selectedArea * 400;
            });

            return metrics;
        },

        getOverallMetrics(rectangleMetrics) {
            return this.rectangleOrder.reduce((totals, rectangleId) => {
                const block = rectangleMetrics[rectangleId];
                totals.totalArea += block.totalArea;
                totals.selectedArea += block.selectedArea;
                totals.value += block.value;
                return totals;
            }, { totalArea: 0, selectedArea: 0, value: 0 });
        },

        formatCurrency(value) {
            return new Intl.NumberFormat('en-GB', {
                style: 'currency',
                currency: 'GBP',
                maximumFractionDigits: 0
            }).format(value);
        },

        formatCompactArea(value) {
            return `${value.toFixed(2)}m²`;
        },

        renderBlocks(rectangleMetrics) {
            const filterColor = this.filterMeta[this.currentFilter].color;

            this.rectangleOrder.forEach(rectangleId => {
                const shape = document.querySelector(`.shape.${rectangleId}`);
                const report = shape?.querySelector('.shape-report');
                const block = rectangleMetrics[rectangleId];

                if (!shape || !report || !block) {
                    return;
                }

                const intensity = Math.max(0, Math.min(block.progressPct / 100, 1));
                const opacity = block.selectedArea > 0 ? (0.14 + intensity * 0.62) : 0.08;

                shape.style.setProperty('--shape-fill', filterColor);
                shape.style.setProperty('--shape-fill-opacity', opacity.toFixed(2));
                shape.style.setProperty('--shape-progress', `${Math.max(block.progressPct, block.selectedArea > 0 ? 6 : 0).toFixed(1)}%`);
                shape.classList.toggle('has-coverage', block.selectedArea > 0.01);
                shape.classList.toggle('is-minimal', block.selectedArea <= 0.01);
                shape.style.transform = `translateY(${(1 - intensity) * 4}px)`;

                report.querySelector('[data-role="share"]').textContent = `${block.progressPct.toFixed(1)}%`;
                report.querySelector('[data-role="value"]').textContent = this.formatCompactArea(block.selectedArea);
                report.querySelector('[data-role="meta"]').textContent =
                    `${this.formatCurrency(block.value)} · ${this.formatCompactArea(block.totalArea)} block size`;
            });
        },

        updateHero(overallMetrics) {
            const heroValue = document.getElementById('reportHeroValue');
            const heroMeta = document.getElementById('reportHeroMeta');
            const filter = this.filterMeta[this.currentFilter];
            const percentage = overallMetrics.totalArea > 0
                ? (overallMetrics.selectedArea / overallMetrics.totalArea) * 100
                : 0;

            if (heroValue) {
                heroValue.textContent = filter.hero;
            }

            if (heroMeta) {
                heroMeta.textContent = `${this.formatCompactArea(overallMetrics.selectedArea)} · ${this.formatCurrency(overallMetrics.value)} · ${percentage.toFixed(1)}% of floor`;
            }
        },

        updateStatsCard(overallMetrics) {
            const coveredAreaElement = document.getElementById('covered-area');
            const totalAreaElement = document.getElementById('total-area');
            const progressFillElement = document.getElementById('progress-fill');
            const percentageElement = document.getElementById('coverage-percentage');
            const coverageLabelElement = document.getElementById('coverage-label');
            const filter = this.filterMeta[this.currentFilter];
            const percentage = overallMetrics.totalArea > 0
                ? (overallMetrics.selectedArea / overallMetrics.totalArea) * 100
                : 0;

            if (coveredAreaElement) {
                coveredAreaElement.textContent = overallMetrics.selectedArea.toFixed(2);
            }

            if (totalAreaElement) {
                totalAreaElement.textContent = overallMetrics.totalArea.toFixed(2);
            }

            if (coverageLabelElement) {
                coverageLabelElement.textContent = filter.label;
            }

            if (progressFillElement) {
                progressFillElement.style.width = `${percentage}%`;
                progressFillElement.style.backgroundColor = filter.color;
            }

            if (percentageElement) {
                percentageElement.textContent = `${percentage.toFixed(1)}%`;
            }
        },

        render() {
            this.ensureBlockReports();
            this.softenGridTexture();

            const rectangleMetrics = this.calculateRectangleMetrics();
            const overallMetrics = this.getOverallMetrics(rectangleMetrics);

            this.renderBlocks(rectangleMetrics);
            this.updateHero(overallMetrics);
            this.updateStatsCard(overallMetrics);
        }
    };

    GridSync.init();
});
