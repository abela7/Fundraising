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
        // Configuration
        apiUrl: null,
        pollInterval: 2000, // Fetch updates every 2 seconds for faster response
        allocationColor: '#e2ca18', // Default unified color (used in "all" mode)
        pledgedColor: '#f97316',    // Orange for pledged
        paidColor: '#22c55e',       // Green for paid

        // State
        gridReady: false,
        activeFilter: 'all', // 'all', 'pledged', or 'paid'
        lastData: null,      // Cache last API response for re-filtering without refetch
        
        /**
         * Initializes the synchronization process.
         */
        init() {
            console.log('GridSync: Initializing...');
            this.resolveApiUrl();
            console.log('GridSync: Using API URL ->', this.apiUrl);
            this.setupFilterButtons();
            this.waitForGridCreation().then(() => {
                console.log('GridSync: Grid is ready. Starting synchronization.');
                this.gridReady = true;
                this.startPolling();
                this.setupRefreshSignals();
            });
        },

        /**
         * Sets up the filter toggle buttons.
         */
        setupFilterButtons() {
            const buttons = document.querySelectorAll('.floor-filter-btn');
            buttons.forEach(btn => {
                btn.addEventListener('click', () => {
                    const filter = btn.getAttribute('data-filter');
                    if (filter === this.activeFilter) return;

                    this.activeFilter = filter;
                    buttons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');

                    console.log('GridSync: Filter changed to', filter);

                    // Re-apply with cached data (no need to refetch)
                    if (this.lastData) {
                        this.updateGrid(this.lastData.grid_cells);
                        this.updateStatsCard(this.lastData.summary);
                    }
                });
            });
        },

        /**
         * Resolves the correct API URL based on current hosting path (works for / and /YourApp/).
         */
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

        /**
         * Waits until the grid cells are created in the DOM by the main script.
         */
        waitForGridCreation() {
            return new Promise(resolve => {
                const checkInterval = setInterval(() => {
                    // Check for the presence of a specific, late-generated cell
                    const lastCell = document.querySelector('[id^="G0505-"]');
                    if (lastCell) {
                        clearInterval(checkInterval);
                        resolve();
                    }
                }, 100);
            });
        },
        
        /**
         * Starts the polling process to fetch live data.
         */
        startPolling() {
            this.fetchAndUpdate(); // Initial fetch
            
            // Regular polling every 2 seconds
            setInterval(() => this.fetchAndUpdate(), this.pollInterval);
            
            // AGGRESSIVE polling to check for localStorage changes every 500ms
            this.lastRefreshCheck = 0;
            setInterval(() => {
                const lastRefresh = localStorage.getItem('floorMapRefresh');
                if (lastRefresh && parseInt(lastRefresh) > this.lastRefreshCheck) {
                    console.log('🚀 GridSync: AGGRESSIVE POLLING detected localStorage change!');
                    this.lastRefreshCheck = parseInt(lastRefresh);
                    this.fetchAndUpdate();
                }
            }, 500);
        },

        /**
         * Sets up immediate refresh signals from admin actions.
         */
        setupRefreshSignals() {
            // Listen for localStorage signals from admin pages
            window.addEventListener('storage', (e) => {
                console.log('GridSync: Storage event detected', e.key, e.newValue);
                if (e.key === 'floorMapRefresh' && e.newValue) {
                    console.log('🚀 GridSync: IMMEDIATE REFRESH TRIGGERED by admin action!');
                    this.fetchAndUpdate();
                }
            });

            // Make refresh function globally available for window.opener calls
            window.refreshFloorMap = () => {
                console.log('GridSync: Direct refresh triggered');
                this.fetchAndUpdate();
            };

            // Check for refresh signals on page focus (in case we missed localStorage event)
            window.addEventListener('focus', () => {
                const lastRefresh = localStorage.getItem('floorMapRefresh');
                if (lastRefresh && (Date.now() - parseInt(lastRefresh)) < 10000) {
                    console.log('GridSync: Refresh on focus due to recent admin action');
                    this.fetchAndUpdate();
                }
            });

            console.log('GridSync: Refresh signals setup complete');
        },
        
        /**
         * Fetches data from the API and updates the grid visuals.
         */
        async fetchAndUpdate() {
            console.log('GridSync: Fetching latest grid status...');
            try {
                if (!this.apiUrl) { this.resolveApiUrl(); }
                const response = await fetch(this.apiUrl);
                if (!response.ok) {
                    throw new Error(`API request failed with status ${response.status}`);
                }
                const data = await response.json();
                
                if (data.success && data.data && data.data.grid_cells) {
                    this.lastData = data.data; // Cache for filter switching
                    const cellData = data.data.grid_cells;
                    console.log(`GridSync: Received ${Object.keys(cellData).length} rectangles with allocations.`);
                    this.updateGrid(cellData);
                    this.updateStatsCard(data.data.summary);
                } else {
                    console.error('GridSync: API response was not successful or "grid_cells" data is missing.', data);
                }
            } catch (error) {
                console.error('GridSync: Error fetching or parsing data:', error);
            }
        },
        
        /**
         * Updates the DOM based on the fetched grid data.
         * @param {object} allocatedData - The grid_cells object from the API.
         */
        updateGrid(allocatedData) {
            console.time('GridSync: Update Duration');

            // Reset ALL previously styled cells
            const allStyledCells = document.querySelectorAll('.cell-allocated, .cell-pledged, .cell-paid, .cell-blocked');
            allStyledCells.forEach(cell => {
                cell.style.backgroundColor = '';
                cell.style.transition = '';
                cell.classList.remove('cell-allocated', 'cell-pledged', 'cell-paid', 'cell-blocked');
            });

            // Also reset any cells with inline background colors from previous allocations
            const knownColors = [
                this.allocationColor, this.pledgedColor, this.paidColor,
                'rgb(226, 202, 24)', 'rgb(249, 115, 22)', 'rgb(34, 197, 94)'
            ];
            const allCells = document.querySelectorAll('.grid-tile-quarter');
            allCells.forEach(cell => {
                if (cell.style.backgroundColor && knownColors.includes(cell.style.backgroundColor)) {
                    cell.style.backgroundColor = '';
                    cell.style.transition = '';
                    cell.classList.remove('cell-allocated', 'cell-pledged', 'cell-paid', 'cell-blocked');
                }
            });

            // Apply new statuses based on active filter
            let allocatedCount = 0;
            const filter = this.activeFilter;

            for (const rectangleId in allocatedData) {
                const cells = allocatedData[rectangleId];
                if (Array.isArray(cells)) {
                    cells.forEach(cellData => {
                        const cellElement = document.getElementById(cellData.cell_id);
                        if (!cellElement) return;

                        const status = cellData.status;

                        // Skip cells that don't match the filter
                        if (filter === 'pledged' && status !== 'pledged') return;
                        if (filter === 'paid' && status !== 'paid') return;

                        cellElement.style.transition = 'background-color 0.5s ease';

                        if (status === 'pledged' || status === 'paid') {
                            // In "all" mode use unified gold; in filtered mode use distinct colors
                            if (filter === 'all') {
                                // Show both but with distinct colors
                                cellElement.style.backgroundColor = status === 'paid' ? this.paidColor : this.pledgedColor;
                            } else {
                                cellElement.style.backgroundColor = status === 'paid' ? this.paidColor : this.pledgedColor;
                            }
                            cellElement.classList.add(status === 'paid' ? 'cell-paid' : 'cell-pledged');
                            cellElement.classList.add('cell-allocated');
                            allocatedCount++;
                        } else if (status === 'blocked') {
                            cellElement.style.backgroundColor = this.allocationColor;
                            cellElement.classList.add('cell-blocked');
                            allocatedCount++;
                        }
                    });
                }
            }

            console.log(`GridSync: Applied ${filter} filter - ${allocatedCount} cells visible`);
            console.timeEnd('GridSync: Update Duration');
        },

        /**
         * Updates the stats card with live coverage data.
         * @param {object} summary - The summary data from the API.
         */
        updateStatsCard(summary) {
            if (!summary) return;

            const coveredAreaElement = document.getElementById('covered-area');
            const totalAreaElement = document.getElementById('total-area');
            const progressFillElement = document.getElementById('progress-fill');
            const percentageElement = document.getElementById('coverage-percentage');
            const coverageLabelElement = document.querySelector('.coverage-label');

            // Calculate area based on active filter
            const totalArea = parseFloat(summary.total_area_sqm || 0);
            const pledgedCells = parseInt(summary.pledged_cells || 0);
            const paidCells = parseInt(summary.paid_cells || 0);
            const cellArea = 0.25; // Each 0.5x0.5 cell = 0.25 m²

            let filteredArea;
            let filterLabel;
            let barColor;

            if (this.activeFilter === 'pledged') {
                filteredArea = pledgedCells * cellArea;
                filterLabel = 'Pledged Only';
                barColor = this.pledgedColor;
            } else if (this.activeFilter === 'paid') {
                filteredArea = paidCells * cellArea;
                filterLabel = 'Paid Only';
                barColor = this.paidColor;
            } else {
                filteredArea = parseFloat(summary.allocated_area_sqm || 0);
                filterLabel = 'Coverage Progress';
                barColor = '#ffd700';
            }

            if (coveredAreaElement) {
                coveredAreaElement.textContent = filteredArea.toFixed(2);
            }

            if (totalAreaElement) {
                totalAreaElement.textContent = totalArea.toFixed(2);
            }

            if (coverageLabelElement) {
                coverageLabelElement.textContent = filterLabel;
            }

            if (progressFillElement && percentageElement) {
                const percentage = totalArea > 0 ? (filteredArea / totalArea) * 100 : 0;
                progressFillElement.style.width = `${percentage}%`;
                progressFillElement.style.background = barColor;
                percentageElement.textContent = `${percentage.toFixed(1)}%`;
            }

            console.log(`GridSync: Stats updated [${this.activeFilter}] - ${filteredArea.toFixed(2)}m² of ${totalArea.toFixed(2)}m²`);
        }
    };
    
    // Start the process
    GridSync.init();
    
});
