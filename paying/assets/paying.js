(function () {
  const config = window.PAY_SYNC || {};
  const screens = {};
  document.querySelectorAll('[data-pay-step]').forEach(function (el) {
    screens[el.getAttribute('data-pay-step')] = el;
  });
  if (!screens.welcome) {
    return;
  }

  function asAnswerMap(value) {
    const out = {};
    if (!value || typeof value !== 'object') {
      return out;
    }
    Object.keys(value).forEach(function (key) {
      if (!/^\d+$/.test(key)) {
        out[key] = value[key];
      }
    });
    if (Array.isArray(value)) {
      Object.getOwnPropertyNames(value).forEach(function (key) {
        if (key !== 'length' && !/^\d+$/.test(key)) {
          out[key] = value[key];
        }
      });
    }
    return out;
  }

  let startStep = config.step === 'info' ? 'status' : (config.step || 'welcome');
  if (!screens[startStep]) {
    startStep = 'welcome';
  }
  const state = {
    token: config.token || '',
    sign: config.sign || '',
    saveUrl: config.saveUrl || '',
    step: startStep,
    answers: asAnswerMap(config.answers),
    revision: Number(config.revision || 0)
  };

  let saveTimer = 0;
  let saving = false;
  let saveAgain = false;
  let homeTimer = 0;

  function homeUrl() {
    const url = String(config.homeUrl || 'https://donate.abuneteklehaymanot.org/').trim();
    return url !== '' ? url : 'https://donate.abuneteklehaymanot.org/';
  }

  function goHomeAfterThanks() {
    if (homeTimer) {
      return;
    }
    flushSave(false);
    homeTimer = window.setTimeout(function () {
      window.location.href = homeUrl();
    }, 2000);
  }

  function syncChoicesIntoAnswers() {
    document.querySelectorAll('[data-pay-choice].is-selected').forEach(function (btn) {
      const key = btn.getAttribute('data-pay-choice');
      const value = btn.getAttribute('data-pay-value');
      if (key && value) {
        state.answers[key] = value;
      }
    });
  }

  function bookingComplete() {
    readFields();
    syncChoicesIntoAnswers();
    const date = String(state.answers.contact_date || '');
    const time = String(state.answers.contact_time || '');
    const method = String(state.answers.contact_method || '');
    return /^\d{4}-\d{2}-\d{2}$/.test(date)
      && /^\d{2}:\d{2}/.test(time)
      && (method === 'whatsapp' || method === 'phone');
  }

  function normalizeUkPhone(raw) {
    let compact = String(raw || '').replace(/[^\d+]/g, '');
    if (compact.indexOf('0044') === 0) {
      compact = '+44' + compact.slice(4);
    }
    if (compact.indexOf('+44') === 0) {
      const rest = compact.slice(3);
      return /^7\d{9}$/.test(rest) ? '0' + rest : '';
    }
    if (/^447\d{9}$/.test(compact)) {
      return '0' + compact.slice(2);
    }
    if (/^07\d{9}$/.test(compact)) {
      return compact;
    }
    return '';
  }

  function phoneReady() {
    readFields();
    syncChoicesIntoAnswers();
    if (state.answers.phone_correct === 'yes') {
      return true;
    }
    if (state.answers.phone_correct !== 'no') {
      return false;
    }
    return normalizeUkPhone(state.answers.contact_phone) !== '';
  }

  function updatePhoneEntry() {
    const wrap = document.querySelector('[data-pay-phone-entry]');
    if (wrap) {
      wrap.hidden = state.answers.phone_correct !== 'no';
    }
  }

  function reportedPaidComplete() {
    readFields();
    const raw = String(state.answers.reported_paid || '').replace(/[£,\s]/g, '');
    return /^\d+(\.\d{1,2})?$/.test(raw);
  }

  function formatReportedPaid() {
    readFields();
    const raw = String(state.answers.reported_paid || '').replace(/[£,\s]/g, '');
    const amount = Number(raw);
    if (!isFinite(amount) || amount < 0 || !/^\d+(\.\d{1,2})?$/.test(raw)) {
      return '';
    }
    return '£' + amount.toLocaleString('en-GB', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function updateReportedPaidDisplay() {
    const label = formatReportedPaid();
    document.querySelectorAll('[data-pay-reported-paid]').forEach(function (el) {
      el.textContent = label;
    });
  }

  function statusAnswer() {
    syncChoicesIntoAnswers();
    return String(state.answers.status_correct || '');
  }

  function nextStepName() {
    if (state.step === 'welcome') {
      return 'status';
    }
    if (state.step === 'status') {
      if (statusAnswer() === 'yes') {
        return 'contact';
      }
      if (statusAnswer() === 'no') {
        return 'correction';
      }
      return '';
    }
    if (state.step === 'contact') {
      return 'phone';
    }
    if (state.step === 'correction') {
      return 'pay_method';
    }
    if (state.step === 'phone') {
      return 'done';
    }
    return '';
  }

  function prevStepName() {
    if (state.step === 'status') {
      return 'welcome';
    }
    if (state.step === 'contact' || state.step === 'correction') {
      return 'status';
    }
    if (state.step === 'pay_method') {
      return 'correction';
    }
    if (state.step === 'phone') {
      return 'contact';
    }
    if (state.step === 'done') {
      return 'phone';
    }
    return '';
  }

  function allowedStep(step) {
    if (!screens[step]) {
      return 'welcome';
    }
    syncChoicesIntoAnswers();
    if (step === 'correction') {
      if (statusAnswer() === 'no') {
        return 'correction';
      }
      return statusAnswer() === 'yes' ? 'contact' : 'status';
    }
    if (step === 'pay_method') {
      if (statusAnswer() !== 'no') {
        return statusAnswer() === 'yes' ? 'contact' : 'status';
      }
      if (!reportedPaidComplete()) {
        return 'correction';
      }
      return 'pay_method';
    }
    if (step === 'contact') {
      if (statusAnswer() === 'yes') {
        return 'contact';
      }
      return statusAnswer() === 'no' ? 'correction' : 'status';
    }
    if (step === 'phone' || step === 'done') {
      if (statusAnswer() === 'no') {
        return reportedPaidComplete() ? 'pay_method' : 'correction';
      }
      if (statusAnswer() !== 'yes') {
        return 'status';
      }
      if (!bookingComplete()) {
        return 'contact';
      }
      if (step === 'done' && !phoneReady()) {
        return 'phone';
      }
    }
    return step;
  }

  function canGoNext() {
    if (!nextStepName()) {
      return false;
    }
    syncChoicesIntoAnswers();
    if (state.step === 'status' && statusAnswer() !== 'yes' && statusAnswer() !== 'no') {
      return false;
    }
    if (state.step === 'correction' && !reportedPaidComplete()) {
      return false;
    }
    if (state.step === 'contact' && !bookingComplete()) {
      return false;
    }
    if (state.step === 'phone' && !phoneReady()) {
      return false;
    }
    return true;
  }

  function applyFields() {
    document.querySelectorAll('[data-pay-field]').forEach(function (el) {
      const key = el.getAttribute('data-pay-field');
      if (!key || !Object.prototype.hasOwnProperty.call(state.answers, key)) {
        return;
      }
      const value = state.answers[key];
      if (el.type === 'checkbox') {
        el.checked = value === true || value === 1 || value === '1';
        return;
      }
      if (el.type === 'radio') {
        el.checked = String(el.value) === String(value);
        return;
      }
      el.value = value == null ? '' : String(value);
    });
    applyChoices();
  }

  function applyChoices() {
    document.querySelectorAll('[data-pay-choice]').forEach(function (btn) {
      const key = btn.getAttribute('data-pay-choice');
      const value = btn.getAttribute('data-pay-value');
      const selected = key != null && String(state.answers[key] ?? '') === String(value);
      btn.classList.toggle('is-selected', selected);
      btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  }

  function readFields() {
    document.querySelectorAll('[data-pay-field]').forEach(function (el) {
      const key = el.getAttribute('data-pay-field');
      if (!key || el.closest('[hidden]')) {
        return;
      }
      if (el.type === 'checkbox') {
        state.answers[key] = !!el.checked;
        return;
      }
      if (el.type === 'radio') {
        if (el.checked) {
          state.answers[key] = el.value;
        }
        return;
      }
      state.answers[key] = el.value;
    });
  }

  function updateNav() {
    document.querySelectorAll('[data-pay-back]').forEach(function (btn) {
      btn.hidden = prevStepName() === '';
    });
    document.querySelectorAll('[data-pay-next]').forEach(function (btn) {
      btn.hidden = !canGoNext();
    });
  }

  function showStep(step, fromHistory, shouldSave) {
    step = allowedStep(step);
    if (!screens[step]) {
      step = 'welcome';
    }
    state.step = step;
    Object.keys(screens).forEach(function (name) {
      const screen = screens[name];
      if (!screen) {
        return;
      }
      const active = name === step;
      screen.hidden = !active;
      screen.classList.toggle('is-active', active);
    });
    if (step === 'phone' && !String(config.phone || '').replace(/\s/g, '') && state.answers.phone_correct !== 'yes') {
      state.answers.phone_correct = 'no';
      applyChoices();
    }
    updatePhoneEntry();
    updateReportedPaidDisplay();
    updateNav();
    if (!fromHistory) {
      const hash = '#' + step;
      if (location.hash !== hash) {
        history.pushState({ step: step }, '', hash);
      }
    }
    window.scrollTo(0, 0);
    if (shouldSave !== false) {
      queueSave();
    }
    if (step === 'done') {
      goHomeAfterThanks();
    }
  }

  function payload() {
    readFields();
    syncChoicesIntoAnswers();
    return {
      token: state.token,
      sign: state.sign,
      step: state.step,
      answers: asAnswerMap(state.answers),
      revision: state.revision
    };
  }

  function queueSave() {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(function () {
      flushSave(false);
    }, 400);
  }

  function flushSave(useBeacon) {
    if (!state.token || !state.sign || !state.saveUrl) {
      return;
    }
    readFields();
    const body = JSON.stringify(payload());
    if (useBeacon === true && navigator.sendBeacon) {
      navigator.sendBeacon(state.saveUrl, new Blob([body], { type: 'application/json' }));
      return;
    }
    if (saving) {
      saveAgain = true;
      return;
    }
    saving = true;
    fetch(state.saveUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: body,
      keepalive: true
    }).then(function (res) {
      return res.json().then(function (data) {
        return { res: res, data: data };
      });
    }).then(function (result) {
      if (result.res.ok && result.data && result.data.success) {
        state.revision = Number(result.data.revision || state.revision);
        const incoming = asAnswerMap(result.data.answers);
        state.answers = Object.assign({}, incoming, state.answers);
        syncChoicesIntoAnswers();
        applyChoices();
        updatePhoneEntry();
        updateReportedPaidDisplay();
        updateNav();
      }
    }).catch(function () {
      // Keep local answers; the next change retries.
    }).then(function () {
      saving = false;
      if (saveAgain) {
        saveAgain = false;
        flushSave(false);
      }
    });
  }

  document.querySelectorAll('[data-pay-next]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const next = nextStepName();
      if (next) {
        showStep(next, false);
      }
    });
  });
  document.querySelectorAll('[data-pay-back]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const prev = prevStepName();
      if (prev) {
        showStep(prev, false);
      }
    });
  });
  document.querySelectorAll('[data-pay-field]').forEach(function (el) {
    el.addEventListener('input', function () {
      queueSave();
      updateReportedPaidDisplay();
      updateNav();
    });
    el.addEventListener('change', function () {
      queueSave();
      updateReportedPaidDisplay();
      updateNav();
    });
  });
  document.querySelectorAll('[data-pay-choice]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const key = btn.getAttribute('data-pay-choice');
      const value = btn.getAttribute('data-pay-value');
      if (!key) {
        return;
      }
      state.answers = asAnswerMap(state.answers);
      state.answers[key] = value;
      if (key === 'phone_correct' && value === 'yes') {
        const stored = normalizeUkPhone(config.phone) || String(config.phone || '').trim();
        if (stored) {
          state.answers.contact_phone = stored;
        }
      }
      applyChoices();
      updatePhoneEntry();
      updateReportedPaidDisplay();
      updateNav();
      queueSave();
      if (key === 'phone_correct' && value === 'yes') {
        showStep('done', false);
      }
    });
  });

  window.addEventListener('popstate', function (event) {
    const step = (event.state && event.state.step)
      || (location.hash ? location.hash.replace('#', '') : 'welcome');
    showStep(step, true);
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushSave(true);
    }
  });
  window.addEventListener('pagehide', function () {
    flushSave(true);
  });

  applyFields();
  const hashStepRaw = location.hash ? location.hash.replace('#', '') : '';
  const hashStep = hashStepRaw === 'info' ? 'status' : hashStepRaw;
  const start = allowedStep(screens[hashStep] ? hashStep : state.step);
  history.replaceState({ step: 'welcome' }, '', '#welcome');
  if (start !== 'welcome') {
    history.pushState({ step: start }, '', '#' + start);
  }
  showStep(start, true, false);
})();
