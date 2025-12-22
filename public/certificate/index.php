<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Certificate - Exact Replica</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        html, body {
            height: 100%;
            width: 100%;
            overflow: hidden;
        }

        body {
            background: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Montserrat', sans-serif;
        }

        #cert-scaler {
            transform-origin: center center;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Fixed size canvas */
        .certificate {
            width: 1200px;
            height: 750px;
            background-image: url('../../assets/images/cert-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 30px 80px rgba(0,0,0,0.6);
        }

        /* ===== TOP SECTION ===== */
        .top-section {
            position: absolute;
            top: 25px;
            left: 0;
            right: 0;
            text-align: center;
        }

        .top-verse {
            color: #ffcc33;
            font-size: 41px; /* Increased from 18px */
            font-weight: 200;
            line-height: 1.3;
            font-family: "Nyala", "Segoe UI Ethiopic", serif;
            padding: 0 60px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .church-name {
            font-size: 48px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-top: 15px;
        }

        /* ===== CENTER TITLES ===== */
        .center-section {
            position: absolute;
            top: 200px; /* Adjusted down slightly */
            left: 0;
            right: 0;
            text-align: center;
        }

        .title-am {
            font-size: 135px;
            font-weight: 900;
            line-height: 1;
            font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
            text-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .title-en {
            font-size: 120px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -3px;
            margin-top: 5px;
            text-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* ===== BOTTOM SECTION ===== */
        .bottom-section {
            position: absolute;
            bottom: 40px;
            left: 50px;
            right: 50px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .bank-area {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: left;
            vertical-align: middle;
            gap: 30px;
        }

        .qr-code {
            width: 160px;
            height: 160px;
            background: white;
            padding: 10px;
            flex-shrink: 0;
        }

        .qr-code img {
            width: 100%;
            height: 100%;
            display: block;
        }

        .bank-details {
            font-size: 44px; /* Increased from 32px */
            font-weight: 800;
            line-height: 1.2;
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

        /* ===== RIGHT SIDE ===== */
        .right-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .sqm-label {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            text-align: center;
            transform: rotate(360deg);
            font-size: 36px;
            font-weight: 800;
            margin-right: 15px;
            margin-bottom: -10px;
        }

        .pill-box {
            width: 280px;
            height: 80px;
            background: rgba(220, 220, 220, 0.95);
            border-radius: 40px;
            box-shadow: inset 0 3px 8px rgba(0,0,0,0.15);
        }

        /* ===== CHURCH IMAGE OVERLAY ===== */
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
            mask-image: radial-gradient(circle, rgba(0,0,0,0.8) 30%, rgba(0,0,0,0) 70%);
            -webkit-mask-image: radial-gradient(circle, rgba(0,0,0,0.8) 30%, rgba(0,0,0,0) 70%);
            filter: saturate(0.6) brightness(1.1);
        }

        /* Ensure all content stays above the overlay */
        .top-section,
        .center-section,
        .bottom-section {
            z-index: 1;
        }
    </style>
</head>
<body>
    <div id="cert-scaler">
        <div class="certificate">
            <!-- Subtle church image overlay -->
            <div class="church-overlay"></div>

            <div class="top-section">
                <div class="top-verse">
                    "የምሠራውም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጁልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ፡፡" ፪ ዜና ፪፡፱
                </div>
                <div class="church-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>
            </div>

            <div class="center-section">
                <div class="title-am">ይህ ታሪኬ ነው</div>
                <div class="title-en">It is My History</div>
            </div>

            <div class="bottom-section">
                <div class="bank-area">
                    <div class="qr-code">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=http://donate.abuneteklehaymanot.org/" alt="QR">
                    </div>
                    <div class="bank-details">
                        <div class="bank-row">
                            <span class="bank-label">Acc.name -</span>
                            <span class="bank-val">LMKATH</span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Acc.no -</span>
                            <span class="bank-val">85455687</span>
                        </div>
                        <div class="bank-row">
                            <span class="bank-label">Sort code -</span>
                            <span class="bank-val">53-70-44</span>
                        </div>
                    </div>
                </div>

                <div class="right-area">
                    <div class="sqm-label">Sq.m</div>
                    <div class="pill-box"></div>
                    <div class="pill-box"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function scaleCert() {
            const scaler = document.getElementById('cert-scaler');
            const winW = window.innerWidth;
            const winH = window.innerHeight;
            const baseW = 1200;
            const baseH = 750;

            const scale = Math.min(winW / baseW, winH / baseH) * 0.95;
            scaler.style.transform = `scale(${scale})`;
        }

        window.addEventListener('resize', scaleCert);
        window.addEventListener('load', scaleCert);
        scaleCert();
    </script>
</body>
</html>
