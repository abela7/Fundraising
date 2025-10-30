// Grid Allocation Viewer JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Table search/filter functionality (client-side enhancement)
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
    }
    
    // Highlight matching cells
    const urlParams = new URLSearchParams(window.location.search);
    const searchTerm = urlParams.get('search');
    if (searchTerm) {
        const tableCells = document.querySelectorAll('#allocationsTable tbody td');
        tableCells.forEach(cell => {
            if (cell.textContent.toLowerCase().includes(searchTerm.toLowerCase())) {
                cell.style.backgroundColor = '#fff3cd';
            }
        });
    }
});

