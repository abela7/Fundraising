<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate - 100% Design Match</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@900&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #111;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        /* The Main Canvas */
        .certificate {
            width: 850px;
            height: 550px;
            background-color: #006070;
            position: relative;
            overflow: hidden;
            color: white;
            box-shadow: 0 30px 60px rgba(0,0,0,0.5);
            border-radius: 4px;
        }

        /* The Light Ray Effect from the Image */
        .certificate::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 100%;
            height: 200%;
            background: linear-gradient(110deg, transparent 30%, rgba(255,255,255,0.08) 45%, transparent 60%);
            transform: rotate(15deg);
            pointer-events: none;
            z-index: 1;
        }

        /* Second ray for more depth */
        .certificate::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -20%;
            width: 80%;
            height: 200%;
            background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,0.04) 50%, transparent 65%);
            transform: rotate(15deg);
            pointer-events: none;
            z-index: 1;
        }

        .content {
            position: relative;
            z-index: 5;
            padding: 20px 35px;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Top Verse - Yellow Color */
        .top-verse {
            color: #f6c445;
            font-size: 19px;
            font-weight: 700;
            text-align: center;
            line-height: 1.3;
            margin-bottom: 8px;
            font-family: "Nyala", "Segoe UI Ethiopic", "Noto Sans Ethiopic", sans-serif;
            max-width: 90%;
        }

        .org-name {
            font-size: 38px;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 25px;
            text-transform: uppercase;
            text-align: center;
            width: 100%;
        }

        /* Title Sections */
        .title-am {
            font-size: 115px;
            font-weight: 900;
            margin-top: 5px;
            line-height: 1;
            font-family: "Nyala", "Segoe UI Ethiopic", "Noto Sans Ethiopic", sans-serif;
            text-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        .title-en {
            font-size: 100px;
            font-weight: 900;
            margin-top: -10px;
            font-family: 'Montserrat', sans-serif;
            letter-spacing: -2px;
            text-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }

        /* Bottom Section Layout */
        .bottom-container {
            position: absolute;
            bottom: 35px;
            left: 35px;
            right: 35px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            width: calc(100% - 70px);
        }

        .bottom-left {
            display: flex;
            gap: 25px;
            align-items: center;
        }

        .qr-placeholder {
            width: 135px;
            height: 135px;
            background: white;
            padding: 8px;
            border-radius: 2px;
        }

        .qr-placeholder img {
            width: 100%;
            height: 100%;
            display: block;
        }

        .bank-info {
            font-size: 34px;
            font-weight: 700;
            line-height: 1.25;
        }

        .bank-row {
            display: flex;
            gap: 15px;
            align-items: baseline;
        }

        .bank-label { 
            color: #fff; 
            min-width: 165px; 
            font-weight: 600;
        }
        
        .bank-val { 
            color: #f6c445; 
            font-weight: 700;
        }

        /* Sq.m and Value Boxes */
        .right-section {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 15px;
        }

        .sqm-label {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: -5px;
            margin-right: 15px;
            color: #fff;
        }

        .value-box {
            width: 230px;
            height: 68px;
            background: #e6e7e8;
            border-radius: 40px;
            box-shadow: inset 0 2px 8px rgba(0,0,0,0.15);
        }

        /* Scaling for different screens */
        @media (max-width: 880px) {
            .certificate { transform: scale(0.8); }
        }
        
        @media (max-width: 700px) {
            .certificate { transform: scale(0.6); }
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="content">
            <div class="top-verse">
                “የምሠራትም ቤት እጅግ ታላቅና ድንቅ ይሆናልና ብዙ እንጨት ያዘጋጅልኝ ዘንድ እነሆ ባሪያዎቼ ከባሪያዎችህ ጋር ይሆናሉ።” ፪ ዜና ፪፡፱
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
</body>
</html>
