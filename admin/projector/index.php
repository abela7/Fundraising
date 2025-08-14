<?php
require_once '../../config/db.php';
require_once '../../shared/auth.php';
require_login();
require_admin();

$current_user = current_user();
$db = db();

// Handle footer message update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_footer') {
    $message = trim($_POST['footer_message'] ?? '');
    $is_visible = isset($_POST['footer_visible']) ? 1 : 0;
    
    if (strlen($message) <= 200) { // Character limit
        $stmt = $db->prepare("UPDATE projector_footer SET message = ?, is_visible = ?, updated_at = NOW() WHERE id = 1");
        $stmt->bind_param("si", $message, $is_visible);
        
        if ($stmt->execute()) {
            $status = $is_visible ? "enabled" : "disabled";
            $success_msg = "Footer message updated and ticker $status successfully!";
        } else {
            $error_msg = "Failed to update footer message.";
        }
    } else {
        $error_msg = "Message is too long. Maximum 200 characters allowed.";
    }
}

// Handle display mode update
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'update_display_mode') {
    $display_mode = trim($_POST['display_mode'] ?? 'amount');
    
    // Validate display mode
    $valid_modes = ['amount', 'sqm', 'both'];
    if (in_array($display_mode, $valid_modes)) {
        $stmt = $db->prepare("UPDATE settings SET projector_display_mode = ? WHERE id = 1");
        $stmt->bind_param("s", $display_mode);
        
        if ($stmt->execute()) {
            $success_msg = "Projector display mode updated successfully!";
        } else {
            $error_msg = "Failed to update display mode.";
        }
    } else {
        $error_msg = "Invalid display mode selected.";
    }
}

// Get current footer message
$result = $db->query("SELECT message, is_visible, updated_at FROM projector_footer WHERE id = 1");
$current_footer = $result->fetch_assoc();
if (!$current_footer) {
    // Create default if not exists
    $db->query("INSERT INTO projector_footer (id, message, is_visible) VALUES (1, 'Every contribution makes a difference. Thank you for your generosity!', 1) ON DUPLICATE KEY UPDATE id=id");
    $current_footer = ['message' => 'Every contribution makes a difference. Thank you for your generosity!', 'is_visible' => 1, 'updated_at' => date('Y-m-d H:i:s')];
}

// Get current settings including display mode
$settings_result = $db->query("SELECT projector_display_mode FROM settings WHERE id = 1");
$current_settings = $settings_result->fetch_assoc();
$current_display_mode = $current_settings['projector_display_mode'] ?? 'amount';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projector Control - Fundraising System</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/theme.css">
    <link rel="stylesheet" href="../assets/admin.css">
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/favicon.svg" type="image/svg+xml">
</head>
<body>
    <div class="admin-wrapper">
        <?php include '../includes/sidebar.php'; ?>
        <div class="admin-content">
            <?php include '../includes/topbar.php'; ?>
            <main class="main-content">
                <div class="container-fluid">
                    
                    <div class="d-flex justify-content-end gap-2 mb-3">
                                <button class="btn btn-primary" onclick="openProjectorView()">
                                    <i class="fas fa-external-link-alt me-2"></i>Open Projector View
                                </button>
                        <button class="btn btn-outline-primary" onclick="openFullscreen()">
                            <i class="fas fa-expand me-2"></i>Open Fullscreen
                        </button>
                    </div>
                    
                    <?php if (isset($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                    <?php endif; ?>

                    <?php if (isset($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_msg) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                    <?php endif; ?>
                    
                    <!-- Main Controls -->
                    <div class="row">
                        <!-- Footer Message Control (Promoted to Front) -->
                        <div class="col-lg-8 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-comment-alt me-2"></i>Footer Message Control
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_footer">
                                        
                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="footer_visible" id="footerVisible" 
                                                       <?= $current_footer['is_visible'] ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-bold" for="footerVisible">
                                                    <i class="fas fa-broadcast-tower me-2"></i>Show Live Update Ticker
                                                </label>
                                            </div>
                                            <small class="text-muted">When enabled, the footer ticker will scroll at the bottom of the projector screen</small>
                            </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Footer Message</label>
                                            <textarea class="form-control" name="footer_message" id="footerMessage" 
                                                      rows="4" maxlength="200" 
                                                      placeholder="Enter footer message (bank details, instructions, etc.)"><?= htmlspecialchars($current_footer['message']) ?></textarea>
                                            <div class="form-text">
                                                <span id="charCount"><?= strlen($current_footer['message']) ?></span>/200 characters
                        </div>
                    </div>
                    
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-save me-2"></i>Update Footer Settings
                                        </button>
                                    </form>
                                    
                                    <hr>
                                    
                                    <div class="current-message">
                                        <h6 class="text-muted mb-2">Current Status:</h6>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="badge <?= $current_footer['is_visible'] ? 'bg-success' : 'bg-secondary' ?>">
                                                <i class="fas <?= $current_footer['is_visible'] ? 'fa-broadcast-tower' : 'fa-pause' ?> me-1"></i>
                                                <?= $current_footer['is_visible'] ? 'LIVE TICKER ON' : 'TICKER OFF' ?>
                                    </span>
                                </div>
                                        <div class="p-2 bg-light rounded small">
                                            <?= htmlspecialchars($current_footer['message']) ?>
                                        </div>
                                        <small class="text-muted">
                                            Last updated: <?= date('M j, Y \a\t g:i A', strtotime($current_footer['updated_at'])) ?>
                                        </small>
                                    </div>
                                    
                                    <hr>
                                    
                                    <!-- Quick Presets -->
                                    <div class="quick-presets">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="text-muted mb-0">Quick Presets:</h6>
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#presetsModal">
                                                <i class="fas fa-plus"></i> Manage
                                            </button>
                                        </div>
                                        <div class="d-grid gap-2" id="presetButtons">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="setPreset('Bank: ABC Bank Ltd. | Account: 12345678 | Sort: 12-34-56')">
                                                <i class="fas fa-university me-1"></i> Bank Details
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="setPreset('Thank you for your generous contributions! Every donation makes a difference.')">
                                                <i class="fas fa-heart me-1"></i> Thank You Message
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="setPreset('ለደግ መልካም ልባቸው እናመሰግናለን! የእርስዎ አስተዋፅዖ ወሳኝ ነው።')">
                                                <i class="fas fa-language me-1"></i> Amharic Thank You
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="setPreset('We are almost at our goal! Thank you for your amazing support!')">
                                                <i class="fas fa-bullhorn me-1"></i> Goal Update
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="setPreset('🎉 GOAL ACHIEVED! Thank you all for making this possible! 🎉')">
                                                <i class="fas fa-trophy me-1"></i> Goal Achieved
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                    onclick="setPreset('')">
                                                <i class="fas fa-times me-1"></i> Clear Message
                                            </button>
                            </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                        <!-- Display Mode Control -->
                        <div class="col-lg-4 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-display me-2"></i>Display Mode Control
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_display_mode">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">How to Show Donations</label>
                                            <select name="display_mode" class="form-select" id="displayModeSelect">
                                                <option value="amount" <?= $current_display_mode === 'amount' ? 'selected' : '' ?>>
                                                    Amount Only (Alemu pledged GBP 400)
                                                </option>
                                                <option value="sqm" <?= $current_display_mode === 'sqm' ? 'selected' : '' ?>>
                                                    Square Meters Only (Alemu pledged 1 Square Meter)
                                                </option>
                                                <option value="both" <?= $current_display_mode === 'both' ? 'selected' : '' ?>>
                                                    Both (Alemu pledged 1 Square Meter (£400))
                                                </option>
                                            </select>
                                            <small class="text-muted">Choose how donations appear on the live projector display</small>
                                </div>
                                        
                                        <div class="current-mode-info mb-3">
                                            <div class="alert alert-info d-flex align-items-center">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <div>
                                                    <strong>Current Mode:</strong> 
                                                    <span id="currentModeText">
                                                        <?php 
                                                        switch($current_display_mode) {
                                                            case 'sqm': echo 'Square Meters Only'; break;
                                                            case 'both': echo 'Both Amount & Square Meters'; break;
                                                            default: echo 'Amount Only'; break;
                                                        }
                                                        ?>
                                                    </span>
                                </div>
                            </div>
                        </div>
                        
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="fas fa-sync-alt me-2"></i>Update Display Mode
                                        </button>
                                    </form>
                                    </div>
                                </div>
                            </div>
                            
                        
                    
                            
                                </div>
                            </div>
                </div>
            </main>
                        </div>
                    </div>
                    
    <!-- Custom Presets Modal -->
    <div class="modal fade" id="presetsModal" tabindex="-1" aria-labelledby="presetsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="presetsModalLabel">
                        <i class="fas fa-bookmark me-2"></i>Manage Quick Presets
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Add New Preset -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-plus me-2"></i>Add New Preset</h6>
                        </div>
                        <div class="card-body">
                            <form id="addPresetForm">
                        <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Preset Name</label>
                                        <input type="text" class="form-control" id="presetName" placeholder="e.g., Bank Details" maxlength="50">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Icon</label>
                                        <select class="form-select" id="presetIcon">
                                            <option value="fas fa-university">🏦 Bank</option>
                                            <option value="fas fa-heart">❤️ Heart</option>
                                            <option value="fas fa-language">🌐 Language</option>
                                            <option value="fas fa-bullhorn">📢 Announcement</option>
                                            <option value="fas fa-trophy">🏆 Trophy</option>
                                            <option value="fas fa-star">⭐ Star</option>
                                            <option value="fas fa-info-circle">ℹ️ Info</option>
                                            <option value="fas fa-church">⛪ Church</option>
                                            <option value="fas fa-hands-helping">🤝 Helping</option>
                                            <option value="fas fa-gift">🎁 Gift</option>
                                        </select>
                                    </div>
                                    <div class="col-md-5 mb-3">
                                        <label class="form-label">Color</label>
                                        <select class="form-select" id="presetColor">
                                            <option value="btn-outline-secondary">⚪ Default</option>
                                            <option value="btn-outline-primary">🔵 Blue</option>
                                            <option value="btn-outline-success">🟢 Green</option>
                                            <option value="btn-outline-warning">🟡 Yellow</option>
                                            <option value="btn-outline-info">🔵 Cyan</option>
                                            <option value="btn-outline-danger">🔴 Red</option>
                                            </select>
                                    </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Message Text</label>
                                    <textarea class="form-control" id="presetMessage" rows="3" maxlength="200" 
                                              placeholder="Enter the preset message..."></textarea>
                                    <div class="form-text"><span id="presetCharCount">0</span>/200 characters</div>
                                        </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Add Preset
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                    <!-- Current Presets -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-list me-2"></i>Current Presets</h6>
                        </div>
                        <div class="card-body">
                            <div id="currentPresets">
                                <div class="d-flex justify-content-between align-items-center p-2 border rounded mb-2">
                                    <div>
                                        <span class="badge bg-secondary"><i class="fas fa-university"></i></span>
                                        <span class="ms-2 fw-bold">Bank Details</span>
                                        <small class="text-muted d-block">Bank: ABC Bank Ltd. | Account: 12345678...</small>
                                        </div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editPreset(0)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deletePreset(0)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        </div>
                                        </div>
                                <div class="d-flex justify-content-between align-items-center p-2 border rounded mb-2">
                                    <div>
                                        <span class="badge bg-secondary"><i class="fas fa-heart"></i></span>
                                        <span class="ms-2 fw-bold">Thank You Message</span>
                                        <small class="text-muted d-block">Thank you for your generous contributions...</small>
                                        </div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editPreset(1)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deletePreset(1)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        </div>
                                    </div>
                                <!-- More presets will be added here dynamically -->
                                </div>
                            </div>
                        </div>
                    </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="savePresets()">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                            </div>
                        </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/admin.js"></script>
    
    <script>
        // Display mode selector
        const displayModeSelect = document.getElementById('displayModeSelect');
        const currentModeText = document.getElementById('currentModeText');
        
        displayModeSelect.addEventListener('change', function() {
            const modeTexts = {
                'amount': 'Amount Only',
                'sqm': 'Square Meters Only', 
                'both': 'Both Amount & Square Meters'
            };
            currentModeText.textContent = modeTexts[this.value] || 'Amount Only';
        });

        // Character counter for main textarea
        const footerMessage = document.getElementById('footerMessage');
        const charCount = document.getElementById('charCount');
        
        footerMessage.addEventListener('input', function() {
            charCount.textContent = this.value.length;
            
            if (this.value.length > 200) {
                charCount.parentElement.classList.add('text-danger');
            } else {
                charCount.parentElement.classList.remove('text-danger');
            }
        });

        // Character counter for preset textarea
        const presetMessage = document.getElementById('presetMessage');
        const presetCharCount = document.getElementById('presetCharCount');
        
        presetMessage.addEventListener('input', function() {
            presetCharCount.textContent = this.value.length;
            
            if (this.value.length > 200) {
                presetCharCount.parentElement.classList.add('text-danger');
            } else {
                presetCharCount.parentElement.classList.remove('text-danger');
            }
        });

        // Custom presets management
        let customPresets = JSON.parse(localStorage.getItem('projectorPresets') || '[]');

        // Set preset message
        function setPreset(message) {
            footerMessage.value = message;
            charCount.textContent = message.length;
            charCount.parentElement.classList.remove('text-danger');
        }

        // Add new preset
        document.getElementById('addPresetForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const name = document.getElementById('presetName').value.trim();
            const icon = document.getElementById('presetIcon').value;
            const color = document.getElementById('presetColor').value;
            const message = document.getElementById('presetMessage').value.trim();
            
            if (!name || !message) {
                alert('Please fill in both name and message fields.');
                return;
            }
            
            const preset = {
                id: Date.now(),
                name: name,
                icon: icon,
                color: color,
                message: message
            };
            
            customPresets.push(preset);
            localStorage.setItem('projectorPresets', JSON.stringify(customPresets));
            
            // Clear form
            document.getElementById('presetName').value = '';
            document.getElementById('presetMessage').value = '';
            presetCharCount.textContent = '0';
            
            // Refresh UI
            updatePresetsUI();
            updateCurrentPresetsDisplay();
        });

        // Update presets UI
        function updatePresetsUI() {
            const container = document.getElementById('presetButtons');
            
            // Clear existing custom presets (keep default ones)
            const customButtons = container.querySelectorAll('[data-custom="true"]');
            customButtons.forEach(btn => btn.remove());
            
            // Add custom presets
            customPresets.forEach(preset => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = `btn btn-sm ${preset.color}`;
                button.setAttribute('data-custom', 'true');
                button.onclick = () => setPreset(preset.message);
                button.innerHTML = `<i class="${preset.icon} me-1"></i> ${preset.name}`;
                
                // Insert before the clear button
                const clearButton = container.querySelector('.btn-outline-danger');
                container.insertBefore(button, clearButton);
            });
        }

        // Update current presets display in modal
        function updateCurrentPresetsDisplay() {
            const container = document.getElementById('currentPresets');
            
            // Clear existing
            container.innerHTML = '';
            
            // Add default presets (non-editable)
            const defaultPresets = [
                { name: 'Bank Details', icon: 'fas fa-university', message: 'Bank: ABC Bank Ltd. | Account: 12345678 | Sort: 12-34-56' },
                { name: 'Thank You Message', icon: 'fas fa-heart', message: 'Thank you for your generous contributions! Every donation makes a difference.' },
                { name: 'Amharic Thank You', icon: 'fas fa-language', message: 'ለደግ መልካም ልባቸው እናመሰግናለን! የእርስዎ አስተዋፅዖ ወሳኝ ነው።' }
            ];
            
            defaultPresets.forEach(preset => {
                const div = document.createElement('div');
                div.className = 'd-flex justify-content-between align-items-center p-2 border rounded mb-2';
                div.innerHTML = `
                    <div>
                        <span class="badge bg-secondary"><i class="${preset.icon}"></i></span>
                        <span class="ms-2 fw-bold">${preset.name}</span>
                        <small class="text-muted d-block">${preset.message.substring(0, 50)}...</small>
                    </div>
                    <div>
                        <span class="badge bg-info">Default</span>
                    </div>
                `;
                container.appendChild(div);
            });
            
            // Add custom presets
            customPresets.forEach((preset, index) => {
                const div = document.createElement('div');
                div.className = 'd-flex justify-content-between align-items-center p-2 border rounded mb-2';
                div.innerHTML = `
                    <div>
                        <span class="badge bg-primary"><i class="${preset.icon}"></i></span>
                        <span class="ms-2 fw-bold">${preset.name}</span>
                        <small class="text-muted d-block">${preset.message.substring(0, 50)}...</small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editPreset(${index})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deletePreset(${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;
                container.appendChild(div);
            });
        }

        // Edit preset
        function editPreset(index) {
            const preset = customPresets[index];
            document.getElementById('presetName').value = preset.name;
            document.getElementById('presetIcon').value = preset.icon;
            document.getElementById('presetColor').value = preset.color;
            document.getElementById('presetMessage').value = preset.message;
            presetCharCount.textContent = preset.message.length;
            
            // Remove the preset temporarily
            customPresets.splice(index, 1);
            updateCurrentPresetsDisplay();
        }

        // Delete preset
        function deletePreset(index) {
            if (confirm('Are you sure you want to delete this preset?')) {
                customPresets.splice(index, 1);
                localStorage.setItem('projectorPresets', JSON.stringify(customPresets));
                updatePresetsUI();
                updateCurrentPresetsDisplay();
            }
        }

        // Save presets
        function savePresets() {
            localStorage.setItem('projectorPresets', JSON.stringify(customPresets));
            updatePresetsUI();
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('presetsModal'));
            modal.hide();
        }

        // Initialize presets on page load
        document.addEventListener('DOMContentLoaded', function() {
            updatePresetsUI();
        });

        // When modal opens, refresh the display
        document.getElementById('presetsModal').addEventListener('shown.bs.modal', function() {
            updateCurrentPresetsDisplay();
        });
        
        // Open projector view
        function openProjectorView() {
            window.open('/fundraising/public/projector/', '_blank');
        }
        
        // Open fullscreen
        function openFullscreen() {
            const url = '/fundraising/public/projector/';
            const win = window.open(url, '_blank');
            if (win) {
                setTimeout(() => {
                    if (win.document.documentElement.requestFullscreen) {
                        win.document.documentElement.requestFullscreen();
                    }
                }, 1000);
            }
        }
        
        // Refresh preview
        function refreshPreview() {
            const iframe = document.getElementById('previewFrame');
            iframe.src = iframe.src;
        }
        
        // Copy link
        function copyLink() {
            const link = document.getElementById('projectorLink');
            link.select();
            document.execCommand('copy');
            
            // Show feedback
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            btn.classList.add('btn-success');
            btn.classList.remove('btn-outline-secondary');
            
            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
            }, 1000);
        }
    </script>
</body>
</html>
