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
        apiUrl: '/api/grid_status.php',
        pollInterval: 2000, // Fetch updates every 2 seconds for faster response
        allocationColor: '#e2ca18', // Unified color for both pledged and paid
        
        // State
        gridReady: false,
        
        /**
         * Initializes the synchronization process.
         */
        init() {
            console.log('GridSync: Initializing...');
            this.waitForGridCreation().then(() => {
                console.log('GridSync: Grid is ready. Starting synchronization.');
                this.gridReady = true;
                this.startPolling();
                this.setupRefreshSignals();
            });
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
                const response = await fetch(this.apiUrl);
                if (!response.ok) {
                    throw new Error(`API request failed with status ${response.status}`);
                }
                const data = await response.json();
                
                if (data.success && data.data && data.data.grid_cells) {
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
            
            // First, reset ALL previously allocated cells to their original state
            const allAllocatedCells = document.querySelectorAll('.cell-allocated, .cell-pledged, .cell-paid, .cell-blocked');
            allAllocatedCells.forEach(cell => {
                cell.style.backgroundColor = '';
                cell.style.transition = '';
                cell.classList.remove('cell-allocated', 'cell-pledged', 'cell-paid', 'cell-blocked');
            });
            
            // Also reset any cells that might have inline background colors from previous allocations
            const allCells = document.querySelectorAll('.grid-tile-quarter');
            allCells.forEach(cell => {
                if (cell.style.backgroundColor && cell.style.backgroundColor !== '') {
                    // Only reset if it's not a natural hover/default state
                    if (cell.style.backgroundColor === this.allocationColor || 
                        cell.style.backgroundColor === 'rgb(226, 202, 24)') {
                        cell.style.backgroundColor = '';
                        cell.style.transition = '';
                        cell.classList.remove('cell-allocated', 'cell-pledged', 'cell-paid', 'cell-blocked');
                    }
                }
            });
            
            console.log(`GridSync: Reset all previously allocated cells`);
            
            // Apply new statuses
            let allocatedCount = 0;
            for (const rectangleId in allocatedData) {
                const cells = allocatedData[rectangleId];
                if (Array.isArray(cells)) {
                    cells.forEach(cellData => {
                        const cellElement = document.getElementById(cellData.cell_id);
                        if (cellElement) {
                            // Add a smooth transition effect
                            cellElement.style.transition = 'background-color 0.5s ease';
                            
                            if (cellData.status === 'pledged' || cellData.status === 'paid') {
                                cellElement.style.backgroundColor = this.allocationColor;
                                cellElement.classList.add('cell-allocated');
                                allocatedCount++;
                            } else if (cellData.status === 'blocked') {
                                // Handle blocked cells (same color but different class for debugging)
                                cellElement.style.backgroundColor = this.allocationColor;
                                cellElement.classList.add('cell-blocked');
                                allocatedCount++;
                            }
                        } else {
                            console.warn(`GridSync: Cell ID "${cellData.cell_id}" found in data but not in DOM.`);
                        }
                    });
                }
            }
            
            console.log(`GridSync: Applied allocation styling to ${allocatedCount} cells`);
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

            if (coveredAreaElement && summary.allocated_area_sqm !== undefined) {
                const coveredArea = parseFloat(summary.allocated_area_sqm).toFixed(2);
                coveredAreaElement.textContent = coveredArea;
            }

            if (totalAreaElement && summary.total_area_sqm !== undefined) {
                const totalArea = parseFloat(summary.total_area_sqm).toFixed(2);
                totalAreaElement.textContent = totalArea;
            }

            if (progressFillElement && percentageElement && summary.allocated_area_sqm !== undefined && summary.total_area_sqm !== undefined) {
                const coveredArea = parseFloat(summary.allocated_area_sqm);
                const totalArea = parseFloat(summary.total_area_sqm);
                const percentage = totalArea > 0 ? (coveredArea / totalArea) * 100 : 0;
                
                progressFillElement.style.width = `${percentage}%`;
                percentageElement.textContent = `${percentage.toFixed(1)}%`;
            }

            console.log(`GridSync: Stats updated - ${summary.allocated_area_sqm}m² of ${summary.total_area_sqm}m² covered`);
        }
    };
    
    // Start the process
    GridSync.init();
    
});
