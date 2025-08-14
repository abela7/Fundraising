// Error Pages JavaScript

// Add some interactive features to error pages
document.addEventListener('DOMContentLoaded', function() {
    // Add click effect to error icon
    const errorIcon = document.querySelector('.error-icon');
    if (errorIcon) {
        errorIcon.addEventListener('click', function() {
            this.style.animation = 'none';
            this.offsetHeight; // Trigger reflow
            this.style.animation = 'bounceIn 1s ease-out';
        });
    }
    
    // Add hover effects to buttons
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.05)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
    
    // Add keyboard navigation
    document.addEventListener('keydown', function(e) {
        // Press 'H' to go home
        if (e.key.toLowerCase() === 'h') {
            window.location.href = '../dashboard/';
        }
        
        // Press 'B' to go back
        if (e.key.toLowerCase() === 'b') {
            history.back();
        }
        
        // Press 'R' to reload
        if (e.key.toLowerCase() === 'r') {
            window.location.reload();
        }
    });
    
    // Show keyboard shortcuts hint
    setTimeout(() => {
        if (window.innerWidth > 768) { // Only on desktop
            const hint = document.createElement('div');
            hint.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: rgba(0, 0, 0, 0.8);
                color: white;
                padding: 1rem;
                border-radius: 8px;
                font-size: 0.75rem;
                z-index: 9999;
                opacity: 0;
                transition: opacity 0.3s ease;
            `;
            hint.innerHTML = `
                <div style="margin-bottom: 0.5rem; font-weight: bold;">Keyboard Shortcuts:</div>
                <div>H - Go Home</div>
                <div>B - Go Back</div>
                <div>R - Reload</div>
            `;
            
            document.body.appendChild(hint);
            
            // Fade in
            setTimeout(() => {
                hint.style.opacity = '1';
            }, 100);
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                hint.style.opacity = '0';
                setTimeout(() => hint.remove(), 300);
            }, 5000);
        }
    }, 2000);
    
    // Add some particle effects on 404 page
    if (document.querySelector('.error-code')?.textContent === '404') {
        createParticles();
    }
});

// Create floating particles for 404 page
function createParticles() {
    const container = document.querySelector('.error-container');
    
    for (let i = 0; i < 20; i++) {
        setTimeout(() => {
            const particle = document.createElement('div');
            particle.style.cssText = `
                position: absolute;
                width: 4px;
                height: 4px;
                background: var(--accent);
                border-radius: 50%;
                pointer-events: none;
                opacity: 0;
                left: ${Math.random() * 100}%;
                top: ${Math.random() * 100}%;
            `;
            
            container.appendChild(particle);
            
            // Animate particle
            particle.style.transition = 'all 3s ease-out';
            particle.style.opacity = '0.6';
            particle.style.transform = `translateY(-100px) translateX(${Math.random() * 200 - 100}px)`;
            
            // Remove particle after animation
            setTimeout(() => {
                particle.remove();
            }, 3000);
        }, i * 200);
    }
    
    // Repeat particles every 10 seconds
    setTimeout(createParticles, 10000);
}
