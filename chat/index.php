<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Live Chat · WhaleTracker';
$activePage = 'chat';
$tabRevision = null;

include __DIR__ . '/../templates/layout_top.php';
include __DIR__ . '/../templates/tab-chat.php';
?>

<script>
const chatEndpoint = '/stats/chat.php';
const defaultAvatar = '<?= WT_DEFAULT_AVATAR_URL ?>';
const chatMessagesBox = document.getElementById('chat-messages');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');
const navCountEl = document.getElementById('nav-online-count');
const chatStatus = document.getElementById('chat-status');
const chatPanel = document.getElementById('chat-panel');
const chatTitleBaseRaw = document.title.replace(/^\(\d+\)\s*/, '').trim();
const chatTitleBase = chatTitleBaseRaw.length ? chatTitleBaseRaw : 'Live Chat · WhaleTracker';
let chatTitleCount = 0;
let chatPolling = false;
let chatTimer = null;
let chatAutoScrollEnabled = true;
let chatMessages = [];
const chatMessageIds = new Set();
let chatOldestId = null;
let chatNewestId = null;
let chatHasMoreOlder = true;
let chatLoadingOlder = false;
let chatRefreshInFlight = false;
let chatRefreshPendingForceScroll = false;

function updateChatTitleCount() {
    document.title = `(${chatTitleCount}) ${chatTitleBase}`;
}

function resetChatTitleCount() {
    chatTitleCount = 0;
    updateChatTitleCount();
}

function incrementChatTitleCount(delta = 1) {
    if (delta <= 0) {
        return;
    }
    chatTitleCount += delta;
    updateChatTitleCount();
}
resetChatTitleCount();

const COLOR_MAP = {
    default: null,
    cornflowerblue: '#6495ED',
    blue: '#80B5FF',
    gold: '#FFD700',
    green: '#00FF90',
    red: '#FF4040',
    gray: '#CCCCCC',
    yellow: '#FFEA00',
    white: '#FFFFFF',
    black: '#000000'
};

function setChatStatus(text, isError = false) {
    if (!chatStatus) {
        return;
    }
    chatStatus.textContent = text || '';
    chatStatus.classList.toggle('chat-status-error', Boolean(isError));
}

async function fetchChatData(params = {}) {
    const url = new URL(chatEndpoint, window.location.origin);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, value);
        }
    });
    url.searchParams.set('t', Date.now());
    const response = await fetch(url.toString(), { cache: 'no-store' });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const payload = await response.json();
    if (!payload || payload.ok !== true) {
        throw new Error(payload && payload.error ? payload.error : 'Chat backend error');
    }
    return payload;
}

let topArrow = null;
let lockArrow = null;
let bottomArrow = null;
if (chatPanel) {
    topArrow = document.createElement('div');
    topArrow.classList.add('navarrow', 'navarrow-top');
    topArrow.innerHTML = '<img src="/stats/reisen_up.png" alt="Scroll to top">';
    topArrow.addEventListener('click', () => {
        const box = document.getElementById('chat-messages');
        if (box) {
            box.scrollTop = 0;
            loadOlderMessages();
        }
    });

    lockArrow = document.createElement('div');
    lockArrow.classList.add('navarrow', 'navarrow-lock');
    lockArrow.title = 'Lock chat to bottom';
    lockArrow.innerHTML = '<img src="/stats/keine_lock.png" alt="Lock chat to bottom">';
    lockArrow.classList.toggle('is-active', chatAutoScrollEnabled);
    lockArrow.addEventListener('click', () => {
        chatAutoScrollEnabled = !chatAutoScrollEnabled;
        lockArrow.classList.toggle('is-active', chatAutoScrollEnabled);
        if (chatAutoScrollEnabled && chatMessagesBox) {
            chatMessagesBox.scrollTop = chatMessagesBox.scrollHeight;
        }
    });

    bottomArrow = document.createElement('div');
    bottomArrow.classList.add('navarrow', 'navarrow-bottom');
    bottomArrow.innerHTML = '<img src="/stats/tewi_down.png" alt="Scroll to bottom">';
    bottomArrow.addEventListener('click', () => {
        const box = document.getElementById('chat-messages');
        if (box) box.scrollTop = box.scrollHeight;
    });
    chatPanel.appendChild(topArrow);
    chatPanel.appendChild(lockArrow);
    chatPanel.appendChild(bottomArrow);
}

function renderMessageText(text) {
    const fragment = document.createDocumentFragment();
    if (!text) {
        return fragment;
    }
    const parts = String(text).split(/(\{[a-zA-Z]+\})/g);
    let currentColor = null;
    parts.forEach(part => {
        if (!part) {
            return;
        }
        const colorMatch = part.match(/^\{([a-zA-Z]+)\}$/);
        if (colorMatch) {
            const token = colorMatch[1].toLowerCase();
            currentColor = COLOR_MAP.hasOwnProperty(token) ? COLOR_MAP[token] : currentColor;
            if (token === 'default') {
                currentColor = null;
            }
            return;
        }
        const span = document.createElement('span');
        span.textContent = part;
        if (currentColor) {
            span.style.color = currentColor;
        }
        fragment.appendChild(span);
    });
    return fragment;
}

function formatTimestamp(seconds) {
    if (!seconds) {
        return '';
    }
    const date = new Date(Number(seconds) * 1000);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function buildChatRow(entry) {
    const row = document.createElement('div');
    row.className = 'chat-row';
    const avatar = document.createElement('img');
    avatar.className = 'chat-avatar';
    avatar.alt = '';
    avatar.src = entry.avatar || defaultAvatar;
    const content = document.createElement('div');
    content.className = 'chat-content';
    const header = document.createElement('div');
    header.className = 'chat-header';
    const nameSpan = document.createElement('span');
    const nameContent = entry.name || 'Unknown';
    const nameFragment = renderMessageText(nameContent);
    if (nameFragment && nameFragment.childNodes.length > 0) {
        nameSpan.appendChild(nameFragment);
    } else {
        nameSpan.textContent = nameContent;
    }
    header.appendChild(nameSpan);
    if (entry.created_at) {
        const timeSpan = document.createElement('span');
        timeSpan.className = 'chat-timestamp';
        timeSpan.textContent = formatTimestamp(entry.created_at);
        header.appendChild(timeSpan);
    }
    const body = document.createElement('div');
    body.className = 'chat-body';
    body.appendChild(renderMessageText(entry.message));
    content.appendChild(header);
    content.appendChild(body);
    row.appendChild(avatar);
    row.appendChild(content);
    return row;
}

function renderChat(messages, options = {}) {
    if (!chatMessagesBox) {
        return;
    }
    const skipAutoScroll = options.skipAutoScroll === true;
    const forceScroll = options.forceScrollToBottom === true;
    chatMessagesBox.innerHTML = '';
    messages.forEach(msg => {
        chatMessagesBox.appendChild(buildChatRow(msg));
    });
    if ((chatAutoScrollEnabled && !skipAutoScroll) || forceScroll) {
        chatMessagesBox.scrollTop = chatMessagesBox.scrollHeight;
    }
}

function updateChatPlaceholder(count, max) {
    if (!chatInput) {
        return;
    }
    const template = chatInput.getAttribute('data-dynamic-placeholder') || 'Type to {count} players | All messages are deleted after 24hrs';
    chatInput.placeholder = template.replace('{count}', String(count));
}

async function loadInitialChat() {
    try {
        const payload = await fetchChatData({ limit: 50 });
        chatMessages = Array.isArray(payload.messages) ? payload.messages : [];
        chatMessageIds.clear();
        registerMessageIds(chatMessages);
        chatOldestId = payload.oldest_id ?? (chatMessages.length ? chatMessages[0].id : null);
        chatNewestId = payload.newest_id ?? (chatMessages.length ? chatMessages[chatMessages.length - 1].id : null);
        chatHasMoreOlder = payload.has_more_older !== false;
        renderChat(chatMessages, { forceScrollToBottom: true });
        setChatStatus('');
        resetChatTitleCount();
    } catch (err) {
        console.error('[WhaleTracker] Initial chat load failed:', err);
        setChatStatus('Chat sync failed', true);
    }
}

async function loadOlderMessages() {
    if (chatLoadingOlder || !chatHasMoreOlder || chatOldestId === null) {
        return;
    }
    if (!chatMessagesBox) {
        return;
    }
    chatLoadingOlder = true;
    const prevScrollHeight = chatMessagesBox.scrollHeight;
    const prevScrollTop = chatMessagesBox.scrollTop;
    try {
        const payload = await fetchChatData({ before: chatOldestId, limit: 50 });
        const older = Array.isArray(payload.messages) ? payload.messages : [];
        if (older.length > 0) {
            const toPrepend = [];
            older.forEach(msg => {
                const id = normalizeMessageId(msg);
                if (id === null || chatMessageIds.has(id)) {
                    return;
                }
                chatMessageIds.add(id);
                toPrepend.push(msg);
            });
            if (toPrepend.length > 0) {
                chatMessages = toPrepend.concat(chatMessages);
                chatOldestId = payload.oldest_id ?? chatOldestId;
                chatHasMoreOlder = payload.has_more_older !== false;
                renderChat(chatMessages, { skipAutoScroll: true });
                const newHeight = chatMessagesBox.scrollHeight;
                chatMessagesBox.scrollTop = newHeight - (prevScrollHeight - prevScrollTop);
            } else if (payload.has_more_older === false) {
                chatHasMoreOlder = false;
            }
        } else {
            chatHasMoreOlder = false;
        }
        setChatStatus('');
    } catch (err) {
        console.error('[WhaleTracker] Failed to load older chat:', err);
        setChatStatus('Chat sync failed', true);
    } finally {
        chatLoadingOlder = false;
    }
}

async function refreshChat(options = {}) {
    const forceScroll = options.forceScrollToBottom === true;
    if (chatRefreshInFlight) {
        if (forceScroll) {
            chatRefreshPendingForceScroll = true;
        }
        return;
    }
    chatRefreshInFlight = true;
    if (chatNewestId === null) {
        await loadInitialChat();
        chatRefreshInFlight = false;
        return;
    }
    try {
        const payload = await fetchChatData({ after: chatNewestId, limit: 50 });
        const newMessages = Array.isArray(payload.messages) ? payload.messages : [];
        if (payload.newest_id !== undefined && payload.newest_id !== null) {
            chatNewestId = payload.newest_id;
        }
        if (newMessages.length > 0) {
            const toAppend = [];
            let alertCount = 0;
            newMessages.forEach(msg => {
                const id = normalizeMessageId(msg);
                if (id === null || chatMessageIds.has(id)) {
                    return;
                }
                chatMessageIds.add(id);
                toAppend.push(msg);
                if (msg && msg.alert !== 0 && msg.alert !== false) {
                    alertCount++;
                }
            });
            if (toAppend.length > 0) {
                chatMessages = chatMessages.concat(toAppend);
                if (alertCount > 0) {
                    incrementChatTitleCount(alertCount);
                }
            }
            renderChat(chatMessages, {
                forceScrollToBottom: options.forceScrollToBottom === true
            });
        } else if (options.forceScrollToBottom === true) {
            renderChat(chatMessages, { forceScrollToBottom: true });
        }
        setChatStatus('');
    } catch (err) {
        console.error('[WhaleTracker] Chat refresh failed:', err);
        setChatStatus('Chat sync failed', true);
    } finally {
        chatRefreshInFlight = false;
        if (chatRefreshPendingForceScroll) {
            chatRefreshPendingForceScroll = false;
            refreshChat({ forceScrollToBottom: true });
        }
    }
}

function startChat() {
    if (chatPolling) {
        return;
    }
    chatPolling = true;
    loadInitialChat();
    chatTimer = setInterval(refreshChat, 4000);
}

async function submitChatMessage(message) {
    try {
        const response = await fetch(chatEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message }),
        });
        const payload = await response.json();
        if (!response.ok || !payload) {
            throw new Error('Send failed');
        }
        if (payload.message === 'persona-updated') {
            if (chatInput) {
                chatInput.value = '';
            }
            setChatStatus('Persona updated', false);
            return;
        }
        if (payload.message === 'persona-not-found') {
            if (chatInput) {
                chatInput.value = '';
            }
            let msg = 'Persona not found';
            if (Array.isArray(payload.options) && payload.options.length) {
                const names = payload.options
                    .map(opt => opt && opt.name ? opt.name : '')
                    .filter(Boolean)
                    .join(', ');
                if (names) {
                    msg += ` — available: ${names}`;
                }
            }
            setChatStatus(msg, true);
            return;
        }
        if (payload.ok !== true) {
            throw new Error(payload.error || 'Send failed');
        }
        if (chatInput) {
            chatInput.value = '';
        }
        await refreshChat({ forceScrollToBottom: true });
        if (chatMessagesBox) {
            chatMessagesBox.scrollTop = chatMessagesBox.scrollHeight;
        }
        setChatStatus('');
    } catch (err) {
        console.error('[WhaleTracker] Chat send failed:', err);
        setChatStatus('Failed to send message', true);
    }
}

function bindChatForm() {
    if (!chatForm || !chatInput) {
        return;
    }
    chatForm.addEventListener('submit', event => {
        event.preventDefault();
        const message = chatInput.value.trim();
        if (message === '') {
            return;
        }
        submitChatMessage(message);
    });

    chatInput.addEventListener('keydown', event => {
        if (event.key === 'Enter' && chatInput.value.trim().length > 1 && chatInput.value.trim()[0] === '/') {
            event.preventDefault();
            submitChatMessage(chatInput.value.trim());
        }
    });
}

function bindAutoScrollGuards() {
    if (!chatMessagesBox) {
        return;
    }
    const disable = () => {
        chatAutoScrollEnabled = false;
    };
    ['wheel', 'touchmove', 'pointerdown'].forEach(evt => {
        chatMessagesBox.addEventListener(evt, disable, { passive: true });
    });
    if (chatInput) {
        ['focus', 'keydown', 'mousedown', 'touchstart'].forEach(evt => {
            chatInput.addEventListener(evt, disable);
        });
    }
}

function bindScrollLoadMore() {
    if (!chatMessagesBox) {
        return;
    }
    chatMessagesBox.addEventListener('scroll', () => {
        if (chatMessagesBox.scrollTop <= 0) {
            loadOlderMessages();
        }
    });
}

function normalizeMessageId(entry) {
    if (!entry || entry.id === undefined || entry.id === null) {
        return null;
    }
    const idNum = Number(entry.id);
    if (Number.isFinite(idNum)) {
        return idNum;
    }
    if (typeof entry.id === 'string' && entry.id.length > 0) {
        return entry.id;
    }
    return null;
}

function registerMessageIds(messages) {
    messages.forEach(msg => {
        const id = normalizeMessageId(msg);
        if (id !== null) {
            chatMessageIds.add(id);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindChatForm();
    bindAutoScrollGuards();
    bindScrollLoadMore();
    startChat();
});
</script>

<?php include __DIR__ . '/../templates/layout_bottom.php'; ?>
