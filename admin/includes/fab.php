<!-- Floating Action Button (FAB) Menu -->
<style>
    .fab-container {
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 12px;
        pointer-events: none; /* Don't block clicks when closed */
    }
    
    .fab-container .fab-main,
    .fab-container.active {
        pointer-events: auto; /* Enable clicks on main button and when open */
    }

    .fab-main {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(180deg, #075985 0%, #0a6286 50%, #075985 100%);
        color: white;
        border: none;
        box-shadow: 0 4px 15px rgba(7, 89, 133, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        position: relative;
        order: 2;
    }

    .fab-main:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(7, 89, 133, 0.6);
        background: linear-gradient(180deg, #0a6286 0%, #0ea5e9 50%, #0a6286 100%);
    }

    .fab-main.active {
        transform: rotate(45deg);
        background: linear-gradient(180deg, #075985 0%, #0a6286 50%, #075985 100%);
        box-shadow: 0 4px 15px rgba(7, 89, 133, 0.5);
    }

    .fab-options {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column-reverse;
        gap: 12px;
        align-items: flex-end;
        pointer-events: none;
        opacity: 0;
        transform: translateY(20px);
        transition: all 0.3s ease;
        order: 1;
        margin-bottom: 0;
    }

    .fab-container.active .fab-options {
        pointer-events: auto;
        opacity: 1;
        transform: translateY(0);
    }

    .fab-item {
        display: flex;
        align-items: center;
        gap: 10px;
        justify-content: flex-end;
        transform: translateY(10px);
        opacity: 0;
        transition: all 0.3s ease;
    }
    
    .fab-container.active .fab-item {
        transform: translateY(0);
        opacity: 1;
    }

    .fab-item:hover {
        transform: translateX(-5px) translateY(0);
    }

    .fab-btn {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        color: white;
        border: none;
        box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s;
    }

    .fab-btn:hover {
        transform: scale(1.1);
        color: white;
    }

    .fab-label {
        background: rgba(0, 0, 0, 0.8);
        color: white;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        pointer-events: none;
        opacity: 0;
        transform: translateX(10px);
        transition: all 0.2s;
    }

    .fab-item:hover .fab-label {
        opacity: 1;
        transform: translateX(0);
    }
    
    .fab-container.active .fab-label {
        opacity: 1;
        transform: translateX(0);
        pointer-events: auto;
    }

    /* Colors */
    .bg-purple { background: #6f42c1; }
    .bg-indigo { background: #6610f2; }
    .bg-pink   { background: #d63384; }
    .bg-orange { background: #fd7e14; }
    .bg-teal   { background: #20c997; }
    .bg-cyan   { background: #0dcaf0; }
    .bg-whatsapp { background: #25D366; }
    
    /* Animation Delays */
    .fab-item:nth-child(1) { transition-delay: 0.05s; }
    .fab-item:nth-child(2) { transition-delay: 0.1s; }
    .fab-item:nth-child(3) { transition-delay: 0.15s; }
    .fab-item:nth-child(4) { transition-delay: 0.2s; }
    .fab-item:nth-child(5) { transition-delay: 0.25s; }
    .fab-item:nth-child(6) { transition-delay: 0.3s; }
    .fab-item:nth-child(7) { transition-delay: 0.35s; }
    .fab-item:nth-child(8) { transition-delay: 0.4s; }

    /* Mobile adjustments */
    @media (max-width: 576px) {
        .fab-container {
            bottom: 15px;
            right: 15px;
            gap: 10px;
        }
        .fab-main {
            width: 44px;
            height: 44px;
            font-size: 18px;
        }
        .fab-btn {
            width: 40px;
            height: 40px;
            font-size: 15px;
        }
        .fab-label {
            display: block;
            font-size: 12px;
            padding: 4px 8px;
        }
    }
</style>

<div class="fab-container" id="fabContainer">
    <ul class="fab-options">
        <!-- Install App Button (hidden when installed) -->
        <li class="fab-item" id="installAppItem" style="display: none;">
            <span class="fab-label">Install App</span>
            <button type="button" class="fab-btn bg-warning text-dark" id="installAppBtn" onclick="installPWA()">
                <i class="fas fa-download"></i>
            </button>
        </li>
        
        <!-- 8. WhatsApp Inbox -->
        <li class="fab-item">
            <span class="fab-label">WhatsApp Inbox</span>
            <a href="<?php echo url_for('admin/messaging/whatsapp/inbox.php'); ?>" class="fab-btn bg-whatsapp">
                <i class="fab fa-whatsapp"></i>
            </a>
        </li>
        
        <!-- 7. Donor Management -->
        <li class="fab-item">
            <span class="fab-label">Donor Management</span>
            <a href="<?php echo url_for('admin/donor-management/donors.php'); ?>" class="fab-btn bg-purple">
                <i class="fas fa-users"></i>
            </a>
        </li>
        
        <!-- 6. Call Center -->
        <li class="fab-item">
            <span class="fab-label">Call Center</span>
            <a href="<?php echo url_for('admin/call-center/index.php'); ?>" class="fab-btn bg-indigo">
                <i class="fas fa-headset"></i>
            </a>
        </li>

        <!-- 4. Payment Management -->
        <li class="fab-item">
            <span class="fab-label">Payment Management</span>
            <a href="<?php echo url_for('admin/donor-management/payments.php'); ?>" class="fab-btn bg-cyan">
                <i class="fas fa-money-bill-wave"></i>
            </a>
        </li>

        <!-- 5. Approve Payment -->
        <li class="fab-item">
            <span class="fab-label">Approve Payment</span>
            <a href="<?php echo url_for('admin/donations/review-pledge-payments.php'); ?>" class="fab-btn bg-success">
                <i class="fas fa-check-circle"></i>
            </a>
        </li>

        <!-- 2. Add Payment -->
        <li class="fab-item">
            <span class="fab-label">Add Payment</span>
            <a href="<?php echo url_for('admin/donations/record-pledge-payment.php'); ?>" class="fab-btn bg-teal">
                <i class="fas fa-file-invoice-dollar"></i>
            </a>
        </li>

        <!-- 1. Add Donation (Pledge) -->
        <li class="fab-item">
            <span class="fab-label">Add Donation</span>
            <a href="<?php echo url_for('registrar/index.php'); ?>" class="fab-btn bg-primary">
                <i class="fas fa-hand-holding-heart"></i>
            </a>
        </li>
    </ul>
    
    <button class="fab-main" id="fabMain" aria-label="Quick Actions">
        <i class="fas fa-plus"></i>
    </button>
</div>

<script>
(function() {
    // PWA Install functionality - wrapped in IIFE to avoid conflicts
    var fabDeferredPrompt = null;

    function isAppInstalled() {
        return window.matchMedia('(display-mode: standalone)').matches || 
               window.navigator.standalone === true;
    }

    function updateInstallButton() {
        var installItem = document.getElementById('installAppItem');
        if (!installItem) return;
        
        if (isAppInstalled()) {
            installItem.style.display = 'none';
        } else {
            installItem.style.display = 'flex';
        }
    }

    // Expose install function globally
    window.installPWA = async function() {
        if (fabDeferredPrompt) {
            fabDeferredPrompt.prompt();
            var result = await fabDeferredPrompt.userChoice;
            
            if (result.outcome === 'accepted') {
                alert('App installed successfully!');
            }
            fabDeferredPrompt = null;
            updateInstallButton();
        } else if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
            alert('To install on iOS:\n\n1. Tap Share (⬆️)\n2. Tap "Add to Home Screen"\n3. Tap "Add"');
        } else {
            alert('To install:\n\n1. Open browser menu (⋮)\n2. Tap "Install App" or "Add to Home Screen"');
        }
    };

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        fabDeferredPrompt = e;
        console.log('[FAB] Install prompt captured');
        updateInstallButton();
    });

    window.addEventListener('appinstalled', function() {
        console.log('[FAB] App installed');
        fabDeferredPrompt = null;
        updateInstallButton();
    });

    document.addEventListener('DOMContentLoaded', function() {
        var fabContainer = document.getElementById('fabContainer');
        var fabMain = document.getElementById('fabMain');
        
        if (fabMain && fabContainer) {
            fabMain.addEventListener('click', function(e) {
                e.stopPropagation();
                fabContainer.classList.toggle('active');
                fabMain.classList.toggle('active');
            });

            document.addEventListener('click', function(e) {
                if (!fabContainer.contains(e.target)) {
                    fabContainer.classList.remove('active');
                    fabMain.classList.remove('active');
                }
            });
        }
        
        updateInstallButton();
    });
})();
</script>
