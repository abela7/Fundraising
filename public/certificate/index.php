<?php
declare(strict_types=1);
/**
 * Certificate Background Test - ONLY BACKGROUND
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Background Test</title>
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

        /* ========== CERTIFICATE BACKGROUND ONLY ========== */
        .certificate {
            width: 850px;
            height: 550px;
            position: relative;
            overflow: hidden;
            /* Base color - darker teal */
            background-color: #0e7f8b;
        }

        /* Geometric shapes using pseudo-elements and divs */
        
        /* Top-left lighter triangle - goes from top-left corner down */
        .shape-1 {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #1a9a9a;
            clip-path: polygon(0 0, 55% 0, 55% 55%, 0 100%);
        }

        /* Bottom-left section - slightly different shade */
        .shape-2 {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #148d8d;
            clip-path: polygon(0 48%, 48% 48%, 55% 100%, 0 100%);
        }

        /* The pointed arrow tip area */
        .shape-3 {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #11888a;
            clip-path: polygon(48% 48%, 62% 48%, 62% 62%, 55% 100%, 48% 100%);
        }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="shape-1"></div>
        <div class="shape-2"></div>
        <div class="shape-3"></div>
    </div>
</body>
</html>
