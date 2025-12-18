<?php
/**
 * PWA Support Include
 * 
 * Include this file in the <head> section of admin pages to enable PWA features.
 * Usage: <?php include __DIR__ . '/../includes/pwa.php'; ?>
 */
?>
<!-- PWA Support -->
<link rel="manifest" href="/admin/manifest.json">
<meta name="theme-color" content="#0a6286">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Fundraising">
<link rel="apple-touch-icon" href="/assets/favicon.svg">

<script>
// Register Service Worker
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('/admin/sw.js')
      .then(function(reg) {
        console.log('[PWA] Service Worker registered:', reg.scope);
      })
      .catch(function(err) {
        console.log('[PWA] Service Worker registration failed:', err);
      });
  });
}
</script>

