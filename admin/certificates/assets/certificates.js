/**
 * Certificate Management JavaScript
 * Handles print and download functionality
 */

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
    
    // Get the certificate HTML and styles
    const certHtml = certificate.outerHTML;
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Certificate - Print</title>
            <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&display=swap" rel="stylesheet">
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
                }
                
                .title-en {
                    font-size: 120px;
                    font-weight: 900;
                    line-height: 1;
                    letter-spacing: -3px;
                    margin-top: 5px;
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
                }
                
                .bank-row {
                    display: flex;
                    gap: 15px;
                }
                
                .bank-label { 
                    color: #fff; 
                    min-width: 220px;
                }
                
                .bank-val { 
                    color: #ffcc33; 
                }
                
                .right-area {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 15px;
                }
                
                .sqm-label {
                    font-size: 36px;
                    font-weight: 800;
                }
                
                .pill-box {
                    width: 280px;
                    height: 100px;
                    background: rgba(220, 220, 220, 0.95);
                    border-radius: 50px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                
                .sqm-value {
                    font-size: 56px;
                    font-weight: 900;
                    color: #333;
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
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    btn.disabled = true;
    
    // Get donor name for filename
    const donorName = document.querySelector('.bank-val')?.textContent || 'certificate';
    const safeName = donorName.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    
    html2canvas(element, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: null
    }).then(canvas => {
        // Create download link
        const link = document.createElement('a');
        link.download = `certificate_${safeName}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
        
        // Restore button
        btn.innerHTML = originalText;
        btn.disabled = false;
    }).catch(err => {
        console.error('Error generating certificate image:', err);
        alert('Error generating certificate. Please try printing instead.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
}
