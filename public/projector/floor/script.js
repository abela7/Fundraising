// Floor Plan Visualization System
class FloorPlanTracker {
    constructor() {
        this.currentView = '1x1';
        this.buildingData = {
            totalArea: 513,
            fundedArea: 0,
            pendingArea: 0,
            squares: [],
            gridConfig: {
                '1x1': { size: 20, cols: 20, rows: 26, price: 400 },
                '0.5x1': { size: 10, cols: 40, rows: 26, price: 200 },
                '0.5x0.5': { size: 10, cols: 40, rows: 52, price: 100 }
            }
        };
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.generateGrid('1x1');
        this.simulateInitialData();
        this.updateStatistics();
        this.startLiveUpdates();
    }

    bindEvents() {
        // Grid toggle buttons
        $('.toggle-btn').on('click', (e) => {
            const view = $(e.currentTarget).data('view');
            this.changeGridView(view);
        });

        // Close popup on overlay click
        $('#popupOverlay').on('click', () => {
            this.closePopup();
        });

        // Close popup on escape key
        $(document).on('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closePopup();
            }
        });
    }

    changeGridView(viewType) {
        this.currentView = viewType;
        
        // Update button states
        $('.toggle-btn').removeClass('active');
        $(`.toggle-btn[data-view="${viewType}"]`).addClass('active');
        
        // Regenerate grid
        this.generateGrid(viewType);
        
        // Restore square states
        this.restoreSquareStates();
        this.updateStatistics();
    }

    generateGrid(viewType) {
        const config = this.buildingData.gridConfig[viewType];
        const gridOverlay = $('#gridOverlay');
        gridOverlay.empty();
        
        // Clear existing squares data
        this.buildingData.squares = [];
        
        // Building boundaries (approximate for the irregular shape)
        const bounds = {
            xMin: 100,
            xMax: 400,
            yMin: 20,
            yMax: 560
        };
        
        let squareId = 0;
        
        // Generate grid squares
        for (let y = 0; y < config.rows; y++) {
            for (let x = 0; x < config.cols; x++) {
                const xPos = bounds.xMin + (x * config.size);
                const yPos = bounds.yMin + (y * config.size);
                
                // Check if square is within building bounds
                if (this.isWithinBuilding(xPos, yPos, config.size, viewType)) {
                    const square = this.createGridSquare(
                        xPos, yPos, config.size, config.size, 
                        squareId, viewType
                    );
                    
                    gridOverlay.append(square);
                    
                    // Add to data structure
                    this.buildingData.squares.push({
                        id: squareId,
                        x: xPos,
                        y: yPos,
                        size: config.size,
                        viewType: viewType,
                        status: 'available',
                        donor: null,
                        date: null,
                        amount: config.price
                    });
                    
                    squareId++;
                }
            }
        }
    }

    isWithinBuilding(x, y, size, viewType) {
        // Simplified boundary check - you can make this more precise
        // This checks if the square is roughly within the building outline
        
        // Main building area
        if (x >= 100 && x + size <= 400 && y >= 20 && y + size <= 560) {
            return true;
        }
        
        // Extended areas (stepped sections)
        if (x >= 80 && x + size <= 420 && y >= 40 && y + size <= 120) {
            return true;
        }
        
        // Side extensions
        if (x >= 80 && x + size <= 130 && y >= 120 && y + size <= 480) {
            return true;
        }
        
        if (x >= 350 && x + size <= 400 && y >= 120 && y + size <= 480) {
            return true;
        }
        
        return false;
    }

    createGridSquare(x, y, width, height, id, viewType) {
        const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', width);
        rect.setAttribute('height', height);
        rect.setAttribute('class', 'grid-square available');
        rect.setAttribute('data-id', id);
        rect.setAttribute('rx', '2');
        rect.setAttribute('ry', '2');
        
        // Add click event
        $(rect).on('click', () => {
            this.showSquareInfo(id);
        });
        
        return rect;
    }

    showSquareInfo(squareId) {
        const square = this.buildingData.squares.find(s => s.id === squareId);
        if (!square) return;
        
        const popup = $('#infoPopup');
        const overlay = $('#popupOverlay');
        const icon = $('#popupIcon');
        const title = $('#popupTitle');
        const content = $('#popupContent');
        
        let popupContent = '';
        
        if (square.status === 'funded') {
            icon.text('🎉');
            title.text('Fully Funded Area!');
            popupContent = `
                <div class="popup-info-row">
                    <span class="popup-info-label">Area Size:</span>
                    <span>${this.getAreaLabel(square.viewType)}</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Sponsored by:</span>
                    <span>${square.donor}</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Contribution:</span>
                    <span>£${square.amount}</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Funded on:</span>
                    <span>${square.date || 'Recently'}</span>
                </div>
                <p style="margin-top: 15px; color: #4CAF50; font-weight: bold;">
                    Thank you for your generous support! 🙏
                </p>
            `;
        } else if (square.status === 'pending') {
            icon.text('⏳');
            title.text('Reserved Area');
            popupContent = `
                <div class="popup-info-row">
                    <span class="popup-info-label">Area Size:</span>
                    <span>${this.getAreaLabel(square.viewType)}</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Status:</span>
                    <span style="color: #FFC107;">Payment Pending</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Reserved Amount:</span>
                    <span>£${square.amount}</span>
                </div>
                <p style="margin-top: 15px; color: #666;">
                    This area will be confirmed once payment is processed.
                </p>
            `;
        } else {
            icon.text('✨');
            title.text('Available for Sponsorship!');
            popupContent = `
                <div class="popup-info-row">
                    <span class="popup-info-label">Area Size:</span>
                    <span>${this.getAreaLabel(square.viewType)}</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Sponsorship Amount:</span>
                    <span style="color: #667eea; font-weight: bold;">£${square.amount}</span>
                </div>
                <div class="popup-info-row">
                    <span class="popup-info-label">Square ID:</span>
                    <span>#${square.id}</span>
                </div>
                <p style="margin-top: 15px; color: #666;">
                    Help us build our church! Your sponsorship of this area will make a lasting impact on our community.
                </p>
                <p style="margin-top: 10px; font-size: 0.9rem; color: #999;">
                    Contact our fundraising team to sponsor this area.
                </p>
            `;
        }
        
        content.html(popupContent);
        popup.addClass('show');
        overlay.addClass('show');
    }

    getAreaLabel(viewType) {
        switch(viewType) {
            case '1x1': return '1 m²';
            case '0.5x1': return '0.5 m²';
            case '0.5x0.5': return '0.25 m²';
            default: return '1 m²';
        }
    }

    closePopup() {
        $('#infoPopup').removeClass('show');
        $('#popupOverlay').removeClass('show');
    }

    simulateInitialData() {
        // Simulate some initial funded areas
        const donors = [
            'John & Mary Smith', 'The Johnson Family', 'David Brown', 
            'Sarah Wilson', 'Michael & Lisa Davis', 'Robert Taylor',
            'Jennifer Anderson', 'William Thomas', 'Elizabeth Jackson',
            'Christopher White', 'Patricia Harris', 'Daniel Martin'
        ];
        
        // Fund some random squares
        const squaresToFund = Math.floor(this.buildingData.squares.length * 0.2);
        for (let i = 0; i < squaresToFund; i++) {
            const randomIndex = Math.floor(Math.random() * this.buildingData.squares.length);
            const square = this.buildingData.squares[randomIndex];
            
            if (square.status === 'available') {
                square.status = Math.random() < 0.8 ? 'funded' : 'pending';
                square.donor = donors[Math.floor(Math.random() * donors.length)];
                square.date = this.getRandomDate();
                
                if (square.status === 'funded') {
                    this.buildingData.fundedArea += this.getAreaValue(square.viewType);
                } else {
                    this.buildingData.pendingArea += this.getAreaValue(square.viewType);
                }
                
                // Update visual
                this.updateSquareVisual(square);
            }
        }
    }

    getAreaValue(viewType) {
        switch(viewType) {
            case '1x1': return 1;
            case '0.5x1': return 0.5;
            case '0.5x0.5': return 0.25;
            default: return 1;
        }
    }

    updateSquareVisual(square) {
        const rect = $(`[data-id="${square.id}"]`);
        if (rect.length) {
            rect.removeClass('available funded pending recent');
            rect.addClass(square.status);
        }
    }

    restoreSquareStates() {
        this.buildingData.squares.forEach(square => {
            this.updateSquareVisual(square);
        });
    }

    updateStatistics() {
        const totalFunded = this.buildingData.fundedArea;
        const totalPending = this.buildingData.pendingArea;
        const totalArea = this.buildingData.totalArea;
        
        // Calculate revenue based on current view
        const config = this.buildingData.gridConfig[this.currentView];
        const totalRevenue = totalFunded * config.price;
        const goalAmount = 410400;
        const percentComplete = ((totalRevenue / goalAmount) * 100).toFixed(1);
        
        // Update main stats
        $('#totalRaised').text(totalRevenue.toLocaleString());
        $('#percentComplete').text(percentComplete);
        $('#totalArea').text(totalFunded.toFixed(1));
        
        // Update progress bar
        const progressPercent = ((totalFunded / totalArea) * 100).toFixed(1);
        $('#progressFill').css('width', `${progressPercent}%`);
        $('#progressText').text(`${progressPercent}%`);
        
        // Update area stats
        $('#fundedArea').text(totalFunded.toFixed(1));
        $('#availableArea').text((totalArea - totalFunded - totalPending).toFixed(1));
    }

    getRandomDate() {
        const dates = [
            'January 15, 2024', 'February 3, 2024', 'February 20, 2024',
            'March 5, 2024', 'March 18, 2024', 'April 2, 2024',
            'April 15, 2024', 'May 1, 2024', 'May 10, 2024'
        ];
        return dates[Math.floor(Math.random() * dates.length)];
    }

    startLiveUpdates() {
        // Simulate live donations every 10 seconds
        setInterval(() => {
            this.simulateLiveDonation();
        }, 10000);
    }

    simulateLiveDonation() {
        const availableSquares = this.buildingData.squares.filter(s => s.status === 'available');
        
        if (availableSquares.length > 0) {
            const randomSquare = availableSquares[Math.floor(Math.random() * availableSquares.length)];
            const donors = ['Anonymous Donor', 'Online Contributor', 'Church Member', 'Community Supporter'];
            
            // Mark as recently funded
            randomSquare.status = 'recent';
            randomSquare.donor = donors[Math.floor(Math.random() * donors.length)];
            randomSquare.date = 'Just now';
            
            // Update visual
            this.updateSquareVisual(randomSquare);
            
            // Show celebration
            this.showCelebration();
            
            // Change to funded after animation
            setTimeout(() => {
                randomSquare.status = 'funded';
                this.buildingData.fundedArea += this.getAreaValue(randomSquare.viewType);
                this.updateSquareVisual(randomSquare);
                this.updateStatistics();
            }, 3000);
        }
    }

    showCelebration() {
        const celebration = $('#celebration');
        celebration.addClass('show');
        
        setTimeout(() => {
            celebration.removeClass('show');
        }, 2000);
    }
}

// Initialize when document is ready
$(document).ready(() => {
    new FloorPlanTracker();
});

// Utility functions
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: 'GBP'
    }).format(amount);
}

function formatPercentage(value, total) {
    return ((value / total) * 100).toFixed(1);
}
