<?php
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Current Match Log · WhaleTracker</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/stats/css/whaletracker.css">
    <style>
        body {
            background: #0f1118;
            color: #f5f6fa;
            font-family: 'Heebo', 'Helvetica Neue', Arial, sans-serif;
            margin: 0;
        }
        .current-wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 2rem 1rem 3rem;
        }
        .current-brand {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        .current-brand img {
            max-width: 180px;
        }
        .current-controls {
            display: flex;
            justify-content: center;
            margin-bottom: 1rem;
        }
        #refresh-current-log {
            padding: 0.65rem 1.5rem;
            border: none;
            border-radius: 999px;
            background: rgba(127, 200, 255, 0.25);
            color: #04142a;
            font-weight: 600;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        #refresh-current-log:hover {
            background: rgba(127, 200, 255, 0.45);
            transform: translateY(-1px);
        }
        .current-log-container {
            background: rgba(18, 20, 28, 0.92);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            padding: 1.5rem;
            min-height: 200px;
        }
    </style>
</head>
<body>
<div class="current-wrapper">
    <div class="current-brand">
        <img src="/stats/assets/whaletracker_logo.png" alt="WhaleTracker logo">
        <img src="/stats/assets/wholesome2.gif" alt="Wholesome">
    </div>
    <div class="current-controls">
        <button id="refresh-current-log">Refresh Logs</button>
    </div>
    <div id="current-log-container" class="current-log-container">Loading current log…</div>
</div>
<script>
const refreshBtn = document.getElementById('refresh-current-log');
const container = document.getElementById('current-log-container');
const endpoint = '/stats/current_log_fragment.php';

async function loadCurrentLog() {
    container.textContent = 'Loading current log…';
    try {
        const response = await fetch(endpoint + '?t=' + Date.now(), { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Request failed');
        }
        const html = await response.text();
        container.innerHTML = html;
    } catch (err) {
        console.error('[WhaleTracker] Failed to load current log', err);
        container.textContent = 'Failed to load current log. Please try again.';
    }
}

refreshBtn.addEventListener('click', loadCurrentLog);
window.addEventListener('load', loadCurrentLog);
</script>
</body>
</html>
