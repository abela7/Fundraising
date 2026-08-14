(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const saveUrl = 'api/first-message.php';
  const modeAll = document.getElementById('dvcModeAll');
  const modeSelected = document.getElementById('dvcModeSelected');
  const selectCount = document.getElementById('dvcSelectCount');
  const checkPage = document.getElementById('dvcCheckPage');
  const flashEl = document.getElementById('dvcMsgFlash');
  const saveBtn = document.getElementById('dvcSaveRecipients');
  if (!saveBtn) return;

  let recipientMode = config.recipient_mode === 'selected' ? 'selected' : 'all';
  const selectedIds = new Set();
  (config.donor_ids || []).forEach(function (id) {
    selectedIds.add(Number(id));
  });
  let groupTotal = 0;
  let visibleRows = [];

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
      const kpi = document.getElementById('kpiDonors');
      if (kpi) {
        const n = parseInt(String(kpi.textContent).replace(/,/g, ''), 10);
        if (isFinite(n)) groupTotal = n;
      }
      refreshChecks();
    }
  };

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

  const dataBody = document.getElementById('dataBody');
  if (dataBody) {
    dataBody.addEventListener('change', function (event) {
      const box = event.target.closest('.dvc-row-check');
      if (!box || !canTick()) return;
      const id = Number(box.getAttribute('data-donor-id') || 0);
      if (id <= 0) return;
      if (box.checked) selectedIds.add(id);
      else selectedIds.delete(id);
      refreshChecks();
    });
  }

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

  const selectPageBtn = document.getElementById('dvcSelectPage');
  if (selectPageBtn) {
    selectPageBtn.addEventListener('click', function () {
      setMode('selected');
      visibleRows.forEach(function (row) {
        const id = Number(row.donor_id || row.id || 0);
        if (id > 0) selectedIds.add(id);
      });
      refreshChecks();
    });
  }

  const clearBtn = document.getElementById('dvcClearSelected');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      setMode('selected');
      selectedIds.clear();
      refreshChecks();
    });
  }

  saveBtn.addEventListener('click', async function () {
    try {
      const body = new URLSearchParams({
        csrf_token: csrf,
        action: 'save_recipients',
        recipient_mode: recipientMode,
        donor_ids: JSON.stringify(Array.from(selectedIds))
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
      recipientMode = data.recipient_mode === 'selected' ? 'selected' : 'all';
      selectedIds.clear();
      (data.donor_ids || []).forEach(function (id) { selectedIds.add(Number(id)); });
      setMode(recipientMode);
      showFlash('Recipients saved. Messages are not sent yet.', false);
    } catch (err) {
      showFlash(err.message || 'Could not save recipients.', true);
    }
  });

  setMode(recipientMode);
})();
