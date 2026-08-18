(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  const defaults = {
    message: config.default_contact_message || '',
    ask: config.default_contact_ask || '',
    labels: config.default_contact_labels || {
      date: 'ቀን',
      time: 'ሰዓት',
      method: 'እንዴት እንደውልልዎ?',
      whatsapp: 'የWhatsApp ጥሪ',
      phone: 'የስልክ ጥሪ'
    },
    callback: config.default_callback_message || ''
  };
  const messageEl = document.getElementById('dvcContactMessage');
  const callbackEl = document.getElementById('dvcCallbackMessage');
  const askEl = document.getElementById('dvcContactAsk');
  const dateEl = document.getElementById('dvcContactDate');
  const timeEl = document.getElementById('dvcContactTime');
  const methodEl = document.getElementById('dvcContactMethod');
  const whatsappEl = document.getElementById('dvcContactWhatsapp');
  const phoneEl = document.getElementById('dvcContactPhone');
  const previewEl = document.getElementById('dvcContactPreview');
  const countEl = document.getElementById('dvcMsgCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  if (!messageEl || !askEl) return;

  const fieldEls = [messageEl, callbackEl, askEl, dateEl, timeEl, methodEl, whatsappEl, phoneEl].filter(Boolean);
  let activeEl = messageEl;

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

  function fieldHtml(el) {
    return escapeHtml(previewText(el ? el.value : '')).replace(/\n/g, '<br>');
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
    if (countEl) countEl.textContent = messageEl.value.length + ' / 4000';
    if (!previewEl) return;
    previewEl.innerHTML =
      '<div class="dvc-status-card dvc-am-text">'
      + '<div class="dvc-status-footer">' + fieldHtml(messageEl) + '</div>'
      + '</div>'
      + '<div class="dvc-status-card dvc-am-text mt-2">'
      + '<div class="dvc-status-footer">' + fieldHtml(callbackEl) + '</div>'
      + '</div>'
      + '<div class="dvc-status-card dvc-am-text mt-2">'
      + '<div class="dvc-status-title">' + fieldHtml(askEl) + '</div>'
      + '<div class="dvc-status-row"><span class="dvc-status-label">' + (fieldHtml(dateEl) || escapeHtml(defaults.labels.date)) + '</span><span class="dvc-status-value">20/08/2026</span></div>'
      + '<div class="dvc-status-row"><span class="dvc-status-label">' + (fieldHtml(timeEl) || escapeHtml(defaults.labels.time)) + '</span><span class="dvc-status-value">14:30</span></div>'
      + '<div class="dvc-status-row dvc-status-remain-row"><span class="dvc-status-label">' + (fieldHtml(methodEl) || escapeHtml(defaults.labels.method)) + '</span></div>'
      + '<div class="dvc-status-footer">' + (fieldHtml(whatsappEl) || escapeHtml(defaults.labels.whatsapp)) + ' · ' + (fieldHtml(phoneEl) || escapeHtml(defaults.labels.phone)) + '</div>'
      + '</div>';
  }

  function insertToken(token) {
    const el = activeEl || messageEl;
    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    const value = el.value;
    const max = el === messageEl || el === askEl || el === callbackEl ? 4000 : 200;
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

  fieldEls.forEach(function (el) {
    el.addEventListener('focus', function () {
      activeEl = el;
    });
    el.addEventListener('input', updatePreview);
  });

  document.querySelectorAll('.dvc-var-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      insertToken(btn.getAttribute('data-token') || '');
    });
  });

  const resetBtn = document.getElementById('dvcResetContact');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      messageEl.value = defaults.message;
      if (callbackEl) callbackEl.value = defaults.callback;
      askEl.value = defaults.ask;
      if (dateEl) dateEl.value = defaults.labels.date;
      if (timeEl) timeEl.value = defaults.labels.time;
      if (methodEl) methodEl.value = defaults.labels.method;
      if (whatsappEl) whatsappEl.value = defaults.labels.whatsapp;
      if (phoneEl) phoneEl.value = defaults.labels.phone;
      updatePreview();
    });
  }

  document.getElementById('dvcSaveContact').addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        group: config.group || 'pledge_paying',
        action: 'save_contact',
        contact_message: messageEl.value,
        callback_message: callbackEl ? callbackEl.value : '',
        contact_ask: askEl.value,
        contact_date_label: dateEl ? dateEl.value : '',
        contact_time_label: timeEl ? timeEl.value : '',
        contact_method_label: methodEl ? methodEl.value : '',
        contact_whatsapp_label: whatsappEl ? whatsappEl.value : '',
        contact_phone_label: phoneEl ? phoneEl.value : ''
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
      showFlash('Contact page saved. Donors will see every line you edited.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save the contact page.', true);
    }
  });

  updatePreview();
})();
