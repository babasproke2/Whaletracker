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
const onlineRefreshMs = 4000;
const defaultAvatar = '<?= WT_DEFAULT_AVATAR_URL ?>';
const onlineTable = document.getElementById('stats-table-online');
const onlineTbody = onlineTable ? onlineTable.querySelector('tbody') : null;
const onlineEmpty = document.getElementById('online-empty');
const onlineCountLabel = document.getElementById('nav-online-count');
let visibleMaxPlayers = 32;

const classNameMap = {0: 'Spectator', 1: 'Scout', 2: 'Sniper', 3: 'Soldier', 4: 'Demoman', 5: 'Medic', 6: 'Heavy', 7: 'Pyro', 8: 'Spy', 9: 'Engineer'};
const classIconBase = '/leaderboard/';
const classIconMap = {
    Spectator: 'Icon_replay.png',
    Unknown: 'Icon_replay.png',
    Scout: 'Scout.png',
    Soldier: 'Soldier.png',
    Pyro: 'Pyro.png',
    Demoman: 'Demoman.png',
    Heavy: 'Heavy.png',
    Engineer: 'Engineer.png',
    Medic: 'Medic.png',
    Sniper: 'Sniper.png',
    Spy: 'Spy.png'
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
    const name = classNameMap[classId] || 'Unknown';
    const iconFile = classIconMap[name] || classIconMap.Unknown;
    return {
        name,
        icon: classIconBase + iconFile
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
        onlineTable.style.display = 'none';
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
        const accuracy = shots > 0 ? (hits / shots) * 100 : null;
        const accuracySortValue = accuracy !== null ? accuracy : 0;
        const accuracyDisplay = accuracy !== null ? `${formatNumber(accuracy, 1)}%` : '—';
        const classEntries = Array.isArray(player.class_accuracy_summary) ? player.class_accuracy_summary : [];

        const tooltipParts = [];
        let bestClassEntry = null;
        classEntries.forEach(entry => {
            if (!entry || typeof entry.accuracy !== 'number') {
                return;
            }
            if (!bestClassEntry || entry.accuracy > bestClassEntry.accuracy) {
                bestClassEntry = entry;
            }
        });
        if (bestClassEntry) {
            const classHits = Number(bestClassEntry.hits || 0);
            const classShots = Number(bestClassEntry.shots || 0);
            if (classShots > 0) {
                tooltipParts.push(`${bestClassEntry.label || 'Class'}: ${formatNumber(bestClassEntry.accuracy, 1)}% (${classHits.toLocaleString()}/${classShots.toLocaleString()})`);
            } else {
                tooltipParts.push(`${bestClassEntry.label || 'Class'}: ${formatNumber(bestClassEntry.accuracy, 1)}%`);
            }
        } else if (accuracy !== null) {
            tooltipParts.push(`${formatNumber(accuracy, 1)}% overall accuracy`);
        } else {
            tooltipParts.push('Accuracy unavailable');
        }
        const accuracyTitle = tooltipParts.join(' • ');

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
        visibleMaxPlayers = Number(payload.visible_max_players) || 32;
        renderOnline(players);
        updateOnlineButtonInfo(players.length, visibleMaxPlayers);
    } catch (err) {
        console.error('[WhaleTracker] Failed to fetch online stats:', err);
        updateOnlineButtonInfo(0, visibleMaxPlayers);
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
