// Mobile navigation for registrar messages
document.addEventListener('DOMContentLoaded', function() {
    const chatWrap = document.getElementById('chatWrap');
    const backBtn = document.getElementById('backToList');
    
    // Back to conversation list on mobile
    if (backBtn) {
        backBtn.addEventListener('click', function() {
            chatWrap.classList.remove('show-thread');
        });
    }
    
    // Function to show thread when conversation is clicked (mobile)
    window.showThread = function() {
        if (window.innerWidth <= 992) {
            chatWrap.classList.add('show-thread');
        }
    };
    
    // Function to go back to list (mobile)
    window.backToList = function() {
        if (window.innerWidth <= 992) {
            chatWrap.classList.remove('show-thread');
        }
    };
});