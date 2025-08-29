/**
 * Advanced Grid Synchronization
 * 
 * This script provides a more sophisticated mapping between your database cell IDs
 * and the actual DOM elements in your floor plan.
 */

class AdvancedGridSync {
    constructor() {
        this.apiUrl = '../../../api/grid_status.php'; // Correct path: go up 3 levels from /public/projector/floor/
        this.cellIdToElementMap = new Map();
        this.elementToCellIdMap = new Map();
        this.statusUpdateQueue = [];
        this.isReady = false;
        
        this.init();
    }
    
    async init() {
        console.log('🔧 Advanced Grid Sync Initializing...');
        
        // Wait for both DOM and grid creation to complete
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.waitForGridCreation());
        } else {
            this.waitForGridCreation();
        }
    }
    
    async waitForGridCreation() {
        // Wait a bit for the grid creation functions to complete
        setTimeout(() => {
            this.buildCellMapping();
            this.startLiveSync();
        }, 1000);
    }
    
    buildCellMapping() {
        console.log('🗺️ Building cell ID mapping...');
        
        // Find all grid cells in the DOM
        const allGridCells = document.querySelectorAll('[id*="0101-"], [id*="0105-"], [id*="0505-"]');
        
        console.log(`Found ${allGridCells.length} grid cells in DOM`);
        
        allGridCells.forEach(element => {
            const cellId = element.id;
            
            // Map both ways for quick lookup
            this.cellIdToElementMap.set(cellId, element);
            this.elementToCellIdMap.set(element, cellId);
            
            // Add default styling and event handlers
            this.setupCellElement(element, cellId);
        });
        
        console.log(`✅ Mapped ${this.cellIdToElementMap.size} cells`);
        this.isReady = true;
    }
    
    setupCellElement(element, cellId) {
        // Add transition for smooth color changes
        element.style.transition = 'background-color 0.3s ease';
        
        // Add click handler for debugging
        element.addEventListener('click', (e) => {
            e.stopPropagation();
            console.log(`🎯 Clicked cell: ${cellId}`);
            this.showCellInfo(cellId);
        });
        
        // Set default title
        element.title = `Cell ${cellId}`;
    }
    
    async startLiveSync() {
        if (!this.isReady) {
            console.warn('⚠️ Grid not ready, delaying sync...');
            setTimeout(() => this.startLiveSync(), 500);
            return;
        }
        
        console.log('🚀 Starting live synchronization...');
        
        // Initial sync
        await this.syncWithDatabase();
        
        // Set up auto-refresh every 3 seconds
        setInterval(() => {
            this.syncWithDatabase();
        }, 3000);
        
        console.log('✅ Live sync active!');
    }
    
    async syncWithDatabase() {
        try {
            console.log('🔄 Syncing with database...');
            
            const response = await fetch(this.apiUrl);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'API error');
            }
            
            // Process the grid data
            this.processGridData(data.data);
            
        } catch (error) {
            console.error('❌ Sync failed:', error);
            this.showSyncError(error.message);
        }
    }
    
    processGridData(apiData) {
        // Reset all cells to default first
        this.resetAllCells();
        
        const { grid_cells, statistics } = apiData;
        let processedCells = 0;
        
        // Process each rectangle's allocated cells
        Object.entries(grid_cells).forEach(([rectangleId, cells]) => {
            cells.forEach(cellData => {
                // Try to find the cell by actual cell ID if available in API data
                const allocated = this.allocateSpecificCell(cellData) || 
                                this.allocateFirstAvailableCell(rectangleId, cellData);
                if (allocated) {
                    processedCells++;
                }
            });
        });
        
        // Update statistics
        this.updateStatisticsDisplay(statistics);
        
        console.log(`✅ Processed ${processedCells} allocated cells`);
    }
    
    allocateSpecificCell(cellData) {
        // If the API provides the actual cell ID, use it directly
        if (cellData.cell_id && this.cellIdToElementMap.has(cellData.cell_id)) {
            this.setCellStatus(cellData.cell_id, cellData.status, cellData);
            return true;
        }
        return false;
    }
    
    allocateFirstAvailableCell(rectangleId, cellData) {
        // Find the first available cell in this rectangle
        const availableCells = Array.from(this.cellIdToElementMap.entries())
            .filter(([cellId, element]) => {
                return cellId.startsWith(rectangleId) && 
                       element.classList.contains('status-available');
            })
            .sort(([a], [b]) => a.localeCompare(b)); // Sort by ID for consistent allocation
        
        if (availableCells.length > 0) {
            const [cellId, element] = availableCells[0];
            this.setCellStatus(cellId, cellData.status, cellData);
            return true;
        }
        
        return false;
    }
    
    setCellStatus(cellId, status, cellData = {}) {
        const element = this.cellIdToElementMap.get(cellId);
        if (!element) {
            console.warn(`⚠️ Cell not found: ${cellId}`);
            return;
        }
        
        // Apply status directly to the DOM element's background
        switch (status) {
            case 'paid':
                element.style.backgroundColor = '#22c55e !important'; // Green
                break;
                
            case 'pledged':
                element.style.backgroundColor = '#ff8c00 !important'; // Orange
                break;
                
            default:
                // Reset to original - no background color
                element.style.backgroundColor = '';
                break;
        }
        
        // Update title with info
        const donor = cellData.donor || 'Unknown';
        const amount = cellData.amount || 0;
        element.title = status === 'available' 
            ? `Cell ${cellId}`
            : `Cell ${cellId} - ${status.toUpperCase()} by ${donor} (£${amount})`;
    }
    
    resetAllCells() {
        this.cellIdToElementMap.forEach((element, cellId) => {
            // Reset to original state - no background styling
            element.style.backgroundColor = '';
            element.title = `Cell ${cellId}`;
        });
    }
    
    updateStatisticsDisplay(stats) {
        // Create or update a stats overlay
        let statsOverlay = document.getElementById('live-stats-overlay');
        
        if (!statsOverlay) {
            statsOverlay = document.createElement('div');
            statsOverlay.id = 'live-stats-overlay';
            statsOverlay.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 15px;
                border-radius: 8px;
                font-family: monospace;
                font-size: 14px;
                z-index: 1000;
                min-width: 250px;
            `;
            document.body.appendChild(statsOverlay);
        }
        
        statsOverlay.innerHTML = `
            <div style="font-weight: bold; margin-bottom: 10px;">📊 Live Statistics</div>
            <div>Total Cells: ${stats.total_cells.toLocaleString()}</div>
            <div style="color: #22c55e;">Paid: ${stats.paid_cells} (${stats.total_area_paid}m²)</div>
            <div style="color: #ff8c00;">Pledged: ${stats.pledged_cells} (${stats.total_area_pledged}m²)</div>
            <div>Available: ${stats.available_cells.toLocaleString()}</div>
            <div style="margin-top: 5px; font-weight: bold;">
                Progress: ${stats.progress_percentage.toFixed(2)}%
            </div>
            <div>Amount: £${stats.total_amount.toLocaleString()}</div>
            <div style="margin-top: 10px; font-size: 12px; opacity: 0.7;">
                Last updated: ${new Date().toLocaleTimeString()}
            </div>
        `;
    }
    
    showCellInfo(cellId) {
        const element = this.cellIdToElementMap.get(cellId);
        if (!element) return;
        
        // Create info popup
        const info = document.createElement('div');
        info.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            color: black;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #ccc;
            z-index: 10000;
            font-family: Arial, sans-serif;
        `;
        
        const status = element.classList.contains('status-paid') ? 'PAID' :
                      element.classList.contains('status-pledged') ? 'PLEDGED' : 'AVAILABLE';
        
        info.innerHTML = `
            <h3>Cell Information</h3>
            <p><strong>Cell ID:</strong> ${cellId}</p>
            <p><strong>Status:</strong> ${status}</p>
            <p><strong>Rectangle:</strong> ${cellId.charAt(0)}</p>
            <p><strong>Type:</strong> ${cellId.includes('0101') ? '1x1m' : cellId.includes('0105') ? '1x0.5m' : '0.5x0.5m'}</p>
            <button onclick="this.parentElement.remove()">Close</button>
        `;
        
        document.body.appendChild(info);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (info.parentElement) {
                info.remove();
            }
        }, 5000);
    }
    
    showSyncError(message) {
        console.error('🔴 Sync Error:', message);
        
        // Show error overlay
        let errorOverlay = document.getElementById('sync-error-overlay');
        
        if (!errorOverlay) {
            errorOverlay = document.createElement('div');
            errorOverlay.id = 'sync-error-overlay';
            errorOverlay.style.cssText = `
                position: fixed;
                top: 20px;
                left: 20px;
                background: rgba(220, 38, 38, 0.9);
                color: white;
                padding: 15px;
                border-radius: 8px;
                font-family: Arial, sans-serif;
                z-index: 1001;
                max-width: 300px;
            `;
            document.body.appendChild(errorOverlay);
        }
        
        errorOverlay.innerHTML = `
            <div style="font-weight: bold;">⚠️ Sync Error</div>
            <div style="margin-top: 5px; font-size: 14px;">${message}</div>
        `;
        
        // Auto-hide after 10 seconds
        setTimeout(() => {
            if (errorOverlay.parentElement) {
                errorOverlay.remove();
            }
        }, 10000);
    }
}

// Initialize when ready
window.advancedGridSync = new AdvancedGridSync();

// Expose methods for debugging
window.debugGrid = () => {
    console.log('🐛 Grid Debug Info:');
    console.log('Mapped cells:', window.advancedGridSync.cellIdToElementMap.size);
    console.log('Ready:', window.advancedGridSync.isReady);
    console.log('Cell mapping:', window.advancedGridSync.cellIdToElementMap);
};

console.log('🔧 Advanced Grid Sync Script Loaded');
