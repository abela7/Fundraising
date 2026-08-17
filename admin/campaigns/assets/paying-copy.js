(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  const defaults = config.defaults || {};
  const fieldEls = Array.prototype.slice.call(document.querySelectorAll('[data-copy-key]'));
  const previewEl = document.getElementById('dvcCopyPreview');
  const countEl = document.getElementById('dvcMsgCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  if (!fieldEls.length) {
    return;
  }
  let activeEl = fieldEls[0];

  const previewDonor = {
    name: 'Abeba',
    pledged: 400,
    paid: 120,
    balance: 280,
    phone: '07360436171'
  };

  function money(amount) {
    return '£' + Number(amount || 0).toLocaleString('en-GB', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function previewText(template) {
    return String(template || '')
      .split('{name}').join(previewDonor.name)
      .split('{pledge_amount}').join(money(previewDonor.pledged))
      .split('{total_paid}').join(money(previewDonor.paid))
      .split('{remaining_amount}').join(money(previewDonor.balance))
      .split('{phone}').join(previewDonor.phone);
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
    if (countEl && activeEl) {
      const max = activeEl.getAttribute('maxlength') || '';
      countEl.textContent = max ? (activeEl.value.length + ' / ' + max) : '';
    }
    if (!previewEl) return;
    previewEl.innerHTML = fieldEls.map(function (el) {
      return '<div class="dvc-status-card dvc-am-text mb-2"><div class="dvc-status-footer">'
        + escapeHtml(previewText(el.value)).replace(/\n/g, '<br>')
        + '</div></div>';
    }).join('');
  }

  function insertToken(token) {
    const el = activeEl || fieldEls[0];
    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    const max = Number(el.getAttribute('maxlength') || 4000);
    const next = el.value.slice(0, start) + token + el.value.slice(end);
    if (next.length > max) return;
    el.value = next;
    const pos = start + token.length;
    el.focus();
    if (typeof el.setSelectionRange === 'function') {
      el.setSelectionRange(pos, pos);
    }
    updatePreview();
  }

  fieldEls.forEach(function (el) {
    el.addEventListener('focus', function () {
      activeEl = el;
      updatePreview();
    });
    el.addEventListener('input', updatePreview);
  });

  document.querySelectorAll('.dvc-var-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      insertToken(btn.getAttribute('data-token') || '');
    });
  });

  const resetBtn = document.getElementById('dvcResetCopy');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      fieldEls.forEach(function (el) {
        const key = el.getAttribute('data-copy-key');
        el.value = defaults[key] || '';
      });
      updatePreview();
    });
  }

  document.getElementById('dvcSaveCopy').addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        action: 'save_paying_pages'
      });
      fieldEls.forEach(function (el) {
        body.set(el.getAttribute('data-copy-key'), el.value);
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
      showFlash('Page saved. Donors will see every line you edited.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save this page.', true);
    }
  });

  updatePreview();
})();
