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
            background-image: url('../../assets/images/cert-bg.png');
            background-size: cover;
            background-position: center;
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
            padding-top: 30px;
        }

        .top-verse {
            color: #ffcc33; /* Gold */
            font-size: 22px;
            font-weight: 800;
            text-align: center;
            line-height: 1.3;
            font-family: "Nyala", "Segoe UI Ethiopic", serif;
            margin-bottom: 30px;
            max-width: 1050px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .church-name {
            font-size: 56px;
            font-weight: 600;
            letter-spacing: 1px;
            text-transform: uppercase;
            text-align: center;
            margin-bottom: 55px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .title-am {
            font-size: 180px;
            font-weight: 900;
            line-height: 0.8;
            margin-bottom: 15px;
            font-family: "Nyala", "Segoe UI Ethiopic", sans-serif;
            text-shadow: 0 10px 20px rgba(0,0,0,0.3);
        }

        .title-en {
            font-size: 165px;
            font-weight: 900;
            line-height: 0.8;
            letter-spacing: -6px;
            margin-bottom: 20px;
            text-shadow: 0 10px 20px rgba(0,0,0,0.3);
        }

        .bottom-area {
            position: absolute;
            bottom: 50px;
            width: 100%;
            padding: 0 65px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .bank-section {
            display: flex;
            align-items: center;
            gap: 40px;
        }

        .qr-code {
            width: 180px;
            height: 180px;
            background: white;
            padding: 12px;
            border: 1px solid #000;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .qr-code img { width: 100%; height: 100%; }

        .bank-details {
            font-size: 44px;
            font-weight: 800;
            line-height: 1.25;
        }

        .bank-row {
            display: grid;
            grid-template-columns: 260px auto;
            gap: 15px;
        }

        .bank-label { color: #fff; }
        .bank-val { color: #ffcc33; }

        .right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 25px;
            position: relative;
        }

        .sqm-label {
            font-size: 42px;
            font-weight: 800;
            margin-right: 10px;
            margin-bottom: -5px;
            color: #fff;
            text-align: right;
        }

        .pill-box {
            width: 330px;
            height: 95px;
            background: rgba(217, 217, 217, 0.9); /* Replicating the Original Grey */
            border-radius: 50px;
            box-shadow: inset 0 4px 10px rgba(0,0,0,0.1);
        }

        /* Adjusting Sq.m position to match image precisely */
        .sqm-container {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
    </style>
</head>
<body>
    <div id="cert-scaler">
        <div class="certificate">
            <!-- Background shapes are now part of the provided image cert-bg.png -->
            
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
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=LMKATH-85455687" alt="QR">
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

            const scale = Math.min(winW / baseW, winH / baseH) * 0.98;
            scaler.style.transform = `scale(${scale})`;
        }

        window.addEventListener('resize', scaleCert);
        window.addEventListener('load', scaleCert);
        scaleCert();
    </script>
</body>
</html>
