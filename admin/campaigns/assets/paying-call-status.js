(function () {
  document.addEventListener('change', function (e) {
    const select = e.target && e.target.closest
      ? e.target.closest('[data-call-status-select]')
      : null;
    if (!select) {
      return;
    }
    saveCallStatus(select);
  });

  async function saveCallStatus(select) {
    const config = window.PAY_REPORT || {};
    const csrf = config.csrf || '';
    const donorId = Number(select.getAttribute('data-donor-id') || 0);
    const status = String(select.value || '');
    const previous = select.getAttribute('data-status') || 'pending';
    if (donorId <= 0 || status === '') {
      return;
    }
    if (status === previous) {
      return;
    }
    select.disabled = true;
    try {
      const body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('donor_id', String(donorId));
      body.set('call_status', status);
      const res = await fetch('api/paying-call-status.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
      });
      const data = await res.json();
      if (!res.ok || data.success === false) {
        throw new Error(data.error || 'Could not save the call status.');
      }
      const saved = String(data.call_status || status);
      select.value = saved;
      select.setAttribute('data-status', saved);
      if (typeof config.onStatusSaved === 'function') {
        config.onStatusSaved(data);
      }
    } catch (err) {
      select.value = previous;
      window.alert(err instanceof Error ? err.message : 'Could not save the call status.');
    } finally {
      select.disabled = false;
    }
  }
})();
