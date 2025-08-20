// Church Floor Plan - Interactive Grid System
// Using modern libraries: jQuery, GSAP

class ChurchFloorPlan {
    constructor() {
        this.currentGridSize = '1x1';
        this.gridData = {};
        this.canvas = null;
        this.isInitialized = false;
        
        this.init();
    }

    init() {
        $(document).ready(() => {
            this.setupEventListeners();
            this.initializeGrid();
            this.loadSimulatedData();
            this.setupAnimations();
        });
    }

    setupEventListeners() {
        // Grid size toggle buttons
        $('.grid-btn').on('click', (e) => {
            const gridSize = $(e.target).data('grid');
            this.switchGridSize(gridSize);
        });

        // Window resize handling
        $(window).on('resize', () => {
            this.handleResize();
        });
    }

    switchGridSize(gridSize) {
        if (gridSize === this.currentGridSize) return;

        // Update active button
        $('.grid-btn').removeClass('active');
        $(`.grid-btn[data-grid="${gridSize}"]`).addClass('active');

        // Animate transition
        gsap.to('.floor-plan-wrapper', {
            opacity: 0.5,
            duration: 0.3,
            onComplete: () => {
                this.currentGridSize = gridSize;
                this.initializeGrid();
                this.loadSimulatedData();
                
                gsap.to('.floor-plan-wrapper', {
                    opacity: 1,
                    duration: 0.3
                });
            }
        });
    }

    initializeGrid() {
        const container = $('.floor-plan-wrapper');
        const containerWidth = container.width();
        const containerHeight = container.height();

        // Get SVG element and church outline path
        const svg = document.getElementById('churchSVG');
        const churchOutline = document.getElementById('churchOutline');
        if (!svg || !churchOutline) {
            console.error('SVG or churchOutline not found.');
            return;
        }

        // Clear existing grid cells from SVG
        $(svg).find('.grid-cell').remove();

        // Get SVG viewBox dimensions
        const viewBox = svg.viewBox.baseVal;
        const svgWidth = viewBox.width;
        const svgHeight = viewBox.height;

        // Calculate grid dimensions based on current size (50px = 1m)
        let cellPxWidth, cellPxHeight;
        switch (this.currentGridSize) {
            case '1x1':
                cellPxWidth = 50;
                cellPxHeight = 50;
                break;
            case '0.5x1':
                cellPxWidth = 25;
                cellPxHeight = 50;
                break;
            case '0.5x0.5':
                cellPxWidth = 25;
                cellPxHeight = 25;
                break;
        }

        // Calculate number of cells based on SVG dimensions
        const cols = Math.ceil(svgWidth / cellPxWidth);
        const rows = Math.ceil(svgHeight / cellPxHeight);

        // Store grid info
        this.gridInfo = {
            cellPxWidth,
            cellPxHeight,
            cols,
            rows,
            svgWidth,
            svgHeight
        };

        // Generate grid cells
        for (let row = 0; row < rows; row++) {
            for (let col = 0; col < cols; col++) {
                const x = col * cellPxWidth;
                const y = row * cellPxHeight;
                const cellId = `cell_${col}_${row}`;

                // Check if the center of the cell is within the church outline
                const centerX = x + cellPxWidth / 2;
                const centerY = y + cellPxHeight / 2;
                const svgPoint = svg.createSVGPoint();
                svgPoint.x = centerX;
                svgPoint.y = centerY;

                if (churchOutline.isPointInFill(svgPoint)) {
                    const cell = this.createGridCell(x, y, cellPxWidth, cellPxHeight, col, row, cellId);
                    svg.appendChild(cell);
                }
            }
        }
    }

    createGridCell(x, y, width, height, col, row, id) {
        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', width);
        rect.setAttribute('height', height);
        rect.setAttribute('class', 'grid-cell');
        rect.setAttribute('id', id);
        rect.setAttribute('data-col', col);
        rect.setAttribute('data-row', row);

        // Add click event
        rect.addEventListener('click', () => {
            this.handleCellClick(col, row, $(rect));
        });

        // Add hover effects
        rect.addEventListener('mouseenter', () => {
            this.handleCellHover($(rect), true);
        });

        rect.addEventListener('mouseleave', () => {
            this.handleCellHover($(rect), false);
        });

        return rect;
    }

    handleCellClick(col, row, cell) {
        const cellId = `cell_${col}_${row}`;
        const cellData = this.gridData[cellId] || this.getDefaultCellData(col, row);

        // Update info panel with animation
        this.updateInfoPanel(cellData);
        
        // Add click animation
        gsap.to(cell, {
            scale: 1.1,
            duration: 0.1,
            yoyo: true,
            repeat: 1
        });

        // Log for debugging
        console.log('Cell clicked:', { col, row, data: cellData });
    }

    handleCellHover(cell, isHovering) {
        if (isHovering) {
            gsap.to(cell, {
                scale: 1.05,
                duration: 0.2,
                ease: "power2.out"
            });
        } else {
            gsap.to(cell, {
                scale: 1,
                duration: 0.2,
                ease: "power2.out"
            });
        }
    }

    getDefaultCellData(col, row) {
        const area = this.calculateArea(col, row);
        return {
            area: area,
            status: 'available',
            price: this.calculatePrice(area),
            coordinates: { col, row }
        };
    }

    calculateArea(col, row) {
        const { cellWidth, cellHeight } = this.gridInfo;
        
        // Convert pixels to meters (assuming 50px = 1m)
        const widthM = (cellWidth / 50);
        const heightM = (cellHeight / 50);
        
        return widthM * heightM;
    }

    calculatePrice(area) {
        // £400 per square meter
        return area * 400;
    }

    updateInfoPanel(cellData) {
        const { area, status, price, coordinates } = cellData;
        
        // Animate info panel update
        gsap.to('#infoPanel', {
            scale: 1.02,
            duration: 0.2,
            yoyo: true,
            repeat: 1
        });

        // Update content with smooth transitions
        $('#infoTitle').text(`Grid Area ${coordinates.col},${coordinates.row}`);
        $('#infoArea').text(`Area: ${area} m²`);
        $('#infoStatus').text(`Status: ${status.charAt(0).toUpperCase() + status.slice(1)}`);
        $('#infoPrice').text(`Price: £${price.toLocaleString()}`);
    }

    loadSimulatedData() {
        // Simulate some funded and pending areas
        this.gridData = {};
        
        const totalCells = this.gridInfo.cols * this.gridInfo.rows;
        const fundedCount = Math.floor(totalCells * 0.15); // 15% funded
        const pendingCount = Math.floor(totalCells * 0.25); // 25% pending

        // Mark some cells as funded
        for (let i = 0; i < fundedCount; i++) {
            const col = Math.floor(Math.random() * this.gridInfo.cols);
            const row = Math.floor(Math.random() * this.gridInfo.rows);
            const cellId = `cell_${col}_${row}`;
            
            this.gridData[cellId] = {
                area: this.calculateArea(col, row),
                status: 'funded',
                price: this.calculatePrice(this.calculateArea(col, row)),
                coordinates: { col, row }
            };

            // Update visual
            $(`#${cellId}`).addClass('funded');
        }

        // Mark some cells as pending
        for (let i = 0; i < pendingCount; i++) {
            const col = Math.floor(Math.random() * this.gridInfo.cols);
            const row = Math.floor(Math.random() * this.gridInfo.rows);
            const cellId = `cell_${col}_${row}`;
            
            // Don't overwrite funded cells
            if (!this.gridData[cellId]) {
                this.gridData[cellId] = {
                    area: this.calculateArea(col, row),
                    status: 'pending',
                    price: this.calculatePrice(this.calculateArea(col, row)),
                    coordinates: { col, row }
                };

                // Update visual
                $(`#${cellId}`).addClass('pending');
            }
        }

        // Update statistics
        this.updateStatistics();
    }

    updateStatistics() {
        let fundedArea = 0;
        let totalArea = 0;

        Object.values(this.gridData).forEach(cell => {
            if (cell.status === 'funded') {
                fundedArea += cell.area;
            }
            totalArea += cell.area;
        });

        const progressPercent = totalArea > 0 ? Math.round((fundedArea / totalArea) * 100) : 0;

        // Animate statistics update
        gsap.to('#fundedArea', {
            duration: 0.5,
            text: `${fundedArea.toFixed(1)} m²`,
            ease: "power2.out"
        });

        gsap.to('#progressPercent', {
            duration: 0.5,
            text: `${progressPercent}%`,
            ease: "power2.out"
        });
    }

    setupAnimations() {
        // Initial page load animations
        gsap.from('.header', {
            y: -50,
            opacity: 0,
            duration: 1,
            ease: "power3.out"
        });

        gsap.from('.controls-panel', {
            x: -50,
            opacity: 0,
            duration: 1,
            delay: 0.2,
            ease: "power3.out"
        });

        gsap.from('.floor-plan-container', {
            y: 50,
            opacity: 0,
            duration: 1,
            delay: 0.4,
            ease: "power3.out"
        });

        gsap.from('.info-panel', {
            y: 50,
            opacity: 0,
            duration: 1,
            delay: 0.6,
            ease: "power3.out"
        });
    }

    handleResize() {
        if (this.isInitialized) {
            // Debounce resize events
            clearTimeout(this.resizeTimeout);
            this.resizeTimeout = setTimeout(() => {
                this.initializeGrid();
                this.loadSimulatedData();
            }, 250);
        }
    }

    // Public method to update cell status (for real-time updates)
    updateCellStatus(col, row, status) {
        const cellId = `cell_${col}_${row}`;
        const cell = $(`#${cellId}`);
        
        if (cell.length) {
            // Remove existing status classes
            cell.removeClass('funded pending available');
            
            // Add new status class
            cell.addClass(status);
            
            // Update data
            if (this.gridData[cellId]) {
                this.gridData[cellId].status = status;
            }
            
            // Update statistics
            this.updateStatistics();
        }
    }

    // Public method to refresh all data (for real-time updates)
    refreshData() {
        // This would typically fetch from your API
        this.loadSimulatedData();
    }
}

// Initialize the application
const churchFloorPlan = new ChurchFloorPlan();

// Make it globally accessible for debugging
window.churchFloorPlan = churchFloorPlan;

// Auto-refresh every 30 seconds (for real-time updates)
setInterval(() => {
    churchFloorPlan.refreshData();
}, 30000);

// Export for potential module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ChurchFloorPlan;
}
