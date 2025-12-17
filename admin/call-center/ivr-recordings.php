<?php
/**
 * IVR Voice Recordings Management
 * 
 * Record, manage, and configure voice recordings for the IVR system
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/auth.php';
require_login();

$db = db();
$pageTitle = 'IVR Voice Recordings';

// Ensure tables exist
ensureTablesExist($db);

// Get all recordings grouped by category
$recordings = getRecordingsByCategory($db);

// Get statistics
$stats = getRecordingStats($db);

function ensureTablesExist($db) {
    $check = $db->query("SHOW TABLES LIKE 'ivr_voice_recordings'");
    if ($check->num_rows === 0) {
        // Run the SQL file
        $sqlFile = __DIR__ . '/../../database/ivr_voice_recordings.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $db->multi_query($sql);
            while ($db->next_result()) {;}
        }
    }
}

function getRecordingsByCategory($db) {
    $result = $db->query("
        SELECT * FROM ivr_voice_recordings 
        ORDER BY category, sort_order, title
    ");
    
    $recordings = [];
    while ($row = $result->fetch_assoc()) {
        $recordings[$row['category']][] = $row;
    }
    return $recordings;
}

function getRecordingStats($db) {
    $stats = [
        'total' => 0,
        'with_recording' => 0,
        'using_tts' => 0,
        'active' => 0
    ];
    
    $result = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN file_path IS NOT NULL AND file_path != '' THEN 1 ELSE 0 END) as with_recording,
            SUM(CASE WHEN use_recording = 1 THEN 1 ELSE 0 END) as using_recording,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
        FROM ivr_voice_recordings
    ");
    
    if ($row = $result->fetch_assoc()) {
        $stats['total'] = (int)$row['total'];
        $stats['with_recording'] = (int)$row['with_recording'];
        $stats['using_tts'] = $stats['total'] - (int)$row['using_recording'];
        $stats['active'] = (int)$row['active'];
    }
    
    return $stats;
}

$categoryNames = [
    'welcome' => '👋 Welcome Messages',
    'menu' => '📋 Menu Options',
    'donor' => '💝 Donor Menu',
    'general' => '📞 General Caller Menu',
    'payment' => '💳 Payment Options',
    'contact' => '📱 Contact & SMS',
    'error' => '⚠️ Error Messages',
    'custom' => '🎯 Custom Messages'
];

$categoryColors = [
    'welcome' => '#10b981',
    'menu' => '#3b82f6',
    'donor' => '#8b5cf6',
    'general' => '#06b6d4',
    'payment' => '#f59e0b',
    'contact' => '#ec4899',
    'error' => '#ef4444',
    'custom' => '#6b7280'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1e293b;
            --light: #f8fafc;
            --border: #e2e8f0;
        }
        
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .page-container {
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin: 0;
        }
        
        .page-subtitle {
            color: #64748b;
            margin: 0.5rem 0 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .category-section {
            background: white;
            border-radius: 20px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .category-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .category-header:hover {
            background: #f8fafc;
        }
        
        .category-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .category-badge {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .recording-list {
            padding: 0;
        }
        
        .recording-item {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: center;
            transition: background 0.2s;
        }
        
        .recording-item:last-child {
            border-bottom: none;
        }
        
        .recording-item:hover {
            background: #f8fafc;
        }
        
        .recording-info h4 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark);
            margin: 0 0 0.25rem;
        }
        
        .recording-info p {
            font-size: 0.875rem;
            color: #64748b;
            margin: 0;
        }
        
        .recording-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-weight: 500;
        }
        
        .status-recorded {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-tts {
            background: #fef3c7;
            color: #92400e;
        }
        
        .recording-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-action {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-record {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }
        
        .btn-record:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .btn-play {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-play:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .btn-edit {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
        }
        
        .btn-edit:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }
        
        /* Recording Modal */
        .modal-content {
            border: none;
            border-radius: 20px;
            overflow: hidden;
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1.5rem;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .recording-preview {
            background: #f8fafc;
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .waveform-container {
            height: 80px;
            background: linear-gradient(135deg, #1e293b, #334155);
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .waveform-bars {
            display: flex;
            gap: 3px;
            align-items: center;
            height: 60px;
        }
        
        .waveform-bar {
            width: 4px;
            background: linear-gradient(to top, #6366f1, #a78bfa);
            border-radius: 2px;
            transition: height 0.1s;
        }
        
        .record-btn-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            font-size: 2rem;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 8px 30px rgba(239, 68, 68, 0.4);
        }
        
        .record-btn-large:hover {
            transform: scale(1.1);
        }
        
        .record-btn-large.recording {
            animation: pulse 1s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .recording-timer {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin: 1rem 0;
            font-family: 'SF Mono', 'Monaco', monospace;
        }
        
        .recording-controls {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .fallback-text {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .fallback-text label {
            font-weight: 600;
            color: #92400e;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-switch-lg .form-check-input {
            width: 3rem;
            height: 1.5rem;
        }
        
        .toggle-mode {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: #f8fafc;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        
        .back-btn:hover {
            opacity: 1;
            color: white;
        }
        
        audio {
            width: 100%;
            margin-top: 1rem;
        }
        
        .text-preview {
            background: #f1f5f9;
            padding: 1rem;
            border-radius: 8px;
            font-style: italic;
            color: #475569;
            margin-top: 0.5rem;
        }
        
        .tts-preview {
            background: #f8fafc;
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            border-left: 3px solid #f59e0b;
        }
        
        .recording-item .form-check-input {
            width: 2.5rem;
            height: 1.25rem;
            cursor: pointer;
        }
        
        .recording-item .form-check-input:checked {
            background-color: var(--success);
            border-color: var(--success);
        }
        
        .recording-item .form-check-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .custom-toast {
            background: white;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }
        
        .custom-toast.success {
            border-left: 4px solid var(--success);
        }
        
        .custom-toast.error {
            border-left: 4px solid var(--danger);
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <a href="../donor-management/twilio/index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Call Dashboard
        </a>
        
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-microphone-alt text-primary me-2"></i>
                        IVR Voice Recordings
                    </h1>
                    <p class="page-subtitle">Record your own voice messages for the phone system</p>
                </div>
                <div>
                    <button class="btn btn-primary btn-lg" onclick="testIVR()">
                        <i class="fas fa-phone-alt me-2"></i> Test IVR
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Messages</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success);"><?php echo $stats['with_recording']; ?></div>
                <div class="stat-label">Recorded</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning);"><?php echo $stats['using_tts']; ?></div>
                <div class="stat-label">Using TTS</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--primary);"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Active</div>
            </div>
        </div>
        
        <!-- Recording Categories -->
        <?php foreach ($recordings as $category => $items): ?>
        <div class="category-section">
            <div class="category-header" onclick="toggleCategory('<?php echo $category; ?>')">
                <h3 class="category-title">
                    <span style="color: <?php echo $categoryColors[$category] ?? '#6b7280'; ?>">
                        <?php echo $categoryNames[$category] ?? ucfirst($category); ?>
                    </span>
                    <span class="category-badge"><?php echo count($items); ?></span>
                </h3>
                <i class="fas fa-chevron-down" id="icon-<?php echo $category; ?>"></i>
            </div>
            <div class="recording-list" id="list-<?php echo $category; ?>">
                <?php foreach ($items as $recording): ?>
                <div class="recording-item" id="recording-item-<?php echo $recording['id']; ?>">
                    <div class="recording-info">
                        <h4><?php echo htmlspecialchars($recording['title']); ?></h4>
                        <p><?php echo htmlspecialchars($recording['description']); ?></p>
                        
                        <!-- Status & Toggle -->
                        <div class="recording-status d-flex align-items-center gap-3 flex-wrap">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       id="toggle-<?php echo $recording['id']; ?>" 
                                       <?php echo ($recording['file_path'] && $recording['use_recording']) ? 'checked' : ''; ?>
                                       <?php echo !$recording['file_path'] ? 'disabled' : ''; ?>
                                       onchange="toggleUseRecording(<?php echo $recording['id']; ?>, this.checked)">
                                <label class="form-check-label small" for="toggle-<?php echo $recording['id']; ?>">
                                    <?php if ($recording['file_path'] && $recording['use_recording']): ?>
                                        <span class="status-badge status-recorded">
                                            <i class="fas fa-microphone me-1"></i> Using Recording
                                        </span>
                                    <?php elseif ($recording['file_path']): ?>
                                        <span class="status-badge status-tts">
                                            <i class="fas fa-robot me-1"></i> Using TTS (has recording)
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-tts">
                                            <i class="fas fa-robot me-1"></i> Using TTS
                                        </span>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php if ($recording['file_path'] && $recording['duration_seconds']): ?>
                            <span class="text-muted small">
                                <i class="fas fa-clock me-1"></i><?php echo number_format($recording['duration_seconds'], 1); ?>s
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- TTS Fallback Text Preview -->
                        <?php if ($recording['fallback_text']): ?>
                        <div class="tts-preview mt-2">
                            <small class="text-muted">
                                <i class="fas fa-comment-alt me-1"></i>
                                <em>"<?php echo htmlspecialchars(substr($recording['fallback_text'], 0, 100)); ?><?php echo strlen($recording['fallback_text']) > 100 ? '...' : ''; ?>"</em>
                            </small>
                        </div>
                        <?php else: ?>
                        <div class="tts-preview mt-2">
                            <small class="text-warning">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <em>No TTS fallback text set</em>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="recording-actions">
                        <?php if ($recording['file_path']): ?>
                        <button class="btn-action btn-play" onclick="playRecording(<?php echo $recording['id']; ?>, '<?php echo htmlspecialchars($recording['file_url']); ?>')" title="Play Recording">
                            <i class="fas fa-play"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn-action btn-record" onclick="openRecordModal(<?php echo htmlspecialchars(json_encode($recording)); ?>)" title="Record New">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button class="btn-action btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($recording)); ?>)" title="Edit Settings">
                            <i class="fas fa-cog"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Recording Modal -->
    <div class="modal fade" id="recordModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-microphone me-2"></i>
                        <span id="modalTitle">Record Voice Message</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="recordingId">
                    
                    <div class="toggle-mode">
                        <label class="form-check-label fw-bold">Use Recording</label>
                        <div class="form-check form-switch form-switch-lg">
                            <input class="form-check-input" type="checkbox" id="useRecording" onchange="toggleRecordingMode()">
                        </div>
                        <span class="text-muted" id="modeLabel">Currently using TTS</span>
                    </div>
                    
                    <div class="recording-preview">
                        <div class="waveform-container" id="waveformContainer">
                            <div class="waveform-bars" id="waveformBars">
                                <!-- Bars will be generated by JS -->
                            </div>
                        </div>
                        
                        <div class="recording-timer" id="recordingTimer">00:00</div>
                        
                        <button class="record-btn-large" id="recordBtn" onclick="toggleRecording()">
                            <i class="fas fa-microphone"></i>
                        </button>
                        
                        <p class="text-muted mt-3" id="recordingHint">Click to start recording</p>
                        
                        <audio id="audioPreview" controls style="display: none;"></audio>
                    </div>
                    
                    <div class="recording-controls" id="postRecordControls" style="display: none;">
                        <button class="btn btn-outline-danger" onclick="discardRecording()">
                            <i class="fas fa-trash me-2"></i> Discard
                        </button>
                        <button class="btn btn-success" onclick="saveRecording()">
                            <i class="fas fa-save me-2"></i> Save Recording
                        </button>
                    </div>
                    
                    <!-- Always visible save button for settings -->
                    <div class="mt-3 text-center" id="saveSettingsBtn">
                        <button class="btn btn-primary btn-lg" onclick="saveSettings()">
                            <i class="fas fa-save me-2"></i> Save Settings
                        </button>
                        <p class="text-muted small mt-2">Saves the toggle and fallback text settings</p>
                    </div>
                    
                    <div class="upload-section mt-4 p-3 bg-light rounded">
                        <h6 class="mb-3"><i class="fas fa-upload me-2"></i> Or Upload MP3 File</h6>
                        <p class="text-muted small mb-2">For best quality, upload a pre-recorded MP3 file (recommended)</p>
                        <input type="file" class="form-control" id="audioFileUpload" accept="audio/mpeg,audio/mp3,audio/wav,.mp3,.wav" onchange="handleFileUpload(event)">
                    </div>
                    
                    <div class="fallback-text">
                        <label><i class="fas fa-robot me-1"></i> Fallback Text (TTS)</label>
                        <textarea class="form-control" id="fallbackText" rows="3" placeholder="Text to read if no recording is available..."></textarea>
                        <div class="text-preview mt-2" id="textPreview"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Audio Player (hidden) -->
    <audio id="globalAudioPlayer" style="display: none;"></audio>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        let recordingStartTime = null;
        let timerInterval = null;
        let currentRecordingId = null;
        let recordedBlob = null;
        
        // Generate waveform bars
        function generateWaveformBars() {
            const container = document.getElementById('waveformBars');
            container.innerHTML = '';
            for (let i = 0; i < 50; i++) {
                const bar = document.createElement('div');
                bar.className = 'waveform-bar';
                bar.style.height = '10px';
                container.appendChild(bar);
            }
        }
        
        generateWaveformBars();
        
        // Toggle category visibility
        function toggleCategory(category) {
            const list = document.getElementById('list-' + category);
            const icon = document.getElementById('icon-' + category);
            
            if (list.style.display === 'none') {
                list.style.display = 'block';
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-down');
            } else {
                list.style.display = 'none';
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-right');
            }
        }
        
        // Open recording modal
        function openRecordModal(recording) {
            currentRecordingId = recording.id;
            document.getElementById('recordingId').value = recording.id;
            document.getElementById('modalTitle').textContent = 'Record: ' + recording.title;
            document.getElementById('fallbackText').value = recording.fallback_text || '';
            document.getElementById('textPreview').textContent = recording.fallback_text || '';
            document.getElementById('useRecording').checked = recording.use_recording == 1;
            toggleRecordingMode();
            
            // Reset recording state
            resetRecordingState();
            
            // Show existing recording if available
            if (recording.file_url) {
                document.getElementById('audioPreview').src = recording.file_url;
                document.getElementById('audioPreview').style.display = 'block';
            }
            
            new bootstrap.Modal(document.getElementById('recordModal')).show();
        }
        
        // Toggle recording mode display in modal
        function toggleRecordingMode() {
            const useRec = document.getElementById('useRecording').checked;
            document.getElementById('modeLabel').textContent = useRec ? 'Using your recording' : 'Using TTS voice';
        }
        
        // Toggle use_recording via AJAX (instant save)
        async function toggleUseRecording(recordingId, useRecording) {
            const toggle = document.getElementById('toggle-' + recordingId);
            const originalState = !useRecording;
            
            try {
                const formData = new FormData();
                formData.append('recording_id', recordingId);
                formData.append('use_recording', useRecording ? '1' : '0');
                formData.append('toggle_only', '1');
                
                const response = await fetch('api/save-ivr-recording.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('success', useRecording ? 'Now using recording' : 'Now using TTS voice');
                    
                    // Update the label
                    const label = toggle.nextElementSibling;
                    if (useRecording) {
                        label.innerHTML = '<span class="status-badge status-recorded"><i class="fas fa-microphone me-1"></i> Using Recording</span>';
                    } else {
                        label.innerHTML = '<span class="status-badge status-tts"><i class="fas fa-robot me-1"></i> Using TTS (has recording)</span>';
                    }
                } else {
                    toggle.checked = originalState;
                    showToast('error', 'Error: ' + result.error);
                }
            } catch (err) {
                toggle.checked = originalState;
                showToast('error', 'Failed to save: ' + err.message);
            }
        }
        
        // Show toast notification
        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = 'custom-toast ' + type;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle text-success' : 'exclamation-circle text-danger'}"></i>
                <span>${message}</span>
            `;
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Toggle recording
        async function toggleRecording() {
            if (isRecording) {
                stopRecording();
            } else {
                await startRecording();
            }
        }
        
        // Start recording
        async function startRecording() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream);
                audioChunks = [];
                
                mediaRecorder.ondataavailable = (event) => {
                    audioChunks.push(event.data);
                };
                
                mediaRecorder.onstop = () => {
                    recordedBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const audioUrl = URL.createObjectURL(recordedBlob);
                    document.getElementById('audioPreview').src = audioUrl;
                    document.getElementById('audioPreview').style.display = 'block';
                    document.getElementById('postRecordControls').style.display = 'flex';
                    
                    // Stop all tracks
                    stream.getTracks().forEach(track => track.stop());
                };
                
                mediaRecorder.start();
                isRecording = true;
                recordingStartTime = Date.now();
                
                // Update UI
                document.getElementById('recordBtn').classList.add('recording');
                document.getElementById('recordBtn').innerHTML = '<i class="fas fa-stop"></i>';
                document.getElementById('recordingHint').textContent = 'Click to stop recording';
                
                // Start timer
                timerInterval = setInterval(updateTimer, 100);
                
                // Animate waveform
                animateWaveform();
                
            } catch (err) {
                alert('Could not access microphone. Please allow microphone access.');
                console.error('Microphone error:', err);
            }
        }
        
        // Stop recording
        function stopRecording() {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                isRecording = false;
                
                // Update UI
                document.getElementById('recordBtn').classList.remove('recording');
                document.getElementById('recordBtn').innerHTML = '<i class="fas fa-microphone"></i>';
                document.getElementById('recordingHint').textContent = 'Recording complete! Click to re-record';
                
                clearInterval(timerInterval);
            }
        }
        
        // Update timer display
        function updateTimer() {
            const elapsed = Date.now() - recordingStartTime;
            const seconds = Math.floor(elapsed / 1000);
            const minutes = Math.floor(seconds / 60);
            const displaySeconds = seconds % 60;
            document.getElementById('recordingTimer').textContent = 
                String(minutes).padStart(2, '0') + ':' + String(displaySeconds).padStart(2, '0');
        }
        
        // Animate waveform bars
        function animateWaveform() {
            const bars = document.querySelectorAll('.waveform-bar');
            
            function animate() {
                if (!isRecording) return;
                
                bars.forEach(bar => {
                    const height = Math.random() * 50 + 10;
                    bar.style.height = height + 'px';
                });
                
                requestAnimationFrame(animate);
            }
            
            animate();
        }
        
        // Reset recording state
        function resetRecordingState() {
            isRecording = false;
            recordedBlob = null;
            document.getElementById('recordBtn').classList.remove('recording');
            document.getElementById('recordBtn').innerHTML = '<i class="fas fa-microphone"></i>';
            document.getElementById('recordingHint').textContent = 'Click to start recording';
            document.getElementById('recordingTimer').textContent = '00:00';
            document.getElementById('postRecordControls').style.display = 'none';
            document.getElementById('audioPreview').style.display = 'none';
            clearInterval(timerInterval);
        }
        
        // Discard recording
        function discardRecording() {
            resetRecordingState();
        }
        
        // Save recording
        async function saveRecording() {
            if (!recordedBlob && !document.getElementById('useRecording').checked) {
                // Just saving TTS settings
                await saveSettings();
                return;
            }
            
            const formData = new FormData();
            formData.append('recording_id', currentRecordingId);
            formData.append('use_recording', document.getElementById('useRecording').checked ? '1' : '0');
            formData.append('fallback_text', document.getElementById('fallbackText').value);
            
            if (recordedBlob) {
                formData.append('audio', recordedBlob, 'recording.webm');
            }
            
            try {
                const response = await fetch('api/save-ivr-recording.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Recording saved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (err) {
                alert('Error saving recording: ' + err.message);
            }
        }
        
        // Save just settings (no new recording)
        async function saveSettings() {
            const btn = document.querySelector('#saveSettingsBtn button');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
            btn.disabled = true;
            
            const formData = new FormData();
            formData.append('recording_id', currentRecordingId);
            formData.append('use_recording', document.getElementById('useRecording').checked ? '1' : '0');
            formData.append('fallback_text', document.getElementById('fallbackText').value);
            
            try {
                const response = await fetch('api/save-ivr-recording.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showToast('success', 'Settings saved successfully!');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    showToast('error', 'Error: ' + result.error);
                }
            } catch (err) {
                btn.innerHTML = originalText;
                btn.disabled = false;
                showToast('error', 'Error: ' + err.message);
            }
        }
        
        // Play recording
        function playRecording(id, url) {
            const player = document.getElementById('globalAudioPlayer');
            player.src = url;
            player.play();
        }
        
        // Open edit modal (for settings)
        function openEditModal(recording) {
            openRecordModal(recording);
        }
        
        // Test IVR
        function testIVR() {
            alert('To test your IVR:\n\n1. Call your Twilio number\n2. Listen to the recorded messages\n3. Press buttons to navigate the menu\n\nYour recordings will play if enabled!');
        }
        
        // Update text preview on input
        document.getElementById('fallbackText').addEventListener('input', function() {
            document.getElementById('textPreview').textContent = this.value;
        });
        
        // Handle MP3 file upload
        function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            // Check file type
            const validTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav'];
            if (!validTypes.includes(file.type) && !file.name.match(/\.(mp3|wav)$/i)) {
                alert('Please upload an MP3 or WAV file for best Twilio compatibility.');
                event.target.value = '';
                return;
            }
            
            // Preview the uploaded file
            const audioUrl = URL.createObjectURL(file);
            document.getElementById('audioPreview').src = audioUrl;
            document.getElementById('audioPreview').style.display = 'block';
            
            // Store for upload
            uploadedFile = file;
            recordedBlob = null; // Clear any recorded blob
            
            document.getElementById('postRecordControls').style.display = 'flex';
            document.getElementById('recordingHint').textContent = 'MP3 file selected. Click Save to upload.';
        }
        
        let uploadedFile = null;
        
        // Modified save function to handle uploaded files
        async function saveRecording() {
            const formData = new FormData();
            formData.append('recording_id', currentRecordingId);
            formData.append('use_recording', document.getElementById('useRecording').checked ? '1' : '0');
            formData.append('fallback_text', document.getElementById('fallbackText').value);
            
            // Prefer uploaded MP3 over browser recording
            if (uploadedFile) {
                formData.append('audio', uploadedFile, uploadedFile.name);
                formData.append('is_mp3_upload', '1');
            } else if (recordedBlob) {
                formData.append('audio', recordedBlob, 'recording.webm');
                formData.append('is_mp3_upload', '0');
            }
            
            try {
                document.querySelector('.modal-body').style.opacity = '0.5';
                
                const response = await fetch('api/save-ivr-recording.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Recording saved successfully!' + (result.format_note ? '\n\n' + result.format_note : ''));
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                    document.querySelector('.modal-body').style.opacity = '1';
                }
            } catch (err) {
                alert('Error saving recording: ' + err.message);
                document.querySelector('.modal-body').style.opacity = '1';
            }
        }
        
        // Test recording in browser
        function testRecordingInBrowser(url) {
            const audio = new Audio(url);
            audio.play();
        }
        
        // Diagnostic check
        async function checkRecording(key) {
            try {
                const response = await fetch('api/test-ivr-recording.php?key=' + encodeURIComponent(key));
                const result = await response.json();
                console.log('Recording Diagnostic:', result);
                
                let message = 'Recording: ' + result.title + '\n\n';
                message += 'Mode: ' + result.mode + '\n';
                message += 'File exists: ' + (result.checks?.file_exists ? 'Yes' : 'No') + '\n';
                message += 'URL accessible: ' + (result.checks?.url_accessible ? 'Yes' : 'No') + '\n';
                message += 'Twilio compatible: ' + (result.checks?.twilio_compatible ? 'Yes' : 'No') + '\n';
                
                if (result.recommendation) {
                    message += '\nRecommendation: ' + result.recommendation;
                }
                
                alert(message);
            } catch (err) {
                alert('Error checking recording: ' + err.message);
            }
        }
    </script>
</body>
</html>

