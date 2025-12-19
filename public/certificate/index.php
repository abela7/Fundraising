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
        }

        .certificate {
            width: 1200px;
            height: 750px;
            background-color: #005a66; /* Exact Teal from Original */
            position: relative;
            overflow: hidden;
            color: white;
            flex-shrink: 0;
        }

        /* Geometric Background Shapes (Lightning Effect) */
        .bg-shape-1 {
            position: absolute;
            top: 0;
            left: 15%;
            width: 50%;
            height: 100%;
            background: rgba(255, 255, 255, 0.04);
            clip-path: polygon(20% 0%, 100% 0%, 60% 100%, 0% 100%);
            z-index: 1;
        }

        .bg-shape-2 {
            position: absolute;
            top: 0;
            right: 0;
            width: 45%;
            height: 100%;
            background: rgba(255, 255, 255, 0.07);
            clip-path: polygon(35% 0%, 100% 0%, 100% 100%, 0% 100%);
            z-index: 1;
        }

        .content {
            position: relative;
            z-index: 10;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 25px;
        }

        .top-verse {
            color: #ffcc33; /* Exact Gold */
            font-size: 22px;
            font-weight: 800;
            text-align: center;
            line-height: 1.3;
            font-family: "Nyala", "Segoe UI Ethiopic", serif;
            margin-bottom: 35px;
            max-width: 1000px;
        }

        .church-name {
            font-size: 58px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 60px;
        }

        .title-am {
            font-size: 175px;
            font-weight: 900;
            line-height: 0.8;
            margin-bottom: 10px;
            font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
        }

        .title-en {
            font-size: 155px;
            font-weight: 900;
            line-height: 0.8;
            letter-spacing: -5px;
            margin-bottom: 20px;
        }

        .bottom-area {
            position: absolute;
            bottom: 50px;
            width: 100%;
            padding: 0 60px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .bank-section {
            display: flex;
            align-items: center;
            gap: 35px;
        }

        .qr-code {
            width: 175px;
            height: 175px;
            background: white;
            padding: 10px;
            border: 1px solid #000;
        }

        .qr-code img { width: 100%; height: 100%; }

        .bank-details {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.2;
        }

        .bank-row {
            display: grid;
            grid-template-columns: 240px auto;
            gap: 10px;
        }

        .bank-label { color: #fff; }
        .bank-val { color: #ffcc33; }

        .right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 20px;
        }

        .sqm-label {
            font-size: 38px;
            font-weight: 800;
            margin-right: 15px;
            margin-bottom: -10px;
        }

        .pill-box {
            width: 320px;
            height: 95px;
            background: #d9d9d9; /* Grey from Original */
            border-radius: 50px;
        }
    </style>
</head>
<body>
    <div id="cert-scaler">
        <div class="certificate">
            <div class="bg-shape-1"></div>
            <div class="bg-shape-2"></div>
            
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
                        <div class="sqm-label">Sq.m</div>
                        <div class="pill-box"></div>
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

            const scale = Math.min(winW / baseW, winH / baseH) * 0.98;
            scaler.style.transform = `scale(${scale})`;
        }

        window.addEventListener('resize', scaleCert);
        window.addEventListener('load', scaleCert);
        scaleCert();
    </script>
</body>
</html>
