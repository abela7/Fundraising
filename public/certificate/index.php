<?php
declare(strict_types=1);

/**
 * Certificate Preview/Download Page
 * Generates personalized donation certificates
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';

$sqm = $_GET['sqm'] ?? '1';
$reference = $_GET['ref'] ?? '0000';
$format = $_GET['format'] ?? 'view';

// Calculate display value for square meters
function formatSqm(float $value): string {
    if ($value >= 1) {
        return number_format($value, $value == floor($value) ? 0 : 2);
    } elseif ($value == 0.5) {
        return '½';
    } elseif ($value == 0.25) {
        return '¼';
    } elseif ($value == 0.75) {
        return '¾';
    } else {
        return number_format($value, 2);
    }
}

$sqmDisplay = formatSqm((float)$sqm);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donation Certificate - Liverpool Abune Teklehaymanot EOTC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400;1,600&family=Noto+Serif+Ethiopic:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #1a1a2e;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .page-title {
            color: #fff;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            text-align: center;
        }

        .certificate-wrapper {
            background: #fff;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }

        /* ========== CERTIFICATE DESIGN ========== */
        .certificate {
            width: 850px;
            height: 550px;
            background-color: #0e7f8c;
            position: relative;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
        }

        /* Geometric arrow/chevron background pattern */
        .certificate::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                /* Top triangle - lighter teal */
                linear-gradient(
                    to bottom right,
                    #1a9a9a 0%,
                    #1a9a9a 50%,
                    transparent 50%
                ),
                /* Base color */
                #0e7f8c;
            clip-path: polygon(0 0, 45% 0, 60% 55%, 0 100%);
            z-index: 0;
        }

        .certificate::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            /* Bottom angled section - slightly different shade */
            background: #148a8a;
            clip-path: polygon(0 45%, 45% 45%, 60% 100%, 0 100%);
            z-index: 0;
        }

        .certificate-content {
            position: relative;
            z-index: 1;
        }

        /* Top Amharic Quote - small yellow text at very top */
        .top-quote {
            font-family: 'Noto Serif Ethiopic', serif;
            color: #e8c547;
            font-size: 14px;
            text-align: center;
            line-height: 1.5;
            padding: 20px 40px 10px;
        }

        /* Church Name - white, uppercase, spaced */
        .church-name {
            color: #ffffff;
            font-size: 20px;
            font-weight: 600;
            text-align: center;
            letter-spacing: 4px;
            text-transform: uppercase;
            padding: 10px 0 0;
        }

        /* Main Content Area */
        .main-content {
            position: relative;
            height: calc(100% - 120px);
            display: flex;
        }

        /* Left side - Slogan centered */
        .slogan-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding-left: 40px;
        }

        .slogan-amharic {
            font-family: 'Noto Serif Ethiopic', serif;
            color: #e8c547;
            font-size: 72px;
            font-style: italic;
            font-weight: 500;
            line-height: 1.1;
        }

        .slogan-english {
            font-family: 'Playfair Display', serif;
            color: #e8c547;
            font-size: 58px;
            font-style: italic;
            font-weight: 400;
            margin-top: -5px;
        }

        /* Right side - Sq.m boxes */
        .info-boxes {
            width: 200px;
            padding: 30px 30px 0 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .box-group {
            text-align: right;
            margin-bottom: 15px;
        }

        .box-label {
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            text-align: right;
        }

        .value-box {
            background: #f5f5f5;
            border-radius: 25px;
            padding: 12px 30px;
            min-width: 150px;
            text-align: center;
            display: inline-block;
        }

        .sqm-value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
            display: inline;
        }

        .sqm-unit {
            font-size: 16px;
            color: #666;
            vertical-align: super;
            margin-left: 2px;
        }

        .ref-value {
            font-size: 26px;
            font-weight: 700;
            color: #333;
            letter-spacing: 3px;
        }

        /* Bottom Section - QR and Bank Details */
        .bottom-section {
            position: absolute;
            bottom: 25px;
            left: 30px;
            display: flex;
            align-items: flex-end;
            gap: 25px;
        }

        .qr-code {
            width: 110px;
            height: 110px;
            background: #fff;
            border-radius: 8px;
            padding: 8px;
        }

        .qr-code img {
            width: 100%;
            height: 100%;
        }

        .bank-details {
            color: #ffffff;
            font-size: 16px;
            line-height: 1.9;
        }

        .bank-row {
            display: flex;
            gap: 15px;
        }

        .bank-label {
            font-weight: 500;
            min-width: 90px;
        }

        .bank-value {
            font-weight: 700;
            letter-spacing: 1px;
        }

        /* ========== PAGE CONTROLS ========== */
        .download-actions {
            margin-top: 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #0e8b8b;
            color: #fff;
        }

        .btn-primary:hover {
            background: #0a7070;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #e8c547;
            color: #0e8b8b;
        }

        .btn-secondary:hover {
            background: #d4b33e;
            transform: translateY(-2px);
        }

        .btn svg {
            width: 18px;
            height: 18px;
        }

        .test-form {
            background: rgba(255,255,255,0.1);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
            justify-content: center;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            color: #fff;
            font-size: 13px;
            font-weight: 500;
        }

        .form-group input, .form-group select {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            min-width: 120px;
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .page-title, .download-actions, .test-form {
                display: none;
            }
            .certificate-wrapper {
                box-shadow: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <h1 class="page-title">Donation Certificate Preview</h1>

    <!-- Test Form -->
    <form class="test-form" method="GET">
        <div class="form-group">
            <label>Square Meters</label>
            <select name="sqm">
                <option value="0.25" <?= $sqm == '0.25' ? 'selected' : '' ?>>¼ (0.25)</option>
                <option value="0.5" <?= $sqm == '0.5' ? 'selected' : '' ?>>½ (0.5)</option>
                <option value="1" <?= $sqm == '1' ? 'selected' : '' ?>>1</option>
                <option value="2" <?= $sqm == '2' ? 'selected' : '' ?>>2</option>
                <option value="4" <?= $sqm == '4' ? 'selected' : '' ?>>4</option>
            </select>
        </div>
        <div class="form-group">
            <label>Reference Number</label>
            <input type="text" name="ref" value="<?= htmlspecialchars($reference) ?>" maxlength="10">
        </div>
        <button type="submit" class="btn btn-secondary">Update Preview</button>
    </form>

    <!-- Certificate -->
    <div class="certificate-wrapper">
        <div class="certificate" id="certificate">
            <div class="certificate-content">
            
            <!-- Top Amharic Quote -->
            <div class="top-quote">
                "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንፈስት ያዘጋጁልኝ ዘንድ<br>
                አሁ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ::" ፪ ዜና ፪÷ቿ
            </div>

            <!-- Church Name -->
            <div class="church-name">Liverpool Abune Teklehaymanot EOTC</div>

            <!-- Main Content -->
            <div class="main-content">
                <!-- Left - Slogan -->
                <div class="slogan-area">
                    <div class="slogan-amharic">ይህ ታሪኬ ነው</div>
                    <div class="slogan-english">It is My History</div>
                </div>

                <!-- Right - Info Boxes -->
                <div class="info-boxes">
                    <div class="box-group">
                        <div class="box-label">Sq.m</div>
                        <div class="value-box">
                            <span class="sqm-value"><?= htmlspecialchars($sqmDisplay) ?></span><span class="sqm-unit">m²</span>
                        </div>
                    </div>
                    
                    <div class="box-group">
                        <div class="box-label">Reference</div>
                        <div class="value-box">
                            <span class="ref-value"><?= htmlspecialchars($reference) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section -->
            <div class="bottom-section">
                <div class="qr-code">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode('https://fundraising.abuneteklehaymanot.org/verify?ref=' . $reference) ?>" alt="QR Code">
                </div>
                
                <div class="bank-details">
                    <div class="bank-row">
                        <span class="bank-label">Acc.name</span>
                        <span class="bank-value">LMKATH</span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Acc.no</span>
                        <span class="bank-value">85455687</span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Sort code</span>
                        <span class="bank-value">53-70-44</span>
                    </div>
                </div>
            </div>
            </div><!-- /certificate-content -->
        </div>
    </div>

    <!-- Download Actions -->
    <div class="download-actions">
        <button class="btn btn-primary" onclick="downloadAsImage()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            Download as Image
        </button>
        <button class="btn btn-secondary" onclick="downloadAsPDF()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
            Download as PDF
        </button>
        <button class="btn btn-primary" onclick="window.print()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
            Print
        </button>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <script>
        async function downloadAsImage() {
            const certificate = document.getElementById('certificate');
            
            try {
                const canvas = await html2canvas(certificate, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    backgroundColor: null
                });
                
                const link = document.createElement('a');
                link.download = 'LATEOTC-Certificate-<?= htmlspecialchars($reference) ?>.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            } catch (error) {
                console.error('Error generating image:', error);
                alert('Error generating image. Please try again.');
            }
        }

        async function downloadAsPDF() {
            const certificate = document.getElementById('certificate');
            const { jsPDF } = window.jspdf;
            
            try {
                const canvas = await html2canvas(certificate, {
                    scale: 2,
                    useCORS: true,
                    allowTaint: true,
                    backgroundColor: null
                });
                
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF({
                    orientation: 'landscape',
                    unit: 'px',
                    format: [canvas.width / 2, canvas.height / 2]
                });
                
                pdf.addImage(imgData, 'PNG', 0, 0, canvas.width / 2, canvas.height / 2);
                pdf.save('LATEOTC-Certificate-<?= htmlspecialchars($reference) ?>.pdf');
            } catch (error) {
                console.error('Error generating PDF:', error);
                alert('Error generating PDF. Please try again.');
            }
        }
    </script>
</body>
</html>
