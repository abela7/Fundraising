<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Floor Map - Live Fundraising</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
  /* 1m size + canvas size - Enhanced responsive scaling */
  :root { 
    --m: min(3.5vw, 3.5vh, 48px); 
    --cols: 41; 
    --rows: 20; 
    --max-width: calc(var(--cols) * var(--m));
    --max-height: calc(var(--rows) * var(--m));
  }
  
  /* Responsive breakpoints with proportional scaling */
  @media (max-width: 1600px) { :root { --m: min(3.2vw, 3.2vh, 44px); } }
  @media (max-width: 1200px) { :root { --m: min(3.0vw, 3.0vh, 40px); } }
  @media (max-width: 900px)  { :root { --m: min(2.8vw, 2.8vh, 36px); } }
  @media (max-width: 600px)  { :root { --m: min(2.6vw, 2.6vh, 32px); } }
  @media (max-width: 480px)  { :root { --m: min(2.4vw, 2.4vh, 28px); } }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  /* Enhanced animated background with subtle moving gradient */
  body{
    display: flex; align-items: center; justify-content: center;
    padding: 0; margin: 0; min-height: 100vh;
    background: linear-gradient(-45deg, #0f172a, #1e293b, #334155, #1e293b);
    background-size: 400% 400%;
    animation: gradientShift 15s ease infinite;
    font-family: system-ui, -apple-system, sans-serif;
    position: relative;
    overflow: hidden;
  }
  
  /* Animated background gradient */
  @keyframes gradientShift {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
  }
  
  /* Subtle floating particles effect */
  body::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-image: 
      radial-gradient(2px 2px at 20px 30px, rgba(255, 255, 255, 0.1), transparent),
      radial-gradient(2px 2px at 40px 70px, rgba(255, 255, 255, 0.05), transparent),
      radial-gradient(1px 1px at 90px 40px, rgba(255, 255, 255, 0.08), transparent),
      radial-gradient(1px 1px at 130px 80px, rgba(255, 255, 255, 0.06), transparent),
      radial-gradient(2px 2px at 160px 30px, rgba(255, 255, 255, 0.04), transparent);
    background-repeat: repeat;
    background-size: 200px 100px;
    animation: floatParticles 20s linear infinite;
    pointer-events: none;
    z-index: 1;
  }
  
  @keyframes floatParticles {
    0% { transform: translateY(0px) translateX(0px); }
    33% { transform: translateY(-10px) translateX(5px); }
    66% { transform: translateY(5px) translateX(-3px); }
    100% { transform: translateY(0px) translateX(0px); }
  }

  .game-container{
    display: flex; 
    align-items: center; 
    justify-content: center;
    width: 100vw; 
    min-height: 100vh;
    position: relative;
    padding: 20px;
    box-sizing: border-box;
    z-index: 2;
  }

  /* Enhanced floor map with subtle glow and pulse effect */
  .floor-map{
    display: grid;
    grid-template-columns: repeat(var(--cols), var(--m));
    grid-template-rows: repeat(var(--rows), var(--m));
    position: relative;
    max-width: 95vw; max-height: 95vh;
    width: fit-content; height: fit-content;
    filter: drop-shadow(0 0 20px rgba(255, 215, 0, 0.1));
    animation: subtlePulse 4s ease-in-out infinite;
    
    .main-section {
      width: 100%;
      height: 100%;
      display: grid;
      grid-template-columns: repeat(28, 1fr);
      grid-template-rows: repeat(44, 1fr);
      gap: 2px;
      padding: 5px;
      background-color: #E0E0E0;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    /* Base style for all cell types */
    .meter-container, .half-tile, .grid-tile-quarter {
      background-color: #F5F5F5;
    }
    
    /* Interactive hover effect only on the smallest, selectable cells */
    .grid-tile-quarter:hover {
      background-color: #FFFFFF;
      transition: background-color 0.2s ease-in-out;
    }
  }
  
  /* Subtle pulse animation for the floor map */
  @keyframes subtlePulse {
    0%, 100% { 
      filter: drop-shadow(0 0 20px rgba(255, 215, 0, 0.1));
      transform: scale(1);
    }
    50% { 
      filter: drop-shadow(0 0 25px rgba(255, 215, 0, 0.15));
      transform: scale(1.002);
    }
  }

  /* Enhanced shape styling with subtle animations */
  .shape{ 
    display:flex; align-items:center; justify-content:center; 
    font-weight:800; color:#fff; opacity:.78;
    font-size: max(12px, min(1.2em, calc(var(--m) * 0.4)));
    user-select: none;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
  }
  
  /* Add subtle shimmer effect to shapes */
  .shape::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: linear-gradient(
      45deg,
      transparent,
      rgba(255, 255, 255, 0.05),
      transparent
    );
    animation: shimmer 8s ease-in-out infinite;
    pointer-events: none;
  }
  
  @keyframes shimmer {
    0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
    50% { transform: translateX(100%) translateY(100%) rotate(45deg); }
    100% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
  }
  
  /* Enhanced shape colors with subtle gradients */
  .A{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  .B{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  .C{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  .D{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  .E{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  .F{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  .G{ background: linear-gradient(135deg, #8B8680, #9a9590); }
  
  /* Hover effects for shapes */
  .shape:hover {
    opacity: 0.9;
    transform: scale(1.02);
    filter: brightness(1.1);
  }

  /* Totals: A=108, B=9, C=16, D=120, E=120, F=20, G=120 => 513 */

  /* A already ends at row 16 */
  .A{ grid-column:  1 / span  9; grid-row:  5 / span 12; }

  .B{ grid-column:  1 / span  3; grid-row: 17 / span  3; }

  /* C base aligned to row 16 (moved down, same height) */
  .C{ grid-column: 10 / span  2; grid-row:  9 / span  8; }

  /* E base aligned to row 16 (moved up, same height) */
  .E{ grid-column: 12 / span 12; grid-row:  7 / span 10; }

  /* D base aligned to row 16 (moved down, same height) */
  .D{ grid-column: 24 / span 10; grid-row:  5 / span 12; }

  .G{ grid-column: 34 / span  8; grid-row:  6 / span 15; }

  /* F previously set to AH column, rows 2–5 */
  .F{ grid-column: 34 / span  5; grid-row:  2 / span  4; }

  /* Enhanced stats card with live indicator */
  .stats-card {
    position: absolute;
    bottom: calc(var(--m) * 0.5);
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 215, 0, 0.2);
    border-radius: 12px;
    color: #ffffff;
    font-family: system-ui, -apple-system, sans-serif;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: calc(var(--m) * 0.3) calc(var(--m) * 0.6);
    box-sizing: border-box;
    z-index: 100;
    backdrop-filter: blur(10px);
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    animation: cardGlow 3s ease-in-out infinite;
    
    /* Responsive width based on content */
    min-width: calc(var(--m) * 8);
    width: auto;
  }
  
  /* Subtle glow animation for stats card */
  @keyframes cardGlow {
    0%, 100% { 
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), 0 0 0 rgba(255, 215, 0, 0.1);
    }
    50% { 
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3), 0 0 20px rgba(255, 215, 0, 0.2);
    }
  }
  
  /* Add live indicator dot */
  .stats-card::after {
    content: '';
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    background: #00ff88;
    border-radius: 50%;
    animation: livePulse 2s ease-in-out infinite;
    box-shadow: 0 0 10px rgba(0, 255, 136, 0.5);
  }
  
  @keyframes livePulse {
    0%, 100% { 
      opacity: 1;
      transform: scale(1);
    }
    50% { 
      opacity: 0.6;
      transform: scale(1.2);
    }
  }

  /* Mobile positioning */
  @media (max-width: 768px) {
    .stats-card {
      bottom: calc(var(--m) * 0.3);
      padding: calc(var(--m) * 0.2) calc(var(--m) * 0.4);
    }
  }

  /* Add subtle animation to coverage numbers */
  .coverage-numbers {
    font-size: calc(var(--m) * 0.8);
    font-weight: 700;
    color: #ffd700;
    line-height: 1;
    text-align: center;
    margin-bottom: calc(var(--m) * 0.12);
    letter-spacing: 1px;
    animation: numberGlow 4s ease-in-out infinite;
  }
  
  @keyframes numberGlow {
    0%, 100% { 
      text-shadow: 0 0 10px rgba(255, 215, 0, 0.3);
    }
    50% { 
      text-shadow: 0 0 20px rgba(255, 215, 0, 0.6);
    }
  }

  .coverage-label {
    font-size: calc(var(--m) * 0.28);
    color: #8b949e;
    text-transform: uppercase;
    letter-spacing: 2px;
    text-align: center;
    margin-bottom: calc(var(--m) * 0.3);
    font-weight: 500;
  }

  /* Enhanced progress bar with animated fill */
  .progress-bar {
    width: calc(var(--m) * 10);
    height: calc(var(--m) * 0.16);
    background: rgba(51, 65, 85, 0.8);
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: calc(var(--m) * 0.25);
    position: relative;
  }
  
  .progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #ffd700, #ffed4e, #ffd700);
    background-size: 200% 100%;
    border-radius: 8px;
    transition: width 0.8s ease-in-out;
    width: 0%;
    animation: progressShimmer 2s ease-in-out infinite;
    position: relative;
  }
  
  @keyframes progressShimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
  }

  .percentage {
    font-size: calc(var(--m) * 0.55);
    color: #ffffff;
    text-align: center;
    line-height: 1;
    font-weight: 500;
    margin-top: calc(var(--m) * 0.08);
  }

  /* Fullscreen button styling */
  .fullscreen-btn {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 1100;
    background: rgba(0, 0, 0, 0.4);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    padding: 8px 10px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    cursor: pointer;
    backdrop-filter: blur(6px);
    transition: opacity 0.25s ease, transform 0.2s ease;
    opacity: 0.35;
    font-family: system-ui, -apple-system, sans-serif;
  }

  .fullscreen-btn:hover { 
    opacity: 1; 
    transform: translateY(-1px); 
  }
  
  .fullscreen-btn.hidden { 
    opacity: 0; 
    pointer-events: none; 
  }
  
  .fullscreen-btn i { 
    font-size: 1rem; 
  }

  @media (max-width: 768px) {
    .fullscreen-btn { 
      padding: 6px 8px; 
      font-size: 0.8rem; 
      top: 8px; 
      right: 8px; 
    }
    .fullscreen-btn i { 
      font-size: 0.9rem; 
    }
  }

  /* Clean floor map design with enhanced visual effects */
</style>
</head>
<body>
  <button class="fullscreen-btn" id="fullscreenBtn" title="Toggle Fullscreen (F)">
    <i class="fas fa-expand"></i>
    <span class="label">Fullscreen</span>
  </button>

  <div class="game-container">
    <div class="floor-map">
      <div class="shape A">A</div>
      <div class="shape B">B</div>
      <div class="shape C">C</div>
      <div class="shape D">D</div>
      <div class="shape E">E</div>
      <div class="shape F">F</div>
      <div class="shape G">G</div>
      
      <!-- Stats card inside floor map at bottom -->
      <div class="stats-card">
        <div class="coverage-numbers">
          <span id="covered-area">0.00</span>m² / <span id="total-area">513.00</span>m²
        </div>
        <div class="coverage-label">Coverage Progress</div>
        <div class="progress-bar">
          <div class="progress-fill" id="progress-fill"></div>
        </div>
        <div class="percentage" id="coverage-percentage">0.0%</div>
      </div>
    </div>
  </div>

<script>
// Grid system for rectangle A - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeA() {
  const shapeA = document.querySelector('.A');
  
  // Shape A dimensions: columns 1-9 (9 wide), rows 5-16 (12 tall)
  const startCol = 1;
  const endCol = 9;
  const startRow = 5;
  const endRow = 16;
  
  // Clear existing content
  shapeA.innerHTML = '';
  
  // Create finest subdivided grid tiles for shape A (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      
      // Generate unique IDs for different tile sizes
      const meterContainerNumber = (row * (endCol - startCol + 1)) + col + 1;
      const paddedNumber = meterContainerNumber.toString().padStart(2, '0');
      
      // Set ID for 1m x 1m container
      meterContainer.id = `A0101-${paddedNumber}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1.0');
       meterContainer.style.cursor = 'pointer';
       meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
       
       // Add click handler for 1m x 1m container
       meterContainer.addEventListener('click', function(e) {
         // Only trigger if clicking the container itself, not child elements
         if (e.target === this) {
           console.log(`Clicked 1m x 1m container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
           // Visual feedback
           this.style.backgroundColor = 'rgba(255, 255, 0, 0.1)';
           setTimeout(() => {
             this.style.backgroundColor = 'transparent';
           }, 300);
         }
       });
      
      // Create 1m x 0.5m sections (2 per 1m x 1m tile)
      for (let halfRow = 0; halfRow < 2; halfRow++) {
        const halfTile = document.createElement('div');
        
        // Calculate sequential number for 1x0.5 tiles
        const halfTileNumber = ((row * (endCol - startCol + 1) + col) * 2) + halfRow + 1;
        const paddedHalfNumber = halfTileNumber.toString().padStart(2, '0');
        
        halfTile.id = `A0105-${paddedHalfNumber}`;
        halfTile.className = 'half-tile';
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', `A0101-${paddedNumber}`);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0%';
        halfTile.style.top = `${halfRow * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.zIndex = '1';
        halfTile.style.cursor = 'pointer';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
         
         // Add click handler for 1m x 0.5m tiles
         halfTile.addEventListener('click', function(e) {
           // Only trigger if clicking the half tile itself, not child elements
           if (e.target === this) {
             console.log(`Clicked 1m x 0.5m tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
             // Visual feedback
             this.style.backgroundColor = 'rgba(255, 255, 0, 0.15)';
             setTimeout(() => {
               this.style.backgroundColor = 'transparent';
             }, 300);
           }
         });
         
         meterContainer.appendChild(halfTile);
      }
      
      // Create four 0.5m x 0.5m sections for each 1m x 1m tile
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          
          // Calculate sequential number for 0.5x0.5 tiles
          const quarterTileNumber = ((row * (endCol - startCol + 1) + col) * 4) + (subRow * 2 + subCol) + 1;
          const paddedQuarterNumber = quarterTileNumber.toString().padStart(2, '0');
          
          tile.id = `A0505-${paddedQuarterNumber}`;
          tile.className = 'grid-tile-quarter';
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', `A0101-${paddedNumber}`);
          tile.setAttribute('data-half-parent', `A0105-${(((row * (endCol - startCol + 1) + col) * 2) + Math.floor(subRow) + 1).toString().padStart(2, '0')}`);
          tile.dataset.col = col;
          tile.dataset.row = row;
          tile.dataset.subCol = subCol;
          tile.dataset.subRow = subRow;
          tile.dataset.shape = 'A';
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Position tile within the 1m x 1m container
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          tile.style.zIndex = '2';
          tile.style.zIndex = '2';
          tile.style.zIndex = '2';
          tile.style.zIndex = '2';
          
          // Color coding for different quarters
          const quarterIndex = subRow * 2 + subCol;
          const quarterColors = [
            'rgba(255, 255, 255, 0.05)', // Top-left (0)
            'rgba(255, 255, 255, 0.15)', // Top-right (1)
            'rgba(255, 255, 255, 0.1)',  // Bottom-left (2)
            'rgba(255, 255, 255, 0.2)'   // Bottom-right (3)
          ];
          
          tile.style.backgroundColor = quarterColors[quarterIndex];
          
          // Store original color for reset
          tile.dataset.originalColor = quarterColors[quarterIndex];
          
          // Hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.3)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
        }
      }
      
      shapeA.appendChild(meterContainer);
    }
  }
  
  // Make shape A relative positioned to contain absolute tiles
  shapeA.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape A`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Grid system for rectangle B - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeB() {
  const shapeB = document.querySelector('.B');
  
  // Shape B dimensions: columns 1-3 (3 wide), rows 17-19 (3 tall)
  const startCol = 1;
  const endCol = 3;
  const startRow = 17;
  const endRow = 19;
  
  // Clear existing content
  shapeB.innerHTML = '';
  
  // Create finest subdivided grid tiles for shape B (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      
      // Generate unique IDs for different tile sizes
      const meterContainerNumber = ((row - startRow) * (endCol - startCol + 1)) + (col - startCol) + 1;
      const paddedNumber = meterContainerNumber.toString().padStart(2, '0');
      
      // Set ID for 1m x 1m container
      meterContainer.id = `B0101-${paddedNumber}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1.0');
      meterContainer.style.cursor = 'pointer';
      meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
       
      // Add click handler for 1m x 1m container
      meterContainer.addEventListener('click', function(e) {
        // Only trigger if clicking the container itself, not child elements
        if (e.target === this) {
          console.log(`Clicked 1m x 1m container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
          // Visual feedback
          this.style.backgroundColor = 'rgba(255, 255, 0, 0.1)';
          setTimeout(() => {
            this.style.backgroundColor = 'transparent';
          }, 300);
        }
      });
      
      // Create 1m x 0.5m sections (2 per 1m x 1m tile)
      for (let halfRow = 0; halfRow < 2; halfRow++) {
        const halfTile = document.createElement('div');
        
        // Calculate sequential number for 1x0.5 tiles
        const halfTileNumber = (((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 2) + halfRow + 1;
        const paddedHalfNumber = halfTileNumber.toString().padStart(2, '0');
        
        halfTile.id = `B0105-${paddedHalfNumber}`;
        halfTile.className = 'half-tile';
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', `B0101-${paddedNumber}`);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0%';
        halfTile.style.top = `${halfRow * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.cursor = 'pointer';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
         
        // Add click handler for 1m x 0.5m tiles
        halfTile.addEventListener('click', function(e) {
          // Only trigger if clicking the half tile itself, not child elements
          if (e.target === this) {
            console.log(`Clicked 1m x 0.5m tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Visual feedback
            this.style.backgroundColor = 'rgba(255, 255, 0, 0.15)';
            setTimeout(() => {
              this.style.backgroundColor = 'transparent';
            }, 300);
          }
        });
         
        meterContainer.appendChild(halfTile);
      }
      
      // Create four 0.5m x 0.5m sections for each 1m x 1m tile
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          
          // Calculate sequential number for 0.5x0.5 tiles
          const quarterTileNumber = (((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 4) + (subRow * 2 + subCol) + 1;
          const paddedQuarterNumber = quarterTileNumber.toString().padStart(2, '0');
          
          tile.id = `B0505-${paddedQuarterNumber}`;
          tile.className = 'grid-tile-quarter';
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', `B0101-${paddedNumber}`);
          tile.setAttribute('data-half-parent', `B0105-${((((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 2) + Math.floor(subRow) + 1).toString().padStart(2, '0')}`);
          tile.dataset.col = col;
          tile.dataset.row = row;
          tile.dataset.subCol = subCol;
          tile.dataset.subRow = subRow;
          tile.dataset.shape = 'B';
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Position tile within the 1m x 1m container
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          
          // Color coding for different quarters
          const quarterIndex = subRow * 2 + subCol;
          const quarterColors = [
            'rgba(255, 255, 255, 0.05)', // Top-left (0)
            'rgba(255, 255, 255, 0.15)', // Top-right (1)
            'rgba(255, 255, 255, 0.1)',  // Bottom-left (2)
            'rgba(255, 255, 255, 0.2)'   // Bottom-right (3)
          ];
          
          tile.style.backgroundColor = quarterColors[quarterIndex];
          
          // Store original color for reset
          tile.dataset.originalColor = quarterColors[quarterIndex];
          
          // Hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.3)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
        }
      }
      
      shapeB.appendChild(meterContainer);
    }
  }
  
  // Make shape B relative positioned to contain absolute tiles
  shapeB.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape B`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Grid system for rectangle C - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeC() {
  const shapeC = document.querySelector('.C');
  
  // Shape C dimensions: columns 10-11 (2 wide), rows 9-16 (8 tall)
  const startCol = 10;
  const endCol = 11;
  const startRow = 9;
  const endRow = 16;
  
  // Clear existing content
  shapeC.innerHTML = '';
  
  // Create finest subdivided grid tiles for shape C (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      
      // Generate unique IDs for different tile sizes
      const meterContainerNumber = ((row - startRow) * (endCol - startCol + 1)) + (col - startCol) + 1;
      const paddedNumber = meterContainerNumber.toString().padStart(2, '0');
      
      // Set ID for 1m x 1m container
      meterContainer.id = `C0101-${paddedNumber}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1.0');
      meterContainer.style.cursor = 'pointer';
      meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
       
      // Add click handler for 1m x 1m container
      meterContainer.addEventListener('click', function(e) {
        // Only trigger if clicking the container itself, not child elements
        if (e.target === this) {
          console.log(`Clicked 1m x 1m container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
          // Visual feedback
          this.style.backgroundColor = 'rgba(255, 255, 0, 0.1)';
          setTimeout(() => {
            this.style.backgroundColor = 'transparent';
          }, 300);
        }
      });
      
      // Create 1m x 0.5m sections (2 per 1m x 1m tile)
      for (let halfRow = 0; halfRow < 2; halfRow++) {
        const halfTile = document.createElement('div');
        
        // Calculate sequential number for 1x0.5 tiles
        const halfTileNumber = (((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 2) + halfRow + 1;
        const paddedHalfNumber = halfTileNumber.toString().padStart(2, '0');
        
        halfTile.id = `C0105-${paddedHalfNumber}`;
        halfTile.className = 'half-tile';
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', `C0101-${paddedNumber}`);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0%';
        halfTile.style.top = `${halfRow * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.cursor = 'pointer';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
         
        // Add click handler for 1m x 0.5m tiles
        halfTile.addEventListener('click', function(e) {
          // Only trigger if clicking the half tile itself, not child elements
          if (e.target === this) {
            console.log(`Clicked 1m x 0.5m tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Visual feedback
            this.style.backgroundColor = 'rgba(255, 255, 0, 0.15)';
            setTimeout(() => {
              this.style.backgroundColor = 'transparent';
            }, 300);
          }
        });
         
        meterContainer.appendChild(halfTile);
      }
      
      // Create four 0.5m x 0.5m sections for each 1m x 1m tile
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          
          // Calculate sequential number for 0.5x0.5 tiles
          const quarterTileNumber = (((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 4) + (subRow * 2 + subCol) + 1;
          const paddedQuarterNumber = quarterTileNumber.toString().padStart(2, '0');
          
          tile.id = `C0505-${paddedQuarterNumber}`;
          tile.className = 'grid-tile-quarter';
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', `C0101-${paddedNumber}`);
          tile.setAttribute('data-half-parent', `C0105-${((((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 2) + Math.floor(subRow) + 1).toString().padStart(2, '0')}`);
          tile.dataset.col = col;
          tile.dataset.row = row;
          tile.dataset.subCol = subCol;
          tile.dataset.subRow = subRow;
          tile.dataset.shape = 'C';
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Position tile within the 1m x 1m container
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          
          // Color coding for different quarters
          const quarterIndex = subRow * 2 + subCol;
          const quarterColors = [
            'rgba(255, 255, 255, 0.05)', // Top-left (0)
            'rgba(255, 255, 255, 0.15)', // Top-right (1)
            'rgba(255, 255, 255, 0.1)',  // Bottom-left (2)
            'rgba(255, 255, 255, 0.2)'   // Bottom-right (3)
          ];
          
          tile.style.backgroundColor = quarterColors[quarterIndex];
          
          // Store original color for reset
          tile.dataset.originalColor = quarterColors[quarterIndex];
          
          // Hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.3)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
        }
      }
      
      shapeC.appendChild(meterContainer);
    }
  }
  
  // Make shape C relative positioned to contain absolute tiles
  shapeC.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape C`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Grid system for rectangle D - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeD() {
  const shapeD = document.querySelector('.D');
  
  // Shape D dimensions: columns 24-33 (10 wide), rows 5-16 (12 tall)
  const startCol = 24;
  const endCol = 33;
  const startRow = 5;
  const endRow = 16;
  
  // Clear existing content
  shapeD.innerHTML = '';
  
  // Create finest subdivided grid tiles for shape D (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      
      // Generate unique IDs for different tile sizes
      const meterContainerNumber = ((row - startRow) * (endCol - startCol + 1)) + (col - startCol) + 1;
      const paddedNumber = meterContainerNumber.toString().padStart(2, '0');
      
      // Set ID for 1m x 1m container
      meterContainer.id = `D0101-${paddedNumber}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1.0');
      meterContainer.style.cursor = 'pointer';
      meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
       
      // Add click handler for 1m x 1m container
      meterContainer.addEventListener('click', function(e) {
        // Only trigger if clicking the container itself, not child elements
        if (e.target === this) {
          console.log(`Clicked 1m x 1m container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
          // Visual feedback
          this.style.backgroundColor = 'rgba(255, 255, 0, 0.1)';
          setTimeout(() => {
            this.style.backgroundColor = 'transparent';
          }, 300);
        }
      });
      
      // Create 1m x 0.5m sections (2 per 1m x 1m tile)
      for (let halfRow = 0; halfRow < 2; halfRow++) {
        const halfTile = document.createElement('div');
        
        // Calculate sequential number for 1x0.5 tiles
        const halfTileNumber = (((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 2) + halfRow + 1;
        const paddedHalfNumber = halfTileNumber.toString().padStart(2, '0');
        
        halfTile.id = `D0105-${paddedHalfNumber}`;
        halfTile.className = 'half-tile';
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', `D0101-${paddedNumber}`);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0%';
        halfTile.style.top = `${halfRow * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.cursor = 'pointer';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
         
        // Add click handler for 1m x 0.5m tiles
        halfTile.addEventListener('click', function(e) {
          // Only trigger if clicking the half tile itself, not child elements
          if (e.target === this) {
            console.log(`Clicked 1m x 0.5m tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Visual feedback
            this.style.backgroundColor = 'rgba(255, 255, 0, 0.15)';
            setTimeout(() => {
              this.style.backgroundColor = 'transparent';
            }, 300);
          }
        });
         
        meterContainer.appendChild(halfTile);
      }
      
      // Create four 0.5m x 0.5m sections for each 1m x 1m tile
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          
          // Calculate sequential number for 0.5x0.5 tiles
          const quarterTileNumber = (((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 4) + (subRow * 2 + subCol) + 1;
          const paddedQuarterNumber = quarterTileNumber.toString().padStart(2, '0');
          
          tile.id = `D0505-${paddedQuarterNumber}`;
          tile.className = 'grid-tile-quarter';
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', `D0101-${paddedNumber}`);
          tile.setAttribute('data-half-parent', `D0105-${((((row - startRow) * (endCol - startCol + 1) + (col - startCol)) * 2) + Math.floor(subRow) + 1).toString().padStart(2, '0')}`);
          tile.dataset.col = col;
          tile.dataset.row = row;
          tile.dataset.subCol = subCol;
          tile.dataset.subRow = subRow;
          tile.dataset.shape = 'D';
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Position tile within the 1m x 1m container
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          
          // Color coding for different quarters
          const quarterIndex = subRow * 2 + subCol;
          const quarterColors = [
            'rgba(255, 255, 255, 0.05)', // Top-left (0)
            'rgba(255, 255, 255, 0.15)', // Top-right (1)
            'rgba(255, 255, 255, 0.1)',  // Bottom-left (2)
            'rgba(255, 255, 255, 0.2)'   // Bottom-right (3)
          ];
          
          tile.style.backgroundColor = quarterColors[quarterIndex];
          
          // Store original color for reset
          tile.dataset.originalColor = quarterColors[quarterIndex];
          
          // Hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.3)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
        }
      }
      
      shapeD.appendChild(meterContainer);
    }
  }
  
  // Make shape D relative positioned to contain absolute tiles
  shapeD.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape D`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Grid system for rectangle E - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeE() {
  const shapeE = document.querySelector('.E');
  
  // Shape E dimensions: columns 12-23 (12 wide), rows 7-16 (10 tall)
  const startCol = 12;
  const endCol = 23;
  const startRow = 7;
  const endRow = 16;
  
  // Clear existing content
  shapeE.innerHTML = '';
  
  let meterContainerIdE = 1;
  let halfTileIdE = 1;
  let quarterTileIdE = 1;
  
  // Create finest subdivided grid tiles for shape E (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.id = `E0101-${String(meterContainerIdE).padStart(2, '0')}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1');
      meterContainer.setAttribute('data-parent', 'E');
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      meterContainer.style.cursor = 'pointer';
      meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
      
      // Add click handler for 1m x 1m container
      meterContainer.addEventListener('click', function(e) {
        e.stopPropagation();
        console.log(`Clicked container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
        // Add visual feedback
        const originalBorder = this.style.border;
        this.style.border = '2px solid rgba(255, 255, 0, 0.8)';
        setTimeout(() => {
          this.style.border = originalBorder;
        }, 500);
      });
      
      // Create two 1m x 0.5m sections within each 1m x 1m container
      for (let halfSection = 0; halfSection < 2; halfSection++) {
        const halfTile = document.createElement('div');
        halfTile.className = 'half-tile';
        halfTile.id = `E0105-${String(halfTileIdE).padStart(2, '0')}`;
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', meterContainer.id);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0';
        halfTile.style.top = `${halfSection * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.cursor = 'pointer';
        halfTile.style.zIndex = '1';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
        
        // Add click handler for 1m x 0.5m tile
        halfTile.addEventListener('click', function(e) {
          e.stopPropagation();
          console.log(`Clicked half-tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
          // Add visual feedback
          const originalBg = this.style.backgroundColor;
          this.style.backgroundColor = 'rgba(255, 255, 0, 0.3)';
          setTimeout(() => {
            this.style.backgroundColor = originalBg;
          }, 300);
        });
        
        meterContainer.appendChild(halfTile);
        halfTileIdE++;
      }
      
      // Create four 0.5m x 0.5m tiles within each 1m x 1m container
      const colors = ['rgba(255,255,255,0.1)', 'rgba(255,255,255,0.15)', 'rgba(255,255,255,0.2)', 'rgba(255,255,255,0.25)'];
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          tile.className = 'quarter-tile';
          tile.id = `E0505-${String(quarterTileIdE).padStart(2, '0')}`;
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', meterContainer.id);
          tile.setAttribute('data-half-parent', `E0105-${String(halfTileIdE - 2 + subRow).padStart(2, '0')}`);
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.backgroundColor = colors[(subRow * 2 + subCol) % colors.length];
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          tile.style.zIndex = '2';
          tile.dataset.originalColor = colors[(subRow * 2 + subCol) % colors.length];
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Add hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.4)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
          quarterTileIdE++;
        }
      }
      
      shapeE.appendChild(meterContainer);
      meterContainerIdE++;
    }
  }
  
  // Make shape E relative positioned to contain absolute tiles
  shapeE.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape E`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Grid system for rectangle F - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeF() {
  const shapeF = document.querySelector('.F');
  
  // Shape F dimensions: columns 34-38 (5 wide), rows 2-5 (4 tall)
  const startCol = 34;
  const endCol = 38;
  const startRow = 2;
  const endRow = 5;
  
  // Clear existing content
  shapeF.innerHTML = '';
  
  let meterContainerIdF = 1;
  let halfTileIdF = 1;
  let quarterTileIdF = 1;
  
  // Create finest subdivided grid tiles for shape F (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.id = `F0101-${String(meterContainerIdF).padStart(2, '0')}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1');
      meterContainer.setAttribute('data-parent', 'F');
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      meterContainer.style.cursor = 'pointer';
      meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
      
      // Add click handler for 1m x 1m container
      meterContainer.addEventListener('click', function(e) {
        e.stopPropagation();
        console.log(`Clicked container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
        // Add visual feedback
        const originalBorder = this.style.border;
        this.style.border = '2px solid rgba(255, 255, 0, 0.8)';
        setTimeout(() => {
          this.style.border = originalBorder;
        }, 500);
      });
      
      // Create two 1m x 0.5m sections within each 1m x 1m container
      for (let halfSection = 0; halfSection < 2; halfSection++) {
        const halfTile = document.createElement('div');
        halfTile.className = 'half-tile';
        halfTile.id = `F0105-${String(halfTileIdF).padStart(2, '0')}`;
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', meterContainer.id);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0';
        halfTile.style.top = `${halfSection * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.cursor = 'pointer';
        halfTile.style.zIndex = '1';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
        
        // Add click handler for 1m x 0.5m tile
        halfTile.addEventListener('click', function(e) {
          e.stopPropagation();
          console.log(`Clicked half-tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
          // Add visual feedback
          const originalBg = this.style.backgroundColor;
          this.style.backgroundColor = 'rgba(255, 255, 0, 0.3)';
          setTimeout(() => {
            this.style.backgroundColor = originalBg;
          }, 300);
        });
        
        meterContainer.appendChild(halfTile);
        halfTileIdF++;
      }
      
      // Create four 0.5m x 0.5m tiles within each 1m x 1m container
      const colors = ['rgba(255,255,255,0.1)', 'rgba(255,255,255,0.15)', 'rgba(255,255,255,0.2)', 'rgba(255,255,255,0.25)'];
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          tile.className = 'quarter-tile';
          tile.id = `F0505-${String(quarterTileIdF).padStart(2, '0')}`;
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', meterContainer.id);
          tile.setAttribute('data-half-parent', `F0105-${String(halfTileIdF - 2 + subRow).padStart(2, '0')}`);
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.backgroundColor = colors[(subRow * 2 + subCol) % colors.length];
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          tile.style.zIndex = '2';
          tile.dataset.originalColor = colors[(subRow * 2 + subCol) % colors.length];
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Add hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.4)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
          quarterTileIdF++;
        }
      }
      
      shapeF.appendChild(meterContainer);
      meterContainerIdF++;
    }
  }
  
  // Make shape F relative positioned to contain absolute tiles
  shapeF.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape F`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Grid system for rectangle G - 0.5m x 0.5m subdivisions (maximum flexibility)
function createGridForShapeG() {
  const shapeG = document.querySelector('.G');
  
  // Shape G dimensions: columns 34-41 (8 wide), rows 6-20 (15 tall)
  const startCol = 34;
  const endCol = 41;
  const startRow = 6;
  const endRow = 20;
  
  // Clear existing content
  shapeG.innerHTML = '';
  
  let meterContainerIdG = 1;
  let halfTileIdG = 1;
  let quarterTileIdG = 1;
  
  // Create finest subdivided grid tiles for shape G (0.5m x 0.5m)
  for (let row = startRow; row <= endRow; row++) {
    for (let col = startCol; col <= endCol; col++) {
      // Create a 1m x 1m container with thin border
      const meterContainer = document.createElement('div');
      meterContainer.className = 'meter-container';
      meterContainer.id = `G0101-${String(meterContainerIdG).padStart(2, '0')}`;
      meterContainer.setAttribute('data-type', '1x1');
      meterContainer.setAttribute('data-area', '1');
      meterContainer.setAttribute('data-parent', 'G');
      meterContainer.style.position = 'absolute';
      meterContainer.style.left = `${((col - startCol) / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.top = `${((row - startRow) / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.width = `${(1 / (endCol - startCol + 1)) * 100}%`;
      meterContainer.style.height = `${(1 / (endRow - startRow + 1)) * 100}%`;
      meterContainer.style.border = '1px solid rgba(0,0,0,0.4)';
      meterContainer.style.boxSizing = 'border-box';
      meterContainer.style.cursor = 'pointer';
      meterContainer.title = `Tile ${meterContainer.id} (Type: 1x1m, Area: 1.0m²)`;
      
      // Add click handler for 1m x 1m container
      meterContainer.addEventListener('click', function(e) {
        e.stopPropagation();
        console.log(`Clicked container: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m²)`);
        // Add visual feedback
        const originalBorder = this.style.border;
        this.style.border = '2px solid rgba(255, 255, 0, 0.8)';
        setTimeout(() => {
          this.style.border = originalBorder;
        }, 500);
      });
      
      // Create two 1m x 0.5m sections within each 1m x 1m container
      for (let halfSection = 0; halfSection < 2; halfSection++) {
        const halfTile = document.createElement('div');
        halfTile.className = 'half-tile';
        halfTile.id = `G0105-${String(halfTileIdG).padStart(2, '0')}`;
        halfTile.setAttribute('data-type', '1x0.5');
        halfTile.setAttribute('data-area', '0.5');
        halfTile.setAttribute('data-parent', meterContainer.id);
        halfTile.style.position = 'absolute';
        halfTile.style.left = '0';
        halfTile.style.top = `${halfSection * 50}%`;
        halfTile.style.width = '100%';
        halfTile.style.height = '50%';
        halfTile.style.boxSizing = 'border-box';
        halfTile.style.cursor = 'pointer';
        halfTile.style.zIndex = '1';
        halfTile.title = `Tile ${halfTile.id} (Type: 1x0.5m, Area: 0.5m²)`;
        
        // Add click handler for 1m x 0.5m tile
        halfTile.addEventListener('click', function(e) {
          e.stopPropagation();
          console.log(`Clicked half-tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
          // Add visual feedback
          const originalBg = this.style.backgroundColor;
          this.style.backgroundColor = 'rgba(255, 255, 0, 0.3)';
          setTimeout(() => {
            this.style.backgroundColor = originalBg;
          }, 300);
        });
        
        meterContainer.appendChild(halfTile);
        halfTileIdG++;
      }
      
      // Create four 0.5m x 0.5m tiles within each 1m x 1m container
      const colors = ['rgba(255,255,255,0.1)', 'rgba(255,255,255,0.15)', 'rgba(255,255,255,0.2)', 'rgba(255,255,255,0.25)'];
      for (let subRow = 0; subRow < 2; subRow++) {
        for (let subCol = 0; subCol < 2; subCol++) {
          const tile = document.createElement('div');
          tile.className = 'quarter-tile';
          tile.id = `G0505-${String(quarterTileIdG).padStart(2, '0')}`;
          tile.setAttribute('data-type', '0.5x0.5');
          tile.setAttribute('data-area', '0.25');
          tile.setAttribute('data-parent', meterContainer.id);
          tile.setAttribute('data-half-parent', `G0105-${String(halfTileIdG - 2 + subRow).padStart(2, '0')}`);
          tile.style.position = 'absolute';
          tile.style.left = `${subCol * 50}%`;
          tile.style.top = `${subRow * 50}%`;
          tile.style.width = '50%';
          tile.style.height = '50%';
          tile.style.backgroundColor = colors[(subRow * 2 + subCol) % colors.length];
          tile.style.boxSizing = 'border-box';
          tile.style.cursor = 'pointer';
          tile.style.zIndex = '2';
          tile.dataset.originalColor = colors[(subRow * 2 + subCol) % colors.length];
          tile.title = `Tile ${tile.id} (Type: 0.5x0.5m, Area: 0.25m²)`;
          
          // Add hover effects
          tile.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(255, 255, 255, 0.4)';
          });
          
          tile.addEventListener('mouseleave', function() {
            this.style.backgroundColor = this.dataset.originalColor;
          });
          
          // Add click handler with new naming system
          tile.addEventListener('click', function(e) {
            e.stopPropagation();
            console.log(`Clicked tile: ${this.id} (Type: ${this.getAttribute('data-type')}, Area: ${this.getAttribute('data-area')}m², Parent: ${this.getAttribute('data-parent')})`);
            // Add visual feedback
            const originalBg = tile.style.backgroundColor;
            tile.style.backgroundColor = 'rgba(255, 255, 0, 0.5)';
            setTimeout(() => {
              tile.style.backgroundColor = originalBg;
            }, 300);
          });
          
          meterContainer.appendChild(tile);
          quarterTileIdG++;
        }
      }
      
      shapeG.appendChild(meterContainer);
      meterContainerIdG++;
    }
  }
  
  // Make shape G relative positioned to contain absolute tiles
  shapeG.style.position = 'relative';
  
  const totalTiles = (endCol - startCol + 1) * (endRow - startRow + 1) * 4;
  console.log(`Created ${totalTiles} quarter-meter tiles (0.5m x 0.5m = 0.25m²) for shape G`);
  console.log('Grid flexibility: 1m x 1m, 1m x 0.5m, and 0.5m x 0.5m sections available');
}

// Initialize grid after page load
document.addEventListener('DOMContentLoaded', function() {
  createGridForShapeA();
  createGridForShapeB();
  createGridForShapeC();
  createGridForShapeD();
  createGridForShapeE();
  createGridForShapeF();
  createGridForShapeG();
  console.log('Church floor map loaded with complete grid system for all shapes A, B, C, D, E, F, and G');
});
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="live-grid-sync.js" defer></script>

<script>
// Fullscreen functionality
document.addEventListener('DOMContentLoaded', function() {
    const fsBtn = document.getElementById('fullscreenBtn');
    let fsHideTimer;

    function isFullscreen() {
        return !!(document.fullscreenElement || document.webkitFullscreenElement || 
                 document.mozFullScreenElement || document.msFullscreenElement);
    }

    async function toggleFullscreen() {
        try {
            if (!isFullscreen()) {
                const el = document.documentElement;
                if (el.requestFullscreen) await el.requestFullscreen();
                else if (el.webkitRequestFullscreen) await el.webkitRequestFullscreen();
                else if (el.msRequestFullscreen) await el.msRequestFullscreen();
                fsBtn.querySelector('i').className = 'fas fa-compress';
                fsBtn.querySelector('.label').textContent = 'Exit';
            } else {
                if (document.exitFullscreen) await document.exitFullscreen();
                else if (document.webkitExitFullscreen) await document.webkitExitFullscreen();
                else if (document.mozCancelFullScreen) await document.mozCancelFullScreen();
                else if (document.msExitFullscreen) await document.msExitFullscreen();
                fsBtn.querySelector('i').className = 'fas fa-expand';
                fsBtn.querySelector('.label').textContent = 'Fullscreen';
            }
        } catch (error) {
            console.warn('Fullscreen toggle failed:', error);
        }
    }

    fsBtn.addEventListener('click', toggleFullscreen);

    // Keyboard shortcut: F to toggle fullscreen
    document.addEventListener('keydown', (e) => {
        if (e.key.toLowerCase() === 'f') {
            e.preventDefault();
            toggleFullscreen();
        }
    });

    // Double-click anywhere to toggle fullscreen
    document.addEventListener('dblclick', (e) => {
        // Ignore double-clicks on links/buttons/inputs
        if (e.target.closest('a,button,input,textarea,select')) return;
        toggleFullscreen();
    });

    // Auto-hide fullscreen button after 3s of inactivity
    function scheduleFsHide() {
        if (fsHideTimer) clearTimeout(fsHideTimer);
        fsBtn.classList.remove('hidden');
        fsHideTimer = setTimeout(() => fsBtn.classList.add('hidden'), 3000);
    }
    ['mousemove','touchstart','keydown'].forEach(evt => document.addEventListener(evt, scheduleFsHide));
    scheduleFsHide();
});
</script>

</body>
</html>
