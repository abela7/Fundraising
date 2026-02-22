/**
 * Donor Management JavaScript
 * Client-side functionality for donor management
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('Donor Management module loaded');
    
    // View Toggle functionality
    const btnGridView = document.getElementById('btnGridView');
    const btnListView = document.getElementById('btnListView');
    const tableContainer = document.getElementById('donorsTableContainer');
    
    if (btnGridView && btnListView && tableContainer) {
        // Load saved preference
        const savedView = localStorage.getItem('donorsViewPref') || 'grid';
        
        function setView(viewMode) {
            if (viewMode === 'grid') {
                tableContainer.classList.add('donors-grid-view');
                document.body.classList.remove('force-list-view');
                btnGridView.classList.add('active');
                btnListView.classList.remove('active');
            } else {
                tableContainer.classList.remove('donors-grid-view');
                document.body.classList.add('force-list-view');
                btnListView.classList.add('active');
                btnGridView.classList.remove('active');
            }
            localStorage.setItem('donorsViewPref', viewMode);
        }
        
        // Initialize view
        setView(savedView);
        
        // Bind events
        btnGridView.addEventListener('click', () => setView('grid'));
        btnListView.addEventListener('click', () => setView('list'));
    }
});

