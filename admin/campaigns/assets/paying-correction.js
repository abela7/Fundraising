(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  const defaults = {
    message: config.default_correction_message || '',
    ask: config.default_correction_ask || '',
    amountLabel: config.default_correction_amount_label || 'የተከፈለ መጠን (£)',
    methodAsk: config.default_correction_method_ask || 'እንዴት ከፍለዋል?',
    cashLabel: config.default_correction_cash_label || 'ጥሬ ገንዘብ',
    cardLabel: config.default_correction_card_label || 'ባንክ ትራንስፈር'
  };
  const messageEl = document.getElementById('dvcCorrectionMessage');
  const askEl = document.getElementById('dvcCorrectionAsk');
  const amountEl = document.getElementById('dvcCorrectionAmount');
  const methodAskEl = document.getElementById('dvcCorrectionMethodAsk');
  const cashEl = document.getElementById('dvcCorrectionCash');
  const cardEl = document.getElementById('dvcCorrectionCard');
  const previewEl = document.getElementById('dvcCorrectionPreview');
  const countEl = document.getElementById('dvcMsgCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  if (!messageEl || !askEl || !amountEl || !methodAskEl || !cashEl || !cardEl) return;

  const fieldEls = [messageEl, askEl, amountEl, methodAskEl, cashEl, cardEl];
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
      + '<div class="dvc-status-title">' + fieldHtml(askEl) + '</div>'
      + '<div class="dvc-status-row"><span class="dvc-status-label">' + (fieldHtml(amountEl) || escapeHtml(defaults.amountLabel)) + '</span><span class="dvc-status-value">£80.00</span></div>'
      + '</div>'
      + '<div class="dvc-status-card dvc-am-text mt-2">'
      + '<div class="dvc-status-title">' + (fieldHtml(amountEl) || escapeHtml(defaults.amountLabel)) + '</div>'
      + '<div class="dvc-status-row dvc-status-remain-row"><span class="dvc-status-value">£80.00</span></div>'
      + '<div class="dvc-status-title mt-2">' + (fieldHtml(methodAskEl) || escapeHtml(defaults.methodAsk)) + '</div>'
      + '<div class="dvc-status-footer">' + (fieldHtml(cashEl) || escapeHtml(defaults.cashLabel)) + ' · ' + (fieldHtml(cardEl) || escapeHtml(defaults.cardLabel)) + '</div>'
      + '</div>';
  }

  function insertToken(token) {
    const el = activeEl || messageEl;
    const start = el.selectionStart || 0;
    const end = el.selectionEnd || 0;
    const value = el.value;
    const max = (el === amountEl || el === cashEl || el === cardEl) ? 200 : 4000;
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

  const resetBtn = document.getElementById('dvcResetCorrection');
  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      messageEl.value = defaults.message;
      askEl.value = defaults.ask;
      amountEl.value = defaults.amountLabel;
      methodAskEl.value = defaults.methodAsk;
      cashEl.value = defaults.cashLabel;
      cardEl.value = defaults.cardLabel;
      updatePreview();
    });
  }

  document.getElementById('dvcSaveCorrection').addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        group: config.group || 'pledge_paying',
        action: 'save_correction',
        correction_message: messageEl.value,
        correction_ask: askEl.value,
        correction_amount_label: amountEl.value,
        correction_method_ask: methodAskEl.value,
        correction_cash_label: cashEl.value,
        correction_card_label: cardEl.value
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
      showFlash('After-no page saved. Donors who say no will see it next.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save the after-no page.', true);
    }
  });

  updatePreview();
})();
