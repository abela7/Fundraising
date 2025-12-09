<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Background Only</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #1a1a2e;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        /* ========== BACKGROUND ONLY ========== */
        .certificate {
            width: 850px;
            height: 550px;
            position: relative;
            overflow: hidden;
        }

        /* Base - Deep teal/blue background */
        .certificate::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #0a6b6b;
            /* Very subtle diagonal crosshatch texture */
            background-image: 
                repeating-linear-gradient(
                    135deg,
                    transparent,
                    transparent 3px,
                    rgba(255, 255, 255, 0.015) 3px,
                    rgba(255, 255, 255, 0.015) 6px
                ),
                repeating-linear-gradient(
                    45deg,
                    transparent,
                    transparent 3px,
                    rgba(0, 0, 0, 0.01) 3px,
                    rgba(0, 0, 0, 0.01) 6px
                );
        }

        /* Lighter teal angular zig-zag shape */
        .certificate::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: #1a9a9a;
            /* Same subtle diagonal texture on the lighter shape */
            background-image: 
                repeating-linear-gradient(
                    135deg,
                    transparent,
                    transparent 3px,
                    rgba(255, 255, 255, 0.02) 3px,
                    rgba(255, 255, 255, 0.02) 6px
                ),
                repeating-linear-gradient(
                    45deg,
                    transparent,
                    transparent 3px,
                    rgba(0, 0, 0, 0.015) 3px,
                    rgba(0, 0, 0, 0.015) 6px
                );
            /* Angular zig-zag shape: top-left down-right, then horizontal right, then down-right */
            clip-path: polygon(
                0 0,
                55% 0,
                55% 48%,
                62% 48%,
                62% 62%,
                55% 100%,
                0 100%
            );
        }
    </style>
</head>
<body>
    <div class="certificate"></div>
</body>
</html>
