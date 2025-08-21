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
        
        // DOM Elements
        elements: {
            allocatedArea: document.getElementById('allocated-area'),
            progressBar: document.getElementById('progress-bar-fill'),
            paidCells: document.getElementById('paid-cells'),
            pledgedCells: document.getElementById('pledged-cells'),
            totalAllocatedCells: document.getElementById('total-allocated-cells'),
            availableCells: document.getElementById('available-cells')
        },
        
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
            this.fetchAndUpdate(); // Initial fetch
            setInterval(() => this.fetchAndUpdate(), this.pollInterval);
        },
        
        /**
         * Fetches data from the API and updates the grid visuals.
         */
        async fetchAndUpdate() {
            console.log('GridSync: Fetching latest grid status...');
            try {
                // Fetch both detailed and summary data in parallel for efficiency
                const [detailResponse, summaryResponse] = await Promise.all([
                    fetch(this.apiUrl),
                    fetch(`${this.apiUrl}?format=summary`)
                ]);

                if (!detailResponse.ok || !summaryResponse.ok) {
                    throw new Error('API request failed');
                }

                const detailData = await detailResponse.json();
                const summaryData = await summaryResponse.json();

                if (detailData.success && detailData.data.grid_cells) {
                    this.updateGrid(detailData.data.grid_cells);
                } else {
                    console.error('GridSync: Detailed data response was not successful.', detailData);
                }
                
                if (summaryData.success && summaryData.data.statistics) {
                    this.updateInfoPanel(summaryData.data.statistics);
                } else {
                    console.error('GridSync: Summary data response was not successful.', summaryData);
                }

            } catch (error) {
                console.error('GridSync: Error fetching or parsing data:', error);
            }
        },
        
        /**
         * Updates the info panel with the latest summary statistics.
         * @param {object} stats - The statistics object from the API.
         */
        updateInfoPanel(stats) {
            const totalAllocated = stats.pledged_cells + stats.paid_cells;
            
            this.elements.allocatedArea.textContent = stats.allocated_area_sqm.toFixed(2);
            this.elements.paidCells.textContent = stats.paid_cells;
            this.elements.pledgedCells.textContent = stats.pledged_cells;
            this.elements.totalAllocatedCells.textContent = totalAllocated;
            this.elements.availableCells.textContent = stats.available_cells;
            
            // Update progress bar
            const percentage = stats.progress_percentage || 0;
            this.elements.progressBar.style.width = `${percentage}%`;
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
        }
    };
    
    // Start the process
    GridSync.init();
    
});
