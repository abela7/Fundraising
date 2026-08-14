(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  let defaultStatus = config.default_status || 'ይህ መረጃ ትክክል ነው?';
  let defaultTitle = config.default_status_title || 'ባለን መረጃ መሰረት';
  const titleEl = document.getElementById('dvcStatusTitle');
  const bodyEl = document.getElementById('dvcStatusBody');
  const previewEl = document.getElementById('dvcStatusPreview');
  const countEl = document.getElementById('dvcMsgCount');
  const titleCountEl = document.getElementById('dvcTitleCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  if (!bodyEl) return;

  let activeEl = titleEl || bodyEl;

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
    if (countEl) countEl.textContent = bodyEl.value.length + ' / 4000';
    if (titleCountEl && titleEl) titleCountEl.textContent = titleEl.value.length + ' / 200';
    if (!previewEl) return;
    const title = titleEl ? escapeHtml(previewText(titleEl.value)).replace(/\n/g, '<br>') : '';
    const footer = escapeHtml(previewText(bodyEl.value)).replace(/\n/g, '<br>');
    previewEl.innerHTML =
      (title ? '<div class="dvc-status-title">' + title + '</div>' : '')
      + '<div class="dvc-status-row">'
      + '<span class="dvc-status-label">ጠቅላላ የገቡት ቃልኪዳን መጠን</span>'
      + '<span class="dvc-status-value">' + escapeHtml(money(previewDonor.pledged)) + '</span>'
      + '</div>'
      + '<div class="dvc-status-row">'
      + '<span class="dvc-status-label">እስካሁን የከፈሉት</span>'
      + '<span class="dvc-status-value dvc-status-paid">' + escapeHtml(money(previewDonor.paid)) + '</span>'
      + '</div>'
      + '<div class="dvc-status-row dvc-status-remain-row">'
      + '<span class="dvc-status-label">ቀሪ</span>'
      + '<span class="dvc-status-value dvc-status-remain">' + escapeHtml(money(previewDonor.balance)) + '</span>'
      + '</div>'
      + (footer ? '<div class="dvc-status-footer">' + footer + '</div>' : '');
  }

  function insertToken(token) {
    const el = activeEl || bodyEl;
    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    const value = el.value;
    const max = el === titleEl ? 200 : 4000;
    const next = value.slice(0, start) + token + value.slice(end);
    if (next.length > max) return;
    el.value = next;
    const pos = start + token.length;
    el.focus();
    if (typeof el.setSelectionRange === 'function') {
      el.setSelectionRange(pos, pos);
    }
    updatePreview();
  }

  if (titleEl) {
    titleEl.addEventListener('focus', function () {
      activeEl = titleEl;
    });
    titleEl.addEventListener('input', updatePreview);
  }
  bodyEl.addEventListener('focus', function () {
    activeEl = bodyEl;
  });
  bodyEl.addEventListener('input', updatePreview);

  document.querySelectorAll('.dvc-var-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      insertToken(btn.getAttribute('data-token') || '');
    });
  });

  const resetBtn = document.getElementById('dvcResetStatus');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (titleEl) titleEl.value = defaultTitle;
      bodyEl.value = defaultStatus;
      updatePreview();
    });
  }

  document.getElementById('dvcSaveStatus').addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        action: 'save_status',
        status_title: titleEl ? titleEl.value : '',
        status_message: bodyEl.value
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
      showFlash('Status page saved. Donors will see the title above the amounts.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save the status page.', true);
    }
  });

  updatePreview();
})();
