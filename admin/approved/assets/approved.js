document.addEventListener('DOMContentLoaded', function () {
    // Animate table rows on load
    const tableRows = document.querySelectorAll('.table tbody tr');
    tableRows.forEach((row, index) => {
        row.style.animation = `fadeInUp 0.5s ease-out ${index * 0.05}s forwards`;
    });

    // Add ripple effect to buttons
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(button => {
        button.addEventListener('click', function (e) {
            const x = e.clientX - e.target.offsetLeft;
            const y = e.clientY - e.target.offsetTop;

            const ripples = document.createElement('span');
            ripples.style.left = x + 'px';
            ripples.style.top = y + 'px';
            this.appendChild(ripples);

            setTimeout(() => {
                ripples.remove();
            }, 1000);
        });
    });
});

// Add CSS for animations
const style = document.createElement('style');
style.innerHTML = `
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.btn span {
    position: absolute;
    background: #fff;
    transform: translate(-50%, -50%);
    pointer-events: none;
    border-radius: 50%;
    animation: ripple 1s linear infinite;
}

@keyframes ripple {
    0% {
        width: 0;
        height: 0;
        opacity: 0.5;
    }
    100% {
        width: 500px;
        height: 500px;
        opacity: 0;
    }
}
`;
document.head.appendChild(style);
