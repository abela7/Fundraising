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
            background: #0b6f7c;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
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

        /* Guideline boxes (keep to mimic the blank placeholders) */
        .placeholder-stack {
            position: absolute;
            right: 52px;
            bottom: 70px;
            display: grid;
            gap: 20px;
            width: 170px;
        }

        .placeholder {
            height: 60px;
            border-radius: 24px;
            background: #f0f1f2;
        }
    </style>
</head>
<body>
    <div class="certificate" aria-label="Certificate background only">
        <div class="placeholder-stack" aria-hidden="true">
            <div class="placeholder"></div>
            <div class="placeholder"></div>
        </div>
    </div>
</body>
</html>
