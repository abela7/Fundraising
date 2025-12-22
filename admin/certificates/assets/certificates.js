/**
 * Certificate Management JavaScript
 * Handles print, download, and responsive scaling
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initial scaling with a small delay to ensure DOM is ready
    setTimeout(updateCertScale, 100);
    
    // Update scale on window resize with debounce
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(updateCertScale, 150);
    });

    // Also update on orientation change (mobile)
    window.addEventListener('orientationchange', function() {
        setTimeout(updateCertScale, 300);
    });

    // Scroll to preview section if donor is selected
    const previewSection = document.getElementById('preview-section');
    if (previewSection && window.innerWidth < 768) {
        setTimeout(() => {
            previewSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 500);
    }
});

/**
 * Update certificate scale to fit container perfectly
 */
function updateCertScale() {
    const container = document.querySelector('.certificate-preview-container');
    const scaler = document.getElementById('cert-scaler');
    const certificate = document.querySelector('.certificate');
    
    if (!container || !scaler || !certificate) return;
    
    // Get container dimensions (accounting for padding)
    const containerStyles = getComputedStyle(container);
    const paddingX = parseFloat(containerStyles.paddingLeft) + parseFloat(containerStyles.paddingRight);
    const paddingY = parseFloat(containerStyles.paddingTop) + parseFloat(containerStyles.paddingBottom);
    
    const availableWidth = container.offsetWidth - paddingX;
    const availableHeight = Math.min(
        window.innerHeight * 0.65, // Max 65% of viewport height
        600 // Maximum height cap
    );
    
    // Base certificate dimensions
    const baseWidth = 1200;
    const baseHeight = 750;
    
    // Calculate scale to fit width
    let scale = availableWidth / baseWidth;
    
    // Check if height also fits, if not adjust scale
    const scaledHeight = baseHeight * scale;
    if (scaledHeight > availableHeight) {
        scale = availableHeight / baseHeight;
    }
    
    // Ensure minimum readability
    scale = Math.max(scale, 0.15);
    
    // Don't scale up beyond original size
    scale = Math.min(scale, 1);
    
    // Apply transform
    scaler.style.transform = `scale(${scale})`;
    scaler.style.transformOrigin = 'top center';
    
    // Update container height to match scaled certificate
    const newHeight = (baseHeight * scale) + paddingY;
    container.style.minHeight = `${newHeight}px`;
    
    // Set explicit dimensions on scaler for proper layout
    scaler.style.width = `${baseWidth}px`;
    scaler.style.height = `${baseHeight}px`;
}

/**
 * Print the certificate
 */
function printCertificate() {
    const certificate = document.querySelector('.certificate');
    if (!certificate) {
        alert('No certificate to print');
        return;
    }
    
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    
    // Get the certificate HTML
    const certHtml = certificate.outerHTML;
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Certificate - Print</title>
            <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@200;600;800;900&display=swap" rel="stylesheet">
            <style>
                @page {
                    size: landscape;
                    margin: 0;
                }
                
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    background: white;
                    font-family: 'Montserrat', sans-serif;
                }
                
                .certificate {
                    width: 1200px;
                    height: 750px;
                    background-image: url('../../assets/images/cert-bg.png');
                    background-size: cover;
                    background-position: center;
                    background-repeat: no-repeat;
                    position: relative;
                    color: white;
                }
                
                .church-overlay {
                    position: absolute;
                    top: 0;
                    right: 0;
                    width: 500px;
                    height: 100%;
                    pointer-events: none;
                    overflow: hidden;
                    z-index: 0;
                }
                
                .church-overlay::before {
                    content: '';
                    position: absolute;
                    top: 50%;
                    right: -50px;
                    transform: translateY(-50%);
                    width: 450px;
                    height: 450px;
                    background-image: url('../../assets/images/new-church.png');
                    background-size: cover;
                    background-position: center;
                    border-radius: 50%;
                    opacity: 0.15;
                    filter: saturate(0.6) brightness(1.1);
                }
                
                .top-section {
                    position: absolute;
                    top: 25px;
                    left: 0;
                    right: 0;
                    text-align: center;
                    z-index: 1;
                }
                
                .top-verse {
                    color: #ffcc33;
                    font-size: 41px;
                    font-weight: 200;
                    line-height: 1.3;
                    font-family: "Nyala", "Segoe UI Ethiopic", serif;
                    padding: 0 60px;
                }
                
                .church-name {
                    font-size: 48px;
                    font-weight: 600;
                    letter-spacing: 1px;
                    text-transform: uppercase;
                    margin-top: 15px;
                    margin-bottom: 15px;
                    padding-top: 10px;
                    padding-bottom: 10px;
                }
                
                .center-section {
                    position: absolute;
                    top: 200px;
                    left: 0;
                    right: 0;
                    text-align: center;
                    z-index: 1;
                }
                
                .title-am {
                    font-size: 135px;
                    font-weight: 900;
                    line-height: 1;
                    font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
                    margin-bottom: 10px;
                    padding-top: 45px;
                }
                
                .title-en {
                    font-size: 120px;
                    font-weight: 900;
                    line-height: 1;
                    letter-spacing: -3px;
                    margin-top: 5px;
                    margin-bottom: 10px;
                }
                
                .bottom-section {
                    position: absolute;
                    bottom: 40px;
                    left: 50px;
                    right: 50px;
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    z-index: 1;
                }
                
                .bank-area {
                    display: flex;
                    align-items: center;
                    gap: 30px;
                }
                
                .qr-code {
                    width: 160px;
                    height: 160px;
                    background: white;
                    padding: 10px;
                }
                
                .qr-code img {
                    width: 100%;
                    height: 100%;
                }
                
                .bank-details {
                    font-size: 38px;
                    font-weight: 800;
                    line-height: 1.3;
                    max-width: 650px;
                }
                
                .bank-row {
                    display: flex;
                    gap: 15px;
                }
                
                .bank-label { 
                    color: #fff; 
                    white-space: nowrap;
                }
                
                .bank-val { 
                    color: #ffcc33; 
                    white-space: normal;
                    word-break: break-word;
                }
                
                .right-area {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 15px;
                }
                
                .pill-box {
                    width: 280px;
                    height: 100px;
                    background: #ffffff;
                    border-radius: 50px;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .sqm-value {
                    font-size: 48px;
                    font-weight: 900;
                    color: #333;
                }
                
                .reference-number {
                    font-size: 20px;
                    font-weight: 600;
                    color: #fff;
                    margin-top: 8px;
                    text-align: right;
                    letter-spacing: 2px;
                    font-family: 'Courier New', monospace;
                }
            </style>
        </head>
        <body>
            ${certHtml}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    
    // Wait for images to load then print
    printWindow.onload = function() {
        setTimeout(() => {
            printWindow.print();
        }, 500);
    };
}

/**
 * Download the certificate as an image
 * Uses html2canvas library (loaded dynamically)
 */
function downloadCertificate() {
    const certificate = document.querySelector('.certificate');
    if (!certificate) {
        alert('No certificate to download');
        return;
    }
    
    // Load html2canvas if not already loaded
    if (typeof html2canvas === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        script.onload = () => captureAndDownload(certificate);
        document.head.appendChild(script);
    } else {
        captureAndDownload(certificate);
    }
}

/**
 * Capture certificate and trigger download
 */
function captureAndDownload(element) {
    // Show loading indicator
    const btn = document.querySelector('button[onclick="downloadCertificate()"]');
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;
    }
    
    // Get donor name for filename
    const donorName = document.querySelector('.bank-val')?.textContent || 'certificate';
    const safeName = donorName.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    
    html2canvas(element, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: null,
        width: 1200,
        height: 750
    }).then(canvas => {
        // Create download link
        const link = document.createElement('a');
        link.download = `certificate_${safeName}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        
        // Restore button
        if (btn) {
        btn.innerHTML = originalText;
        btn.disabled = false;
        }
    }).catch(err => {
        console.error('Error generating certificate image:', err);
        alert('Error generating certificate. Please try printing instead.');
        if (btn) {
        btn.innerHTML = originalText;
        btn.disabled = false;
        }
    });
}
