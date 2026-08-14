(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  let defaultMessage = config.default_message
    || 'ሰላም ጤና ይስጥልን የተከበሩ {name}። ከሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተክርስቲያን ነው።';
  const bodyEl = document.getElementById('dvcFirstMessageBody');
  const previewEl = document.getElementById('dvcMsgPreview');
  const countEl = document.getElementById('dvcMsgCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  if (!bodyEl) return;

  const previewDonor = {
    name: 'Abeba',
    pledged: 400,
    paid: 120,
    balance: 280
  };

  function money(amount) {
    return '£' + Number(amount || 0).toLocaleString('en-GB', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function previewText(template) {
    return String(template || '')
      .split('{name}').join(previewDonor.name)
      .split('{pledge_amount}').join(money(previewDonor.pledged))
      .split('{total_paid}').join(money(previewDonor.paid))
      .split('{remaining_amount}').join(money(previewDonor.balance));
  }

  function showFlash(message, isError) {
    if (!flashEl) return;
    flashEl.textContent = message;
    flashEl.classList.remove('d-none', 'alert-warning', 'alert-success');
    flashEl.classList.add(isError ? 'alert-warning' : 'alert-success');
    window.clearTimeout(showFlash._t);
    showFlash._t = window.setTimeout(function () {
      flashEl.classList.add('d-none');
    }, 3200);
  }

  function updatePreview() {
    if (previewEl) previewEl.textContent = previewText(bodyEl.value);
    if (countEl) countEl.textContent = bodyEl.value.length + ' / 4000';
  }

  function insertToken(token) {
    const start = bodyEl.selectionStart || 0;
    const end = bodyEl.selectionEnd || 0;
    const value = bodyEl.value;
    bodyEl.value = value.slice(0, start) + token + value.slice(end);
    const pos = start + token.length;
    bodyEl.focus();
    bodyEl.setSelectionRange(pos, pos);
    updatePreview();
  }

  document.querySelectorAll('.dvc-var-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      insertToken(btn.getAttribute('data-token') || '');
    });
  });
  bodyEl.addEventListener('input', updatePreview);

  const resetBtn = document.getElementById('dvcResetMessage');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      bodyEl.value = defaultMessage;
      updatePreview();
    });
  }

  document.getElementById('dvcSaveMessage').addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        action: 'save_message',
        first_message: bodyEl.value
      });
      const res = await fetch(saveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
        body: body.toString()
      });
      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Could not save.');
      }
      showFlash('First message saved. It has not been sent.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save first message.', true);
    }
  });

  updatePreview();
})();
