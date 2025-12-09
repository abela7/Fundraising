<?php
declare(strict_types=1);

/**
 * Certificate Preview/Download Page
 * Generates personalized donation certificates
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../shared/csrf.php';

// For demo/preview, allow viewing without auth
// In production, this would require donor authentication

$sqm = $_GET['sqm'] ?? '1';
$reference = $_GET['ref'] ?? '0000';
$donorName = $_GET['name'] ?? '';
$format = $_GET['format'] ?? 'view'; // view, png, pdf

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
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400;1,600&family=Noto+Serif+Ethiopic:wght@400;600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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

        /* Certificate Design */
        .certificate {
            width: 850px;
            height: 550px;
            background: linear-gradient(135deg, #0d7377 0%, #14919b 50%, #0d7377 100%);
            position: relative;
            overflow: hidden;
            font-family: 'Inter', sans-serif;
        }

        /* Subtle pattern overlay */
        .certificate::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 80%, rgba(255,255,255,0.03) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.03) 0%, transparent 50%);
            pointer-events: none;
        }

        .certificate-content {
            position: relative;
            z-index: 1;
            height: 100%;
            padding: 25px 35px;
            display: flex;
            flex-direction: column;
        }

        /* Top Amharic Quote */
        .top-quote {
            font-family: 'Noto Serif Ethiopic', serif;
            color: #f4d03f;
            font-size: 15px;
            text-align: center;
            line-height: 1.6;
            margin-bottom: 8px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
        }

        /* Church Name */
        .church-name {
            color: #ffffff;
            font-size: 22px;
            font-weight: 600;
            text-align: center;
            letter-spacing: 3px;
            margin-bottom: 30px;
            text-transform: uppercase;
        }

        /* Main Slogan Section */
        .slogan-section {
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding-bottom: 40px;
        }

        .slogan-amharic {
            font-family: 'Noto Serif Ethiopic', serif;
            color: #f4d03f;
            font-size: 58px;
            font-style: italic;
            margin-bottom: 5px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .slogan-english {
            font-family: 'Playfair Display', serif;
            color: #f4d03f;
            font-size: 52px;
            font-style: italic;
            font-weight: 400;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        /* Square Meter Display - Right Side */
        .sqm-section {
            position: absolute;
            top: 140px;
            right: 35px;
            text-align: right;
        }

        .sqm-label {
            color: #ffffff;
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .sqm-value-box {
            background: rgba(255,255,255,0.95);
            border-radius: 6px;
            padding: 12px 25px;
            min-width: 140px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .sqm-value {
            font-size: 28px;
            font-weight: 600;
            color: #0d7377;
        }

        .sqm-unit {
            font-size: 14px;
            color: #666;
            margin-left: 5px;
        }

        /* Reference Number Box */
        .ref-section {
            position: absolute;
            top: 240px;
            right: 35px;
            text-align: right;
        }

        .ref-label {
            color: #ffffff;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .ref-value-box {
            background: rgba(255,255,255,0.95);
            border-radius: 6px;
            padding: 10px 25px;
            min-width: 140px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .ref-value {
            font-size: 22px;
            font-weight: 600;
            color: #0d7377;
            letter-spacing: 2px;
        }

        /* Bottom Section */
        .bottom-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: auto;
        }

        /* QR Code */
        .qr-section {
            display: flex;
            align-items: flex-end;
            gap: 20px;
        }

        .qr-code {
            width: 100px;
            height: 100px;
            background: #fff;
            border-radius: 6px;
            padding: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .qr-code img {
            width: 100%;
            height: 100%;
        }

        /* Bank Details */
        .bank-details {
            color: #ffffff;
            font-size: 15px;
            line-height: 1.8;
        }

        .bank-details .label {
            display: inline-block;
            width: 85px;
            font-weight: 500;
        }

        .bank-details .value {
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* Decorative Elements */
        .corner-decoration {
            position: absolute;
            width: 80px;
            height: 80px;
            opacity: 0.1;
        }

        .corner-decoration.top-left {
            top: 10px;
            left: 10px;
            border-top: 3px solid #f4d03f;
            border-left: 3px solid #f4d03f;
        }

        .corner-decoration.top-right {
            top: 10px;
            right: 10px;
            border-top: 3px solid #f4d03f;
            border-right: 3px solid #f4d03f;
        }

        .corner-decoration.bottom-left {
            bottom: 10px;
            left: 10px;
            border-bottom: 3px solid #f4d03f;
            border-left: 3px solid #f4d03f;
        }

        .corner-decoration.bottom-right {
            bottom: 10px;
            right: 10px;
            border-bottom: 3px solid #f4d03f;
            border-right: 3px solid #f4d03f;
        }

        /* Download Buttons */
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
            background: #0d7377;
            color: #fff;
        }

        .btn-primary:hover {
            background: #0a5c5f;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #f4d03f;
            color: #0d7377;
        }

        .btn-secondary:hover {
            background: #e6c235;
            transform: translateY(-2px);
        }

        .btn svg {
            width: 18px;
            height: 18px;
        }

        /* Form for testing */
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
            <!-- Corner Decorations -->
            <div class="corner-decoration top-left"></div>
            <div class="corner-decoration top-right"></div>
            <div class="corner-decoration bottom-left"></div>
            <div class="corner-decoration bottom-right"></div>

            <div class="certificate-content">
                <!-- Top Amharic Quote -->
                <div class="top-quote">
                    "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንፈስት ያዘጋጁልኝ ዘንድ<br>
                    አሁ ባረያዎቼ ከባረያዎችህ ጋር ይሆናሉ::" ፪ ዜና ፪÷ቿ
                </div>

                <!-- Church Name -->
                <div class="church-name">Liverpool Abune Teklehaymanot EOTC</div>

                <!-- Main Slogan -->
                <div class="slogan-section">
                    <div class="slogan-amharic">ይህ ታሪኬ ነው</div>
                    <div class="slogan-english">It is My History</div>
                </div>

                <!-- Square Meter Display -->
                <div class="sqm-section">
                    <div class="sqm-label">Sq.m</div>
                    <div class="sqm-value-box">
                        <span class="sqm-value"><?= htmlspecialchars($sqmDisplay) ?></span>
                        <span class="sqm-unit">m²</span>
                    </div>
                </div>

                <!-- Reference Number -->
                <div class="ref-section">
                    <div class="ref-label">Reference</div>
                    <div class="ref-value-box">
                        <span class="ref-value"><?= htmlspecialchars($reference) ?></span>
                    </div>
                </div>

                <!-- Bottom Section -->
                <div class="bottom-section">
                    <div class="qr-section">
                        <!-- QR Code - Using a placeholder, will generate dynamically -->
                        <div class="qr-code">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode('https://fundraising.abuneteklehaymanot.org/verify?ref=' . $reference) ?>" alt="QR Code">
                        </div>
                        
                        <!-- Bank Details -->
                        <div class="bank-details">
                            <div><span class="label">Acc.name</span> <span class="value">LMKATH</span></div>
                            <div><span class="label">Acc.no</span> <span class="value">85455687</span></div>
                            <div><span class="label">Sort code</span> <span class="value">53-70-44</span></div>
                        </div>
                    </div>
                </div>
            </div>
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

    <!-- html2canvas for image download -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- jsPDF for PDF download -->
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

