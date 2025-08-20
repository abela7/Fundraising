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
        pledgedColor: '#ff8c00', // Orange for pledged
        paidColor: '#22c55e',   // Green for paid
        
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
                const response = await fetch(this.apiUrl);
                if (!response.ok) {
                    throw new Error(`API request failed with status ${response.status}`);
                }
                const data = await response.json();
                
                if (data.success && data.data) {
                    console.log(`GridSync: Received ${Object.keys(data.data).length} rectangles with allocations.`);
                    this.updateGrid(data.data);
                } else {
                    console.error('GridSync: API response was not successful.', data);
                }
            } catch (error) {
                console.error('GridSync: Error fetching or parsing data:', error);
            }
        },
        
        /**
         * Updates the DOM based on the fetched grid data.
         * @param {object} allocatedData - The data object from the API.
         */
        updateGrid(allocatedData) {
            console.time('GridSync: Update Duration');
            
            // First, reset all cells that might have a status
            // This is more efficient than clearing all 3,591 cells every time.
            const styledCells = document.querySelectorAll('.cell-pledged, .cell-paid');
            styledCells.forEach(cell => {
                cell.style.backgroundColor = '';
                cell.classList.remove('cell-pledged', 'cell-paid');
            });
            
            // Apply new statuses
            for (const rectangleId in allocatedData) {
                const cells = allocatedData[rectangleId];
                cells.forEach(cellData => {
                    const cellElement = document.getElementById(cellData.cell_id);
                    if (cellElement) {
                        // Add a smooth transition effect
                        cellElement.style.transition = 'background-color 0.5s ease';
                        
                        if (cellData.status === 'pledged') {
                            cellElement.style.backgroundColor = this.pledgedColor;
                            cellElement.classList.add('cell-pledged');
                        } else if (cellData.status === 'paid') {
                            cellElement.style.backgroundColor = this.paidColor;
                            cellElement.classList.add('cell-paid');
                        }
                    } else {
                        console.warn(`GridSync: Cell ID "${cellData.cell_id}" found in data but not in DOM.`);
                    }
                });
            }
            console.timeEnd('GridSync: Update Duration');
        }
    };
    
    // Start the process
    GridSync.init();
    
});
