<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Certificate - Universal Display</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background: #000;
            height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        /* Scalable Container */
        #cert-scaler {
            transform-origin: center center;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Fixed Internal Canvas (Proportional Coordinate System) */
        .certificate {
            width: 1000px;
            height: 625px;
            background-color: #006070;
            position: relative;
            overflow: hidden;
            color: white;
            flex-shrink: 0;
            border-radius: 4px;
        }

        /* Light Ray Effect */
        .certificate::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 100%;
            height: 200%;
            background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,0.08) 45%, transparent 60%);
            transform: rotate(15deg);
            z-index: 1;
        }

        .content {
            position: relative;
            z-index: 5;
            padding: 30px 50px;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .top-verse {
            color: #f6c445;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            line-height: 1.4;
            margin-bottom: 20px;
            font-family: "Nyala", "Segoe UI Ethiopic", serif;
            max-width: 800px;
        }

        .org-name {
            font-size: 38px;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 40px;
            text-transform: uppercase;
            text-align: center;
        }

        .title-am {
            font-size: 100px;
            font-weight: 900;
            line-height: 1;
            margin-top: 10px;
            font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
        }

        .title-en {
            font-size: 90px;
            font-weight: 900;
            margin-top: -10px;
            font-family: 'Montserrat', sans-serif;
            letter-spacing: -2px;
        }

        .bottom-container {
            position: absolute;
            bottom: 40px;
            left: 50px;
            right: 50px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .bottom-left {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .qr-placeholder {
            width: 150px;
            height: 150px;
            background: white;
            padding: 10px;
            border-radius: 2px;
        }

        .qr-placeholder img {
            width: 100%;
            height: 100%;
        }

        .bank-info {
            font-size: 32px;
            font-weight: 700;
            line-height: 1.3;
        }

        .bank-row {
            display: flex;
            gap: 20px;
        }

        .bank-label { color: #fff; min-width: 170px; }
        .bank-val { color: #f6c445; }

        .right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 15px;
        }

        .sqm-label {
            font-size: 32px;
            font-weight: 700;
            margin-right: 15px;
        }

        .value-box {
            width: 260px;
            height: 75px;
            background: #e6e7e8;
            border-radius: 40px;
            box-shadow: inset 0 3px 10px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div id="cert-scaler">
        <div class="certificate">
            <div class="content">
                <div class="top-verse">
                    “የምሠራትም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጅልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ።”<br>፪ ዜና ፪፡፱
                </div>

                <div class="org-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>

                <div class="title-am">ይህ ታሪኬ ነው</div>
                <div class="title-en">It is My History</div>

                <div class="bottom-container">
                    <div class="bottom-left">
                        <div class="qr-placeholder">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=LMKATH-85455687" alt="QR">
                        </div>
                        <div class="bank-info">
                            <div class="bank-row">
                                <span class="bank-label">Acc.name</span>
                                <span class="bank-val">LMKATH</span>
                            </div>
                            <div class="bank-row">
                                <span class="bank-label">Acc.no</span>
                                <span class="bank-val">85455687</span>
                            </div>
                            <div class="bank-row">
                                <span class="bank-label">Sort code</span>
                                <span class="bank-val">53-70-44</span>
                            </div>
                        </div>
                    </div>

                    <div class="right-section">
                        <div class="sqm-label">Sq.m</div>
                        <div class="value-box"></div>
                        <div class="value-box"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function scaleCertificate() {
            const scaler = document.getElementById('cert-scaler');
            const winW = window.innerWidth;
            const winH = window.innerHeight;
            const baseW = 1000;
            const baseH = 625;

            // Calculate scale to fit both width and height
            const scale = Math.min(winW / baseW, winH / baseH) * 0.95; // 0.95 adds a small margin
            scaler.style.transform = `scale(${scale})`;
        }

        window.addEventListener('resize', scaleCertificate);
        window.addEventListener('load', scaleCertificate);
        scaleCertificate();
    </script>
</body>
</html>
