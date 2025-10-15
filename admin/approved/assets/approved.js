// Enhanced JavaScript for approved page interactions and animations

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips for enhanced user experience
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Add click animations to approved items
    const approvedItems = document.querySelectorAll('.approved-item');
    approvedItems.forEach((item, index) => {
        // Stagger animation for visual appeal
        item.style.animationDelay = `${index * 0.1}s`;
        
        // Add ripple effect on click
        item.addEventListener('click', function(e) {
            if (!e.target.closest('button') && !e.target.closest('form')) {
                createRipple(e, this);
            }
        });
    });

    // Enhanced button interactions
    const actionButtons = document.querySelectorAll('.approved-actions .btn');
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px) scale(1.05)';
        });
        
        button.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Add smooth scroll behavior for better UX
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Real-time search enhancement (if search input exists)
    const searchInputs = document.querySelectorAll('input[type="text"], input[type="search"]');
    searchInputs.forEach(input => {
        let searchTimeout;
        input.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                // Add visual feedback for search
                if (this.value.length > 0) {
                    this.parentElement.classList.add('searching');
                } else {
                    this.parentElement.classList.remove('searching');
                }
            }, 300);
        });
    });

    // Add loading states for forms
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitButton = this.querySelector('button[type="submit"]');
            if (submitButton) {
                const originalText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                
                // Restore button after form submission (or timeout)
                setTimeout(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }, 2000);
            }
        });
    });
});

// Create ripple effect for interactive elements
function createRipple(event, element) {
    const ripple = document.createElement('span');
    const rect = element.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = event.clientX - rect.left - size / 2;
    const y = event.clientY - rect.top - size / 2;
    
    ripple.style.width = ripple.style.height = size + 'px';
    ripple.style.left = x + 'px';
    ripple.style.top = y + 'px';
    ripple.classList.add('ripple');
    
    element.appendChild(ripple);
    
    // Remove ripple after animation
    setTimeout(() => {
        ripple.remove();
    }, 600);
}

// Add CSS for ripple effect
const style = document.createElement('style');
style.textContent = `
    .ripple {
        position: absolute;
        border-radius: 50%;
        background: rgba(0, 123, 255, 0.3);
        transform: scale(0);
        animation: ripple-animation 0.6s ease-out;
        pointer-events: none;
        z-index: 1;
    }
    
    @keyframes ripple-animation {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
    
    .searching {
        border-color: #007bff !important;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25) !important;
    }
    
    .approved-item {
        position: relative;
        overflow: hidden;
    }
`;
document.head.appendChild(style);

// Enhanced modal functionality
if (typeof bootstrap !== 'undefined') {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('show.bs.modal', function() {
            // Add backdrop blur effect
            document.body.classList.add('modal-open');
            document.body.style.overflow = 'hidden';
        });
        
        modal.addEventListener('hidden.bs.modal', function() {
            // Remove backdrop blur effect
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
        });
    });
}

// Add keyboard navigation
document.addEventListener('keydown', function(e) {
    // Escape key to close modals
    if (e.key === 'Escape') {
        const openModal = document.querySelector('.modal.show');
        if (openModal) {
            const modal = bootstrap.Modal.getInstance(openModal);
            if (modal) modal.hide();
        }
    }
    
    // Tab navigation enhancement
    if (e.key === 'Tab') {
        const focusedElement = document.activeElement;
        if (focusedElement && focusedElement.classList.contains('btn')) {
            focusedElement.addEventListener('focus', function() {
                this.style.outline = '2px solid #007bff';
                this.style.outlineOffset = '2px';
            });
            
            focusedElement.addEventListener('blur', function() {
                this.style.outline = 'none';
            });
        }
    }
});

