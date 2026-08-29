document.addEventListener('DOMContentLoaded', () => {
    // Scroll Animations
    const observerOptions = {
        root: null,
        rootMargin: '0px',
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target); // Only animate once
            }
        });
    }, observerOptions);

    const animatedElements = document.querySelectorAll('.fade-in-up');
    animatedElements.forEach(el => observer.observe(el));

    // Lightbox Functionality
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    const floorMapImg = document.querySelector('.floor-map-img');
    const closeBtn = document.querySelector('.lightbox-close');

    if (floorMapImg && lightbox) {
        floorMapImg.addEventListener('click', () => {
            lightbox.classList.add('active');
            lightboxImg.src = floorMapImg.src;
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        });

        const closeLightbox = () => {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        };

        closeBtn.addEventListener('click', closeLightbox);
        
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && lightbox.classList.contains('active')) {
                closeLightbox();
            }
        });
    }

    // Bank Details Drawer
    const donateNowBtn = document.getElementById('donateNowBtn');
    const bankDrawer = document.getElementById('bankDrawer');
    const bankDrawerOverlay = document.getElementById('bankDrawerOverlay');
    const bankDrawerClose = document.getElementById('bankDrawerClose');

    if (donateNowBtn && bankDrawer && bankDrawerOverlay) {
        const openBankDrawer = (e) => {
            e.preventDefault();
            bankDrawer.classList.add('active');
            bankDrawerOverlay.classList.add('active');
            bankDrawer.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        };

        const closeBankDrawer = () => {
            bankDrawer.classList.remove('active');
            bankDrawerOverlay.classList.remove('active');
            bankDrawer.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        };

        donateNowBtn.addEventListener('click', openBankDrawer);
        bankDrawerOverlay.addEventListener('click', closeBankDrawer);
        if (bankDrawerClose) {
            bankDrawerClose.addEventListener('click', closeBankDrawer);
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && bankDrawer.classList.contains('active')) {
                closeBankDrawer();
            }
        });

        // Copy-to-clipboard for each bank detail
        const copyButtons = bankDrawer.querySelectorAll('.bank-copy-btn');
        copyButtons.forEach((btn) => {
            btn.addEventListener('click', async () => {
                const value = btn.dataset.copyValue || '';
                const icon = btn.querySelector('i');

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(value);
                    } else {
                        const textarea = document.createElement('textarea');
                        textarea.value = value;
                        textarea.style.position = 'fixed';
                        textarea.style.opacity = '0';
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                    }

                    btn.classList.add('copied');
                    if (icon) {
                        icon.classList.remove('fa-copy');
                        icon.classList.add('fa-check');
                    }

                    setTimeout(() => {
                        btn.classList.remove('copied');
                        if (icon) {
                            icon.classList.remove('fa-check');
                            icon.classList.add('fa-copy');
                        }
                    }, 1500);
                } catch (err) {
                    console.error('Copy failed:', err);
                }
            });
        });
    }
});
