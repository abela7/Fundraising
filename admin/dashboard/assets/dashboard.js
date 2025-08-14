// Top-Tier Dashboard JavaScript with Magic Interactions
document.addEventListener('DOMContentLoaded', function() {
  
  // Add loading states and smooth transitions
  const kpiCards = document.querySelectorAll('.kpi-card');
  
  // Stagger animation for KPI cards
  kpiCards.forEach((card, index) => {
    card.style.animationDelay = `${index * 0.1}s`;
  });
  
  // Add hover effects with scale and glow
  kpiCards.forEach(card => {
    card.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-8px) scale(1.02)';
      this.style.boxShadow = '0 20px 40px rgba(10, 98, 134, 0.2)';
    });
    
    card.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0) scale(1)';
      this.style.boxShadow = '';
    });
  });
  
  // Real-time data updates with visual feedback
  function updateCounters() {
    const params = new URLSearchParams({ token: window.DASHBOARD_TOKEN || '' });
    fetch(`../../api/totals.php?${params.toString()}`)
      .then(response => response.json())
      .then(data => {
        // Add pulse animation when data updates
        const values = document.querySelectorAll('.kpi-value');
        values.forEach(value => {
          value.classList.add('animate-pulse');
          setTimeout(() => value.classList.remove('animate-pulse'), 1000);
        });
        
        // Update values with smooth counter animation
        const paidEl = document.querySelector('.stat-card:nth-child(1) .stat-value');
        const pledgedEl = document.querySelector('.stat-card:nth-child(2) .stat-value');
        const grandEl = document.querySelector('.stat-card:nth-child(3) .stat-value');

        if (paidEl) animateCounter(paidEl, data.paid_total);
        if (pledgedEl) animateCounter(pledgedEl, data.pledged_total);
        if (grandEl) animateCounter(grandEl, data.grand_total);

        // Update progress bar percentage text if present
        const percentEl = document.querySelector('.progress-percent strong');
        if (percentEl && typeof data.progress_pct === 'number') {
          percentEl.textContent = data.progress_pct.toFixed(0) + '%';
        }
      })
      .catch(error => console.error('Error fetching data:', error));
  }
  
  // Smooth counter animation
  function animateCounter(element, targetValue) {
    const currentValue = parseFloat((element.textContent || '0').replace(/[^\d.]/g, '')) || 0;
    const duration = 1000;
    const startTime = performance.now();
    
    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      
      // Easing function for smooth animation
      const easeOutQuart = 1 - Math.pow(1 - progress, 4);
      const current = currentValue + (targetValue - currentValue) * easeOutQuart;
      
      const formatted = new Intl.NumberFormat('en-GB', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
      }).format(current);
      element.textContent = `${window.DASHBOARD_CURRENCY || 'GBP'} ${formatted}`;
      
      if (progress < 1) {
        requestAnimationFrame(updateCounter);
      }
    }
    
    requestAnimationFrame(updateCounter);
  }
  
  // Add ripple effect to buttons
  document.querySelectorAll('.btn').forEach(button => {
    button.addEventListener('click', function(e) {
      const ripple = document.createElement('span');
      const rect = this.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      const x = e.clientX - rect.left - size / 2;
      const y = e.clientY - rect.top - size / 2;
      
      ripple.style.cssText = `
        position: absolute;
        width: ${size}px;
        height: ${size}px;
        left: ${x}px;
        top: ${y}px;
        background: rgba(255, 255, 255, 0.3);
        border-radius: 50%;
        transform: scale(0);
        animation: ripple 0.6s linear;
        pointer-events: none;
      `;
      
      this.style.position = 'relative';
      this.style.overflow = 'hidden';
      this.appendChild(ripple);
      
      setTimeout(() => ripple.remove(), 600);
    });
  });
  
  // Add CSS for ripple animation
  const style = document.createElement('style');
  style.textContent = `
    @keyframes ripple {
      to {
        transform: scale(4);
        opacity: 0;
      }
    }
    
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }
    
    .animate-pulse {
      animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
  `;
  document.head.appendChild(style);

  // Initial load + polling
  try { updateCounters(); } catch(e) {}
  const intervalMs = Math.max(2000, (window.DASHBOARD_REFRESH_SECS || 5) * 1000);
  setInterval(updateCounters, intervalMs);
  
});
