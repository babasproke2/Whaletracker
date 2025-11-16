</div>
</div>
<script>
(function() {
    const navCountEl = document.getElementById('nav-online-count');
    const chatInput = document.getElementById('chat-input');
    if (!navCountEl && !chatInput) {
        return;
    }
    const endpoint = '/stats/online.php';
    async function updateNavCount() {
        try {
            const res = await fetch(endpoint, { cache: 'no-store' });
            if (!res.ok) {
                throw new Error('Request failed');
            }
            const payload = await res.json();
            const players = Array.isArray(payload.players) ? payload.players : [];
            const count = players.length;
            const max = Number(payload.visible_max_players || payload.visible_max || 32) || 32;
            if (navCountEl) {
                navCountEl.textContent = `${count} / ${max}`;
            }
            if (chatInput) {
                const template = chatInput.getAttribute('data-dynamic-placeholder') || 'Type to {count} players | All messages are deleted after 24hrs';
                chatInput.placeholder = template.replace('{count}', String(count));
            }
        } catch (err) {
            // ignore errors; leave last known count
        }
    }
    updateNavCount();
    setInterval(updateNavCount, 10000);
})();
</script>
</body>
</html>
