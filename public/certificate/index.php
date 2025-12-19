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

        body {
            background: #1a1a1a;
            height: 100vh;
            width: 100vw;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-family: 'Montserrat', sans-serif;
        }

        #cert-scaler {
            transform-origin: center center;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.1s ease-out;
        }

        .certificate {
            width: 1200px;
            height: 750px;
            background-image: url('../../assets/images/cert-bg.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            overflow: hidden;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 50px 100px rgba(0,0,0,0.5);
        }

        .content {
            position: relative;
            z-index: 10;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 25px 50px;
        }

        .top-verse {
            color: #ffcc33;
            font-size: 20px;
            font-weight: 800;
            text-align: center;
            line-height: 1.3;
            font-family: "Nyala", "Segoe UI Ethiopic", serif;
            margin-bottom: 25px;
            max-width: 950px;
        }

        .church-name {
            font-size: 52px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 40px;
        }

        .title-am {
            font-size: 160px;
            font-weight: 900;
            line-height: 0.9;
            margin-bottom: 5px;
            font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
            text-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .title-en {
            font-size: 145px;
            font-weight: 900;
            line-height: 0.9;
            letter-spacing: -4px;
            text-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .bottom-area {
            position: absolute;
            bottom: 45px;
            width: 100%;
            padding: 0 60px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .bank-section {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .qr-code {
            width: 165px;
            height: 165px;
            background: white;
            padding: 10px;
            border: 1px solid #000;
        }

        .qr-code img { width: 100%; height: 100%; display: block; }

        .bank-details {
            font-size: 38px;
            font-weight: 800;
            line-height: 1.2;
        }

        .bank-row {
            display: flex;
            gap: 15px;
            white-space: nowrap;
        }

        .bank-label { color: #fff; min-width: 210px; }
        .bank-val { color: #ffcc33; }

        .right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 15px;
        }

        .sqm-container {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }

        .sqm-label {
            font-size: 36px;
            font-weight: 800;
            margin-right: 15px;
            margin-bottom: -5px;
        }

        .pill-box {
            width: 300px;
            height: 85px;
            background: rgba(217, 217, 217, 0.95);
            border-radius: 50px;
            box-shadow: inset 0 4px 8px rgba(0,0,0,0.1);
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

                <div class="church-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>

                <div class="title-am">ይህ ታሪኬ ነው</div>
                <div class="title-en">It is My History</div>

                <div class="bottom-area">
                    <div class="bank-section">
                        <div class="qr-code">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=LMKATH-85455687" alt="QR">
                        </div>
                        <div class="bank-details">
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
                        <div class="sqm-container">
                            <div class="sqm-label">Sq.m</div>
                            <div class="pill-box"></div>
                        </div>
                        <div class="pill-box"></div>
                    </div>
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

            // Maintain exact aspect ratio and scale to fit any screen
            const scale = Math.min(winW / baseW, winH / baseH) * 0.98;
            scaler.style.transform = `scale(${scale})`;
        }

        window.addEventListener('resize', scaleCert);
        window.addEventListener('load', scaleCert);
        // Run immediately
        scaleCert();
    </script>
</body>
</html>
