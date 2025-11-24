</div>
</div>
<script>
(function() {
    const navCountEl = document.getElementById('nav-online-count');
    const chatInput = document.getElementById('chat-input');
    const navChatLabel = document.getElementById('nav-chat-label');
    if (!navCountEl && !chatInput && !navChatLabel) {
        return;
    }
    const onlineEndpoint = '/stats/online.php';
    const chatEndpoint = '/stats/chat.php?limit=1&alerts_only=1';

    async function updateNavCount() {
        if (!navCountEl && !chatInput) {
            return;
        }
        try {
            const res = await fetch(onlineEndpoint, { cache: 'no-store' });
            if (!res.ok) {
                throw new Error('Request failed');
            }
            const payload = await res.json();
            const players = Array.isArray(payload.players) ? payload.players : [];
            const servers = Array.isArray(payload.servers) ? payload.servers : [];
            let count = servers.reduce((sum, server) => sum + (Number(server.player_count) || 0), 0);
            let max = servers.reduce((sum, server) => sum + (Number(server.visible_max) || 0), 0);
            if (!count) {
                count = Number(payload.player_count || players.length || 0);
            }
            if (!max) {
                max = Number(payload.visible_max_players || payload.visible_max || 32) || 32;
            }
            if (navCountEl) {
                navCountEl.textContent = `${count} / ${max}`;
            }
            if (chatInput) {
                const template = chatInput.getAttribute('data-dynamic-placeholder') || 'Type to {count} players | All messages are deleted after 24hrs';
                chatInput.placeholder = template.replace('{count}', String(count));
            }
        } catch (err) {
            // ignore errors
        }
    }

    function formatChatAge(diffSeconds) {
        if (!Number.isFinite(diffSeconds) || diffSeconds < 0) {
            return '--';
        }
        if (diffSeconds < 60) {
            return 'now';
        }
        if (diffSeconds < 3600) {
            const minutes = Math.max(1, Math.floor(diffSeconds / 60));
            return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        }
        if (diffSeconds < 86400) {
            const hours = Math.max(1, Math.floor(diffSeconds / 3600));
            return `${hours} hour${hours === 1 ? '' : 's'} ago`;
        }
        if (diffSeconds < 604800) {
            const days = Math.max(1, Math.floor(diffSeconds / 86400));
            return `${days} day${days === 1 ? '' : 's'} ago`;
        }
        const weeks = Math.floor(diffSeconds / 604800);
        if (weeks < 5) {
            return `${weeks} week${weeks === 1 ? '' : 's'} ago`;
        }
        const months = Math.max(1, Math.floor(diffSeconds / 2629800));
        return `${months} month${months === 1 ? '' : 's'} ago`;
    }

    async function updateChatAge() {
        if (!navChatLabel) {
            return;
        }
        try {
            const res = await fetch(chatEndpoint + `&t=${Date.now()}`, { cache: 'no-store' });
            if (!res.ok) {
                throw new Error('Request failed');
            }
            const payload = await res.json();
            if (!payload || payload.ok === false) {
                throw new Error('Invalid chat payload');
            }
            const messages = Array.isArray(payload.messages) ? payload.messages : [];
            if (messages.length === 0) {
                navChatLabel.textContent = 'Chat - --';
                return;
            }
            const last = messages[messages.length - 1];
            const createdAt = Number(last.created_at || 0);
            const nowSeconds = Math.floor(Date.now() / 1000);
            const ageLabel = formatChatAge(nowSeconds - createdAt);
            navChatLabel.textContent = `Last msg. ${ageLabel}`;
        } catch (err) {
            // ignore errors; keep existing label
        }
    }

    updateNavCount();
    setInterval(updateNavCount, 10000);
    updateChatAge();
    setInterval(updateChatAge, 60000);
})();
</script>
</body>
</html>
