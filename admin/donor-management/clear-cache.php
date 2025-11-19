<?php
// Clear all caches
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared!<br>";
}

if (function_exists('apc_clear_cache')) {
    apc_clear_cache();
    echo "APC cache cleared!<br>";
}

echo "Cache clear attempted. <a href='view-payment-plan.php?id=12'>View Payment Plan #12</a>";
?>

