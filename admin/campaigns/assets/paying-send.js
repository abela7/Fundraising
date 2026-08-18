(function () {
  const config = window.DVC_PAGE || {};
  const csrf = config.csrf || '';
  const batchLimit = Math.max(1, Number(config.batch_limit || 8));
  const hasMessage = config.has_message === true;
  const whatsappReady = config.whatsapp_ready === true;
  const checkPage = document.getElementById('dvcCheckPage');
  const selectCount = document.getElementById('dvcSelectCount');
  const sendMeta = document.getElementById('dvcSendMeta');
  const sendBtn = document.getElementById('dvcSendNow');
  const confirmBtn = document.getElementById('dvcConfirmSend');
  const flashEl = document.getElementById('dvcMsgFlash');
  const resultEl = document.getElementById('dvcSendResult');
  const progressWrap = document.getElementById('dvcSendProgress');
  const progressBar = document.getElementById('dvcSendProgressBar');
  const modalEl = document.getElementById('dvcSendModal');
  const modalBody = document.getElementById('dvcSendModalBody');
  if (!sendBtn) return;

  const selectedIds = new Set();
  const sentIds = new Set();
  const phoneById = new Map();
  let visibleRows = [];
  let allPaying = null;
  let sending = false;
  const sendModal = modalEl && window.bootstrap ? new window.bootstrap.Modal(modalEl) : null;

  function showFlash(message, isError) {
    if (!flashEl) return;
    flashEl.textContent = message;
    flashEl.classList.remove('d-none', 'alert-warning', 'alert-success');
    flashEl.classList.add(isError ? 'alert-warning' : 'alert-success');
    window.clearTimeout(showFlash._t);
    showFlash._t = window.setTimeout(function () {
      flashEl.classList.add('d-none');
    }, 4000);
  }

  function canTick() {
    return !sending;
  }

  function isSelected(id) {
    return selectedIds.has(Number(id));
  }

  function isSent(id) {
    return sentIds.has(Number(id));
  }

  function hasPhone(id, fallbackPhone) {
    if (phoneById.has(Number(id))) {
      return phoneById.get(Number(id)) === true;
    }
    return String(fallbackPhone || '').trim() !== '';
  }

  function readyCount() {
    let n = 0;
    selectedIds.forEach(function (id) {
      if (!isSent(id) && hasPhone(id, '')) n += 1;
    });
    return n;
  }

  function skippedCount() {
    let n = 0;
    selectedIds.forEach(function (id) {
      if (!isSent(id) && !hasPhone(id, '')) n += 1;
    });
    return n;
  }

  function refreshChecks() {
    document.querySelectorAll('.dvc-row-check').forEach(function (box) {
      const id = Number(box.getAttribute('data-donor-id') || 0);
      const row = box.closest('tr');
      box.disabled = !canTick() || isSent(id);
      box.checked = isSelected(id) || isSent(id);
      if (row) {
        row.classList.toggle('dvc-row-sent', isSent(id));
        const nameWrap = row.querySelector('[data-label="Donor"] div');
        if (nameWrap && isSent(id) && !nameWrap.querySelector('.dvc-sent-flag')) {
          nameWrap.insertAdjacentHTML('beforeend', ' <span class="dvc-badge dvc-badge-new dvc-sent-flag">Sent</span>');
        }
      }
    });
    if (checkPage) {
      const openRows = visibleRows.filter(function (row) {
        return !isSent(row.donor_id || row.id);
      });
      checkPage.disabled = !canTick() || openRows.length === 0;
      checkPage.checked = openRows.length > 0 && openRows.every(function (row) {
        return selectedIds.has(Number(row.donor_id || row.id || 0));
      });
    }
    const selected = selectedIds.size;
    const ready = readyCount();
    const skipped = skippedCount();
    if (selectCount) {
      selectCount.textContent = selected === 0
        ? '0 selected'
        : selected.toLocaleString() + ' selected';
    }
    if (sendMeta) {
      if (sending) {
        sendMeta.textContent = 'Sending… keep this page open.';
      } else if (selected === 0) {
        sendMeta.textContent = 'Tick people in the table, then send.';
      } else if (ready === 0) {
        sendMeta.textContent = 'None of the selected donors have a phone number.';
      } else if (skipped > 0) {
        sendMeta.textContent = ready.toLocaleString() + ' will receive it. '
          + skipped.toLocaleString() + ' have no phone and will be skipped.';
      } else {
        sendMeta.textContent = 'Ready to send to ' + ready.toLocaleString() + ' WhatsApp number'
          + (ready === 1 ? '.' : 's.');
      }
    }
    sendBtn.disabled = sending || !hasMessage || !whatsappReady || ready === 0;
  }

  function rememberPhones(rows) {
    (rows || []).forEach(function (row) {
      const id = Number(row.donor_id || row.id || 0);
      if (id > 0) {
        phoneById.set(id, String(row.phone || '').trim() !== '');
      }
    });
  }

  window.DVC_CAMPAIGN = {
    isSelected: isSelected,
    canTick: function (id) {
      return canTick() && !isSent(id);
    },
    isSent: isSent,
    onRows: function (rows) {
      visibleRows = rows || [];
      rememberPhones(visibleRows);
      refreshChecks();
    }
  };

  const dataBody = document.getElementById('dataBody');
  if (dataBody) {
    dataBody.addEventListener('change', function (event) {
      const box = event.target.closest('.dvc-row-check');
      if (!box || !canTick()) return;
      const id = Number(box.getAttribute('data-donor-id') || 0);
      if (id <= 0 || isSent(id)) return;
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
        if (id <= 0 || isSent(id)) return;
        if (checkPage.checked) selectedIds.add(id);
        else selectedIds.delete(id);
      });
      refreshChecks();
    });
  }

  document.getElementById('dvcSelectPage').addEventListener('click', function () {
    if (!canTick()) return;
    visibleRows.forEach(function (row) {
      const id = Number(row.donor_id || row.id || 0);
      if (id > 0 && !isSent(id)) selectedIds.add(id);
    });
    refreshChecks();
  });

  document.getElementById('dvcClearSelected').addEventListener('click', function () {
    if (!canTick()) return;
    selectedIds.clear();
    refreshChecks();
  });

  async function loadAllPaying() {
    if (allPaying) return allPaying;
    const params = new URLSearchParams();
    if (config.group) params.set('group', config.group);
    const res = await fetch('api/paying-ids.php' + (params.toString() ? '?' + params.toString() : ''), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Could not load the still-paying list.');
    }
    allPaying = data.donors || [];
    allPaying.forEach(function (row) {
      phoneById.set(Number(row.id), row.has_phone === true);
    });
    return allPaying;
  }

  document.getElementById('dvcSelectAll').addEventListener('click', async function () {
    if (!canTick()) return;
    try {
      const donors = await loadAllPaying();
      donors.forEach(function (row) {
        const id = Number(row.id || 0);
        if (id > 0 && !isSent(id)) selectedIds.add(id);
      });
      refreshChecks();
    } catch (err) {
      showFlash(err.message || 'Could not select all donors.', true);
    }
  });

  function chunk(ids, size) {
    const out = [];
    for (let i = 0; i < ids.length; i += size) {
      out.push(ids.slice(i, i + size));
    }
    return out;
  }

  function setProgress(done, total) {
    if (!progressWrap || !progressBar) return;
    progressWrap.classList.remove('d-none');
    const pct = total <= 0 ? 0 : Math.round((done / total) * 100);
    progressBar.style.width = pct + '%';
    progressBar.textContent = done + ' / ' + total;
  }

  function showResult(summary) {
    if (!resultEl) return;
    resultEl.classList.remove('d-none');
    const parts = [
      '<strong>Send finished.</strong>',
      summary.sent + ' sent',
      summary.skipped + ' skipped',
      summary.failed + ' failed'
    ];
    resultEl.innerHTML = parts.join(' · ');
    if (summary.failed > 0) {
      resultEl.classList.add('is-warning');
    } else {
      resultEl.classList.remove('is-warning');
    }
  }

  async function postBatch(ids) {
    const body = new URLSearchParams({
      csrf_token: csrf,
      group: config.group || 'pledge_paying',
      donor_ids: JSON.stringify(ids)
    });
    const res = await fetch('api/send-first-message.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: body.toString()
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Could not send.');
    }
    return data.results || [];
  }

  async function runSend() {
    if (sending) return;
    sending = true;
    if (confirmBtn) confirmBtn.disabled = true;
    sendBtn.disabled = true;

    const ids = Array.from(selectedIds).filter(function (id) {
      return !isSent(id) && hasPhone(id, '');
    });
    if (ids.length === 0) {
      sending = false;
      if (confirmBtn) confirmBtn.disabled = false;
      refreshChecks();
      return;
    }

    refreshChecks();
    setProgress(0, ids.length);

    const summary = { sent: 0, skipped: 0, failed: 0 };
    const batches = chunk(ids, batchLimit);
    let done = 0;

    try {
      for (let i = 0; i < batches.length; i += 1) {
        const results = await postBatch(batches[i]);
        results.forEach(function (row) {
          const id = Number(row.donor_id || 0);
          if (row.status === 'sent') {
            summary.sent += 1;
            if (id > 0) {
              sentIds.add(id);
              selectedIds.delete(id);
            }
          } else if (row.status === 'skipped') {
            summary.skipped += 1;
          } else {
            summary.failed += 1;
          }
        });
        done += batches[i].length;
        setProgress(Math.min(done, ids.length), ids.length);
        refreshChecks();
        if (i < batches.length - 1) {
          await new Promise(function (resolve) { window.setTimeout(resolve, 400); });
        }
      }
      showResult(summary);
      showFlash(
        summary.failed > 0
          ? 'Finished with some failures. Check the summary.'
          : 'Messages sent.',
        summary.failed > 0
      );
    } catch (err) {
      showFlash(err.message || 'Sending stopped.', true);
    } finally {
      sending = false;
      if (confirmBtn) confirmBtn.disabled = false;
      refreshChecks();
    }
  }

  sendBtn.addEventListener('click', function () {
    if (sending) return;
    const ready = readyCount();
    const skipped = skippedCount();
    if (ready <= 0) return;
    if (!modalBody || !sendModal) {
      if (window.confirm('Send the first WhatsApp message to ' + ready + ' donor' + (ready === 1 ? '' : 's') + '?')) {
        runSend();
      }
      return;
    }
    let html = '<p>Send the saved first message to <strong>'
      + ready.toLocaleString()
      + '</strong> still-paying donor'
      + (ready === 1 ? '' : 's')
      + ' on WhatsApp.</p>';
    if (skipped > 0) {
      html += '<p class="mb-0 text-muted">'
        + skipped.toLocaleString()
        + ' selected donor'
        + (skipped === 1 ? ' has' : 's have')
        + ' no phone number and will be skipped.</p>';
    }
    modalBody.innerHTML = html;
    sendModal.show();
  });

  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      if (sending) return;
      confirmBtn.disabled = true;
      if (sendModal) sendModal.hide();
      runSend();
    });
  }

  refreshChecks();
})();
