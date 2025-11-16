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
let chatPolling = false;
let chatTimer = null;
let chatRevision = null;
let chatAutoScrollEnabled = true;

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

let topArrow = null;
let bottomArrow = null;
if (chatPanel) {
    topArrow = document.createElement('div');
    topArrow.classList.add('navarrow', 'navarrow-top');
    topArrow.innerHTML = '<img src="/stats/reisen_up.png" alt="Scroll to top">';
    topArrow.addEventListener('click', () => {
        const box = document.getElementById('chat-messages');
        if (box) box.scrollTop = 0;
    });

    bottomArrow = document.createElement('div');
    bottomArrow.classList.add('navarrow', 'navarrow-bottom');
    bottomArrow.innerHTML = '<img src="/stats/tewi_down.png" alt="Scroll to bottom">';
    bottomArrow.addEventListener('click', () => {
        const box = document.getElementById('chat-messages');
        if (box) box.scrollTop = box.scrollHeight;
    });
    chatPanel.appendChild(topArrow);
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
    header.textContent = entry.name || 'Unknown';
    const body = document.createElement('div');
    body.className = 'chat-body';
    body.appendChild(renderMessageText(entry.message));
    content.appendChild(header);
    content.appendChild(body);
    row.appendChild(avatar);
    row.appendChild(content);
    return row;
}

function renderChat(messages) {
    if (!chatMessagesBox) {
        return;
    }
    chatMessagesBox.innerHTML = '';
    messages.forEach(msg => {
        chatMessagesBox.appendChild(buildChatRow(msg));
    });
    if (chatAutoScrollEnabled) {
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

async function refreshChat() {
    try {
        const response = await fetch(`${chatEndpoint}?t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const payload = await response.json();
        if (!payload || payload.ok === false) {
            throw new Error(payload && payload.error ? payload.error : 'Chat backend error');
        }
        const revision = payload.revision ? String(payload.revision) : null;
        if (revision && chatRevision === revision) {
            return;
        }
        renderChat(Array.isArray(payload.messages) ? payload.messages : []);
        if (revision) {
            chatRevision = revision;
        }
        setChatStatus('');
    } catch (err) {
        console.error('[WhaleTracker] Chat refresh failed:', err);
        setChatStatus('Chat sync failed', true);
    }
}

function startChat() {
    if (chatPolling) {
        return;
    }
    chatPolling = true;
    refreshChat();
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
        if (!response.ok || !payload || payload.ok === false) {
            throw new Error(payload && payload.error ? payload.error : 'Send failed');
        }
        if (chatInput) {
            chatInput.value = '';
        }
        refreshChat();
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

document.addEventListener('DOMContentLoaded', () => {
    bindChatForm();
    bindAutoScrollGuards();
    startChat();
});
</script>

<?php include __DIR__ . '/../templates/layout_bottom.php'; ?>
