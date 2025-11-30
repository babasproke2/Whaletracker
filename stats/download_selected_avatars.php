<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!defined('WT_STEAM_API_KEY') || WT_STEAM_API_KEY === '') {
    fwrite(STDERR, "WT_STEAM_API_KEY is not configured.\n");
    exit(1);
}

$targets = [
    'ロリ中出し' => '76561198105849212',
    'Yellow Dice' => '76561199214588079',
    '76561198123370914' => '76561198123370914',
    '76561198003965335' => '76561198003965335',
    'Doom' => '76561198165590947',
    'The Very Hungry Caterpillar' => '76561198065392296',
    '76561199478391681' => '76561199478391681',
    'Gangeral' => '76561198054651632',
    'Palladion' => '76561198019684644',
];

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

set_time_limit(0);
$delaySeconds = 10;
$total = count($targets);
$index = 0;

foreach ($targets as $label => $steamId) {
    $index++;
    $prefix = sprintf('[%d/%d] %s (%s)', $index, $total, $label, $steamId);
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
                echo $prefix . " - cached avatar as " . $basename . "\n";
            } else {
                echo $prefix . " - download failed\n";
            }
        }
    }

    if ($index < $total) {
        sleep($delaySeconds);
    }
}

echo "Done.\n";
