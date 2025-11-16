(() => {
  const container = document.getElementById('logs-container');
  const emptyState = document.getElementById('logs-empty');
  const toggleSmall = document.getElementById('logs-toggle-small');
  const toggleOld = document.getElementById('logs-toggle-old');
  const refreshBtn = document.getElementById('logs-refresh');
  if (!container) return;

  let showSmall = false;
  let showOld = true;
  const SMALL_LIMIT = 12;
  const fragmentUrl = container.dataset.fragment || 'logs_fragment.php';

  const applyFilters = () => {
    const entries = container.querySelectorAll('.log-entry');
    let visible = 0;
    entries.forEach((entry, index) => {
      const count = Number(entry.dataset.playerCount || 0);
      let hide = false;
      if (!showSmall && index !== 0 && count < SMALL_LIMIT) hide = true;
      if (!showOld && index > 0) hide = true;
      entry.style.display = hide ? 'none' : '';
      if (!hide) visible++;
    });
    if (emptyState) {
      emptyState.style.display = visible === 0 ? '' : 'none';
    }
  };

  const fetchFragment = () => {
    fetch(`${fragmentUrl}?limit=60&t=${Date.now()}`)
      .then(resp => {
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return resp.text();
      })
      .then(html => {
        container.innerHTML = html;
        applyFilters();
      })
      .catch(err => {
        console.error('[WhaleTracker] Failed to load logs fragment', err);
        if (emptyState) {
          emptyState.textContent = 'Failed to load logs.';
          emptyState.style.display = '';
        }
      });
  };

  if (refreshBtn) refreshBtn.addEventListener('click', fetchFragment);
  if (toggleSmall) toggleSmall.addEventListener('click', () => {
    showSmall = !showSmall;
    toggleSmall.textContent = showSmall ? 'Hide <12 Player Logs' : 'Show <12 Player Logs';
    applyFilters();
  });
  if (toggleOld) toggleOld.addEventListener('click', () => {
    showOld = !showOld;
    toggleOld.textContent = showOld ? 'Hide Old' : 'Show Old';
    applyFilters();
  });

  fetchFragment();
})();
