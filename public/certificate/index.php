<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0b6f7c;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif;
        }

        /* Background canvas */
        .certificate {
            width: 850px;
            height: 550px;
            position: relative;
            overflow: hidden;
            background: #0b6f7c;
            /* subtle grain/noise */
            background-image:
                radial-gradient(circle at 12% 18%, rgba(255,255,255,0.02), transparent 26%),
                radial-gradient(circle at 88% 12%, rgba(255,255,255,0.02), transparent 22%),
                radial-gradient(circle at 30% 78%, rgba(0,0,0,0.03), transparent 32%),
                repeating-linear-gradient(
                    135deg,
                    transparent 0,
                    transparent 8px,
                    rgba(255,255,255,0.015) 8px,
                    rgba(255,255,255,0.015) 16px
                );
            border-radius: 10px;
            box-shadow: 0 18px 48px rgba(0,0,0,0.28);
        }

        /* Large left angular shape */
        .certificate::before {
            content: '';
            position: absolute;
            top: -8%;
            left: -12%;
            width: 72%;
            height: 125%;
            background: #158d9c;
            opacity: 0.9;
            clip-path: polygon(0 0, 98% 9%, 64% 100%, 0 100%);
        }

        /* Right zig/arrow shape */
        .certificate::after {
            content: '';
            position: absolute;
            top: 8%;
            right: -14%;
            width: 72%;
            height: 120%;
            background: #0a6b7b;
            opacity: 0.9;
            clip-path: polygon(26% 0, 80% 0, 56% 43%, 100% 43%, 80% 100%, 32% 100%, 50% 60%, 26% 60%);
        }

        /* Foreground content sits above background shapes */
        .content {
            position: absolute;
            inset: 0;
            z-index: 2;
            padding: 22px 28px;
        }

        .top-quote {
            color: #f3d35c;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-align: center;
            line-height: 1.25;
            margin-top: 4px;
            text-shadow: 0 2px 0 rgba(0,0,0,0.18);
            font-family:
                "Noto Sans Ethiopic",
                "Abyssinica SIL",
                "Nyala",
                "Segoe UI",
                system-ui,
                sans-serif;
        }

        .org-name {
            margin-top: 22px;
            text-align: center;
            color: rgba(255,255,255,0.92);
            font-size: 40px;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .title-am {
            margin-top: 40px;
            text-align: center;
            color: rgba(255,255,255,0.98);
            font-size: 118px;
            font-weight: 900;
            line-height: 0.95;
            text-shadow: 0 7px 0 rgba(0,0,0,0.32);
            font-family:
                "Noto Sans Ethiopic",
                "Abyssinica SIL",
                "Nyala",
                "Segoe UI",
                system-ui,
                sans-serif;
        }

        .title-en {
            margin-top: 14px;
            text-align: center;
            color: rgba(255,255,255,0.98);
            font-size: 92px;
            font-weight: 900;
            line-height: 1;
            text-shadow: 0 7px 0 rgba(0,0,0,0.32);
        }

        /* Bottom-left: QR + bank details */
        .bottom-left {
            position: absolute;
            left: 30px;
            bottom: 44px;
            display: flex;
            align-items: flex-end;
            gap: 22px;
        }

        .qr-box {
            width: 120px;
            height: 120px;
            background: #ffffff;
            border: 6px solid #111;
            box-shadow: 0 10px 22px rgba(0,0,0,0.18);
            display: grid;
            place-items: center;
        }

        .qr-box svg {
            width: 100%;
            height: 100%;
        }

        .bank-details {
            padding-bottom: 6px;
            line-height: 1.22;
            font-size: 34px;
            font-weight: 700;
            letter-spacing: 0.2px;
        }

        .bank-row {
            display: flex;
            gap: 24px;
            align-items: baseline;
        }

        .bank-label {
            color: rgba(255,255,255,0.92);
            font-weight: 700;
            min-width: 160px;
        }

        .bank-value {
            color: #f3d35c;
            font-weight: 900;
        }

        /* Right placeholders (Sq.m + Reference) */
        .fields {
            position: absolute;
            right: 42px;
            bottom: 58px;
            width: 210px;
            z-index: 3;
        }

        .field-label {
            position: absolute;
            right: 160px;
            bottom: 170px;
            color: rgba(255,255,255,0.92);
            font-size: 26px;
            font-weight: 700;
            z-index: 4;
        }

        .field-box {
            height: 66px;
            border-radius: 28px;
            background: #f2f3f4;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.65);
            border: 1px solid rgba(0,0,0,0.08);
        }

        .field-box + .field-box {
            margin-top: 22px;
        }

        /* Responsive: keep exact proportions, scale down */
        @media (max-width: 920px) {
            body { padding: 16px; }
            .certificate {
                transform: scale(0.92);
                transform-origin: center;
            }
        }

        @media (max-width: 820px) {
            .certificate {
                transform: scale(0.84);
            }
        }

        @media (max-width: 720px) {
            .certificate {
                transform: scale(0.72);
            }
        }
    </style>
</head>
<body>
    <div class="certificate" aria-label="Certificate">
        <div class="content">
            <div class="top-quote">
                “የማህበረሰብ ልጅ እንዲህ አለ፤ እባክህ የእግዚአብሔርን ቤት አንስተህ የራስህን ታሪክ አድርገው፤ እኔ እገኛለሁ እርዳኝ።” ፡፡ ሆ ን ፡፡
            </div>

            <div class="org-name">LIVERPOOL ABUNE TEKLEHAYMANOT EOTC</div>

            <div class="title-am">ይህ ታሪኬ ነው</div>
            <div class="title-en">It is My History</div>

            <div class="bottom-left">
                <div class="qr-box" aria-label="QR code placeholder">
                    <!-- UI-only: decorative QR-style SVG placeholder -->
                    <svg viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="QR">
                        <rect width="120" height="120" fill="#fff"/>
                        <!-- Finder patterns -->
                        <rect x="8" y="8" width="34" height="34" fill="#000"/>
                        <rect x="13" y="13" width="24" height="24" fill="#fff"/>
                        <rect x="18" y="18" width="14" height="14" fill="#000"/>

                        <rect x="78" y="8" width="34" height="34" fill="#000"/>
                        <rect x="83" y="13" width="24" height="24" fill="#fff"/>
                        <rect x="88" y="18" width="14" height="14" fill="#000"/>

                        <rect x="8" y="78" width="34" height="34" fill="#000"/>
                        <rect x="13" y="83" width="24" height="24" fill="#fff"/>
                        <rect x="18" y="88" width="14" height="14" fill="#000"/>

                        <!-- Random modules to mimic QR density -->
                        <g fill="#000">
                            <rect x="52" y="10" width="6" height="6"/>
                            <rect x="60" y="10" width="6" height="6"/>
                            <rect x="52" y="18" width="6" height="6"/>
                            <rect x="62" y="20" width="6" height="6"/>
                            <rect x="48" y="26" width="6" height="6"/>
                            <rect x="58" y="28" width="6" height="6"/>

                            <rect x="52" y="46" width="6" height="6"/>
                            <rect x="60" y="46" width="6" height="6"/>
                            <rect x="68" y="46" width="6" height="6"/>
                            <rect x="46" y="54" width="6" height="6"/>
                            <rect x="56" y="56" width="6" height="6"/>
                            <rect x="66" y="56" width="6" height="6"/>
                            <rect x="74" y="56" width="6" height="6"/>

                            <rect x="48" y="66" width="6" height="6"/>
                            <rect x="58" y="66" width="6" height="6"/>
                            <rect x="68" y="66" width="6" height="6"/>
                            <rect x="78" y="66" width="6" height="6"/>

                            <rect x="52" y="74" width="6" height="6"/>
                            <rect x="62" y="74" width="6" height="6"/>
                            <rect x="72" y="74" width="6" height="6"/>

                            <rect x="48" y="86" width="6" height="6"/>
                            <rect x="58" y="86" width="6" height="6"/>
                            <rect x="68" y="86" width="6" height="6"/>
                            <rect x="78" y="86" width="6" height="6"/>
                            <rect x="88" y="86" width="6" height="6"/>

                            <rect x="52" y="96" width="6" height="6"/>
                            <rect x="62" y="96" width="6" height="6"/>
                            <rect x="72" y="96" width="6" height="6"/>
                            <rect x="82" y="96" width="6" height="6"/>
                        </g>
                    </svg>
                </div>

                <div class="bank-details" aria-label="Bank details">
                    <div class="bank-row">
                        <div class="bank-label">Acc.name</div>
                        <div class="bank-value">LMKATH</div>
                    </div>
                    <div class="bank-row">
                        <div class="bank-label">Acc.no</div>
                        <div class="bank-value">85455687</div>
                    </div>
                    <div class="bank-row">
                        <div class="bank-label">Sort code</div>
                        <div class="bank-value">53-70-44</div>
                    </div>
                </div>
            </div>

            <div class="field-label">Sq.m</div>
            <div class="fields" aria-hidden="true">
                <div class="field-box"></div>
                <div class="field-box"></div>
            </div>
        </div>
    </div>
</body>
</html>
