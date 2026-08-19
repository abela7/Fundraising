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
  let dirty = false;
  let homeTimer = 0;
  const DRAFT_MAX_AGE_SECONDS = 2592000;

  function homeUrl() {
    const url = String(config.homeUrl || 'https://donate.abuneteklehaymanot.org/').trim();
    return url !== '' ? url : 'https://donate.abuneteklehaymanot.org/';
  }

  function cancelHomeRedirect() {
    if (homeTimer) {
      window.clearTimeout(homeTimer);
      homeTimer = 0;
    }
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

  function paidMethod() {
    syncChoicesIntoAnswers();
    const method = String(state.answers.paid_method || '');
    return method === 'card' ? 'bank' : method;
  }

  function needsCallback() {
    return statusAnswer() === 'no'
      && paidMethod() === 'bank'
      && String(state.answers.send_proof || '') === 'no'
      && String(state.answers.paid_remember || '') === 'no';
  }

  function cashDetailComplete() {
    readFields();
    syncChoicesIntoAnswers();
    if (paidMethod() !== 'cash') {
      return false;
    }
    const remember = String(state.answers.cash_remember || '');
    if (remember === 'yes' || remember === 'no') {
      return true;
    }
    return String(state.answers.cash_when || '') !== '' || String(state.answers.cash_whom || '').trim() !== '';
  }

  function mixedSplitComplete() {
    readFields();
    if (paidMethod() !== 'mixed') {
      return false;
    }
    const cashRaw = String(state.answers.mixed_cash || '').replace(/[£,\s]/g, '');
    const bankRaw = String(state.answers.mixed_bank || '').replace(/[£,\s]/g, '');
    if (!/^\d+(\.\d{1,2})?$/.test(cashRaw) || !/^\d+(\.\d{1,2})?$/.test(bankRaw)) {
      return false;
    }
    const cash = Number(cashRaw);
    const bank = Number(bankRaw);
    return isFinite(cash) && isFinite(bank) && cash > 0 && bank > 0;
  }

  function proofComplete() {
    return /^uploads\/paying_proofs\/[a-f0-9]{16}_[a-f0-9]{32}\.(jpe?g|png|webp|gif)$/i.test(
      String(state.answers.proof_file || '')
    );
  }

  function paidDateComplete() {
    readFields();
    return /^\d{4}-\d{2}-\d{2}$/.test(String(state.answers.paid_date || ''));
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
    if (state.step === 'pay_method') {
      if (paidMethod() === 'cash') {
        return 'cash_detail';
      }
      if (paidMethod() === 'bank') {
        return 'bank_proof';
      }
      if (paidMethod() === 'mixed') {
        return 'mixed_split';
      }
      return '';
    }
    if (state.step === 'cash_detail') {
      return 'bank_proof';
    }
    if (state.step === 'mixed_split') {
      return 'bank_proof';
    }
    if (state.step === 'bank_proof') {
      if (String(state.answers.send_proof || '') === 'no') {
        return paidMethod() === 'cash' || paidMethod() === 'mixed' ? 'done' : 'bank_date';
      }
      if (String(state.answers.send_proof || '') === 'yes') {
        return 'done';
      }
      return '';
    }
    if (state.step === 'bank_date') {
      if (paidDateComplete()) {
        return 'done';
      }
      if (String(state.answers.paid_remember || '') === 'no') {
        return 'contact';
      }
      return '';
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
    if (state.step === 'correction') {
      return 'status';
    }
    if (state.step === 'pay_method') {
      return 'correction';
    }
    if (state.step === 'cash_detail') {
      return 'pay_method';
    }
    if (state.step === 'mixed_split') {
      return 'pay_method';
    }
    if (state.step === 'bank_proof') {
      if (paidMethod() === 'cash') {
        return 'cash_detail';
      }
      if (paidMethod() === 'mixed') {
        return 'mixed_split';
      }
      return 'pay_method';
    }
    if (state.step === 'bank_date') {
      return 'bank_proof';
    }
    if (state.step === 'contact') {
      return statusAnswer() === 'no' ? 'bank_date' : 'status';
    }
    if (state.step === 'phone') {
      return 'contact';
    }
    if (state.step === 'done') {
      if (statusAnswer() === 'yes' || needsCallback()) {
        return 'phone';
      }
      if (paidMethod() === 'cash' || paidMethod() === 'mixed') {
        return String(state.answers.send_proof || '') === 'yes'
          || String(state.answers.send_proof || '') === 'no'
          ? 'bank_proof'
          : (paidMethod() === 'cash' ? 'cash_detail' : 'mixed_split');
      }
      if (String(state.answers.send_proof || '') === 'yes') {
        return 'bank_proof';
      }
      if (paidMethod() === 'bank') {
        return 'bank_date';
      }
      return 'phone';
    }
    return '';
  }

  function allowedStep(step) {
    if (!screens[step]) {
      return 'welcome';
    }
    syncChoicesIntoAnswers();
    if (statusAnswer() === 'no') {
      if (step === 'welcome' || step === 'status') {
        return step;
      }
      if (!reportedPaidComplete()) {
        return 'correction';
      }
      if (step === 'correction' || step === 'pay_method') {
        return step;
      }
      if (paidMethod() === '') {
        return 'pay_method';
      }
      if (paidMethod() === 'cash') {
        if (step === 'cash_detail') {
          return 'cash_detail';
        }
        if (!cashDetailComplete()) {
          return 'cash_detail';
        }
        if (step === 'bank_proof') {
          return 'bank_proof';
        }
        if (String(state.answers.send_proof || '') === 'yes') {
          if (step === 'done' && proofComplete()) {
            return 'done';
          }
          return 'bank_proof';
        }
        if (String(state.answers.send_proof || '') === 'no' && step === 'done') {
          return 'done';
        }
        return 'bank_proof';
      }
      if (paidMethod() === 'mixed') {
        if (step === 'mixed_split') {
          return 'mixed_split';
        }
        if (!mixedSplitComplete()) {
          return 'mixed_split';
        }
        if (step === 'bank_proof') {
          return 'bank_proof';
        }
        if (String(state.answers.send_proof || '') === 'yes') {
          if (step === 'done' && proofComplete()) {
            return 'done';
          }
          return 'bank_proof';
        }
        if (String(state.answers.send_proof || '') === 'no' && step === 'done') {
          return 'done';
        }
        return 'bank_proof';
      }
      if (step === 'bank_proof') {
        return 'bank_proof';
      }
      if (String(state.answers.send_proof || '') === 'no' && step === 'bank_date') {
        return 'bank_date';
      }
      if (needsCallback()) {
        if (step === 'contact') {
          return 'contact';
        }
        if (step === 'phone' || step === 'done') {
          if (!bookingComplete()) {
            return 'contact';
          }
          if (step === 'done' && !phoneReady()) {
            return 'phone';
          }
          return step;
        }
        return 'contact';
      }
      if (String(state.answers.send_proof || '') === 'yes') {
        if (step === 'done' && proofComplete()) {
          return 'done';
        }
        return 'bank_proof';
      }
      if (String(state.answers.send_proof || '') === 'no') {
        if (step === 'done' && paidDateComplete()) {
          return 'done';
        }
        return 'bank_date';
      }
      return 'bank_proof';
    }
    if (step === 'correction' || step === 'pay_method' || step === 'cash_detail' || step === 'mixed_split' || step === 'bank_proof' || step === 'bank_date') {
      return statusAnswer() === 'yes' ? 'contact' : 'status';
    }
    if (step === 'contact') {
      return statusAnswer() === 'yes' ? 'contact' : 'status';
    }
    if (step === 'phone' || step === 'done') {
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
    if (state.step === 'pay_method' && paidMethod() !== 'cash' && paidMethod() !== 'bank' && paidMethod() !== 'mixed') {
      return false;
    }
    if (state.step === 'cash_detail') {
      return cashDetailComplete();
    }
    if (state.step === 'mixed_split') {
      return mixedSplitComplete();
    }
    if (state.step === 'bank_proof') {
      return String(state.answers.send_proof || '') === 'yes' && proofComplete();
    }
    if (state.step === 'bank_date') {
      return paidDateComplete() || String(state.answers.paid_remember || '') === 'no';
    }
    if (state.step === 'contact' && !bookingComplete()) {
      return false;
    }
    if (state.step === 'phone' && !phoneReady()) {
      return false;
    }
    return true;
  }

  function updatePathCopy() {
    const callback = needsCallback();
    const bookingDone = statusAnswer() === 'yes' || callback;
    document.querySelectorAll('[data-pay-contact-yes]').forEach(function (el) {
      el.hidden = callback;
    });
    document.querySelectorAll('[data-pay-contact-callback]').forEach(function (el) {
      el.hidden = !callback;
    });
    document.querySelectorAll('[data-pay-done="booking"]').forEach(function (el) {
      el.hidden = !bookingDone;
    });
    document.querySelectorAll('[data-pay-done="thanks"]').forEach(function (el) {
      el.hidden = bookingDone;
    });
    const proofWrap = document.querySelector('[data-pay-proof-entry]');
    if (proofWrap) {
      proofWrap.hidden = String(state.answers.send_proof || '') !== 'yes';
    }
    const proofName = document.querySelector('[data-pay-proof-name]');
    if (proofName) {
      proofName.textContent = proofComplete() ? String(state.answers.proof_file || '').split('/').pop() : '';
    }
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
    const stayBtn = document.querySelector('.pay-screen.is-active [data-pay-next-stay]');
    const hasInlineNext = !!(stayBtn && !stayBtn.closest('[hidden]'));
    document.querySelectorAll('[data-pay-back]').forEach(function (btn) {
      btn.hidden = prevStepName() === '';
    });
    document.querySelectorAll('[data-pay-next]').forEach(function (btn) {
      const ready = canGoNext();
      if (btn.hasAttribute('data-pay-next-stay')) {
        btn.hidden = false;
        btn.disabled = !ready;
        return;
      }
      btn.hidden = hasInlineNext || !ready;
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
    updatePathCopy();
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
    } else {
      cancelHomeRedirect();
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

  function draftStorageKey() {
    return state.token ? ('pay-draft:' + state.token) : '';
  }

  function isUsableDraft(draft) {
    if (!draft || typeof draft !== 'object') {
      return false;
    }
    const savedAt = draft.saved_at != null ? draft.saved_at : draft.savedAt;
    if (savedAt == null || savedAt === '') {
      return true;
    }
    let stamp = Number(savedAt);
    if (!isFinite(stamp) || stamp <= 0) {
      return false;
    }
    if (stamp > 20000000000) {
      stamp = Math.floor(stamp / 1000);
    }
    return (Date.now() / 1000 - stamp) <= DRAFT_MAX_AGE_SECONDS;
  }

  function writeDraft() {
    const key = draftStorageKey();
    if (!key) {
      return;
    }
    try {
      localStorage.setItem(key, JSON.stringify({
        token: state.token,
        step: state.step,
        answers: asAnswerMap(state.answers),
        revision: state.revision,
        saved_at: Math.floor(Date.now() / 1000)
      }));
    } catch (e) {
      // Private mode or full storage; the next server save still runs.
    }
  }

  function readDraft() {
    const key = draftStorageKey();
    if (!key) {
      return null;
    }
    try {
      const raw = localStorage.getItem(key);
      if (!raw) {
        return null;
      }
      const draft = JSON.parse(raw);
      if (!draft || typeof draft !== 'object' || String(draft.token || '') !== state.token) {
        return null;
      }
      if (!isUsableDraft(draft)) {
        localStorage.removeItem(key);
        return null;
      }
      return draft;
    } catch (e) {
      return null;
    }
  }

  function clearDraft() {
    const key = draftStorageKey();
    if (!key) {
      return;
    }
    try {
      localStorage.removeItem(key);
    } catch (e) {
      // Ignore storage errors.
    }
  }

  function mergeDraftAnswers(incoming) {
    const add = asAnswerMap(incoming);
    Object.keys(add).forEach(function (key) {
      if (add[key] === '' || add[key] == null) {
        return;
      }
      state.answers[key] = add[key];
    });
  }

  function restoreDraft() {
    const draft = readDraft();
    if (!draft) {
      return false;
    }
    mergeDraftAnswers(draft.answers);
    const draftRevision = Number(draft.revision || 0);
    const draftStep = String(draft.step || '');
    if (draftRevision >= state.revision && draftStep !== '' && draftStep !== 'welcome' && screens[draftStep]) {
      state.step = draftStep;
    }
    return true;
  }

  function queueSave() {
    readFields();
    syncChoicesIntoAnswers();
    dirty = true;
    writeDraft();
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(function () {
      flushSave(false);
    }, 400);
  }

  function postSave(body, keepalive) {
    return fetch(state.saveUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: body,
      keepalive: keepalive === true
    });
  }

  function flushSave(useBeacon) {
    if (!state.token || !state.sign || !state.saveUrl) {
      return;
    }
    window.clearTimeout(saveTimer);
    saveTimer = 0;
    readFields();
    syncChoicesIntoAnswers();
    writeDraft();
    if (useBeacon !== true && typeof navigator !== 'undefined' && navigator.onLine === false) {
      return;
    }
    const body = JSON.stringify(payload());
    if (useBeacon === true) {
      let sent = false;
      if (navigator.sendBeacon) {
        try {
          sent = navigator.sendBeacon(state.saveUrl, new Blob([body], { type: 'application/json' }));
        } catch (e) {
          sent = false;
        }
      }
      if (!sent) {
        postSave(body, true).catch(function () {
          dirty = true;
          writeDraft();
        });
      }
      return;
    }
    if (saving) {
      saveAgain = true;
      return;
    }
    saving = true;
    dirty = false;
    postSave(body, true).then(function (res) {
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
        updatePathCopy();
        updateNav();
        if (!dirty) {
          clearDraft();
        } else {
          writeDraft();
        }
        return;
      }
      dirty = true;
      writeDraft();
    }).catch(function () {
      dirty = true;
      writeDraft();
    }).then(function () {
      saving = false;
      if (saveAgain) {
        saveAgain = false;
        flushSave(false);
      }
    });
  }

  function leaveSave() {
    flushSave(true);
  }

  function resumeSave() {
    if (dirty || readDraft()) {
      flushSave(false);
    }
  }

  document.querySelectorAll('[data-pay-next]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (state.step === 'cash_detail' && String(state.answers.cash_remember || '') !== 'no') {
        state.answers.cash_remember = 'yes';
      }
      const next = nextStepName();
      if (next) {
        showStep(next, false);
      }
    });
  });
  document.querySelectorAll('[data-pay-back]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      cancelHomeRedirect();
      const prev = prevStepName();
      if (prev) {
        showStep(prev, false);
      }
    });
  });
  document.querySelectorAll('[data-pay-field]').forEach(function (el) {
    el.addEventListener('input', function () {
      if (el.getAttribute('data-pay-field') === 'paid_date' && String(el.value || '') !== '') {
        delete state.answers.paid_remember;
        applyChoices();
      }
      queueSave();
      updateReportedPaidDisplay();
      updateNav();
    });
    el.addEventListener('change', function () {
      if (el.getAttribute('data-pay-field') === 'paid_date' && String(el.value || '') !== '') {
        delete state.answers.paid_remember;
        applyChoices();
      }
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
      updatePathCopy();
      updateNav();
      queueSave();
      if (key === 'phone_correct' && value === 'yes') {
        showStep('done', false);
      }
      if (key === 'paid_method' && (value === 'cash' || value === 'bank' || value === 'mixed')) {
        showStep(value === 'cash' ? 'cash_detail' : (value === 'mixed' ? 'mixed_split' : 'bank_proof'), false);
      }
      if (key === 'send_proof' && value === 'no') {
        showStep(paidMethod() === 'cash' || paidMethod() === 'mixed' ? 'done' : 'bank_date', false);
      }
      if (key === 'cash_remember' && value === 'no') {
        showStep('bank_proof', false);
      }
      if (key === 'paid_remember' && value === 'no') {
        showStep('contact', false);
      }
    });
  });

  const proofInput = document.querySelector('[data-pay-proof]');
  if (proofInput) {
    proofInput.addEventListener('change', function () {
      const file = proofInput.files && proofInput.files[0];
      if (!file || !state.token || !state.sign || !config.uploadUrl) {
        return;
      }
      const body = new FormData();
      body.append('token', state.token);
      body.append('sign', state.sign);
      body.append('file', file);
      fetch(config.uploadUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: body
      }).then(function (res) {
        return res.json().then(function (data) {
          return { res: res, data: data };
        });
      }).then(function (result) {
        if (result.res.ok && result.data && result.data.success && result.data.proof_file) {
          state.answers.proof_file = result.data.proof_file;
          state.revision = Number(result.data.revision || state.revision);
          updatePathCopy();
          updateNav();
          queueSave();
        }
      }).catch(function () {
        // Keep the local file; the donor can try again.
      });
    });
  }

  window.addEventListener('popstate', function (event) {
    const step = (event.state && event.state.step)
      || (location.hash ? location.hash.replace('#', '') : 'welcome');
    showStep(step, true);
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      leaveSave();
      return;
    }
    if (document.visibilityState === 'visible') {
      resumeSave();
    }
  });
  window.addEventListener('pagehide', leaveSave);
  window.addEventListener('beforeunload', leaveSave);
  window.addEventListener('pageshow', resumeSave);
  window.addEventListener('online', resumeSave);
  document.addEventListener('freeze', leaveSave);
  document.addEventListener('resume', resumeSave);

  applyFields();
  const hadDraft = restoreDraft();
  if (hadDraft) {
    applyFields();
  }
  const hashStepRaw = location.hash ? location.hash.replace('#', '') : '';
  const hashStep = hashStepRaw === 'info' ? 'status' : hashStepRaw;
  const start = allowedStep(screens[hashStep] ? hashStep : state.step);
  history.replaceState({ step: 'welcome' }, '', '#welcome');
  if (start !== 'welcome') {
    history.pushState({ step: start }, '', '#' + start);
  }
  showStep(start, true, false);
  if (hadDraft) {
    flushSave(false);
  }
})();
