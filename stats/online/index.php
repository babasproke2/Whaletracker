<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$pageTitle = 'Online Now · WhaleTracker';
$activePage = 'online';
$tabRevision = null;

include __DIR__ . '/../templates/layout_top.php';
include __DIR__ . '/../templates/tab-online.php';
?>

<script>
const onlineEndpoint = '/stats/online.php';
const onlineRefreshMs = 10000;
const defaultAvatar = '<?= WT_DEFAULT_AVATAR_URL ?>';
const onlineTable = document.getElementById('stats-table-online');
const onlineTbody = onlineTable ? onlineTable.querySelector('tbody') : null;
const onlineEmpty = document.getElementById('online-empty');
const onlineCountLabel = document.getElementById('nav-online-count');
const classIconBase = <?= json_encode(rtrim(WT_CLASS_ICON_BASE, '/')) ?> + '/';
const mapCardsContainer = document.getElementById('online-map-cards');
let visibleMaxPlayers = 32;

const classMetadata = {
    0: { slug: 'spectator', label: 'Spectator', icon: 'Icon_replay.png' },
    1: { slug: 'scout', label: 'Scout', icon: 'Scout.png' },
    2: { slug: 'sniper', label: 'Sniper', icon: 'Sniper.png' },
    3: { slug: 'soldier', label: 'Soldier', icon: 'Soldier.png' },
    4: { slug: 'demoman', label: 'Demoman', icon: 'Demoman.png' },
    5: { slug: 'medic', label: 'Medic', icon: 'Medic.png' },
    6: { slug: 'heavy', label: 'Heavy', icon: 'Heavy.png' },
    7: { slug: 'pyro', label: 'Pyro', icon: 'Pyro.png' },
    8: { slug: 'spy', label: 'Spy', icon: 'Spy.png' },
    9: { slug: 'engineer', label: 'Engineer', icon: 'Engineer.png' },
};

function formatPlaytime(seconds) {
    const totalSeconds = Math.max(0, Number(seconds) || 0);
    const totalMinutes = Math.floor(totalSeconds / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }
    return `${Math.max(minutes, 1)}m`;
}

function formatNumber(value, decimals) {
    const num = Number(value) || 0;
    return num.toFixed(decimals);
}

function createNumberCell(sortValue, displayValue, title) {
    const td = document.createElement('td');
    td.textContent = displayValue;
    if (title) {
        td.title = title;
    }
    td.dataset.sortValue = sortValue;
    return td;
}

function getClassInfo(classId) {
    const meta = classMetadata[classId] || classMetadata[0];
    if (!meta) {
        return { name: 'Unknown', icon: null };
    }
    return {
        name: meta.label,
        icon: meta.icon ? classIconBase + meta.icon : null,
    };
}

function appendClassIcons(target, classId) {
    if (!target) {
        return;
    }
    const wrapper = document.createElement('span');
    wrapper.className = 'class-icon-strip';
    const info = getClassInfo(Number(classId) || 0);
    if (info.icon) {
        const icon = document.createElement('img');
        icon.className = 'online-class-icon';
        icon.src = info.icon;
        icon.alt = info.name;
        icon.title = info.name;
        wrapper.appendChild(icon);
    }
    if (wrapper.children.length > 0) {
        target.appendChild(wrapper);
    }
}

function updateOnlineButtonInfo(count, max) {
    if (onlineCountLabel) {
        onlineCountLabel.textContent = `${count} / ${max}`;
    }
}

function renderServerCards(servers) {
    if (!mapCardsContainer) {
        return;
    }
    mapCardsContainer.innerHTML = '';
    const list = Array.isArray(servers) ? servers.slice() : [];
    if (list.length === 0) {
        const fallback = document.createElement('div');
        fallback.className = 'info-card';
        const infoSide = document.createElement('div');
        infoSide.className = 'info-card-left';
        const label = document.createElement('div');
        label.className = 'label';
        label.textContent = 'Map:';
        const value = document.createElement('div');
        value.className = 'value';
        value.textContent = 'Unknown';
        const players = document.createElement('div');
        players.className = 'subvalue';
        players.textContent = 'Players: 0 / 0';
        infoSide.append(label, value, players);
        fallback.appendChild(infoSide);
        mapCardsContainer.appendChild(fallback);
        return;
    }
    list.sort((a, b) => (Number(a.host_port) || 0) - (Number(b.host_port) || 0));
    list.forEach(server => {
        const card = document.createElement('div');
        card.className = 'info-card';
        const infoSide = document.createElement('div');
        infoSide.className = 'info-card-left';
        const imageSide = document.createElement('div');
        imageSide.className = 'info-card-right';
        const label = document.createElement('div');
        label.className = 'label';
        const ip = server.host_ip || '0.0.0.0';
        const port = Number(server.host_port) || 0;
        const city = (server.city && server.city.trim()) ? server.city : 'Unknown';
        const countryCode = (server.country_code && server.country_code.trim()) ? server.country_code.trim().toLowerCase() : '';
        const connectLink = document.createElement('a');
        connectLink.classList.add('connect-url');
        connectLink.href = `steam://connect/${ip}:${port}`;
        connectLink.textContent = `${ip}:${port}`;
        connectLink.title = 'Connect via Steam';
        label.textContent = '(';
        label.appendChild(connectLink);
        label.appendChild(document.createTextNode(`): ${city}`));
        if (countryCode) {
            label.appendChild(document.createTextNode(' '));
            const flag = document.createElement('img');
            flag.className = 'server-flag';
            flag.alt = countryCode.toUpperCase();
            flag.src = `https://bantculture.com/static/flags/bantflags/${countryCode}.png`;
            flag.title = `Region: ${countryCode}`;
            label.appendChild(flag);
        }
        const extraFlags = Array.isArray(server.extra_flags) ? server.extra_flags.slice() : [];
        extraFlags.forEach(flagName => {
            const normalized = (flagName || '').trim().toLowerCase();
            if (!normalized) {
                return;
            }
            const extra = document.createElement('img');
            extra.className = 'server-flag';
            extra.alt = normalized.toUpperCase();
            extra.src = `https://bantculture.com/static/flags/bantflags/${normalized}.png`;
            extra.title = `${flagName}`;
            label.appendChild(document.createTextNode(' '));
            label.appendChild(extra);
        });
        const value = document.createElement('div');
        value.className = 'value';
        value.textContent = server.map_name || 'Unknown';
        const players = document.createElement('div');
        players.className = 'subvalue';
        const max = Number(server.visible_max) || visibleMaxPlayers;
        players.textContent = `Players: ${server.player_count || 0} / ${max}`;
        infoSide.append(label, value, players);
        const img = document.createElement('img');
        img.className = 'info-card-image';
        img.alt = server.map_name ? `${server.map_name} preview` : 'Map preview';
        img.src = server.map_image || '';
        imageSide.appendChild(img);
        card.append(infoSide, imageSide);
        mapCardsContainer.appendChild(card);
    });
}

function renderOnline(players) {
    if (!onlineTable || !onlineTbody || !onlineEmpty) {
        return;
    }
    const list = Array.isArray(players) ? players.slice() : [];
    list.sort((a, b) => {
        const scoreA = (Number(a.kills) || 0) + (Number(a.assists) || 0);
        const scoreB = (Number(b.kills) || 0) + (Number(b.assists) || 0);
        if (scoreA !== scoreB) {
            return scoreB - scoreA;
        }
        return (Number(b.kills) || 0) - (Number(a.kills) || 0);
    });
    if (list.length === 0) {
        onlineTable.style.display = '';
        onlineEmpty.style.display = '';
        onlineTbody.innerHTML = '';
        return;
    }
    onlineTable.style.display = '';
    onlineEmpty.style.display = 'none';
    onlineTbody.innerHTML = '';

    list.forEach(player => {
        const steamid = player.steamid || '';
        const personaname = player.personaname || steamid || 'Unknown';
        const avatar = player.avatar || defaultAvatar;
        const profileUrl = player.profileurl || null;
        const team = Number(player.team) || 0;
        const isAlive = Number(player.alive) === 1;
        const listedSpectator = Number(player.is_spectator) === 1;

        const kills = Number(player.kills) || 0;
        const deaths = Number(player.deaths) || 0;
        const assists = Number(player.assists) || 0;
        const damage = Number(player.damage) || 0;
        const shots = Number(player.shots) || 0;
        const hits = Number(player.hits) || 0;
        const damageTaken = Number(player.damage_taken) || 0;
        const healing = Number(player.healing) || 0;
        const headshots = Number(player.headshots) || 0;
        const backstabs = Number(player.backstabs) || 0;
        const totalUbers = Number(player.total_ubers) || 0;
        let timeConnected = Number(player.time_connected);
        if (!Number.isFinite(timeConnected) || timeConnected < 0) {
            timeConnected = Number(player.playtime) || 0;
        }
        const minutes = timeConnected > 0 ? (timeConnected / 60) : 0;
        const dpm = minutes > 0 ? damage / minutes : damage;
        const dtpm = minutes > 0 ? damageTaken / minutes : damageTaken;
        const kdValue = deaths > 0 ? kills / deaths : kills;
        const score = kills + assists;
        const weaponEntries = Array.isArray(player.weapon_category_summary) ? player.weapon_category_summary : [];
        const activeClass = Number(player.class) || 0;
        const activeMeta = classMetadata[activeClass];
        const activeAcc = player.active_weapon_accuracy;
        let accuracy = null;
        let accuracyDisplay = '—';
        let accuracyTitle = 'Accuracy unavailable';
        if (activeAcc && activeAcc.shots > 0) {
            const shotsValue = Number(activeAcc.shots) || 0;
            const hitsValue = Number(activeAcc.hits) || 0;
            accuracy = shotsValue > 0 ? (hitsValue / shotsValue) * 100 : null;
            accuracyDisplay = accuracy !== null ? `${formatNumber(accuracy, 1)}%` : '—';
            const label = activeAcc.label || 'Weapon';
            accuracyTitle = `${label} (${shotsValue.toLocaleString()} shots / ${hitsValue.toLocaleString()} hits)`;
        } else if (shots > 0) {
            accuracy = (hits / shots) * 100;
            accuracyDisplay = `${formatNumber(accuracy, 1)}%`;
            accuracyTitle = `${formatNumber(accuracy, 1)}% overall accuracy (${shots.toLocaleString()} shots / ${hits.toLocaleString()} hits)`;
        }
        const accuracySortValue = accuracy !== null ? accuracy : 0;

        const classInfo = getClassInfo(Number(player.class) || 0);
        const isSpectator = listedSpectator
            || (team !== 2 && team !== 3)
            || classInfo.name === 'Spectator'
            || classInfo.name === 'Unknown';

        const tr = document.createElement('tr');
        tr.classList.add('online-player');
        if (team === 2) {
            tr.classList.add('player-team-red');
        } else if (team === 3) {
            tr.classList.add('player-team-blue');
        } else {
            tr.classList.add('player-team-neutral');
        }
        if (!isAlive || isSpectator) {
            tr.classList.add('player-faded');
        }
        if (isSpectator) {
            tr.classList.add('player-spectator');
        }
        tr.dataset.player = personaname.toLowerCase();
        tr.dataset.kills = String(kills);
        tr.dataset.deaths = String(deaths);
        tr.dataset.kd = kdValue.toFixed(4);
        tr.dataset.assists = String(assists);
        tr.dataset.damage = String(damage);
        tr.dataset.damage_taken = String(damageTaken);
        tr.dataset.healing = String(healing);
        tr.dataset.headshots = String(headshots);
        tr.dataset.backstabs = String(backstabs);
        tr.dataset.dpm = dpm.toFixed(4);
        tr.dataset.dtpm = dtpm.toFixed(4);
        tr.dataset.ubers = String(totalUbers);
        tr.dataset.time = String(timeConnected);
        tr.dataset.playtime = String(timeConnected);
        tr.dataset.score = String(score);
        tr.dataset.accuracy = accuracy !== null ? accuracy.toFixed(4) : '0';
        tr.dataset.shots = String(shots);
        tr.dataset.hits = String(hits);

        const playerCell = document.createElement('td');
        playerCell.className = 'player-cell';
        const avatarImg = document.createElement('img');
        avatarImg.className = 'player-avatar';
        avatarImg.src = avatar;
        avatarImg.alt = '';
        playerCell.appendChild(avatarImg);
        const infoWrapper = document.createElement('div');
        const nameEl = document.createElement('a');
        nameEl.textContent = personaname;
        nameEl.title = personaname;
        if (profileUrl) {
            nameEl.href = profileUrl;
            nameEl.target = '_blank';
            nameEl.rel = 'noopener';
        } else {
            nameEl.href = '#';
            nameEl.addEventListener('click', event => event.preventDefault());
        }
        infoWrapper.appendChild(nameEl);
        appendClassIcons(infoWrapper, player.class);
        playerCell.appendChild(infoWrapper);
        tr.appendChild(playerCell);

        tr.appendChild(createNumberCell(kills, kills.toLocaleString(), 'Kills'));
        tr.appendChild(createNumberCell(deaths, deaths.toLocaleString(), 'Deaths'));
        tr.appendChild(createNumberCell(kdValue.toFixed(4), formatNumber(kdValue, 2), 'Kill/Death Ratio'));
        tr.appendChild(createNumberCell(accuracySortValue, accuracyDisplay, accuracyTitle));
        tr.appendChild(createNumberCell(assists, assists.toLocaleString(), 'Assists'));
        tr.appendChild(createNumberCell(damage, damage.toLocaleString(), 'Damage'));
        tr.appendChild(createNumberCell(dtpm, formatNumber(dtpm, 1), 'Damage Taken Per Minute'));
        tr.appendChild(createNumberCell(dpm, formatNumber(dpm, 1), 'Damage Per Minute'));
        tr.appendChild(createNumberCell(headshots, headshots.toLocaleString(), 'Headshots'));
        tr.appendChild(createNumberCell(backstabs, backstabs.toLocaleString(), 'Backstabs'));
        tr.appendChild(createNumberCell(healing, healing.toLocaleString(), 'Healing Done'));
        tr.appendChild(createNumberCell(totalUbers, totalUbers.toLocaleString(), 'Total Ubers'));
        tr.appendChild(createNumberCell(timeConnected, formatPlaytime(timeConnected), 'Time Connected'));

        onlineTbody.appendChild(tr);
    });
}

async function fetchOnline() {
    if (!onlineTable || !onlineTbody || !onlineEmpty) {
        return;
    }
    try {
        const response = await fetch(onlineEndpoint, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Failed request');
        }
        const payload = await response.json();
        if (!payload || payload.success !== true || !Array.isArray(payload.players)) {
            throw new Error('Invalid payload');
        }
        const players = Array.isArray(payload.players) ? payload.players.slice() : [];
        const servers = Array.isArray(payload.servers) ? payload.servers.slice() : [];
        visibleMaxPlayers = Number(payload.visible_max_players) || 32;
        const totalPlayers = servers.reduce((sum, server) => sum + (Number(server.player_count) || 0), 0);
        const totalMax = servers.reduce((sum, server) => sum + (Number(server.visible_max) || 0), 0);
        renderOnline(players);
        renderServerCards(servers);
        const countForNav = totalPlayers || Number(payload.player_count || players.length || 0);
        const maxForNav = totalMax || visibleMaxPlayers;
        updateOnlineButtonInfo(countForNav, maxForNav);
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch online stats:', err);
        updateOnlineButtonInfo(0, visibleMaxPlayers);
        renderServerCards([]);
        updateMapImage(null, null);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (onlineTable && onlineTbody && onlineEmpty) {
        fetchOnline();
        setInterval(fetchOnline, onlineRefreshMs);
    }
});
</script>

<?php include __DIR__ . '/../templates/layout_bottom.php'; ?>
