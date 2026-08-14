(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || (document.getElementById('dvcCsrf') || {}).value || '';
  const saveUrl = 'api/first-message.php';
  let defaultMessage = 'ሰላም ጤና ይስጥልን የተከበሩ {name}። ከሊቨርፑል መካነ ቅዱሳን አቡነ ተክለሃይማኖት ቤተክርስቲያን ነው።';
  const bodyEl = document.getElementById('dvcFirstMessageBody');
  const previewEl = document.getElementById('dvcMsgPreview');
  const countEl = document.getElementById('dvcMsgCount');
  const flashEl = document.getElementById('dvcMsgFlash');
  const modeAll = document.getElementById('dvcModeAll');
  const modeSelected = document.getElementById('dvcModeSelected');
  const selectCount = document.getElementById('dvcSelectCount');
  const checkPage = document.getElementById('dvcCheckPage');
  if (!bodyEl) return;

  let recipientMode = 'all';
  const selectedIds = new Set();
  let previewDonor = {
    name: 'Abeba',
    pledged: 400,
    paid: 120,
    balance: 280
  };
  let groupTotal = 0;
  let visibleRows = [];

  function money(amount) {
    return '£' + Number(amount || 0).toLocaleString('en-GB', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function previewText(template) {
    return String(template || '')
      .split('{name}').join(previewDonor.name || 'Abeba')
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

  function updateCount() {
    if (countEl) countEl.textContent = bodyEl.value.length + ' / 4000';
  }

  function updatePreview() {
    if (previewEl) previewEl.textContent = previewText(bodyEl.value);
    updateCount();
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

  function canTick() {
    return recipientMode === 'selected';
  }

  function isSelected(id) {
    if (recipientMode === 'all') return true;
    return selectedIds.has(Number(id));
  }

  function refreshChecks() {
    document.querySelectorAll('.dvc-row-check').forEach(function (box) {
      const id = Number(box.getAttribute('data-donor-id') || 0);
      box.disabled = !canTick();
      box.checked = isSelected(id);
    });
    if (checkPage) {
      checkPage.disabled = !canTick() || visibleRows.length === 0;
      const allVisible = visibleRows.length > 0 && visibleRows.every(function (row) {
        return selectedIds.has(Number(row.donor_id || row.id || 0));
      });
      checkPage.checked = canTick() && allVisible;
    }
    if (selectCount) {
      if (recipientMode === 'all') {
        selectCount.textContent = groupTotal > 0
          ? 'All ' + groupTotal.toLocaleString() + ' still-paying donors'
          : 'All still-paying donors';
      } else {
        selectCount.textContent = selectedIds.size.toLocaleString() + ' selected';
      }
    }
  }

  function setMode(mode) {
    recipientMode = mode === 'selected' ? 'selected' : 'all';
    if (modeAll) modeAll.checked = recipientMode === 'all';
    if (modeSelected) modeSelected.checked = recipientMode === 'selected';
    document.querySelectorAll('.dvc-mode-option').forEach(function (el) {
      const input = el.querySelector('input');
      el.classList.toggle('is-active', !!(input && input.checked));
    });
    refreshChecks();
  }

  window.DVC_CAMPAIGN = {
    isSelected: isSelected,
    canTick: canTick,
    onRows: function (rows) {
      visibleRows = rows || [];
      if (visibleRows[0]) {
        previewDonor = {
          name: visibleRows[0].name || 'Abeba',
          pledged: visibleRows[0].pledged,
          paid: visibleRows[0].paid,
          balance: visibleRows[0].balance
        };
        updatePreview();
      }
      const kpi = document.getElementById('kpiDonors');
      if (kpi) {
        const n = parseInt(String(kpi.textContent).replace(/,/g, ''), 10);
        if (isFinite(n)) groupTotal = n;
      }
      refreshChecks();
    }
  };

  document.querySelectorAll('.dvc-var-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      insertToken(btn.getAttribute('data-token') || '');
    });
  });
  bodyEl.addEventListener('input', updatePreview);
  document.getElementById('dvcResetMessage').addEventListener('click', function () {
    bodyEl.value = defaultMessage;
    updatePreview();
  });

  if (modeAll) {
    modeAll.addEventListener('change', function () {
      if (modeAll.checked) setMode('all');
    });
  }
  if (modeSelected) {
    modeSelected.addEventListener('change', function () {
      if (modeSelected.checked) setMode('selected');
    });
  }

  document.getElementById('dataBody').addEventListener('change', function (event) {
    const box = event.target.closest('.dvc-row-check');
    if (!box || !canTick()) return;
    const id = Number(box.getAttribute('data-donor-id') || 0);
    if (id <= 0) return;
    if (box.checked) selectedIds.add(id);
    else selectedIds.delete(id);
    refreshChecks();
  });

  if (checkPage) {
    checkPage.addEventListener('change', function () {
      if (!canTick()) return;
      visibleRows.forEach(function (row) {
        const id = Number(row.donor_id || row.id || 0);
        if (id <= 0) return;
        if (checkPage.checked) selectedIds.add(id);
        else selectedIds.delete(id);
      });
      refreshChecks();
    });
  }

  document.getElementById('dvcSelectPage').addEventListener('click', function () {
    setMode('selected');
    visibleRows.forEach(function (row) {
      const id = Number(row.donor_id || row.id || 0);
      if (id > 0) selectedIds.add(id);
    });
    refreshChecks();
  });
  document.getElementById('dvcClearSelected').addEventListener('click', function () {
    setMode('selected');
    selectedIds.clear();
    refreshChecks();
  });

  async function post(action, extra) {
    const body = new URLSearchParams(Object.assign({
      csrf_token: csrf,
      action: action
    }, extra || {}));
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
    return data;
  }

  document.getElementById('dvcSaveMessage').addEventListener('click', async function () {
    try {
      await post('save_message', { first_message: bodyEl.value });
      showFlash('First message saved. It has not been sent.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save first message.', true);
    }
  });

  document.getElementById('dvcSaveRecipients').addEventListener('click', async function () {
    try {
      const data = await post('save_recipients', {
        recipient_mode: recipientMode,
        donor_ids: JSON.stringify(Array.from(selectedIds))
      });
      recipientMode = data.recipient_mode === 'selected' ? 'selected' : 'all';
      selectedIds.clear();
      (data.donor_ids || []).forEach(function (id) { selectedIds.add(Number(id)); });
      setMode(recipientMode);
      showFlash('Recipients saved. Messages are not sent yet.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save recipients.', true);
    }
  });

  async function loadSettings() {
    try {
      const res = await fetch(saveUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok || !data.success) return;
      if (data.first_message) bodyEl.value = data.first_message;
      if (data.default_message) defaultMessage = data.default_message;
      (data.donor_ids || []).forEach(function (id) { selectedIds.add(Number(id)); });
      setMode(data.recipient_mode === 'selected' ? 'selected' : 'all');
      updatePreview();
    } catch (err) {
      updatePreview();
    }
  }

  updatePreview();
  loadSettings();
})();
