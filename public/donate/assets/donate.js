(function() {
    const form = document.getElementById('publicDonationForm');
    if (!form) {
        return;
    }

    const loadedAtInput = document.getElementById('form_loaded_at');
    if (loadedAtInput) {
        loadedAtInput.value = String(Math.floor(Date.now() / 1000));
    }

    const submitBtn = form.querySelector('[data-submit-btn]');
    let isSubmitting = false;

    const normalizePhone = (value) => {
        if (!value) {
            return '';
        }
        return value.replace(/[^0-9+]/g, '');
    };

    const isValidPhone = (value) => {
        return /^[+]?[0-9]{7,20}$/.test(value);
    };

    const setFormBusy = (busy) => {
        if (!submitBtn) {
            return;
        }

        submitBtn.disabled = busy;
        submitBtn.innerHTML = busy
            ? '<i class="fas fa-spinner fa-spin me-2"></i>Sending...'
            : '<i class="fas fa-paper-plane"></i> Submit Request';
    };

    form.addEventListener('submit', function(event) {
        if (isSubmitting) {
            event.preventDefault();
            return;
        }

        const nameInput = form.querySelector('#name');
        const phoneInput = form.querySelector('#phone');
        const messageInput = form.querySelector('#message');

        const name = (nameInput?.value || '').trim();
        const phone = normalizePhone((phoneInput?.value || '').trim());
        const message = (messageInput?.value || '').trim();

        const errors = [];

        if (!name || name.length < 2 || name.length > 120) {
            errors.push('Name must be between 2 and 120 characters.');
        }

        if (!isValidPhone(phone)) {
            errors.push('Please provide a valid phone number.');
        }

        if (message.length > 2000) {
            errors.push('Message must be 2,000 characters or less.');
        }

        if (errors.length > 0) {
            event.preventDefault();
            alert(errors.join('\n'));
            return;
        }

        if (phoneInput) {
            phoneInput.value = phone;
        }

        isSubmitting = true;
        setFormBusy(true);
    });
})();
