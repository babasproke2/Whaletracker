<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!defined('WT_STEAM_API_KEY') || WT_STEAM_API_KEY === '') {
    fwrite(STDERR, "WT_STEAM_API_KEY is not configured.\n");
    exit(1);
}

set_time_limit(0);

function wt_fetch_profile_direct(string $steamId): ?array
{
    $url = sprintf(
        'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=%s&steamids=%s',
        urlencode(WT_STEAM_API_KEY),
        $steamId
    );
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!isset($data['response']['players'][0])) {
        return null;
    }
    return $data['response']['players'][0];
}

$pdo = wt_pdo();
$stmt = $pdo->query("SELECT DISTINCT steamid FROM whaletracker WHERE steamid IS NOT NULL AND steamid != '' ORDER BY last_seen DESC");
$ids = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $steamId = trim((string)$row['steamid']);
    if ($steamId !== '') {
        $ids[] = $steamId;
    }
}

$total = count($ids);
if ($total === 0) {
    echo "No Steam IDs found in whaletracker.\n";
    exit(0);
}

echo "Downloading avatars for {$total} users...\n";
$delay = 10;
$processed = 0;
$success = 0;
foreach ($ids as $index => $steamId) {
    $processed++;
    $prefix = sprintf('[%d/%d] %s', $processed, $total, $steamId);
    $profile = wt_fetch_profile_direct($steamId);
    if (!$profile) {
        echo $prefix . " - failed to fetch profile\n";
    } else {
        $avatarUrl = $profile['avatarfull'] ?? ($profile['avatar'] ?? null);
        if (!$avatarUrl) {
            echo $prefix . " - no avatar URL\n";
        } else {
            $basename = wt_avatar_cache_download($steamId, $avatarUrl, $profile['avatar_cached'] ?? null);
            if ($basename) {
                $profileData = [
                    'steamid' => $steamId,
                    'personaname' => $profile['personaname'] ?? $steamId,
                    'profileurl' => $profile['profileurl'] ?? null,
                    'avatarfull' => wt_avatar_cache_url_from_basename($basename),
                    'avatar_source' => $avatarUrl,
                    'avatar_cached' => $basename,
                    'timecreated' => $profile['timecreated'] ?? null,
                ];
                wt_write_cached_profile($steamId, $profileData);
                echo $prefix . " - saved to " . $basename . "\n";
                $success++;
            } else {
                echo $prefix . " - download failed\n";
            }
        }
    }
    if ($index < $total - 1) {
        sleep($delay);
    }
}

echo "Completed. {$success} of {$total} avatars cached.\n";
