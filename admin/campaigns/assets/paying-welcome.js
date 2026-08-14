(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  let defaultWelcome = config.default_welcome
    || 'የተከበሩ {name}፣\n\nእንኳን በደህና መጡ። ከሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተክርስቲያን ነው።';
  const bodyEl = document.getElementById('dvcWelcomeBody');
  const previewEl = document.getElementById('dvcWelcomePreview');
  const countEl = document.getElementById('dvcMsgCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  if (!bodyEl) return;

  function previewText(template) {
    return String(template || '').split('{name}').join('Abeba');
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

  const resetBtn = document.getElementById('dvcResetWelcome');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      bodyEl.value = defaultWelcome;
      updatePreview();
    });
  }

  document.getElementById('dvcSaveWelcome').addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        action: 'save_welcome',
        welcome_message: bodyEl.value
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
      showFlash('Welcome text saved. Donors will see it on the first screen.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save welcome text.', true);
    }
  });

  updatePreview();
})();
