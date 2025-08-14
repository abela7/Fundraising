// Modern Mobile-First Messaging JavaScript
(function() {
    'use strict';
    
    // State
    let currentOtherId = null;
    let lastMessageId = 0;
    let isLoading = false;
    let isMobile = window.innerWidth < 768;
    
    // Elements
    const elements = {
        chatWrap: document.querySelector('.chat-wrap'),
        chatThread: document.getElementById('chatThread'),
        convContainer: document.getElementById('convContainer'),
        messageContainer: document.getElementById('messageContainer'),
        threadName: document.getElementById('threadName'),
        threadStatus: document.getElementById('threadStatus'),
        threadAvatar: document.getElementById('threadAvatar'),
        searchInput: document.getElementById('searchConv'),
        sendForm: document.getElementById('sendForm'),
        msgBody: document.getElementById('msgBody'),
        otherId: document.getElementById('otherId'),
        clientUuid: document.getElementById('clientUuid'),
        newMsgBtn: document.getElementById('newMsgBtn'),
        backBtn: document.getElementById('backBtn')
    };
    
    // Initialize
    function init() {
        loadConversations();
        attachEventListeners();
        
        // Check mobile on resize
        window.addEventListener('resize', debounce(() => {
            isMobile = window.innerWidth < 768;
        }, 250));
        
        // Auto-refresh
        setInterval(() => {
            loadConversations();
            if (currentOtherId) {
                loadMessages(currentOtherId, true);
            }
        }, 5000);
    }
    
    // Event Listeners
    function attachEventListeners() {
        // New message button
        if (elements.newMsgBtn) {
            elements.newMsgBtn.addEventListener('click', showNewMessageDialog);
        }
        
        // Back button (mobile)
        if (elements.backBtn) {
            elements.backBtn.addEventListener('click', () => {
                if (isMobile) {
                    showConversationList();
                }
            });
        }
        
        // Search
        if (elements.searchInput) {
            elements.searchInput.addEventListener('input', debounce(filterConversations, 300));
        }
        
        // Send form
        if (elements.sendForm) {
            elements.sendForm.addEventListener('submit', handleSendMessage);
        }
        
        // Auto-resize textarea
        if (elements.msgBody) {
            elements.msgBody.addEventListener('input', autoResizeTextarea);
        }
    }
    
    // Load conversations
    async function loadConversations() {
        try {
            const response = await fetch('?action=conversations', { credentials: 'same-origin' });
            const data = await response.json();
            renderConversations(data.conversations || []);
            
            // Check if all conversations have been read and hide badge if so
            const hasUnread = (data.conversations || []).some(conv => conv.unread > 0);
            if (!hasUnread) {
                hideNotificationBadge();
            }
        } catch (error) {
            console.error('Error loading conversations:', error);
        }
    }
    
    // Render conversations
    function renderConversations(conversations) {
        if (!conversations.length) {
            elements.convContainer.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No conversations yet</p>
                </div>
            `;
            return;
        }
        
        const html = conversations.map(conv => {
            const initials = getInitials(conv.other_name);
            const timeStr = formatTime(conv.last_time);
            const isActive = conv.other_id == currentOtherId;
            
            return `
                <div class="conv ${isActive ? 'active' : ''}" 
                     data-other-id="${conv.other_id}" 
                     data-other-name="${escapeHtml(conv.other_name)}">
                    <div class="conv-avatar">${initials}</div>
                    <div class="conv-content">
                        <div class="conv-header">
                            <div class="conv-name">${escapeHtml(conv.other_name)}</div>
                            <div class="conv-time">${timeStr}</div>
                        </div>
                        <div class="conv-preview">${escapeHtml(conv.last_body)}</div>
                    </div>
                    ${conv.unread > 0 ? `<span class="conv-unread">${conv.unread}</span>` : ''}
                </div>
            `;
        }).join('');
        
        elements.convContainer.innerHTML = html;
        
        // Attach click handlers
        document.querySelectorAll('.conv').forEach(conv => {
            conv.addEventListener('click', () => {
                const otherId = conv.dataset.otherId;
                const otherName = conv.dataset.otherName;
                selectConversation(otherId, otherName);
            });
        });
    }
    
    // Select conversation
    function selectConversation(otherId, otherName) {
        currentOtherId = otherId;
        
        // Update UI
        document.querySelectorAll('.conv').forEach(c => c.classList.remove('active'));
        document.querySelector(`.conv[data-other-id="${otherId}"]`)?.classList.add('active');
        
        // Update thread header
        elements.threadName.textContent = otherName;
        elements.threadAvatar.textContent = getInitials(otherName);
        elements.threadStatus.textContent = 'Active';
        
        // Show thread on mobile
        if (isMobile) {
            showThread();
        }
        
        // Show composer
        elements.sendForm.style.display = 'flex';
        
        // Load messages
        loadMessages(otherId);
    }
    
    // Load messages
    async function loadMessages(otherId, isUpdate = false) {
        if (isLoading) return;
        isLoading = true;
        
        try {
            const url = `?action=messages&other_id=${otherId}${isUpdate ? `&after_id=${lastMessageId}` : ''}`;
            const response = await fetch(url, { credentials: 'same-origin' });
            const data = await response.json();
            
            if (!isUpdate) {
                renderMessages(data.messages || []);
            } else {
                appendNewMessages(data.messages || []);
            }
            
            // Update last message ID
            if (data.messages && data.messages.length > 0) {
                lastMessageId = Math.max(...data.messages.map(m => m.id));
            }
            
            // Hide notification badge when messages are read
            hideNotificationBadge();
            
        } catch (error) {
            console.error('Error loading messages:', error);
        } finally {
            isLoading = false;
        }
    }
    
    // Render messages
    function renderMessages(messages) {
        if (!messages.length) {
            elements.messageContainer.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-comment-dots"></i>
                    <p>No messages yet. Start the conversation!</p>
                </div>
            `;
            return;
        }
        
        const html = messages.map(msg => createMessageHtml(msg)).join('');
        elements.messageContainer.innerHTML = html;
        scrollToBottom();
    }
    
    // Append new messages
    function appendNewMessages(messages) {
        const emptyState = elements.messageContainer.querySelector('.empty-state');
        if (emptyState) {
            emptyState.remove();
        }
        
        messages.forEach(msg => {
            if (!document.querySelector(`[data-msg-id="${msg.id}"]`)) {
                const msgHtml = createMessageHtml(msg);
                elements.messageContainer.insertAdjacentHTML('beforeend', msgHtml);
            }
        });
        
        if (messages.length > 0) {
            scrollToBottom();
        }
    }
    
    // Create message HTML
    function createMessageHtml(msg) {
        const timeStr = formatTime(msg.created_at);
        const isRead = msg.read_at !== null;
        
        return `
            <div class="msg ${msg.mine ? 'mine' : 'other'}" data-msg-id="${msg.id}">
                <div class="msg-bubble">
                    ${escapeHtml(msg.body)}
                </div>
                <div class="msg-time">
                    ${timeStr}
                    ${msg.mine && isRead ? ' <i class="fas fa-check-double"></i>' : ''}
                </div>
            </div>
        `;
    }
    
    // Handle send message
    async function handleSendMessage(e) {
        e.preventDefault();
        
        const body = elements.msgBody.value.trim();
        if (!body || !currentOtherId) return;
        
        // --- Build FormData manually for robustness ---
        const formData = new FormData();
        formData.append('body', body);
        formData.append('other_id', currentOtherId);
        formData.append('client_uuid', generateUUID());
        
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput && csrfInput.value) {
            formData.append('csrf_token', csrfInput.value);
        } else {
            console.error('CSRF token field not found.');
            alert('Security token missing. Please refresh the page.');
            return;
        }

        // Optimistic UI update
        const tempMsg = {
            id: 'temp-' + Date.now(),
            body: body,
            mine: true,
            created_at: new Date().toISOString(),
            read_at: null
        };
        appendNewMessages([tempMsg]);
        
        // Clear input
        elements.msgBody.value = '';
        autoResizeTextarea.call(elements.msgBody);
        
        try {
            const response = await fetch('?action=send', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            // Check if response is ok, otherwise show error
            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`Server responded with ${response.status}: ${errorText}`);
            }

            const data = await response.json();
            
            if (data.ok) {
                // Remove temp message
                document.querySelector(`[data-msg-id="${tempMsg.id}"]`)?.remove();
                
                // Add real message
                if (!data.duplicate) {
                    appendNewMessages([{
                        id: data.id,
                        body: body,
                        mine: true,
                        created_at: data.created_at,
                        read_at: null
                    }]);
                }
                
                // Reload conversations to update last message
                loadConversations();
            } else {
                 throw new Error(data.error || 'Unknown error from server');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            // Remove temp message on error
            document.querySelector(`[data-msg-id="${tempMsg.id}"]`)?.remove();
            alert(`Failed to send message. Please check the console for details.`);
        }
    }
    
    // Show new message dialog
    async function showNewMessageDialog() {
        try {
            const response = await fetch('?action=recipients');
            const data = await response.json();
            
            // Create select options
            const options = data.recipients.map(r => 
                `<option value="${r.id}">${escapeHtml(r.name)} (${r.role})</option>`
            ).join('');
            
            // Show in thread header temporarily
            elements.threadName.innerHTML = `
                <select class="form-select form-select-sm" id="recipientSelect">
                    <option value="">Select recipient...</option>
                    ${options}
                </select>
            `;
            
            // Handle selection
            const select = document.getElementById('recipientSelect');
            select.addEventListener('change', () => {
                const option = select.options[select.selectedIndex];
                if (option.value) {
                    selectConversation(option.value, option.text);
                }
            });
            
            // Show thread on mobile
            if (isMobile) {
                showThread();
            }
            
            // Focus select
            select.focus();
        } catch (error) {
            console.error('Error loading recipients:', error);
        }
    }
    
    // Filter conversations
    function filterConversations() {
        const searchTerm = elements.searchInput.value.toLowerCase();
        
        document.querySelectorAll('.conv').forEach(conv => {
            const name = conv.querySelector('.conv-name').textContent.toLowerCase();
            const preview = conv.querySelector('.conv-preview').textContent.toLowerCase();
            
            if (name.includes(searchTerm) || preview.includes(searchTerm)) {
                conv.style.display = '';
            } else {
                conv.style.display = 'none';
            }
        });
    }
    
    // Mobile navigation
    function showThread() {
        elements.chatThread.classList.add('active');
    }
    
    function showConversationList() {
        elements.chatThread.classList.remove('active');
    }
    
    // Utilities
    function scrollToBottom() {
        elements.messageContainer.scrollTop = elements.messageContainer.scrollHeight;
    }
    
    function autoResizeTextarea() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    }
    
    function getInitials(name) {
        return name.split(' ')
            .map(n => n[0])
            .join('')
            .toUpperCase()
            .slice(0, 2);
    }
    
    function formatTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm ago';
        if (diff < 86400000) return Math.floor(diff / 3600000) + 'h ago';
        if (diff < 604800000) return Math.floor(diff / 86400000) + 'd ago';
        
        return date.toLocaleDateString();
    }
    
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    
    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
    
    // Hide notification badge (for when messages are read)
    function hideNotificationBadge() {
        const badge = document.getElementById('messageNotification');
        if (badge) {
            badge.style.display = 'none';
        }
    }
    
    // Start
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
