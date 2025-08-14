// Lightweight DM UI logic
(function(){
  const convContainer = document.getElementById('convContainer');
  const messageContainer = document.getElementById('messageContainer');
  const threadHeader = document.getElementById('threadHeader');
  const otherIdInput = document.getElementById('otherId');
  const bodyInput = document.getElementById('msgBody');
  const form = document.getElementById('sendForm');
  const clientUuidInput = document.getElementById('clientUuid');
  let recipientSelect;

  let currentOther = 0;
  let lastId = 0;

  function fmtTime(ts){
    try { return new Date(ts.replace(' ','T')).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}); } catch(e){ return ts; }
  }

  function uuid(){
    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
  }

  async function loadConversations(){
    const r = await fetch('?action=conversations');
    const data = await r.json();
    convContainer.innerHTML = '';
    // Recipient selector at top
    const selWrap = document.createElement('div');
    selWrap.className = 'p-2 border-bottom';
    selWrap.innerHTML = '<select class="form-select form-select-sm" id="recipientSelect"><option value="">— New message to… —</option></select>';
    convContainer.appendChild(selWrap);
    recipientSelect = selWrap.querySelector('#recipientSelect');
    loadRecipients();

    (data.conversations||[]).forEach(c => {
      const el = document.createElement('div');
      el.className = 'conv';
      el.dataset.otherId = c.other_id;
      el.innerHTML = `
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between align-items-center">
            <div class="conv-name">${c.other_name}</div>
            <div class="conv-time">${fmtTime(c.last_time)}</div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <div class="conv-preview">${c.last_body.substring(0,80)}</div>
            ${c.unread>0?`<span class="conv-unread">${c.unread}</span>`:''}
          </div>
        </div>`;
      el.addEventListener('click', ()=> openThread(c.other_id, c.other_name));
      convContainer.appendChild(el);
    });
  }

  async function loadRecipients(){
    const r = await fetch('?action=recipients');
    const data = await r.json();
    const opts = (data.recipients||[]).map(u=>`<option value="${u.id}">${u.name} (${u.role})</option>`).join('');
    recipientSelect.insertAdjacentHTML('beforeend', opts);
    recipientSelect.addEventListener('change', ()=>{
      const id = parseInt(recipientSelect.value||'0',10);
      if (id>0){
        const name = recipientSelect.options[recipientSelect.selectedIndex].text.replace(/ \(.+\)$/,'');
        openThread(id, name);
        recipientSelect.value = '';
      }
    });
  }

  async function loadMessages(after=0){
    if (!currentOther) return;
    const r = await fetch(`?action=messages&other_id=${currentOther}&after_id=${after}`);
    const data = await r.json();
    const list = data.messages||[];
    list.forEach(m => {
      const wrap = document.createElement('div');
      wrap.className = `msg ${m.mine?'mine':'other'}`;
      wrap.innerHTML = `<div>${escapeHtml(m.body)}</div><div class="msg-time">${fmtTime(m.created_at)}</div>`;
      messageContainer.appendChild(wrap);
      lastId = Math.max(lastId, m.id);
    });
    if (list.length) messageContainer.scrollTop = messageContainer.scrollHeight;
  }

  function escapeHtml(s){
    return s.replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }

  async function openThread(otherId, otherName){
    currentOther = otherId;
    otherIdInput.value = otherId;
    threadHeader.textContent = otherName;
    messageContainer.innerHTML = '';
    lastId = 0;
    await loadMessages(0);
  }

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    if (!currentOther) return;
    const body = bodyInput.value.trim();
    if (!body) return;
    const uuidVal = uuid();
    clientUuidInput.value = uuidVal;
    const formData = new FormData(form);
    const r = await fetch('?action=send', {method:'POST', body: formData});
    const res = await r.json();
    if (res.ok){
      bodyInput.value = '';
      await loadMessages(lastId);
      await loadConversations();
    }
  });

  // Poll for new messages every 5s
  setInterval(()=>{ if (currentOther) loadMessages(lastId); loadConversations(); }, 5000);
  loadConversations();
})();

