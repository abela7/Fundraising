<?php
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/db.php';

// Get floor layout from database
$db = db();

// Get all cells organized by rectangle and position
$stmt = $db->prepare("
    SELECT 
        cell_id,
        rectangle_id,
        cell_type,
        area_size,
        status,
        donor_name,
        amount,
        assigned_date
    FROM floor_grid_cells 
    ORDER BY rectangle_id, 
             CAST(SUBSTRING(cell_id, 2, 4) AS UNSIGNED),
             CAST(SUBSTRING(cell_id, 7) AS UNSIGNED)
");
$stmt->execute();
$allCells = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Organize cells by rectangle
$cellsByRectangle = [];
foreach ($allCells as $cell) {
    $cellsByRectangle[$cell['rectangle_id']][] = $cell;
}

// Get rectangle layout configuration (from your original design)
$rectangles = [
    'A' => ['width' => 366, 'height' => 146, 'top' => 630, 'left' => 0],
    'B' => ['width' => 40, 'height' => 110, 'top' => 666, 'left' => 366],
    'C' => ['width' => 324, 'height' => 38, 'top' => 592, 'left' => 42],
    'D' => ['width' => 366, 'height' => 210, 'top' => 180, 'left' => 0],
    'E' => ['width' => 274, 'height' => 208, 'top' => 390, 'left' => 92],
    'F' => ['width' => 102, 'height' => 120, 'top' => 60, 'left' => 30],
    'G' => ['width' => 274, 'height' => 180, 'top' => 0, 'left' => 132]
];

// Calculate stats
$totalCells = count($allCells);
$pledgedCells = count(array_filter($allCells, fn($c) => $c['status'] === 'pledged'));
$paidCells = count(array_filter($allCells, fn($c) => $c['status'] === 'paid'));
$blockedCells = count(array_filter($allCells, fn($c) => $c['status'] === 'blocked'));
$progress = $totalCells > 0 ? (($pledgedCells + $paidCells) / $totalCells) * 100 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Church Floor Plan - Live Progress</title>
<style>
        /* Professional floor plan styling matching projector theme */
  :root { 
            --bg-primary: #0a0f1b;
            --bg-secondary: #141b2d;
            --bg-card: #1e2a3e;
            --primary: #0b78a6;
            --primary-light: #0d8fc4;
            --success: #22c55e;
            --warning: #ff8c00;
            --accent: #ffd700;
            --text-primary: #ffffff;
            --text-secondary: #cbd5e1;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
    overflow: hidden;
  }

        .floor-container {
    position: relative;
            width: 90vw;
            max-width: 522px;
            aspect-ratio: 522 / 820;
            background: var(--bg-card);
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .floor-plan {
    position: relative;
            width: 100%;
            height: 100%;
            background: #f8f9fa;
            border-radius: 4px;
            overflow: hidden;
        }

        .rectangle {
            position: absolute;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .cell {
            position: absolute;
            border: 1px solid rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6px;
            font-weight: 600;
            color: rgba(0, 0, 0, 0.6);
            min-width: 8px;
            min-height: 8px;
        }

        .cell:hover {
            border-color: var(--primary);
            z-index: 10;
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(11, 120, 166, 0.3);
        }

        /* Cell status styling */
        .cell.available {
            background: transparent;
        }

        .cell.pledged {
            background: var(--warning);
            color: white;
            border-color: var(--warning);
        }

        .cell.paid {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .cell.blocked {
            background: repeating-linear-gradient(
                45deg,
                #666,
                #666 1px,
                #999 1px,
                #999 2px
            );
            opacity: 0.5;
        }

        /* Progress overlay */
        .progress-overlay {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            backdrop-filter: blur(10px);
            z-index: 100;
            min-width: 200px;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
            overflow: hidden;
            margin: 0.5rem 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--warning), var(--success));
            transition: width 0.3s ease;
        }

        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            font-size: 11px;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .stat-color {
            width: 8px;
            height: 8px;
            border-radius: 2px;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .floor-container {
                width: 95vw;
                padding: 1rem;
            }
        }

        /* Animation for new allocations */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); }
        }

        .cell.new-allocation {
            animation: pulse 0.6s ease-in-out;
        }
    </style>
</head>
<body>
    <div class="floor-container">
        <div class="floor-plan" id="floorPlan">
            <?php foreach ($rectangles as $rectId => $rectConfig): ?>
                <div class="rectangle" 
                     id="rectangle-<?= $rectId ?>"
                     style="
                         width: <?= ($rectConfig['width'] / 522) * 100 ?>%;
                         height: <?= ($rectConfig['height'] / 820) * 100 ?>%;
                         top: <?= ($rectConfig['top'] / 820) * 100 ?>%;
                         left: <?= ($rectConfig['left'] / 522) * 100 ?>%;
                         background: <?= $rectId === 'A' ? '#bfa500' :
                                          ($rectId === 'B' ? '#0b78a6' :
                                          ($rectId === 'C' ? '#141b2d' :
                                          ($rectId === 'D' ? '#ffcc00' :
                                          ($rectId === 'E' ? '#22c55e' :
                                          ($rectId === 'F' ? '#1e2a3e' : '#0d8fc4'))))) ?>;
                     ">
                    
                    <?php if (isset($cellsByRectangle[$rectId])): ?>
                        <?php foreach ($cellsByRectangle[$rectId] as $index => $cell): ?>
                            <?php
                            // Simple grid layout for cells within each rectangle
                            $cellsInRect = count($cellsByRectangle[$rectId]);
                            $cellsPerRow = max(1, (int)sqrt($cellsInRect));
                            $row = floor($index / $cellsPerRow);
                            $col = $index % $cellsPerRow;
                            
                            $cellWidth = 100 / $cellsPerRow;
                            $cellHeight = 100 / ceil($cellsInRect / $cellsPerRow);
                            
                            $leftPercent = $col * $cellWidth;
                            $topPercent = $row * $cellHeight;
                            ?>
                            <div class="cell <?= $cell['status'] ?>"
                                 id="<?= htmlspecialchars($cell['cell_id']) ?>"
                                 data-cell-id="<?= htmlspecialchars($cell['cell_id']) ?>"
                                 data-rectangle="<?= $cell['rectangle_id'] ?>"
                                 data-type="<?= htmlspecialchars($cell['cell_type']) ?>"
                                 data-area="<?= $cell['area_size'] ?>"
                                 data-status="<?= $cell['status'] ?>"
                                 data-donor="<?= htmlspecialchars($cell['donor_name'] ?? '') ?>"
                                 data-amount="<?= $cell['amount'] ?? 0 ?>"
                                 style="
                                     width: <?= $cellWidth - 0.5 ?>%;
                                     height: <?= $cellHeight - 0.5 ?>%;
                                     left: <?= $leftPercent ?>%;
                                     top: <?= $topPercent ?>%;
                                 "
                                 title="<?= htmlspecialchars($cell['cell_id']) ?> - <?= ucfirst($cell['status']) ?><?= $cell['donor_name'] ? ' - ' . $cell['donor_name'] : '' ?>">
                                <span style="font-size: 5px; opacity: 0.8;"><?= substr($cell['cell_id'], -2) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Live Progress Display -->
        <div class="progress-overlay">
            <div style="font-size: 14px; font-weight: 600; margin-bottom: 0.5rem;">
                Church Building Progress
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= number_format($progress, 1) ?>%"></div>
            </div>
            <div style="text-align: center; margin-bottom: 0.5rem; font-size: 16px; font-weight: bold;">
                <?= number_format($progress, 1) ?>% Complete
            </div>
            <div class="stats">
                <div class="stat">
                    <div class="stat-color" style="background: var(--success);"></div>
                    <span>Paid: <?= $paidCells ?></span>
                </div>
                <div class="stat">
                    <div class="stat-color" style="background: var(--warning);"></div>
                    <span>Pledged: <?= $pledgedCells ?></span>
                </div>
                <div class="stat">
                    <div class="stat-color" style="background: #666;"></div>
                    <span>Blocked: <?= $blockedCells ?></span>
                </div>
                <div class="stat">
                    <div class="stat-color" style="background: transparent; border: 1px solid #666;"></div>
                    <span>Total: <?= $totalCells ?></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        /**
         * Professional Floor Plan Controller
         * 100% Database-Driven Live Updates
         */
        class FloorPlanController {
            constructor() {
                this.apiUrl = '/api/grid_status.php';
                this.updateInterval = 2000; // 2 seconds
                this.lastKnownState = new Map();
                this.init();
            }

            init() {
                console.log('🏗️ Professional Floor Plan Controller Starting...');
                this.startLiveUpdates();
                this.addCellInteractions();
            }

            startLiveUpdates() {
                this.updateFromDatabase();
                setInterval(() => this.updateFromDatabase(), this.updateInterval);
            }

            async updateFromDatabase() {
                try {
                    const response = await fetch(this.apiUrl);
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        this.updateCellStates(data.data);
                        console.log('✅ Floor plan updated from database');
                    } else {
                        console.error('❌ API returned error:', data.error);
                    }
                } catch (error) {
                    console.error('❌ Failed to update floor plan:', error);
                }
            }

            updateCellStates(apiData) {
                // Process each rectangle's data
                if (apiData.grid_cells) {
                    Object.values(apiData.grid_cells).forEach(rectangleCells => {
                        rectangleCells.forEach(cellData => {
                            const cellElement = document.getElementById(cellData.cell_id);
                            if (cellElement) {
                                const oldStatus = cellElement.dataset.status;
                                const newStatus = cellData.status;
                                
                                // Update cell if status changed
                                if (oldStatus !== newStatus) {
                                    // Remove old status classes
                                    cellElement.classList.remove('available', 'pledged', 'paid', 'blocked');
                                    
                                    // Add new status
                                    cellElement.classList.add(newStatus);
                                    
                                    // Update data attributes
                                    cellElement.dataset.status = newStatus;
                                    cellElement.dataset.donor = cellData.donor || '';
                                    cellElement.dataset.amount = cellData.amount || 0;
                                    
                                    // Update title
                                    const statusText = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
                                    const donorText = cellData.donor ? ` - ${cellData.donor}` : '';
                                    const amountText = cellData.amount ? ` (£${cellData.amount})` : '';
                                    cellElement.title = `${cellData.cell_id} - ${statusText}${donorText}${amountText}`;
                                    
                                    // Pulse animation for new allocations
                                    if (newStatus !== 'available' && oldStatus === 'available') {
                                        cellElement.classList.add('new-allocation');
                                        setTimeout(() => cellElement.classList.remove('new-allocation'), 600);
                                    }
                                    
                                    console.log(`🔄 Cell ${cellData.cell_id}: ${oldStatus} → ${newStatus}`);
                                }
                                
                                this.lastKnownState.set(cellData.cell_id, newStatus);
                            }
                        });
                    });
                }
            }

            addCellInteractions() {
                document.querySelectorAll('.cell').forEach(cell => {
                    cell.addEventListener('click', () => this.showCellInfo(cell));
                });
            }

            showCellInfo(cell) {
                const info = {
                    cellId: cell.dataset.cellId,
                    rectangle: cell.dataset.rectangle,
                    type: cell.dataset.type,
                    area: `${cell.dataset.area}m²`,
                    status: cell.dataset.status,
                    donor: cell.dataset.donor || 'N/A',
                    amount: cell.dataset.amount ? `£${cell.dataset.amount}` : 'N/A'
                };

                console.log('🔍 Cell Info:', info);
                
                // Flash the cell
                cell.style.transform = 'scale(1.2)';
                setTimeout(() => cell.style.transform = '', 200);
            }
        }

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            window.floorPlanController = new FloorPlanController();
});
</script>
</body>
</html>
