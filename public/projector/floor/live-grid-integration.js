/**
 * Live Grid Integration
 * Connects your existing floor grid to the real-time database API
 */

class LiveGridIntegration {
    constructor() {
        this.apiUrl = '/api/grid_status.php';
        this.refreshInterval = 5000; // 5 seconds
        this.intervalId = null;
        this.isInitialized = false;
        
        this.init();
    }
    
    async init() {
        console.log('🎯 Live Grid Integration Starting...');
        
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.start());
        } else {
            this.start();
        }
    }
    
    async start() {
        try {
            // Initial load
            await this.updateGridStatus();
            this.isInitialized = true;
            
            // Start auto-refresh
            this.startAutoRefresh();
            
            console.log('✅ Live Grid Integration Active!');
        } catch (error) {
            console.error('❌ Live Grid Integration Failed:', error);
        }
    }
    
    async updateGridStatus() {
        try {
            console.log('🔄 Fetching grid status...');
            
            const response = await fetch(this.apiUrl);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'API returned unsuccessful response');
            }
            
            // Update grid cells with status
            this.updateCellColors(data.data.grid_cells);
            
            // Update statistics if element exists
            this.updateStatistics(data.data.statistics);
            
            console.log(`✅ Grid updated - ${Object.keys(data.data.grid_cells).length} rectangles processed`);
            
        } catch (error) {
            console.error('❌ Failed to update grid status:', error);
            
            // Show error notification (optional)
            this.showErrorNotification(error.message);
        }
    }
    
    updateCellColors(gridCells) {
        // Reset all cells to default (available) state
        this.resetAllCells();
        
        // Process each rectangle's cells
        Object.entries(gridCells).forEach(([rectangleId, cells]) => {
            cells.forEach(cell => {
                this.updateCellById(cell);
            });
        });
    }
    
    updateCellById(cellData) {
        // Try to find the cell by various possible ID patterns
        const cellId = cellData.x !== undefined && cellData.y !== undefined 
            ? this.findCellByCoordinates(cellData)
            : this.findCellByStatus(cellData);
            
        if (cellId) {
            const element = document.getElementById(cellId);
            if (element) {
                this.applyCellStatus(element, cellData.status, cellData);
            }
        }
    }
    
    findCellByCoordinates(cellData) {
        // This is a fallback - we'll need to map coordinates to your actual cell IDs
        // For now, let's find the first available cell in the rectangle and mark it
        const rectangleId = cellData.rectangle || 'A';
        
        // Try different ID patterns based on your floor plan
        const patterns = [
            `${rectangleId}0101-`, // 1x1 cells
            `${rectangleId}0105-`, // 1x0.5 cells  
            `${rectangleId}0505-`  // 0.5x0.5 cells
        ];
        
        for (const pattern of patterns) {
            // Find any element with this pattern that isn't already colored
            const elements = document.querySelectorAll(`[id^="${pattern}"]`);
            for (const el of elements) {
                if (!el.classList.contains('pledged') && !el.classList.contains('paid')) {
                    return el.id;
                }
            }
        }
        
        return null;
    }
    
    findCellByStatus(cellData) {
        // Alternative approach: find by donor or amount
        // This would require matching database records to DOM elements
        // For now, return null and use coordinate-based approach
        return null;
    }
    
    applyCellStatus(element, status, cellData) {
        // Remove existing status classes
        element.classList.remove('available', 'pledged', 'paid', 'funded');
        
        // Apply new status
        switch (status) {
            case 'paid':
                element.classList.add('paid');
                element.style.backgroundColor = '#22c55e'; // Green
                break;
                
            case 'pledged':
                element.classList.add('pledged');
                element.style.backgroundColor = '#ff8c00'; // Orange
                break;
                
            case 'available':
            default:
                element.classList.add('available');
                element.style.backgroundColor = ''; // Default/transparent
                break;
        }
        
        // Add hover info
        if (cellData.donor) {
            element.title = `${cellData.donor} - £${cellData.amount} (${status})`;
        }
        
        // Add visual feedback
        element.style.transition = 'background-color 0.3s ease';
    }
    
    resetAllCells() {
        // Find all grid cells and reset them to available state
        const allCells = document.querySelectorAll('[id*="0101-"], [id*="0105-"], [id*="0505-"]');
        
        allCells.forEach(cell => {
            cell.classList.remove('pledged', 'paid', 'funded');
            cell.classList.add('available');
            cell.style.backgroundColor = '';
            cell.title = `Cell ${cell.id} - Available`;
        });
    }
    
    updateStatistics(stats) {
        // Update statistics display if elements exist
        const elements = {
            totalCells: document.getElementById('total-cells'),
            pledgedCells: document.getElementById('pledged-cells'),
            paidCells: document.getElementById('paid-cells'),
            availableCells: document.getElementById('available-cells'),
            progressPercentage: document.getElementById('progress-percentage'),
            totalAmount: document.getElementById('total-amount')
        };
        
        Object.entries(elements).forEach(([key, element]) => {
            if (element && stats[key] !== undefined) {
                if (key === 'progressPercentage') {
                    element.textContent = `${stats[key].toFixed(1)}%`;
                } else if (key === 'totalAmount') {
                    element.textContent = `£${stats[key].toLocaleString()}`;
                } else {
                    element.textContent = stats[key].toLocaleString();
                }
            }
        });
        
        console.log(`📊 Stats: ${stats.pledged_cells} pledged, ${stats.paid_cells} paid, ${stats.progress_percentage.toFixed(1)}% complete`);
    }
    
    startAutoRefresh() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
        }
        
        this.intervalId = setInterval(() => {
            this.updateGridStatus();
        }, this.refreshInterval);
        
        console.log(`🔄 Auto-refresh started (every ${this.refreshInterval / 1000}s)`);
    }
    
    stopAutoRefresh() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
        
        console.log('⏹️ Auto-refresh stopped');
    }
    
    showErrorNotification(message) {
        // Simple error notification (can be enhanced)
        console.warn('⚠️ Grid Update Error:', message);
        
        // Optional: Show visual error indicator
        const errorEl = document.getElementById('grid-error-indicator');
        if (errorEl) {
            errorEl.textContent = `Error: ${message}`;
            errorEl.style.display = 'block';
            
            setTimeout(() => {
                errorEl.style.display = 'none';
            }, 5000);
        }
    }
    
    // Manual refresh method
    refresh() {
        return this.updateGridStatus();
    }
    
    // Get current status
    getStatus() {
        return {
            isInitialized: this.isInitialized,
            autoRefreshActive: this.intervalId !== null,
            refreshInterval: this.refreshInterval
        };
    }
}

// Auto-initialize when script loads
window.liveGridIntegration = new LiveGridIntegration();

// Expose for manual control
window.refreshGrid = () => window.liveGridIntegration.refresh();
window.toggleAutoRefresh = () => {
    if (window.liveGridIntegration.intervalId) {
        window.liveGridIntegration.stopAutoRefresh();
    } else {
        window.liveGridIntegration.startAutoRefresh();
    }
};

console.log('🎯 Live Grid Integration Script Loaded');
