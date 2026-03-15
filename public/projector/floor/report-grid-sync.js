/**
 * Report Grid Synchronization Script
 *
 * Used when the floor map is loaded in report mode (?filter=all|pledged|paid).
 * Shows distinct colors for pledged (orange) and paid (green) cells.
 * Listens for filter changes from the parent report page via postMessage.
 */
document.addEventListener('DOMContentLoaded', () => {

    const ReportSync = {
        apiUrl: null,
        pollInterval: 5000,
        pledgedColor: '#f97316',
        paidColor: '#22c55e',
        blockedColor: '#e2ca18',

        gridReady: false,
        activeFilter: window.__gridFilter || 'all',
        lastData: null,

        init() {
            this.resolveApiUrl();
            this.listenForFilterChanges();
            this.waitForGridCreation().then(() => {
                this.gridReady = true;
                this.fetchAndUpdate();
                setInterval(() => this.fetchAndUpdate(), this.pollInterval);
            });
        },

        resolveApiUrl() {
            try {
                const marker = '/public/projector/floor/';
                const path = window.location.pathname;
                const idx = path.indexOf(marker);
                const base = idx !== -1 ? path.substring(0, idx + 1) : '/';
                this.apiUrl = base + 'api/grid_status.php';
            } catch (e) {
                this.apiUrl = '/api/grid_status.php';
            }
        },

        listenForFilterChanges() {
            window.addEventListener('message', (e) => {
                if (e.data && e.data.type === 'gridFilter' && e.data.filter) {
                    const newFilter = e.data.filter;
                    if (newFilter !== this.activeFilter) {
                        this.activeFilter = newFilter;
                        if (this.lastData) {
                            this.updateGrid(this.lastData);
                        }
                    }
                }
            });
        },

        waitForGridCreation() {
            return new Promise(resolve => {
                const check = setInterval(() => {
                    if (document.querySelector('[id^="G0505-"]')) {
                        clearInterval(check);
                        resolve();
                    }
                }, 100);
            });
        },

        async fetchAndUpdate() {
            try {
                const response = await fetch(this.apiUrl);
                if (!response.ok) return;
                const data = await response.json();
                if (data.success && data.data && data.data.grid_cells) {
                    this.lastData = data.data.grid_cells;
                    this.updateGrid(this.lastData);
                }
            } catch (e) {
                console.error('ReportSync error:', e);
            }
        },

        updateGrid(allocatedData) {
            // Reset ALL cells back to default
            const allCells = document.querySelectorAll('.grid-tile-quarter, .quarter-tile');
            allCells.forEach(cell => {
                if (!cell.dataset.defaultColor) {
                    cell.dataset.defaultColor = cell.dataset.originalColor || '';
                }
                const def = cell.dataset.defaultColor;
                cell.style.backgroundColor = def;
                cell.dataset.originalColor = def;
                cell.style.transition = '';
                cell.classList.remove('cell-allocated', 'cell-pledged', 'cell-paid', 'cell-blocked');
            });

            const filter = this.activeFilter;
            let pledgedCount = 0, paidCount = 0;

            for (const rectId in allocatedData) {
                const cells = allocatedData[rectId];
                if (!Array.isArray(cells)) continue;

                cells.forEach(cellData => {
                    const el = document.getElementById(cellData.cell_id);
                    if (!el) return;

                    const status = cellData.status;

                    // Filter logic
                    if (filter === 'pledged' && status !== 'pledged') return;
                    if (filter === 'paid' && status !== 'paid') return;

                    let color;
                    if (status === 'pledged') {
                        color = this.pledgedColor;
                        pledgedCount++;
                    } else if (status === 'paid') {
                        color = this.paidColor;
                        paidCount++;
                    } else if (status === 'blocked') {
                        color = this.blockedColor;
                    } else {
                        return;
                    }

                    el.style.transition = 'background-color 0.4s ease';
                    el.style.backgroundColor = color;
                    el.dataset.originalColor = color;
                    el.classList.add('cell-allocated');
                    el.classList.add(status === 'paid' ? 'cell-paid' : status === 'pledged' ? 'cell-pledged' : 'cell-blocked');
                });
            }

            // Notify parent page with counts
            if (window.parent !== window) {
                window.parent.postMessage({
                    type: 'gridCounts',
                    pledged: pledgedCount,
                    paid: paidCount
                }, '*');
            }
        }
    };

    ReportSync.init();
});
