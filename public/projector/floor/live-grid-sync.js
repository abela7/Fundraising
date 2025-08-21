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
        pollInterval: 5000, // Fetch updates every 5 seconds
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
            this.fetchCellData(); // Initial fetch for the map
            this.fetchSummaryData(); // Initial fetch for the info panel
            setInterval(() => {
                this.fetchCellData();
                this.fetchSummaryData();
            }, this.pollInterval);
        },
        
        /**
         * Fetches the detailed cell allocation data for coloring the map.
         */
        async fetchCellData() {
            try {
                const response = await fetch(this.apiUrl);
                if (!response.ok) throw new Error(`API error ${response.status}`);
                const data = await response.json();
                if (data.success && data.data && data.data.grid_cells) {
                    this.updateGrid(data.data.grid_cells);
                } else {
                    console.error('GridSync: Failed to get cell data.', data);
                }
            } catch (error) {
                console.error('GridSync: Error fetching cell data:', error);
            }
        },

        /**
         * Fetches the summary statistics to populate the info panel.
         */
        async fetchSummaryData() {
            try {
                const response = await fetch(`${this.apiUrl}?format=summary`);
                if (!response.ok) throw new Error(`API error ${response.status}`);
                const data = await response.json();
                if (data.success && data.data && data.data.statistics) {
                    this.updateInfoPanel(data.data.statistics);
                } else {
                    console.error('GridSync: Failed to get summary data.', data);
                }
            } catch (error) {
                console.error('GridSync: Error fetching summary data:', error);
            }
        },

        /**
         * Updates the DOM based on the fetched grid data.
         * @param {object} allocatedData - The grid_cells object from the API.
         */
        updateGrid(allocatedData) {
            console.time('GridSync: Update Duration');
            
            // First, reset all cells that might have a status
            const styledCells = document.querySelectorAll('.cell-pledged, .cell-paid');
            styledCells.forEach(cell => {
                cell.style.backgroundColor = '';
                cell.classList.remove('cell-pledged', 'cell-paid');
            });
            
            // Apply new statuses
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
                            }
                        } else {
                            // This warning is expected if a rectangle has no allocated cells yet.
                            // console.warn(`GridSync: Cell ID "${cellData.cell_id}" found in data but not in DOM.`);
                        }
                    });
                }
            }
            console.timeEnd('GridSync: Update Duration');
        },

        /**
         * Updates the info panel with the latest statistics.
         * @param {object} stats - The statistics object from the API.
         */
        updateInfoPanel(stats) {
            document.getElementById('allocated-area').textContent = parseFloat(stats.allocated_area_sqm).toFixed(2);
            document.getElementById('total-area').textContent = parseFloat(stats.total_area_sqm).toFixed(2);
            
            const progressBar = document.getElementById('progress-bar-fill');
            if (progressBar) {
                progressBar.style.width = `${stats.progress_percentage}%`;
            }

            document.getElementById('paid-cells').textContent = stats.paid_cells;
            document.getElementById('pledged-cells').textContent = stats.pledged_cells;
            document.getElementById('total-allocated-cells').textContent = stats.paid_cells + stats.pledged_cells;
            document.getElementById('available-cells').textContent = stats.available_cells;
        }
    };
    
    // Start the process
    GridSync.init();
    
});
