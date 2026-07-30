<?php
// Copy to config/env.local.php on the server and set real credentials.
// This file is intentionally NOT tracked by Git (see .gitignore)

// Database credentials (server)
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'DB_USER_HERE');
if (!defined('DB_PASS')) define('DB_PASS', 'DB_PASS_HERE');
if (!defined('DB_NAME')) define('DB_NAME', 'DB_NAME_HERE');

// Timezone (optional)
// if (!defined('APP_TZ')) define('APP_TZ', 'Africa/Addis_Ababa');

// Headless Chrome for WhatsApp certificate images (Linux shared hosting)
// if (!defined('CERT_CHROME_PATH')) define('CERT_CHROME_PATH', '/home/USER/chrome/chrome-headless-shell-linux64/chrome-headless-shell');
// if (!defined('CERT_CHROME_LIB_PATH')) define('CERT_CHROME_LIB_PATH', '/home/USER/chrome/libs/usr/lib/x86_64-linux-gnu');
// if (!defined('CERT_APP_URL')) define('CERT_APP_URL', 'https://donate.example.org');

