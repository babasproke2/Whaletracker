#pragma semicolon 1
#pragma newdecls required

#include <sourcemod>
#include <tf2>
#include <tf2_stocks>
#include <sdktools>
#include <sdkhooks>
#include <clientprefs>
#include <morecolors>
#include <adt_array>
#include <datapack>
#include <adt_trie>

#define STEAMID64_LEN 32
#define MENU_TITLE "Whale Tracker Stats"
#define DB_CONFIG_DEFAULT "default"
#define SAVE_QUERY_MAXLEN 2048
#define MAX_CONCURRENT_SAVE_QUERIES 4

enum
{
    CLASS_UNKNOWN = TFClass_Unknown,
    CLASS_SCOUT = TFClass_Scout,
    CLASS_SNIPER = TFClass_Sniper,
    CLASS_SOLDIER = TFClass_Soldier,
    CLASS_DEMOMAN = TFClass_DemoMan,
    CLASS_MEDIC = TFClass_Medic,
    CLASS_HEAVY = TFClass_Heavy,
    CLASS_PYRO = TFClass_Pyro,
    CLASS_SPY = TFClass_Spy,
    CLASS_ENGINEER = TFClass_Engineer,
    CLASS_MIN = CLASS_SCOUT,
    CLASS_MAX = CLASS_ENGINEER,
    CLASS_COUNT = CLASS_MAX + 1
};

public Action OnPlayerTakeDamage(int victim, int &attacker, int &inflictor, float &damage, int &damagetype, int &weapon, float damageForce[3], float damagePosition[3], int damagecustom);

enum struct WhaleStats
{
    bool loaded;
    char steamId[STEAMID64_LEN];
    char firstSeen[32];
    int firstSeenTimestamp;

    int kills;
    int deaths;
    int totalShots;
    int totalHits;
    int totalHealing;
    int totalUbers;
    int totalMedicDrops;
    int totalAirshots;
    int totalHeadshots;
    int totalBackstabs;
    int totalAssists;
    int totalDamage;
    int totalDamageTaken;
    int totalUberDrops;
    int damageClassesMask;
    int classShots[CLASS_COUNT];
    int classHits[CLASS_COUNT];
    int lastSeen;

    int bestKillsLife;
    int bestKillstreak;
    int bestHeadshotsLife;
    int bestBackstabsLife;
    int bestScoreLife;
    int bestAssistsLife;
    int bestUbersLife;

    int mostKillsLifeSession;
    int mostKillstreakSession;
    int mostHeadshotsLifeSession;
    int mostBackstabsLifeSession;
    int mostScoreLifeSession;
    int mostAssistsLifeSession;
    int mostUbersLifeSession;

    int playtime; // seconds

    // runtime counters (not persisted directly)
    int currentKillsLife;
    int currentKillstreak;
    int currentHeadshotsLife;
    int currentBackstabsLife;
    int currentAssistsLife;
    int currentScoreLife;
    int currentUbersLife;

    float connectTime;

    bool isAdmin;
}

WhaleStats g_Stats[MAXPLAYERS + 1];
WhaleStats g_MapStats[MAXPLAYERS + 1];
int g_KillSaveCounter[MAXPLAYERS + 1];

Database g_hDatabase = null;
ConVar g_CvarDatabase = null;
ConVar g_hVisibleMaxPlayers = null;
ConVar g_hDebugMinimalStats = null;
bool g_bDatabaseReady = false;
bool g_bDebugMinimalStats = false;

enum MatchStatField
{
    MatchStat_Kills = 0,
    MatchStat_Deaths,
    MatchStat_Assists,
    MatchStat_Damage,
    MatchStat_DamageTaken,
    MatchStat_Healing,
    MatchStat_Headshots,
    MatchStat_Backstabs,
    MatchStat_Ubers,
    MatchStat_Playtime,
    MatchStat_MedicDrops,
    MatchStat_UberDrops,
    MatchStat_Airshots,
    MatchStat_Shots,
    MatchStat_MatchShots,
    MatchStat_Hits,
    MatchStat_BestStreak,
    MatchStat_BestHeadshotsLife,
    MatchStat_BestBackstabsLife,
    MatchStat_BestScoreLife,
    MatchStat_BestKillsLife,
    MatchStat_BestAssistsLife,
    MatchStat_BestUbersLife,
    MatchStat_IsAdmin,
    MatchStat_Count
};

enum
{
    MATCH_STAT_COUNT = MatchStat_Count
};

StringMap g_DisconnectedStats = null;
StringMap g_MatchNames = null;

char g_sCurrentMap[64];
char g_sCurrentLogId[64];
int g_iMatchStartTime = 0;
bool g_bMatchFinalized = false;

ConVar g_hGameModeCvar = null;

char g_sDatabaseConfig[64];
ArrayList g_SaveQueue = null;
int g_PendingSaveQueries = 0;
bool g_bShuttingDown = false;
Handle g_hOnlineTimer = null;

char g_SaveQueryBuffers[MAX_CONCURRENT_SAVE_QUERIES][SAVE_QUERY_MAXLEN];
int g_SaveQueryUserIds[MAX_CONCURRENT_SAVE_QUERIES];
bool g_SaveQuerySlotUsed[MAX_CONCURRENT_SAVE_QUERIES];

static void RefreshDebugMinimalFlag()
{
    if (g_hDebugMinimalStats != null)
    {
        g_bDebugMinimalStats = g_hDebugMinimalStats.BoolValue;
    }
    else
    {
        g_bDebugMinimalStats = false;
    }
}

public void ConVarChanged_DebugMinimal(ConVar convar, const char[] oldValue, const char[] newValue)
{
    RefreshDebugMinimalFlag();
}

public void OnConfigsExecuted()
{
    RefreshDebugMinimalFlag();
}

static bool UpdateAdminStatus(int client)
{
    bool newStatus = CheckCommandAccess(client, "sm_kick", ADMFLAG_KICK, false);
    bool changed = (g_Stats[client].isAdmin != newStatus);
    g_Stats[client].isAdmin = newStatus;
    g_MapStats[client].isAdmin = newStatus;
    return changed;
}

static void ResetRuntimeCounters(WhaleStats stats)
{
    stats.currentKillsLife = 0;
    stats.currentKillstreak = 0;
    stats.currentHeadshotsLife = 0;
    stats.currentBackstabsLife = 0;
    stats.currentAssistsLife = 0;
    stats.currentScoreLife = 0;
    stats.currentUbersLife = 0;
    stats.mostKillsLifeSession = 0;
    stats.mostKillstreakSession = 0;
    stats.mostHeadshotsLifeSession = 0;
    stats.mostBackstabsLifeSession = 0;
    stats.mostAssistsLifeSession = 0;
    stats.mostScoreLifeSession = 0;
    stats.mostUbersLifeSession = 0;
}

static void ResetStatsStruct(WhaleStats stats, bool resetIdentity)
{
    if (resetIdentity)
    {
        stats.loaded = false;
        stats.steamId[0] = '\0';
        stats.firstSeen[0] = '\0';
        stats.firstSeenTimestamp = 0;
    }

    stats.kills = 0;
    stats.deaths = 0;
    stats.totalShots = 0;
    stats.totalHits = 0;
    stats.totalHealing = 0;
    stats.totalUbers = 0;
    stats.totalMedicDrops = 0;
    stats.totalAirshots = 0;
    stats.totalHeadshots = 0;
    stats.totalBackstabs = 0;
    stats.totalAssists = 0;
    stats.totalDamage = 0;
    stats.totalDamageTaken = 0;
    stats.totalUberDrops = 0;
    stats.damageClassesMask = 0;
    for (int i = 0; i < CLASS_COUNT; i++)
    {
        stats.classShots[i] = 0;
        stats.classHits[i] = 0;
    }
    stats.lastSeen = 0;
    stats.bestKillsLife = 0;
    stats.bestKillstreak = 0;
    stats.bestHeadshotsLife = 0;
    stats.bestBackstabsLife = 0;
    stats.bestScoreLife = 0;
    stats.bestAssistsLife = 0;
    stats.bestUbersLife = 0;
    stats.playtime = 0;
    stats.connectTime = 0.0;
    stats.isAdmin = false;

    ResetRuntimeCounters(stats);
}

static void ResetAllStats(int client)
{
    ResetStatsStruct(g_Stats[client], true);
    ResetStatsStruct(g_MapStats[client], true);
}

static void ResetMapStats(int client)
{
    ResetStatsStruct(g_MapStats[client], false);
    g_MapStats[client].loaded = g_Stats[client].loaded;
    if (g_Stats[client].steamId[0] != '\0')
    {
        strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), g_Stats[client].steamId);
    }
    g_MapStats[client].connectTime = GetEngineTime();
    g_MapStats[client].lastSeen = g_Stats[client].lastSeen;
    g_MapStats[client].isAdmin = g_Stats[client].isAdmin;
}

static void ResetRuntimeStats(int client)
{
    ResetRuntimeCounters(g_Stats[client]);
    ResetRuntimeCounters(g_MapStats[client]);
}

static void TouchClientLastSeen(int client)
{
    int now = GetTime();
    g_Stats[client].lastSeen = now;
    g_MapStats[client].lastSeen = now;
}

void ClearOnlineStats()
{
    static const char deleteQuery[] = "DELETE FROM whaletracker_online";
    QueueSaveQuery(deleteQuery, 0, false);
}

void RemoveOnlineStats(int client)
{
    char steamId[STEAMID64_LEN];
    if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
        return;

    char query[128];
    Format(query, sizeof(query), "DELETE FROM whaletracker_online WHERE steamid = '%s'", steamId);
    QueueSaveQuery(query, 0, false);
}

public Action Timer_UpdateOnlineStats(Handle timer, any data)
{
    if (!g_bDatabaseReady || g_hDatabase == null)
        return Plugin_Continue;

    int now = GetTime();
    float engineNow = GetEngineTime();

    int visibleMax = 32;
    if (g_hVisibleMaxPlayers != null)
    {
        int conVarValue = GetConVarInt(g_hVisibleMaxPlayers);
        if (conVarValue > 0)
        {
            visibleMax = conVarValue;
        }
    }

    char steamId[STEAMID64_LEN];
    char name[MAX_NAME_LENGTH];
    char escapedName[MAX_NAME_LENGTH * 2];
    char query[SAVE_QUERY_MAXLEN];

    for (int client = 1; client <= MaxClients; client++)
    {
        if (!IsClientInGame(client) || IsFakeClient(client))
            continue;

        if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
            continue;

        GetClientName(client, name, sizeof(name));
        RememberMatchPlayerName(steamId, name);
        SQL_EscapeString(g_hDatabase, name, escapedName, sizeof(escapedName));

        TouchClientLastSeen(client);

        TFClassType tfClassType = TF2_GetPlayerClass(client);
        int tfClass = view_as<int>(tfClassType);
        int team = GetClientTeam(client);
        bool alive = IsPlayerAlive(client);
        bool spectator = (team != 2 && team != 3) || tfClassType == TFClass_Unknown;

        int playtime = g_MapStats[client].playtime;
        if (g_MapStats[client].connectTime > 0.0)
        {
            playtime += RoundToFloor(engineNow - g_MapStats[client].connectTime);
        }

        char classValueSegment[512];
        BuildClassValueSegment(g_MapStats[client], classValueSegment, sizeof(classValueSegment));

        Format(query, sizeof(query),
            "REPLACE INTO whaletracker_online "
            ... "(steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, playtime, total_ubers, best_streak, visible_max, time_connected, classes_mask, shots_scout, hits_scout, shots_sniper, hits_sniper, shots_soldier, hits_soldier, shots_demoman, hits_demoman, shots_medic, hits_medic, shots_heavy, hits_heavy, shots_pyro, hits_pyro, shots_spy, hits_spy, shots_engineer, hits_engineer, last_update) "
            ... "VALUES ('%s', '%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s, %d)",
            steamId,
            escapedName,
            tfClass,
            team,
            alive ? 1 : 0,
            spectator ? 1 : 0,
            g_MapStats[client].kills,
            g_MapStats[client].deaths,
            g_MapStats[client].totalAssists,
            g_MapStats[client].totalDamage,
            g_MapStats[client].totalDamageTaken,
            g_MapStats[client].totalHealing,
            g_MapStats[client].totalHeadshots,
            g_MapStats[client].totalBackstabs,
            playtime,
            g_MapStats[client].totalUbers,
            g_MapStats[client].bestKillstreak,
            visibleMax,
            playtime,
            classValueSegment,
            now);

        QueueSaveQuery(query, 0, false);
    }

    Format(query, sizeof(query), "DELETE FROM whaletracker_online WHERE last_update < %d", now - 20);
    QueueSaveQuery(query, 0, false);

    return Plugin_Continue;
}

static void EscapeSqlString(const char[] input, char[] output, int maxlen)
{
    if (maxlen <= 0)
    {
        return;
    }

    output[0] = '\0';

    if (!input[0])
    {
        return;
    }

    if (g_hDatabase != null && g_bDatabaseReady)
    {
        SQL_EscapeString(g_hDatabase, input, output, maxlen);
        return;
    }

    int written = 0;
    for (int i = 0; input[i] != '\0' && written < maxlen - 1; i++)
    {
        char c = input[i];

        if (c == '\\' || c == '\'' || c == '\"')
        {
            if (written + 2 >= maxlen)
            {
                break;
            }
            output[written++] = '\\';
            output[written++] = c;
        }
        else if (c == '\n' || c == '\r' || c == '\t')
        {
            if (written + 2 >= maxlen)
            {
                break;
            }
            output[written++] = '\\';
            output[written++] = (c == '\n') ? 'n' : ((c == '\r') ? 'r' : 't');
        }
        else
        {
            output[written++] = c;
        }
    }

    output[written] = '\0';
}

static void EnsureMatchStorage()
{
    if (g_DisconnectedStats == null)
    {
        g_DisconnectedStats = new StringMap();
    }
    if (g_MatchNames == null)
    {
        g_MatchNames = new StringMap();
    }
}

static void ResetMatchStorage()
{
    EnsureMatchStorage();
    g_DisconnectedStats.Clear();
    g_MatchNames.Clear();
}

static void RememberMatchPlayerName(const char[] steamId, const char[] name)
{
    if (!steamId[0] || !name[0])
        return;

    EnsureMatchStorage();
    g_MatchNames.SetString(steamId, name);
}

static bool GetStoredMatchPlayerName(const char[] steamId, char[] buffer, int maxlen)
{
    if (!steamId[0] || maxlen <= 0)
    {
        return false;
    }

    EnsureMatchStorage();
    return g_MatchNames.GetString(steamId, buffer, maxlen);
}

static void SnapshotFromStats(const WhaleStats stats, int data[MATCH_STAT_COUNT])
{
    data[MatchStat_Kills] = stats.kills;
    data[MatchStat_Deaths] = stats.deaths;
    data[MatchStat_Assists] = stats.totalAssists;
    data[MatchStat_Damage] = stats.totalDamage;
    data[MatchStat_DamageTaken] = stats.totalDamageTaken;
    data[MatchStat_Healing] = stats.totalHealing;
    data[MatchStat_Headshots] = stats.totalHeadshots;
    data[MatchStat_Backstabs] = stats.totalBackstabs;
    data[MatchStat_Ubers] = stats.totalUbers;
    data[MatchStat_Playtime] = stats.playtime;
    data[MatchStat_MedicDrops] = stats.totalMedicDrops;
    data[MatchStat_UberDrops] = stats.totalUberDrops;
    data[MatchStat_Airshots] = stats.totalAirshots;
    data[MatchStat_Shots] = stats.totalShots;
    data[MatchStat_Hits] = stats.totalHits;
    data[MatchStat_BestStreak] = stats.bestKillstreak;
    data[MatchStat_BestHeadshotsLife] = stats.bestHeadshotsLife;
    data[MatchStat_BestBackstabsLife] = stats.bestBackstabsLife;
    data[MatchStat_BestScoreLife] = stats.bestScoreLife;
    data[MatchStat_BestKillsLife] = stats.bestKillsLife;
    data[MatchStat_BestAssistsLife] = stats.bestAssistsLife;
    data[MatchStat_BestUbersLife] = stats.bestUbersLife;
    data[MatchStat_IsAdmin] = stats.isAdmin ? 1 : 0;
}

static void MergeSnapshotArrays(int base[MATCH_STAT_COUNT], const int delta[MATCH_STAT_COUNT])
{
    base[MatchStat_Kills] += delta[MatchStat_Kills];
    base[MatchStat_Deaths] += delta[MatchStat_Deaths];
    base[MatchStat_Assists] += delta[MatchStat_Assists];
    base[MatchStat_Damage] += delta[MatchStat_Damage];
    base[MatchStat_DamageTaken] += delta[MatchStat_DamageTaken];
    base[MatchStat_Healing] += delta[MatchStat_Healing];
    base[MatchStat_Headshots] += delta[MatchStat_Headshots];
    base[MatchStat_Backstabs] += delta[MatchStat_Backstabs];
    base[MatchStat_Ubers] += delta[MatchStat_Ubers];
    base[MatchStat_Playtime] += delta[MatchStat_Playtime];
    base[MatchStat_MedicDrops] += delta[MatchStat_MedicDrops];
    base[MatchStat_UberDrops] += delta[MatchStat_UberDrops];
    base[MatchStat_Airshots] += delta[MatchStat_Airshots];
    base[MatchStat_Shots] += delta[MatchStat_Shots];
    base[MatchStat_Hits] += delta[MatchStat_Hits];

    if (delta[MatchStat_BestStreak] > base[MatchStat_BestStreak])
    {
        base[MatchStat_BestStreak] = delta[MatchStat_BestStreak];
    }
    if (delta[MatchStat_BestHeadshotsLife] > base[MatchStat_BestHeadshotsLife])
    {
        base[MatchStat_BestHeadshotsLife] = delta[MatchStat_BestHeadshotsLife];
    }
    if (delta[MatchStat_BestBackstabsLife] > base[MatchStat_BestBackstabsLife])
    {
        base[MatchStat_BestBackstabsLife] = delta[MatchStat_BestBackstabsLife];
    }
    if (delta[MatchStat_BestScoreLife] > base[MatchStat_BestScoreLife])
    {
        base[MatchStat_BestScoreLife] = delta[MatchStat_BestScoreLife];
    }
    if (delta[MatchStat_BestKillsLife] > base[MatchStat_BestKillsLife])
    {
        base[MatchStat_BestKillsLife] = delta[MatchStat_BestKillsLife];
    }
    if (delta[MatchStat_BestAssistsLife] > base[MatchStat_BestAssistsLife])
    {
        base[MatchStat_BestAssistsLife] = delta[MatchStat_BestAssistsLife];
    }
    if (delta[MatchStat_BestUbersLife] > base[MatchStat_BestUbersLife])
    {
        base[MatchStat_BestUbersLife] = delta[MatchStat_BestUbersLife];
    }

    if (delta[MatchStat_IsAdmin] != 0)
    {
        base[MatchStat_IsAdmin] = 1;
    }
}

static void AppendSnapshotToStorage(const char[] steamId, const int data[MATCH_STAT_COUNT])
{
    if (!steamId[0])
        return;

    EnsureMatchStorage();

    int aggregate[MATCH_STAT_COUNT];
    bool hasExisting = g_DisconnectedStats.GetArray(steamId, aggregate, MATCH_STAT_COUNT);

    if (hasExisting)
    {
        MergeSnapshotArrays(aggregate, data);
    }
    else
    {
        for (int i = 0; i < MATCH_STAT_COUNT; i++)
        {
            aggregate[i] = data[i];
        }
    }

    g_DisconnectedStats.SetArray(steamId, aggregate, MATCH_STAT_COUNT);
}

static bool ExtractSnapshotForSteamId(const char[] steamId, int data[MATCH_STAT_COUNT])
{
    if (!steamId[0])
        return false;

    EnsureMatchStorage();
    return g_DisconnectedStats.GetArray(steamId, data, MATCH_STAT_COUNT);
}

static void RemoveSnapshotForSteamId(const char[] steamId)
{
    if (!steamId[0] || g_DisconnectedStats == null)
        return;

    g_DisconnectedStats.Remove(steamId);
}

static void ApplySnapshotToStats(WhaleStats stats, const int data[MATCH_STAT_COUNT])
{
    stats.kills = data[MatchStat_Kills];
    stats.deaths = data[MatchStat_Deaths];
    stats.totalAssists = data[MatchStat_Assists];
    stats.totalDamage = data[MatchStat_Damage];
    stats.totalDamageTaken = data[MatchStat_DamageTaken];
    stats.totalHealing = data[MatchStat_Healing];
    stats.totalHeadshots = data[MatchStat_Headshots];
    stats.totalBackstabs = data[MatchStat_Backstabs];
    stats.totalUbers = data[MatchStat_Ubers];
    stats.playtime = data[MatchStat_Playtime];
    stats.totalMedicDrops = data[MatchStat_MedicDrops];
    stats.totalUberDrops = data[MatchStat_UberDrops];
    stats.totalAirshots = data[MatchStat_Airshots];
    stats.totalShots = data[MatchStat_Shots];
    stats.totalHits = data[MatchStat_Hits];
    stats.bestKillstreak = data[MatchStat_BestStreak];
    stats.bestHeadshotsLife = data[MatchStat_BestHeadshotsLife];
    stats.bestBackstabsLife = data[MatchStat_BestBackstabsLife];
    stats.bestScoreLife = data[MatchStat_BestScoreLife];
    stats.bestKillsLife = data[MatchStat_BestKillsLife];
    stats.bestAssistsLife = data[MatchStat_BestAssistsLife];
    stats.bestUbersLife = data[MatchStat_BestUbersLife];
    stats.isAdmin = (data[MatchStat_IsAdmin] != 0);
    stats.loaded = true;
}

static void BeginMatchTracking()
{
    ResetMatchStorage();

    g_iMatchStartTime = GetTime();
    GetCurrentMap(g_sCurrentMap, sizeof(g_sCurrentMap));
    if (!g_sCurrentMap[0])
    {
        strcopy(g_sCurrentMap, sizeof(g_sCurrentMap), "unknown");
    }

    int randomPart = GetURandomInt() & 0xFFFF;
    Format(g_sCurrentLogId, sizeof(g_sCurrentLogId), "%d_%04X", g_iMatchStartTime, randomPart);

    g_bMatchFinalized = false;
}

static void InsertPlayerLogRecord(const int data[MATCH_STAT_COUNT], const char[] steamId, const char[] name, int timestamp, bool forceSync)
{
    if (!g_sCurrentLogId[0] || steamId[0] == '\0')
        return;

    char escapedName[256];
    EscapeSqlString(name, escapedName, sizeof(escapedName));

    char query[SAVE_QUERY_MAXLEN];
    Format(query, sizeof(query),
        "INSERT INTO whaletracker_log_players "
        ... "(log_id, steamid, personaname, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, total_ubers, playtime, medic_drops, uber_drops, airshots, best_streak, best_headshots_life, best_backstabs_life, best_score_life, best_kills_life, best_assists_life, best_ubers_life, is_admin, last_updated) "
        ... "VALUES ('%s', '%s', '%s', %d, %d, %d, %d, %d, %d, %d, %d, %d,  %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) "
        ... "ON DUPLICATE KEY UPDATE "
        ... "personaname = VALUES(personaname), "
        ... "kills = VALUES(kills), "
        ... "deaths = VALUES(deaths), "
        ... "assists = VALUES(assists), "
        ... "damage = VALUES(damage), "
        ... "damage_taken = VALUES(damage_taken), "
        ... "healing = VALUES(healing), "
        ... "headshots = VALUES(headshots), "
        ... "backstabs = VALUES(backstabs), "
        ... "total_ubers = VALUES(total_ubers), "
        ... "playtime = VALUES(playtime), "
        ... "medic_drops = VALUES(medic_drops), "
        ... "uber_drops = VALUES(uber_drops), "
        ... "airshots = VALUES(airshots), "
        ... "best_streak = VALUES(best_streak), "
        ... "best_headshots_life = VALUES(best_headshots_life), "
        ... "best_backstabs_life = VALUES(best_backstabs_life), "
        ... "best_score_life = VALUES(best_score_life), "
        ... "best_kills_life = VALUES(best_kills_life), "
        ... "best_assists_life = VALUES(best_assists_life), "
        ... "best_ubers_life = VALUES(best_ubers_life), "
        ... "is_admin = VALUES(is_admin), "
        ... "last_updated = VALUES(last_updated)",
        g_sCurrentLogId,
        steamId,
        escapedName,
        data[MatchStat_Kills],
        data[MatchStat_Deaths],
        data[MatchStat_Assists],
        data[MatchStat_Damage],
        data[MatchStat_DamageTaken],
        data[MatchStat_Healing],
        data[MatchStat_Headshots],
        data[MatchStat_Backstabs],
        data[MatchStat_Ubers],
        data[MatchStat_Playtime],
        data[MatchStat_MedicDrops],
        data[MatchStat_UberDrops],
        data[MatchStat_Airshots],
        data[MatchStat_BestStreak],
        data[MatchStat_BestHeadshotsLife],
        data[MatchStat_BestBackstabsLife],
        data[MatchStat_BestScoreLife],
        data[MatchStat_BestKillsLife],
        data[MatchStat_BestAssistsLife],
        data[MatchStat_BestUbersLife],
        data[MatchStat_IsAdmin],
        timestamp);

    QueueSaveQuery(query, 0, forceSync);
}

static void InsertMatchLogRecord(int endTime, int duration, int participantCount, bool forceSync)
{
    if (!g_sCurrentLogId[0])
        return;

    char mapName[128];
    strcopy(mapName, sizeof(mapName), g_sCurrentMap);
    if (!mapName[0])
    {
        strcopy(mapName, sizeof(mapName), "unknown");
    }

    char escapedMap[128];
    EscapeSqlString(mapName, escapedMap, sizeof(escapedMap));

    char gamemode[64] = "Unknown";
    if (g_hGameModeCvar == null)
    {
        g_hGameModeCvar = FindConVar("sm_gamemode");
    }
    if (g_hGameModeCvar != null)
    {
        g_hGameModeCvar.GetString(gamemode, sizeof(gamemode));
        if (!gamemode[0])
        {
            strcopy(gamemode, sizeof(gamemode), "Unknown");
        }
    }

    char escapedMode[64];
    EscapeSqlString(gamemode, escapedMode, sizeof(escapedMode));

    int started = (g_iMatchStartTime > 0) ? g_iMatchStartTime : endTime;

    char query[512];
    Format(query, sizeof(query),
        "INSERT INTO whaletracker_logs "
        ... "(log_id, map, gamemode, started_at, ended_at, duration, player_count, created_at, updated_at) "
        ... "VALUES ('%s', '%s', '%s', %d, %d, %d, %d, %d, %d) "
        ... "ON DUPLICATE KEY UPDATE "
        ... "map = VALUES(map), "
        ... "gamemode = VALUES(gamemode), "
        ... "started_at = VALUES(started_at), "
        ... "ended_at = VALUES(ended_at), "
        ... "duration = VALUES(duration), "
        ... "player_count = VALUES(player_count), "
        ... "updated_at = VALUES(updated_at)",
        g_sCurrentLogId,
        escapedMap,
        escapedMode,
        started,
        endTime,
        duration,
        participantCount,
        started,
        endTime);

    QueueSaveQuery(query, 0, forceSync);
}

static void FinalizeCurrentMatch(bool shuttingDown)
{
    if (g_bMatchFinalized)
        return;

    EnsureMatchStorage();

    for (int i = 1; i <= MaxClients; i++)
    {
        if (!IsClientInGame(i) || IsFakeClient(i))
            continue;

        AccumulatePlaytime(i);
        EnsureClientSteamId(i);

        if (g_MapStats[i].steamId[0] == '\0')
            continue;

        int data[MATCH_STAT_COUNT];
        SnapshotFromStats(g_MapStats[i], data);
        AppendSnapshotToStorage(g_MapStats[i].steamId, data);

        char name[MAX_NAME_LENGTH];
        GetClientName(i, name, sizeof(name));
        RememberMatchPlayerName(g_MapStats[i].steamId, name);
    }

    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        ResetMatchStorage();
        g_bMatchFinalized = true;
        return;
    }

    StringMapSnapshot snap = g_DisconnectedStats != null ? g_DisconnectedStats.Snapshot() : null;
    if (snap == null || snap.Length == 0)
    {
        if (snap != null)
        {
            delete snap;
        }
        ResetMatchStorage();
        g_bMatchFinalized = true;
        return;
    }

    int endTime = GetTime();
    int duration = (g_iMatchStartTime > 0) ? (endTime - g_iMatchStartTime) : 0;
    if (duration < 0)
    {
        duration = 0;
    }

    for (int i = 0; i < snap.Length; i++)
    {
        char steamId[STEAMID64_LEN];
        snap.GetKey(i, steamId, sizeof(steamId));

        int data[MATCH_STAT_COUNT];
        if (!ExtractSnapshotForSteamId(steamId, data))
            continue;

        char name[MAX_NAME_LENGTH];
        if (!GetStoredMatchPlayerName(steamId, name, sizeof(name)))
        {
            strcopy(name, sizeof(name), steamId);
        }

        InsertPlayerLogRecord(data, steamId, name, endTime, shuttingDown);
    }

    int participantCount = snap.Length;
    delete snap;

    InsertMatchLogRecord(endTime, duration, participantCount, shuttingDown);

    ResetMatchStorage();
    g_bMatchFinalized = true;
    g_sCurrentLogId[0] = '\0';
}

static void EnsureClientSteamId(int client)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return;

    if (g_Stats[client].steamId[0] != '\0')
        return;

    char steamId[STEAMID64_LEN];
    if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
        return;

    strcopy(g_Stats[client].steamId, sizeof(g_Stats[client].steamId), steamId);
    strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), steamId);
}

static int GetClientTfClass(int client)
{
    TFClassType tfClassType = TF2_GetPlayerClass(client);
    int classId = view_as<int>(tfClassType);
    if (classId < CLASS_MIN || classId > CLASS_MAX)
    {
        return 0;
    }
    return classId;
}

static bool ResolveAccuracyWeaponName(int weapon, char[] buffer, int maxlen)
{
    buffer[0] = '\0';

    if (weapon <= MaxClients || !IsValidEntity(weapon))
    {
        return false;
    }

    if (!HasEntProp(weapon, Prop_Send, "m_iItemDefinitionIndex"))
    {
        return false;
    }

    int defIndex = GetEntProp(weapon, Prop_Send, "m_iItemDefinitionIndex");
    if (defIndex <= 0)
    {
        return false;
    }

    GetWeaponNameFromDefIndex(defIndex, buffer, maxlen);
    if (!buffer[0] || StrEqual(buffer, "Unknown", false))
    {
        return false;
    }

    return true;
}

static bool TrackAccuracyEvent(int client, int weapon, bool isHit)
{
    if (!IsValidClient(client) || IsFakeClient(client))
    {
        return false;
    }

    if (g_bDebugMinimalStats)
    {
        return false;
    }

    char weaponName[64];
    if (!ResolveAccuracyWeaponName(weapon, weaponName, sizeof(weaponName)))
    {
        return false;
    }

    int classId = GetClientTfClass(client);
    if (classId <= 0)
    {
        return false;
    }

    if (isHit)
    {
        g_Stats[client].totalHits++;
        g_MapStats[client].totalHits++;
        g_Stats[client].classHits[classId]++;
        g_MapStats[client].classHits[classId]++;
    }
    else
    {
        g_Stats[client].totalShots++;
        g_MapStats[client].totalShots++;
        g_Stats[client].classShots[classId]++;
        g_MapStats[client].classShots[classId]++;
    }

    MarkClassPlayed(g_Stats[client], classId);
    MarkClassPlayed(g_MapStats[client], classId);
    g_Stats[client].damageClassesMask |= 1 << (classId - 1);
    g_MapStats[client].damageClassesMask |= 1 << (classId - 1);

    return true;
}

static void IncrementHeadshotStats(WhaleStats stats, bool debugMinimal)
{
    stats.totalHeadshots++;
    if (debugMinimal)
    {
        return;
    }

    stats.currentHeadshotsLife++;
    if (stats.currentHeadshotsLife > stats.bestHeadshotsLife)
    {
        stats.bestHeadshotsLife = stats.currentHeadshotsLife;
    }
}

static void RecordHeadshotEvent(int client)
{
    if (!IsValidClient(client) || IsFakeClient(client))
    {
        return;
    }

    bool debugMinimal = g_bDebugMinimalStats;
    IncrementHeadshotStats(g_Stats[client], debugMinimal);
    IncrementHeadshotStats(g_MapStats[client], debugMinimal);
}

static void MarkClassPlayed(WhaleStats stats, int classId)
{
    if (classId <= 0 || classId > CLASS_MAX)
    {
        return;
    }
    stats.damageClassesMask |= 1 << (classId - 1);
}

static void BuildClassValueSegment(const WhaleStats stats, char[] buffer, int maxlen)
{
    Format(buffer, maxlen,
        "%d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d",
        stats.damageClassesMask,
        stats.classShots[CLASS_SCOUT],
        stats.classHits[CLASS_SCOUT],
        stats.classShots[CLASS_SNIPER],
        stats.classHits[CLASS_SNIPER],
        stats.classShots[CLASS_SOLDIER],
        stats.classHits[CLASS_SOLDIER],
        stats.classShots[CLASS_DEMOMAN],
        stats.classHits[CLASS_DEMOMAN],
        stats.classShots[CLASS_MEDIC],
        stats.classHits[CLASS_MEDIC],
        stats.classShots[CLASS_HEAVY],
        stats.classHits[CLASS_HEAVY],
        stats.classShots[CLASS_PYRO],
        stats.classHits[CLASS_PYRO],
        stats.classShots[CLASS_SPY],
        stats.classHits[CLASS_SPY],
        stats.classShots[CLASS_ENGINEER],
        stats.classHits[CLASS_ENGINEER]);
}

static void ResetLifeCounters(WhaleStats stats)
{
    stats.currentKillsLife = 0;
    stats.currentKillstreak = 0;
    stats.currentHeadshotsLife = 0;
    stats.currentBackstabsLife = 0;
    stats.currentAssistsLife = 0;
    stats.currentScoreLife = 0;
    stats.currentUbersLife = 0;
}

static void ApplyKillStats(WhaleStats stats, bool backstab, bool medicDrop)
{
    stats.kills++;
    if (g_bDebugMinimalStats)
    {
        if (backstab)
        {
            stats.totalBackstabs++;
        }
        return;
    }

    stats.currentKillsLife++;
    stats.currentKillstreak++;
    stats.currentScoreLife++;

    if (stats.currentKillsLife > stats.bestKillsLife)
    {
        stats.bestKillsLife = stats.currentKillsLife;
    }
    if (stats.currentKillstreak > stats.bestKillstreak)
    {
        stats.bestKillstreak = stats.currentKillstreak;
    }
    if (backstab)
    {
        stats.totalBackstabs++;
        stats.currentBackstabsLife++;
        if (stats.currentBackstabsLife > stats.bestBackstabsLife)
        {
            stats.bestBackstabsLife = stats.currentBackstabsLife;
        }
    }
    if (medicDrop)
    {
        stats.totalMedicDrops++;
        stats.totalUberDrops++;
    }
}

static void ApplyAssistStats(WhaleStats stats)
{
    stats.totalAssists++;
    if (g_bDebugMinimalStats)
    {
        return;
    }

    stats.currentAssistsLife++;
    stats.currentScoreLife++;
    if (stats.currentAssistsLife > stats.bestAssistsLife)
    {
        stats.bestAssistsLife = stats.currentAssistsLife;
    }
}

static void ApplyDeathStats(WhaleStats stats)
{
    stats.deaths++;
    ResetLifeCounters(stats);
}

static void ApplyHealingStats(WhaleStats stats, int amount)
{
    stats.totalHealing += amount;
}

static void ApplyUberStats(WhaleStats stats)
{
    stats.totalUbers++;
    if (g_bDebugMinimalStats)
    {
        return;
    }

    stats.currentUbersLife++;
    if (stats.currentUbersLife > stats.bestUbersLife)
    {
        stats.bestUbersLife = stats.currentUbersLife;
    }
}

static void AccumulatePlaytimeStruct(WhaleStats stats)
{
    if (stats.connectTime <= 0.0)
        return;

    float session = GetEngineTime() - stats.connectTime;
    if (session > 0.0)
    {
        stats.playtime += RoundToFloor(session);
        stats.connectTime = GetEngineTime();
    }
}

static void AccumulatePlaytime(int client)
{
    if (client <= 0 || client > MaxClients)
        return;

    AccumulatePlaytimeStruct(g_Stats[client]);
    AccumulatePlaytimeStruct(g_MapStats[client]);
}

static void RunSaveQuerySync(const char[] query, int userId)
{
    if (g_hDatabase == null)
    {
        return;
    }

    if (!SQL_FastQuery(g_hDatabase, query))
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));

        if (userId > 0)
        {
            LogError("[WhaleTracker] Failed to save stats synchronously (userid %d): %s", userId, error);
        }
        else
        {
            LogError("[WhaleTracker] Failed to save stats synchronously: %s", error);
        }
    }
}

static void PumpSaveQueue()
{
    if (g_SaveQueue == null || g_hDatabase == null || !g_bDatabaseReady || g_bShuttingDown)
    {
        return;
    }

    while (g_PendingSaveQueries < MAX_CONCURRENT_SAVE_QUERIES && g_SaveQueue.Length > 0)
    {
        DataPack pack = view_as<DataPack>(g_SaveQueue.Get(0));
        g_SaveQueue.Erase(0);

        pack.Reset();
        int userId = pack.ReadCell();

        char query[SAVE_QUERY_MAXLEN];
        pack.ReadString(query, sizeof(query));

        delete pack;

        int slot = -1;
        for (int i = 0; i < MAX_CONCURRENT_SAVE_QUERIES; i++)
        {
            if (!g_SaveQuerySlotUsed[i])
            {
                slot = i;
                break;
            }
        }

        if (slot == -1)
        {
            RunSaveQuerySync(query, userId);
            continue;
        }

        strcopy(g_SaveQueryBuffers[slot], SAVE_QUERY_MAXLEN, query);
        g_SaveQueryUserIds[slot] = userId;
        g_SaveQuerySlotUsed[slot] = true;

        g_PendingSaveQueries++;
        g_hDatabase.Query(WhaleTracker_SaveCallback, g_SaveQueryBuffers[slot], slot);
    }
}

static void FlushSaveQueueSync()
{
    if (g_SaveQueue == null)
    {
        return;
    }

    while (g_SaveQueue.Length > 0)
    {
        DataPack pack = view_as<DataPack>(g_SaveQueue.Get(0));
        g_SaveQueue.Erase(0);

        pack.Reset();
        int userId = pack.ReadCell();

        char query[SAVE_QUERY_MAXLEN];
        pack.ReadString(query, sizeof(query));

        delete pack;

        RunSaveQuerySync(query, userId);
    }
}

static void QueueSaveQuery(const char[] query, int userId, bool forceSync)
{
    if (forceSync || g_bShuttingDown)
    {
        RunSaveQuerySync(query, userId);
        return;
    }

    if (g_SaveQueue == null)
    {
        g_SaveQueue = new ArrayList();
    }

    DataPack pack = new DataPack();
    pack.WriteCell(userId);
    pack.WriteString(query);
    g_SaveQueue.Push(pack);

    PumpSaveQueue();
}

public Plugin myinfo =
{
    name = "WhaleTracker",
    author = "Hombre",
    description = "Cumulative player stats system",
    version = "1.0.1",
    url = "https://kogasa.tf"
};

public void OnPluginStart()
{
    if (g_SaveQueue != null)
    {
        delete g_SaveQueue;
    }
    g_SaveQueue = new ArrayList();
    g_PendingSaveQueries = 0;
    g_bShuttingDown = false;

    g_CvarDatabase = CreateConVar("sm_whaletracker_database", DB_CONFIG_DEFAULT, "Databases.cfg entry to use for WhaleTracker");
    g_CvarDatabase.GetString(g_sDatabaseConfig, sizeof(g_sDatabaseConfig));

    if (g_hDebugMinimalStats == null)
    {
        g_hDebugMinimalStats = CreateConVar(
            "sm_whaletracker_debug_minimal",
            "0",
            "Limit WhaleTracker stat tracking to core metrics for debugging crashes (0 = off, 1 = on)",
            FCVAR_NONE,
            true,
            0.0,
            true,
            1.0
        );
        g_hDebugMinimalStats.AddChangeHook(ConVarChanged_DebugMinimal);
    }
    RefreshDebugMinimalFlag();

    if (g_hVisibleMaxPlayers == null)
    {
        g_hVisibleMaxPlayers = FindConVar("sv_visiblemaxplayers");
    }

    HookEvent("player_death", Event_PlayerDeath, EventHookMode_Post);
    HookEvent("player_spawn", Event_PlayerSpawn, EventHookMode_Post);
    HookEvent("player_healed", Event_PlayerHealed, EventHookMode_Post);
    HookEvent("player_chargedeployed", Event_UberDeployed, EventHookMode_Post);

    RegConsoleCmd("sm_whalestats", Command_ShowStats, "Show your Whale Tracker statistics.");
    RegConsoleCmd("sm_stats", Command_ShowStats, "Show your Whale Tracker statistics.");
    RegAdminCmd("sm_savestats", Command_SaveAllStats, ADMFLAG_GENERIC, "Manually save all WhaleTracker stats");

    EnsureMatchStorage();

    if (g_hGameModeCvar == null)
    {
        g_hGameModeCvar = FindConVar("sm_gamemode");
    }

    BeginMatchTracking();

    WhaleTracker_SQLConnect();

    if (g_hOnlineTimer != null)
    {
        CloseHandle(g_hOnlineTimer);
    }
    g_hOnlineTimer = CreateTimer(10.0, Timer_UpdateOnlineStats, _, TIMER_REPEAT);
    ClearOnlineStats();

    for (int i = 1; i <= MaxClients; i++)
    {
        ResetAllStats(i);
        ResetMapStats(i);
        g_KillSaveCounter[i] = 0;
        if (IsClientInGame(i))
        {
            OnClientPutInServer(i);
            if (AreClientCookiesCached(i))
            {
                LoadClientStats(i);
            }
        }
    }
}

public void OnMapStart()
{
    FinalizeCurrentMatch(false);
    BeginMatchTracking();
    ClearOnlineStats();
    for (int i = 1; i <= MaxClients; i++)
    {
        ResetMapStats(i);
        if (IsClientInGame(i))
        {
            g_MapStats[i].connectTime = GetEngineTime();
        }
        g_KillSaveCounter[i] = 0;
    }
}

public void OnMapEnd()
{
    FinalizeCurrentMatch(false);
}

public void OnPluginEnd()
{
    g_bShuttingDown = true;

    FinalizeCurrentMatch(true);

    FlushSaveQueueSync();

    if (g_hOnlineTimer != null)
    {
        CloseHandle(g_hOnlineTimer);
        g_hOnlineTimer = null;
    }

    ClearOnlineStats();

    if (g_SaveQueue != null)
    {
        delete g_SaveQueue;
        g_SaveQueue = null;
    }

    g_hDatabase = null;
    g_bDatabaseReady = false;
    g_hVisibleMaxPlayers = null;
    g_hDebugMinimalStats = null;

    for (int i = 1; i <= MaxClients; i++)
    {
        if (IsClientInGame(i) && !IsFakeClient(i))
        {
            SDKUnhook(i, SDKHook_OnTakeDamage, OnTakeDamage);
        }

    }
}

public void OnClientPutInServer(int client)
{
    if (IsFakeClient(client))
        return;

    ResetRuntimeStats(client);
    g_Stats[client].connectTime = GetEngineTime();
    g_MapStats[client].connectTime = GetEngineTime();
    g_KillSaveCounter[client] = 0;

    ResetMapStats(client);
    EnsureMatchStorage();
    EnsureClientSteamId(client);

    char steamId[STEAMID64_LEN];
    strcopy(steamId, sizeof(steamId), g_MapStats[client].steamId);

    if (steamId[0])
    {
        int snapshot[MATCH_STAT_COUNT];
        if (ExtractSnapshotForSteamId(steamId, snapshot))
        {
            ApplySnapshotToStats(g_MapStats[client], snapshot);
            RemoveSnapshotForSteamId(steamId);
        }

        char name[MAX_NAME_LENGTH];
        GetClientName(client, name, sizeof(name));
        RememberMatchPlayerName(steamId, name);
    }

    if (IsValidClient(client) && IsClientInGame(client))
    {
        SDKHook(client, SDKHook_OnTakeDamage, OnTakeDamage);
    }
    UpdateAdminStatus(client);
    TouchClientLastSeen(client);

    if (AreClientCookiesCached(client))
    {
        LoadClientStats(client);
    }
}

public void OnClientCookiesCached(int client)
{
    if (IsFakeClient(client))
        return;

    UpdateAdminStatus(client);
    LoadClientStats(client);
    TouchClientLastSeen(client);
}

public void OnClientDisconnect(int client)
{
    if (IsFakeClient(client))
        return;

        if (IsClientInGame(client))
        {
            SDKUnhook(client, SDKHook_OnTakeDamage, OnTakeDamage);
        }

    AccumulatePlaytime(client);
    SaveClientStats(client, true);
    RemoveOnlineStats(client);
    ResetAllStats(client);
    g_KillSaveCounter[client] = 0;
}

public void OnClientAuthorized(int client, const char[] auth)
{
    if (!auth[0])
        return;

    strcopy(g_Stats[client].steamId, sizeof(g_Stats[client].steamId), auth);
    strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), auth);
}

public void OnClientPostAdminCheck(int client)
{
    if (!IsValidClient(client))
        return;
    
    // Check immediately (might work for some admin systems)
    UpdateAdminStatus(client);
    
    // Delayed check to catch late-loading admins
    CreateTimer(1.0, Timer_CheckAdminStatus, GetClientUserId(client), TIMER_FLAG_NO_MAPCHANGE);
}

public void OnRebuildAdminCache(AdminCachePart part)
{
    // Handle admin cache rebuilds
    if (part == AdminCache_Admins)
    {
        for (int i = 1; i <= MaxClients; i++)
        {
            if (IsClientInGame(i))
                UpdateAdminStatus(i);
        }
    }
}

public Action Timer_CheckAdminStatus(Handle timer, int userid)
{
    int client = GetClientOfUserId(userid);
    if (client && IsClientInGame(client) && !IsFakeClient(client))
    {
        if (UpdateAdminStatus(client))
        {
            SaveAdminStatus(client);  // Save if changed
        }
    }
    return Plugin_Stop;
}

public void SaveAdminStatus(int client)
{
    if (client > 0 && IsClientInGame(client) && !IsFakeClient(client))
    {
        SaveClientStats(client, false);
    }
}

public void WhaleTracker_SQLConnect()
{
    if (g_hDatabase != null)
    {
        delete g_hDatabase;
        g_hDatabase = null;
        g_bDatabaseReady = false;
    }

    g_CvarDatabase.GetString(g_sDatabaseConfig, sizeof(g_sDatabaseConfig));
    g_bShuttingDown = false;
    Database.Connect(T_SQLConnect, g_sDatabaseConfig);
}

public void T_SQLConnect(Database db, const char[] error, any data)
{
    if (db == null)
    {
        LogError("[WhaleTracker] Database connection failed: %s", error);
        g_bDatabaseReady = false;
        return;
    }

    g_hDatabase = db;
    g_bDatabaseReady = true;

    if (!g_hDatabase.SetCharset("utf8mb4"))
    {
        LogError("[WhaleTracker] Failed to set database charset to utf8mb4, names may be truncated.");
    }

    char query[4096];
    Format(query, sizeof(query),
        "CREATE TABLE IF NOT EXISTS `whaletracker` ("
        ... "`steamid` VARCHAR(32) PRIMARY KEY,"
        ... "`first_seen` INTEGER,"
        ... "`kills` INTEGER DEFAULT 0,"
        ... "`deaths` INTEGER DEFAULT 0,"
        ... "`shots` INTEGER DEFAULT 0,"
        ... "`hits` INTEGER DEFAULT 0,"
        ... "`healing` INTEGER DEFAULT 0,"
        ... "`total_ubers` INTEGER DEFAULT 0,"
        ... "`best_ubers_life` INTEGER DEFAULT 0,"
        ... "`medic_drops` INTEGER DEFAULT 0,"
        ... "`uber_drops` INTEGER DEFAULT 0,"
        ... "`airshots` INTEGER DEFAULT 0,"
        ... "`headshots` INTEGER DEFAULT 0,"
        ... "`backstabs` INTEGER DEFAULT 0,"
        ... "`best_headshots_life` INTEGER DEFAULT 0,"
        ... "`best_backstabs_life` INTEGER DEFAULT 0,"
        ... "`best_kills_life` INTEGER DEFAULT 0,"
        ... "`best_killstreak` INTEGER DEFAULT 0,"
        ... "`best_score_life` INTEGER DEFAULT 0,"
        ... "`assists` INTEGER DEFAULT 0,"
        ... "`best_assists_life` INTEGER DEFAULT 0,"
        ... "`playtime` INTEGER DEFAULT 0,"
        ... "`damage_dealt` INTEGER DEFAULT 0,"
        ... "`damage_taken` INTEGER DEFAULT 0,"
        ... "`last_seen` INTEGER DEFAULT 0,"
        ... "`classes_mask` INTEGER DEFAULT 0,"
        ... "`shots_scout` INTEGER DEFAULT 0,"
        ... "`hits_scout` INTEGER DEFAULT 0,"
        ... "`shots_soldier` INTEGER DEFAULT 0,"
        ... "`hits_soldier` INTEGER DEFAULT 0,"
        ... "`shots_pyro` INTEGER DEFAULT 0,"
        ... "`hits_pyro` INTEGER DEFAULT 0,"
        ... "`shots_demoman` INTEGER DEFAULT 0,"
        ... "`hits_demoman` INTEGER DEFAULT 0,"
        ... "`shots_heavy` INTEGER DEFAULT 0,"
        ... "`hits_heavy` INTEGER DEFAULT 0,"
        ... "`shots_engineer` INTEGER DEFAULT 0,"
        ... "`hits_engineer` INTEGER DEFAULT 0,"
        ... "`shots_medic` INTEGER DEFAULT 0,"
        ... "`hits_medic` INTEGER DEFAULT 0,"
        ... "`shots_sniper` INTEGER DEFAULT 0,"
        ... "`hits_sniper` INTEGER DEFAULT 0,"
        ... "`shots_spy` INTEGER DEFAULT 0,"
        ... "`hits_spy` INTEGER DEFAULT 0,"
        ... "`is_admin` TINYINT(1) DEFAULT 0"
        ... ")");
    g_hDatabase.Query(WhaleTracker_CreateTable, query);

    Format(query, sizeof(query),
        "CREATE TABLE IF NOT EXISTS `whaletracker_online` ("
        ... "`steamid` VARCHAR(32) PRIMARY KEY,"
        ... "`personaname` VARCHAR(128) DEFAULT '',"
        ... "`class` TINYINT DEFAULT 0,"
        ... "`team` TINYINT DEFAULT 0,"
        ... "`alive` TINYINT DEFAULT 0,"
        ... "`is_spectator` TINYINT DEFAULT 0,"
        ... "`kills` INTEGER DEFAULT 0,"
        ... "`deaths` INTEGER DEFAULT 0,"
        ... "`assists` INTEGER DEFAULT 0,"
        ... "`damage` INTEGER DEFAULT 0,"
        ... "`damage_taken` INTEGER DEFAULT 0,"
        ... "`healing` INTEGER DEFAULT 0,"
        ... "`headshots` INTEGER DEFAULT 0,"
        ... "`backstabs` INTEGER DEFAULT 0,"
        ... "`playtime` INTEGER DEFAULT 0,"
        ... "`total_ubers` INTEGER DEFAULT 0,"
        ... "`best_streak` INTEGER DEFAULT 0,"
        ... "`visible_max` INTEGER DEFAULT 0,"
        ... "`time_connected` INTEGER DEFAULT 0,"
        ... "`classes_mask` INTEGER DEFAULT 0,"
        ... "`shots_scout` INTEGER DEFAULT 0,"
        ... "`hits_scout` INTEGER DEFAULT 0,"
        ... "`shots_sniper` INTEGER DEFAULT 0,"
        ... "`hits_sniper` INTEGER DEFAULT 0,"
        ... "`shots_soldier` INTEGER DEFAULT 0,"
        ... "`hits_soldier` INTEGER DEFAULT 0,"
        ... "`shots_demoman` INTEGER DEFAULT 0,"
        ... "`hits_demoman` INTEGER DEFAULT 0,"
        ... "`shots_medic` INTEGER DEFAULT 0,"
        ... "`hits_medic` INTEGER DEFAULT 0,"
        ... "`shots_heavy` INTEGER DEFAULT 0,"
        ... "`hits_heavy` INTEGER DEFAULT 0,"
        ... "`shots_pyro` INTEGER DEFAULT 0,"
        ... "`hits_pyro` INTEGER DEFAULT 0,"
        ... "`shots_spy` INTEGER DEFAULT 0,"
        ... "`hits_spy` INTEGER DEFAULT 0,"
        ... "`shots_engineer` INTEGER DEFAULT 0,"
        ... "`hits_engineer` INTEGER DEFAULT 0,"
        ... "`last_update` INTEGER DEFAULT 0"
        ... ")");
    g_hDatabase.Query(WhaleTracker_CreateOnlineTable, query);

    Format(query, sizeof(query),
        "CREATE TABLE IF NOT EXISTS `whaletracker_logs` ("
        ... "`log_id` VARCHAR(64) PRIMARY KEY,"
        ... "`map` VARCHAR(64) DEFAULT '',"
        ... "`gamemode` VARCHAR(64) DEFAULT 'Unknown',"
        ... "`started_at` INTEGER DEFAULT 0,"
        ... "`ended_at` INTEGER DEFAULT 0,"
        ... "`duration` INTEGER DEFAULT 0,"
        ... "`player_count` INTEGER DEFAULT 0,"
        ... "`created_at` INTEGER DEFAULT 0,"
        ... "`updated_at` INTEGER DEFAULT 0"
        ... ")");
    g_hDatabase.Query(WhaleTracker_CreateLogsTable, query);

    Format(query, sizeof(query),
        "CREATE TABLE IF NOT EXISTS `whaletracker_log_players` ("
        ... "`log_id` VARCHAR(64) NOT NULL,"
        ... "`steamid` VARCHAR(32) NOT NULL,"
        ... "`personaname` VARCHAR(128) DEFAULT '',"
        ... "`kills` INTEGER DEFAULT 0,"
        ... "`deaths` INTEGER DEFAULT 0,"
        ... "`assists` INTEGER DEFAULT 0,"
        ... "`damage` INTEGER DEFAULT 0,"
        ... "`damage_taken` INTEGER DEFAULT 0,"
        ... "`healing` INTEGER DEFAULT 0,"
        ... "`headshots` INTEGER DEFAULT 0,"
        ... "`backstabs` INTEGER DEFAULT 0,"
        ... "`total_ubers` INTEGER DEFAULT 0,"
        ... "`playtime` INTEGER DEFAULT 0,"
        ... "`medic_drops` INTEGER DEFAULT 0,"
        ... "`uber_drops` INTEGER DEFAULT 0,"
        ... "`airshots` INTEGER DEFAULT 0,"
        ... "`best_streak` INTEGER DEFAULT 0,"
        ... "`best_headshots_life` INTEGER DEFAULT 0,"
        ... "`best_backstabs_life` INTEGER DEFAULT 0,"
        ... "`best_score_life` INTEGER DEFAULT 0,"
        ... "`best_kills_life` INTEGER DEFAULT 0,"
        ... "`best_assists_life` INTEGER DEFAULT 0,"
        ... "`best_ubers_life` INTEGER DEFAULT 0,"
        ... "`is_admin` TINYINT DEFAULT 0,"
        ... "`last_updated` INTEGER DEFAULT 0,"
        ... "PRIMARY KEY (`log_id`, `steamid`)"
        ... ")");
    g_hDatabase.Query(WhaleTracker_CreateLogPlayersTable, query);

    SQL_FastQuery(g_hDatabase, "DROP TABLE IF EXISTS `whaletracker_mapstats`");
}

public void WhaleTracker_CreateTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create table: %s", error);
    }

    PumpSaveQueue();

    static const char alterQueries[][128] =
    {
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS damage_dealt INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS damage_taken INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS uber_drops INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS last_seen INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS classes_mask INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_scout INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_scout INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_heavy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_heavy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_pyro INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_pyro INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_spy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_spy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots_engineer INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits_engineer INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) DEFAULT 0"
    };

    for (int i = 0; i < sizeof(alterQueries); i++)
    {
        g_hDatabase.Query(WhaleTracker_AlterCallback, alterQueries[i]);
    }

    static const char alterOnlineQueries[][128] =
    {
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS personaname VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS class TINYINT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS team TINYINT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS alive TINYINT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS is_spectator TINYINT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS kills INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS deaths INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS assists INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS damage_taken INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS healing INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS headshots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS backstabs INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS playtime INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS total_ubers INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS best_streak INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS visible_max INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS time_connected INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS classes_mask INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_scout INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_scout INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_heavy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_heavy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_pyro INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_pyro INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_spy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_spy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots_engineer INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits_engineer INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS last_update INTEGER DEFAULT 0"
    };

    for (int i = 0; i < sizeof(alterOnlineQueries); i++)
    {
        g_hDatabase.Query(WhaleTracker_AlterCallback, alterOnlineQueries[i]);
    }

    static const char alterLogsQueries[][160] =
    {
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS map VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS gamemode VARCHAR(64) DEFAULT 'Unknown'",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS started_at INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS ended_at INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS duration INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS player_count INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS created_at INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_logs ADD COLUMN IF NOT EXISTS updated_at INTEGER DEFAULT 0"
    };

    for (int i = 0; i < sizeof(alterLogsQueries); i++)
    {
        g_hDatabase.Query(WhaleTracker_AlterCallback, alterLogsQueries[i]);
    }

    static const char alterLogPlayersQueries[][192] =
    {
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS personaname VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS kills INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS deaths INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS assists INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS damage_taken INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS healing INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS headshots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS backstabs INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS total_ubers INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS playtime INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS medic_drops INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS uber_drops INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS classes_mask INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_scout INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_scout INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_heavy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_heavy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_pyro INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_pyro INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_spy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_spy INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS shots_engineer INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS hits_engineer INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_streak INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_headshots_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_backstabs_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_score_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_kills_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_assists_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_ubers_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS is_admin TINYINT DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS last_updated INTEGER DEFAULT 0"
    };

    for (int i = 0; i < sizeof(alterLogPlayersQueries); i++)
    {
        g_hDatabase.Query(WhaleTracker_AlterCallback, alterLogPlayersQueries[i]);
    }

    for (int i = 1; i <= MaxClients; i++)
    {
        if (!IsClientInGame(i) || IsFakeClient(i))
            continue;

        if (AreClientCookiesCached(i))
        {
            LoadClientStats(i);
        }
    }
}

public void WhaleTracker_CreateOnlineTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create online table: %s", error);
    }
}

public void WhaleTracker_CreateLogsTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create logs table: %s", error);
    }
}

public void WhaleTracker_CreateLogPlayersTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create log players table: %s", error);
    }
}

public void WhaleTracker_AlterCallback(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to alter table: %s", error);
    }
}

static void LoadClientStats(int client)
{
    if (!IsClientInGame(client) || !g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }

    char steamId[STEAMID64_LEN];
    if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
    {
        return;
    }

    strcopy(g_Stats[client].steamId, sizeof(g_Stats[client].steamId), steamId);
    strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), steamId);

    char query[512];
    Format(query, sizeof(query),
        "SELECT first_seen, kills, deaths, shots, hits, healing, total_ubers, best_ubers_life, medic_drops, uber_drops, airshots, headshots, backstabs, best_headshots_life, best_backstabs_life, best_kills_life, best_killstreak, best_score_life, assists, best_assists_life, playtime, damage_dealt, damage_taken, last_seen, is_admin "
        ... "FROM whaletracker WHERE steamid = '%s'", steamId);

    g_hDatabase.Query(WhaleTracker_LoadCallback, query, client);
}

public void WhaleTracker_LoadCallback(Database db, DBResultSet results, const char[] error, any client)
{
    int index = client;
    if (!IsValidClient(index))
    {
        return;
    }

    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to load stats for %N: %s", index, error);
        return;
    }

    if (results != null && results.FetchRow())
    {
        g_Stats[index].firstSeenTimestamp = results.FetchInt(0);
        FormatTime(g_Stats[index].firstSeen, sizeof(g_Stats[index].firstSeen), "%Y-%m-%d", g_Stats[index].firstSeenTimestamp);
        g_Stats[index].kills = results.FetchInt(1);
        g_Stats[index].deaths = results.FetchInt(2);
        g_Stats[index].totalShots = results.FetchInt(3);
        g_Stats[index].totalHits = results.FetchInt(4);
        g_Stats[index].totalHealing = results.FetchInt(5);
        g_Stats[index].totalUbers = results.FetchInt(6);
        g_Stats[index].bestUbersLife = results.FetchInt(7);
        g_Stats[index].totalMedicDrops = results.FetchInt(8);
        g_Stats[index].totalUberDrops = results.FetchInt(9);
        g_Stats[index].totalAirshots = results.FetchInt(10);
        g_Stats[index].totalHeadshots = results.FetchInt(11);
        g_Stats[index].totalBackstabs = results.FetchInt(12);
        g_Stats[index].bestHeadshotsLife = results.FetchInt(13);
        g_Stats[index].bestBackstabsLife = results.FetchInt(14);
        g_Stats[index].bestKillsLife = results.FetchInt(15);
        g_Stats[index].bestKillstreak = results.FetchInt(16);
        g_Stats[index].bestScoreLife = results.FetchInt(17);
        g_Stats[index].totalAssists = results.FetchInt(18);
        g_Stats[index].bestAssistsLife = results.FetchInt(19);
        g_Stats[index].playtime = results.FetchInt(20);
        g_Stats[index].totalDamage = results.FetchInt(21);
        g_Stats[index].totalDamageTaken = results.FetchInt(22);
        g_Stats[index].lastSeen = results.FetchInt(23);
        g_Stats[index].isAdmin = results.FetchInt(24) != 0;
        g_Stats[index].loaded = true;
        g_MapStats[index].loaded = true;
        g_MapStats[index].isAdmin = g_Stats[index].isAdmin;
        g_MapStats[index].totalUberDrops = g_Stats[index].totalUberDrops;
    }
    else
    {
        g_Stats[index].firstSeenTimestamp = GetTime();
        FormatTime(g_Stats[index].firstSeen, sizeof(g_Stats[index].firstSeen), "%Y-%m-%d", g_Stats[index].firstSeenTimestamp);
        g_Stats[index].loaded = true;
        g_MapStats[index].loaded = true;
        g_MapStats[index].isAdmin = g_Stats[index].isAdmin;
    }

    TouchClientLastSeen(index);
}

static bool HasMapActivity(WhaleStats stats)
{
    return stats.playtime > 0
        || stats.kills > 0
        || stats.deaths > 0
        || stats.totalAssists > 0
        || stats.totalHealing > 0
        || stats.totalDamage > 0
        || stats.totalDamageTaken > 0
        || stats.totalHeadshots > 0
        || stats.totalBackstabs > 0
        || stats.totalUberDrops > 0
        || stats.totalMedicDrops > 0
        || stats.totalShots > 0
        || stats.totalHits > 0;
}

static bool SaveClientMapStats(int client)
{
    if (!IsValidClient(client))
        return false;

    if (!g_MapStats[client].loaded)
        return false;

    if (g_MapStats[client].steamId[0] == '\0')
        return false;

    if (!HasMapActivity(g_MapStats[client]))
        return false;

    int snapshot[MATCH_STAT_COUNT];
    SnapshotFromStats(g_MapStats[client], snapshot);
    AppendSnapshotToStorage(g_MapStats[client].steamId, snapshot);

    EnsureMatchStorage();

    char name[MAX_NAME_LENGTH];
    if (IsClientInGame(client))
    {
        GetClientName(client, name, sizeof(name));
    }
    else if (!GetStoredMatchPlayerName(g_MapStats[client].steamId, name, sizeof(name)))
    {
        name[0] = '\0';
    }

    if (!name[0])
    {
        strcopy(name, sizeof(name), g_MapStats[client].steamId);
    }

    RememberMatchPlayerName(g_MapStats[client].steamId, name);

    return true;
}

static void QueuePrimaryStatsSave(int client, int userId)
{
    char query[SAVE_QUERY_MAXLEN];
    char classValueSegment[512];
    BuildClassValueSegment(g_Stats[client], classValueSegment, sizeof(classValueSegment));

    Format(query, sizeof(query),
        "INSERT INTO whaletracker "
        ... "(steamid, first_seen, kills, deaths, shots, hits, healing, total_ubers, best_ubers_life, medic_drops, uber_drops, airshots, headshots, backstabs, classes_mask, shots_scout, hits_scout, shots_sniper, hits_sniper, shots_soldier, hits_soldier, shots_demoman, hits_demoman, shots_medic, hits_medic, shots_heavy, hits_heavy, shots_pyro, hits_pyro, shots_spy, hits_spy, shots_engineer, hits_engineer) "
        ... "VALUES ('%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %s) "
        ... "ON DUPLICATE KEY UPDATE "
        ... "first_seen = VALUES(first_seen), "
        ... "kills = VALUES(kills), "
        ... "deaths = VALUES(deaths), "
        ... "shots = VALUES(shots), "
        ... "hits = VALUES(hits), "
        ... "healing = VALUES(healing), "
        ... "total_ubers = VALUES(total_ubers), "
        ... "best_ubers_life = VALUES(best_ubers_life), "
        ... "medic_drops = VALUES(medic_drops), "
        ... "uber_drops = VALUES(uber_drops), "
        ... "airshots = VALUES(airshots), "
        ... "headshots = VALUES(headshots), "
        ... "backstabs = VALUES(backstabs), "
        ... "classes_mask = VALUES(classes_mask), "
        ... "shots_scout = VALUES(shots_scout), "
        ... "hits_scout = VALUES(hits_scout), "
        ... "shots_sniper = VALUES(shots_sniper), "
        ... "hits_sniper = VALUES(hits_sniper), "
        ... "shots_soldier = VALUES(shots_soldier), "
        ... "hits_soldier = VALUES(hits_soldier), "
        ... "shots_demoman = VALUES(shots_demoman), "
        ... "hits_demoman = VALUES(hits_demoman), "
        ... "shots_medic = VALUES(shots_medic), "
        ... "hits_medic = VALUES(hits_medic), "
        ... "shots_heavy = VALUES(shots_heavy), "
        ... "hits_heavy = VALUES(hits_heavy), "
        ... "shots_pyro = VALUES(shots_pyro), "
        ... "hits_pyro = VALUES(hits_pyro), "
        ... "shots_spy = VALUES(shots_spy), "
        ... "hits_spy = VALUES(hits_spy), "
        ... "shots_engineer = VALUES(shots_engineer), "
        ... "hits_engineer = VALUES(hits_engineer)",
        g_Stats[client].steamId,
        g_Stats[client].firstSeenTimestamp,
        g_Stats[client].kills,
        g_Stats[client].deaths,
        g_Stats[client].totalShots,
        g_Stats[client].totalHits,
        g_Stats[client].totalHealing,
        g_Stats[client].totalUbers,
        g_Stats[client].bestUbersLife,
        g_Stats[client].totalMedicDrops,
        g_Stats[client].totalUberDrops,
        g_Stats[client].totalAirshots,
        g_Stats[client].totalHeadshots,
        g_Stats[client].totalBackstabs,
        classValueSegment);

    QueueSaveQuery(query, userId, false);
}

static void QueueSecondaryStatsSave(int client, int userId)
{
    char query[SAVE_QUERY_MAXLEN];
    Format(query, sizeof(query),
        "INSERT INTO whaletracker "
        ... "(steamid, best_headshots_life, best_backstabs_life, best_kills_life, best_killstreak, best_score_life, assists, best_assists_life, playtime, damage_dealt, damage_taken, last_seen, is_admin) "
        ... "VALUES ('%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d) "
        ... "ON DUPLICATE KEY UPDATE "
        ... "best_headshots_life = VALUES(best_headshots_life), "
        ... "best_backstabs_life = VALUES(best_backstabs_life), "
        ... "best_kills_life = VALUES(best_kills_life), "
        ... "best_killstreak = VALUES(best_killstreak), "
        ... "best_score_life = VALUES(best_score_life), "
        ... "assists = VALUES(assists), "
        ... "best_assists_life = VALUES(best_assists_life), "
        ... "playtime = VALUES(playtime), "
        ... "damage_dealt = VALUES(damage_dealt), "
        ... "damage_taken = VALUES(damage_taken), "
        ... "last_seen = VALUES(last_seen), "
        ... "is_admin = VALUES(is_admin)",
        g_Stats[client].steamId,
        g_Stats[client].bestHeadshotsLife,
        g_Stats[client].bestBackstabsLife,
        g_Stats[client].bestKillsLife,
        g_Stats[client].bestKillstreak,
        g_Stats[client].bestScoreLife,
        g_Stats[client].totalAssists,
        g_Stats[client].bestAssistsLife,
        g_Stats[client].playtime,
        g_Stats[client].totalDamage,
        g_Stats[client].totalDamageTaken,
        g_Stats[client].lastSeen,
        g_Stats[client].isAdmin ? 1 : 0);

    QueueSaveQuery(query, userId, false);
}

static bool SaveClientStats(int client, bool includeMapStats)
{
    if (!IsValidClient(client))
        return false;

    EnsureClientSteamId(client);

    if (g_Stats[client].steamId[0] == '\0')
        return false;

    AccumulatePlaytime(client);

    if (!g_Stats[client].loaded)
    {
        if (g_Stats[client].firstSeenTimestamp == 0)
        {
            g_Stats[client].firstSeenTimestamp = GetTime();
            FormatTime(g_Stats[client].firstSeen, sizeof(g_Stats[client].firstSeen), "%Y-%m-%d", g_Stats[client].firstSeenTimestamp);
        }
        g_Stats[client].loaded = true;
    }

    g_MapStats[client].loaded = true;

    g_KillSaveCounter[client] = 0;

    TouchClientLastSeen(client);

    int userId = GetClientUserId(client);
    QueuePrimaryStatsSave(client, userId);
    QueueSecondaryStatsSave(client, userId);

    if (includeMapStats)
    {
        SaveClientMapStats(client);
    }

    return true;
}

public void WhaleTracker_SaveCallback(Database db, DBResultSet results, const char[] error, any data)
{
    int slot = data;
    int userId = 0;

    if (slot >= 0 && slot < MAX_CONCURRENT_SAVE_QUERIES)
    {
        userId = g_SaveQueryUserIds[slot];
    }

    if (error[0] != '\0')
    {
        int client = (userId > 0) ? GetClientOfUserId(userId) : 0;
        char queryPreview[256];
        queryPreview[0] = '\0';

        if (slot >= 0 && slot < MAX_CONCURRENT_SAVE_QUERIES)
        {
            if (g_SaveQueryBuffers[slot][0] != '\0')
            {
                strcopy(queryPreview, sizeof(queryPreview), g_SaveQueryBuffers[slot]);
            }
        }

        if (client > 0 && IsValidClient(client))
        {
            if (queryPreview[0])
            {
                LogError("[WhaleTracker] Failed to save stats for %N: %s | Query: %s", client, error, queryPreview);
            }
            else
            {
                LogError("[WhaleTracker] Failed to save stats for %N: %s", client, error);
            }
        }
        else if (userId > 0)
        {
            if (queryPreview[0])
            {
                LogError("[WhaleTracker] Failed to save stats (userid %d): %s | Query: %s", userId, error, queryPreview);
            }
            else
            {
                LogError("[WhaleTracker] Failed to save stats (userid %d): %s", userId, error);
            }
        }
        else
        {
            if (queryPreview[0])
            {
                LogError("[WhaleTracker] Failed to save stats: %s | Query: %s", error, queryPreview);
            }
            else
            {
                LogError("[WhaleTracker] Failed to save stats: %s", error);
            }
        }
    }

    if (slot >= 0 && slot < MAX_CONCURRENT_SAVE_QUERIES)
    {
        g_SaveQuerySlotUsed[slot] = false;
        g_SaveQueryUserIds[slot] = 0;
        g_SaveQueryBuffers[slot][0] = '\0';
    }

    if (g_PendingSaveQueries > 0)
    {
        g_PendingSaveQueries--;
    }

    PumpSaveQueue();
}

public void Event_PlayerSpawn(Event event, const char[] name, bool dontBroadcast)
{
    int client = GetClientOfUserId(event.GetInt("userid"));
    if (!IsValidClient(client))
        return;

    ResetLifeCounters(g_Stats[client]);
    ResetLifeCounters(g_MapStats[client]);
    int classId = GetClientTfClass(client);
    if (classId > 0)
    {
        MarkClassPlayed(g_Stats[client], classId);
        MarkClassPlayed(g_MapStats[client], classId);
    }
}

public void Event_PlayerDeath(Event event, const char[] name, bool dontBroadcast)
{
    int victim = GetClientOfUserId(event.GetInt("userid"));
    int attacker = GetClientOfUserId(event.GetInt("attacker"));
    int assister = GetClientOfUserId(event.GetInt("assister"));
    int deathFlags = GetUserFlagBits(victim);

    if (!(deathFlags & 32))
    {
        if (IsValidClient(attacker) && attacker != victim)
        {
            int custom = event.GetInt("customkill");
            bool backstab = (custom == TF_CUSTOM_BACKSTAB);
            bool medicDrop = IsMedicDrop(victim);

            ApplyKillStats(g_Stats[attacker], backstab, medicDrop);
            ApplyKillStats(g_MapStats[attacker], backstab, medicDrop);

            g_KillSaveCounter[attacker]++;
            if (g_KillSaveCounter[attacker] >= 3)
            {
                SaveClientStats(attacker, false);
            }
        }

        if (IsValidClient(assister) && assister != victim)
        {
            ApplyAssistStats(g_Stats[assister]);
            ApplyAssistStats(g_MapStats[assister]);
        }

        if (IsValidClient(victim))
        {
            ApplyDeathStats(g_Stats[victim]);
            ApplyDeathStats(g_MapStats[victim]);
        }
    }
}

public Action OnTakeDamage(int victim, int &attacker, int &inflictor, float &damage, int &damagetype, int &weapon, float damageForce[3], float damagePosition[3], int damagecustom)
{
    if (attacker == victim)
        return Plugin_Continue;

    bool isHeadshot = (damagecustom == TF_CUSTOM_HEADSHOT || damagecustom == TF_CUSTOM_HEADSHOT_DECAPITATION);
    if (isHeadshot && IsValidClient(attacker) && !IsFakeClient(attacker))
    {
        RecordHeadshotEvent(attacker);
    }

    if (CheckIfAfterburn(damagecustom) || CheckIfBleedDmg(damagetype))
        return Plugin_Continue;

    if (damage <= 0.0)
        return Plugin_Continue;

    if (g_bDebugMinimalStats)
        return Plugin_Continue;

    int damageInt = RoundToFloor(damage);
    if (damageInt < 0)
    {
        damageInt = 0;
    }

    if (IsValidClient(attacker) && !IsFakeClient(attacker))
    {
        if (IsProjectileAirshot(attacker, victim))
            g_Stats[attacker].totalAirshots += 1;

        g_Stats[attacker].totalDamage += damageInt;
        g_MapStats[attacker].totalDamage += damageInt;
        if (!TrackAccuracyEvent(attacker, weapon, true))
        {
            if (!TrackAccuracyEvent(attacker, inflictor, true))
            {
                int activeWeapon = GetEntPropEnt(attacker, Prop_Send, "m_hActiveWeapon");
                if (activeWeapon > MaxClients)
                {
                    TrackAccuracyEvent(attacker, activeWeapon, true);
                }
            }
        }
    }

    if (IsValidClient(victim) && !IsFakeClient(victim))
    {
        g_Stats[victim].totalDamageTaken += damageInt;
        g_MapStats[victim].totalDamageTaken += damageInt;
    }

    return Plugin_Continue;
}

public Action TF2_CalcIsAttackCritical(int client, int weapon, char[] weaponname, bool& result)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return Plugin_Continue;

    if (CheckIfAfterburn(0) || CheckIfBleedDmg(0))
        return Plugin_Continue;

    TrackAccuracyEvent(client, weapon, false);
    return Plugin_Continue;
}

public void Event_PlayerHealed(Event event, const char[] name, bool dontBroadcast)
{
    int healer = GetClientOfUserId(event.GetInt("healer"));
    if (!IsValidClient(healer) || IsFakeClient(healer))
        return;

    int amount = event.GetInt("amount");
    if (amount > 0)
    {
        ApplyHealingStats(g_Stats[healer], amount);
        ApplyHealingStats(g_MapStats[healer], amount);
    }
}

public void Event_UberDeployed(Event event, const char[] name, bool dontBroadcast)
{
    int medic = GetClientOfUserId(event.GetInt("userid"));
    if (!IsValidClient(medic) || IsFakeClient(medic))
        return;

    ApplyUberStats(g_Stats[medic]);
    ApplyUberStats(g_MapStats[medic]);
}

static bool IsMedicDrop(int victim)
{
    if (!IsValidClient(victim) || IsFakeClient(victim))
        return false;

    if (TF2_GetPlayerClass(victim) != TFClass_Medic)
        return false;

    int medigun = GetPlayerWeaponSlot(victim, 1);
    if (medigun <= MaxClients || !IsValidEntity(medigun))
        return false;

    float charge = GetEntPropFloat(medigun, Prop_Send, "m_flChargeLevel");
    return charge >= 1.0;
}

static bool IsProjectileAirshot(int attacker, int victim)
{
    if (!IsValidClient(attacker) || IsFakeClient(attacker) || !IsValidClient(victim) || IsFakeClient(victim))
        return false;

    int weapon = GetPlayerWeaponSlot(attacker, 0);
    if (weapon <= MaxClients || !IsValidEntity(weapon))
        return false;

    char classname[64];
    GetEntityClassname(weapon, classname, sizeof(classname));

    bool projectileWeapon = StrContains(classname, "rocketlauncher", false) != -1
        || StrContains(classname, "grenadelauncher", false) != -1
        || StrContains(classname, "pipeline", false) != -1
        || StrContains(classname, "stickbomb", false) != -1;

    if (!projectileWeapon)
        return false;

    int flags = GetEntityFlags(victim);
    bool victimInAir = !(flags & FL_ONGROUND);
    return victimInAir;
}

static void FormatMatchDuration(int seconds, char[] buffer, int maxlen)
{
    if (maxlen <= 0)
    {
        return;
    }

    if (seconds <= 0)
    {
        strcopy(buffer, maxlen, "0s");
        return;
    }

    int hours = seconds / 3600;
    int minutes = (seconds % 3600) / 60;
    int secs = seconds % 60;

    if (hours > 0)
    {
        Format(buffer, maxlen, "%dh %dm", hours, minutes);
    }
    else if (minutes > 0)
    {
        Format(buffer, maxlen, "%dm %ds", minutes, secs);
    }
    else
    {
        Format(buffer, maxlen, "%ds", secs);
    }
}

stock void GetWeaponNameFromDefIndex(int defIndex, char[] buffer, int maxlen)
{
    switch(defIndex)
    {
        case 9, 10, 11, 12: strcopy(buffer, maxlen, "Shotgun");
        case 13, 200, 15029: strcopy(buffer, maxlen, "Scattergun"); // Note: warpaints are a problem, will add a classname handler for paints
        case 14: strcopy(buffer, maxlen, "Sniper Rifle");
        case 15: strcopy(buffer, maxlen, "Minigun");
        case 16: strcopy(buffer, maxlen, "SMG");
        case 17: strcopy(buffer, maxlen, "Syringe Gun");
        case 18: strcopy(buffer, maxlen, "Rocket Launcher");
        case 19: strcopy(buffer, maxlen, "Grenade Launcher");
        case 1151: strcopy(buffer, maxlen, "Iron Bomber");
        case 20: strcopy(buffer, maxlen, "Stickybomb Launcher");
        case 21: strcopy(buffer, maxlen, "Flame Thrower");
        case 22, 23: strcopy(buffer, maxlen, "Pistol");
        case 24: strcopy(buffer, maxlen, "Revolver");
        case 35: strcopy(buffer, maxlen, "Kritzkrieg");
        case 36: strcopy(buffer, maxlen, "Blutsauger");
        case 39: strcopy(buffer, maxlen, "Flare Gun");
        case 40: strcopy(buffer, maxlen, "Backburner");
        case 41: strcopy(buffer, maxlen, "Natascha");
        case 45: strcopy(buffer, maxlen, "Force-A-Nature");
        case 1103: strcopy(buffer, maxlen, "Back Scatter");
        case 56: strcopy(buffer, maxlen, "Huntsman");
        case 1092: strcopy(buffer, maxlen, "Fortified Compound");
        case 61: strcopy(buffer, maxlen, "Ambassador");
        case 127: strcopy(buffer, maxlen, "Direct Hit");
        case 130: strcopy(buffer, maxlen, "Scottish Resistance");
        case 140: strcopy(buffer, maxlen, "Wrangler");
        case 141: strcopy(buffer, maxlen, "Frontier Justice");
        case 160: strcopy(buffer, maxlen, "Lugermorph");
        case 161: strcopy(buffer, maxlen, "Big Kill");
        case 198: strcopy(buffer, maxlen, "Bonesaw");
        case 199: strcopy(buffer, maxlen, "Shotgun");
        case 201: strcopy(buffer, maxlen, "Sniper Rifle");
        case 202: strcopy(buffer, maxlen, "Minigun");
        case 203: strcopy(buffer, maxlen, "SMG");
        case 204: strcopy(buffer, maxlen, "Syringe Gun");
        case 205: strcopy(buffer, maxlen, "Rocket Launcher");
        case 206: strcopy(buffer, maxlen, "Grenade Launcher");
        case 207: strcopy(buffer, maxlen, "Stickybomb Launcher");
        case 208: strcopy(buffer, maxlen, "Flame Thrower");
        case 209: strcopy(buffer, maxlen, "Pistol");
        case 210: strcopy(buffer, maxlen, "Revolver");
        case 215: strcopy(buffer, maxlen, "Degreaser");
        case 220: strcopy(buffer, maxlen, "Shortstop");
        case 224: strcopy(buffer, maxlen, "L'Etranger");
        case 225: strcopy(buffer, maxlen, "Your Eternal Reward");
        case 226: strcopy(buffer, maxlen, "Battalion's Backup");
        case 228: strcopy(buffer, maxlen, "Black Box");
        case 230: strcopy(buffer, maxlen, "Sydney Sleeper");
        case 264: strcopy(buffer, maxlen, "Frying Pan");
        case 265: strcopy(buffer, maxlen, "Sticky Jumper");
        case 266: strcopy(buffer, maxlen, "Horseless Headless Horsemann's Headtaker");
        case 294: strcopy(buffer, maxlen, "Lugermorph");
        case 1104: strcopy(buffer, maxlen, "Air Strike");
        case 1153: strcopy(buffer, maxlen, "Panic Attack");
        case 298: strcopy(buffer, maxlen, "Iron Curtain");
        case 305: strcopy(buffer, maxlen, "Crusader's Crossbow");
        case 307: strcopy(buffer, maxlen, "Ullapool Caber");
        case 308: strcopy(buffer, maxlen, "Loch-n-Load");
        case 312: strcopy(buffer, maxlen, "Brass Beast");
        case 351: strcopy(buffer, maxlen, "Detonator");
        case 355: strcopy(buffer, maxlen, "Fan O'War");
        case 402: strcopy(buffer, maxlen, "Bazaar Bargain");
        case 412: strcopy(buffer, maxlen, "Overdose");
        case 414: strcopy(buffer, maxlen, "Liberty Launcher");
        case 415: strcopy(buffer, maxlen, "Reserve Shooter");
        case 416: strcopy(buffer, maxlen, "Market Gardener");
        case 424: strcopy(buffer, maxlen, "Tomislav");
        case 425: strcopy(buffer, maxlen, "Family Business");
        case 441: strcopy(buffer, maxlen, "Cow Mangler 5000");
        case 442: strcopy(buffer, maxlen, "Righteous Bison");
        case 444: strcopy(buffer, maxlen, "Mantreads");
        case 448: strcopy(buffer, maxlen, "Soda Popper");
        case 449: strcopy(buffer, maxlen, "Winger");
        case 460: strcopy(buffer, maxlen, "Enforcer");
        case 461: strcopy(buffer, maxlen, "Big Earner");
        case 513: strcopy(buffer, maxlen, "Original");
        case 525: strcopy(buffer, maxlen, "Diamondback");
        case 526: strcopy(buffer, maxlen, "Machina");
        case 527: strcopy(buffer, maxlen, "Widowmaker");
        case 528: strcopy(buffer, maxlen, "Short Circuit");
        case 588: strcopy(buffer, maxlen, "Pomson 6000");
        case 595: strcopy(buffer, maxlen, "Manmelter");
        case 654: strcopy(buffer, maxlen, "Festive Minigun");
        case 656: strcopy(buffer, maxlen, "Holiday Punch");
        case 658: strcopy(buffer, maxlen, "Festive Rocket Launcher");
        case 661: strcopy(buffer, maxlen, "Festive Stickybomb Launcher");
        case 664: strcopy(buffer, maxlen, "Festive Sniper Rifle");
        case 669: strcopy(buffer, maxlen, "Festive Scattergun");
        case 730: strcopy(buffer, maxlen, "Beggar's Bazooka");
        case 740: strcopy(buffer, maxlen, "Scorch Shot");
        case 751: strcopy(buffer, maxlen, "Cleaner's Carbine");
        case 752: strcopy(buffer, maxlen, "Hitman's Heatmaker");
        case 772: strcopy(buffer, maxlen, "Baby Face's Blaster");
        case 773: strcopy(buffer, maxlen, "Pretty Boy's Pocket Pistol");
        case 811: strcopy(buffer, maxlen, "Huo-Long Heater");
        case 812: strcopy(buffer, maxlen, "Flying Guillotine");
        case 832: strcopy(buffer, maxlen, "Huo-Long Heater");
        case 833: strcopy(buffer, maxlen, "Flying Guillotine");
        case 851: strcopy(buffer, maxlen, "AWPer Hand");
        default: strcopy(buffer, maxlen, "Unknown");
    }
}

stock bool CheckIfAfterburn(int damagecustom)
{
    return (damagecustom == TF_CUSTOM_BURNING || damagecustom == TF_CUSTOM_BURNING_FLARE);
}

stock bool CheckIfBleedDmg(int damageType)
{
    return (damageType & DMG_SLASH) != 0;
}

static void SendMatchStatsMessage(int viewer, int target)
{
    if (!IsValidClient(viewer) || IsFakeClient(viewer))
        return;

    if (!IsValidClient(target) || IsFakeClient(target))
    {
        CPrintToChat(viewer, "{blue}[WhaleTracker]{default} No valid player selected.");
        return;
    }

    bool targetInGame = IsClientInGame(target);
    if (targetInGame)
    {
        AccumulatePlaytime(target);
    }

    EnsureClientSteamId(target);
    WhaleStats matchStats;
    matchStats = g_MapStats[target];
    bool hasActivity = HasMapActivity(matchStats) || matchStats.playtime > 0;

    if (!targetInGame && !hasActivity)
    {
        CPrintToChat(viewer, "{blue}[WhaleTracker]{default} %N has no current match data.", target);
        return;
    }

    char playerName[MAX_NAME_LENGTH];
    if (targetInGame)
    {
        GetClientName(target, playerName, sizeof(playerName));
        RememberMatchPlayerName(matchStats.steamId, playerName);
    }
    else if (!GetStoredMatchPlayerName(matchStats.steamId, playerName, sizeof(playerName)))
    {
        strcopy(playerName, sizeof(playerName), matchStats.steamId);
    }

    int kills = matchStats.kills;
    int deaths = matchStats.deaths;
    int assists = matchStats.totalAssists;
    int damage = matchStats.totalDamage;
    int damageTaken = matchStats.totalDamageTaken;
    int healing = matchStats.totalHealing;
    int headshots = matchStats.totalHeadshots;
    int backstabs = matchStats.totalBackstabs;
    int ubers = matchStats.totalUbers;

    int lifetimeKills = g_Stats[target].kills;
    int lifetimeDeaths = g_Stats[target].deaths;

    float kd = (deaths > 0) ? float(kills) / float(deaths) : float(kills);
    float dpm = 0.0, dtpm = 0.0;
    float minutes = (matchStats.playtime > 0) ? float(matchStats.playtime) / 60.0 : 0.0;
    if (minutes > 1.0)
    {
        dpm = (minutes > 0.0) ? float(damage) / minutes : 0.0;
        dtpm = (minutes > 0.0) ? float(damageTaken) / minutes : 0.0;
    }

    char timeBuffer[32];
    FormatMatchDuration(matchStats.playtime, timeBuffer, sizeof(timeBuffer));

    CPrintToChat(viewer, "{blue}[WhaleTracker]{default} %s — Match: K %d | D %d | KD %.2f | A %d | Dmg %d | Dmg/min %.1f",
        playerName, kills, deaths, kd, assists, damage, dpm);
    CPrintToChat(viewer, "{blue}[WhaleTracker]{default} Taken %d | Taken/min %.1f | Heal %d | HS %d | BS %d | Ubers %d | Time %s",
        damageTaken, dtpm, healing, headshots, backstabs, ubers, timeBuffer);
    CPrintToChat(viewer, "{blue}[WhaleTracker]{default} Lifetime Kills %d | Deaths %d", lifetimeKills, lifetimeDeaths);
    CPrintToChat(viewer, "{blue}[WhaleTracker]{default} Visit kogasa.tf/stats for full");
}

public Action Command_ShowStats(int client, int args)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return Plugin_Handled;

    int target = client;

    if (args >= 1)
    {
        char targetArg[64];
        GetCmdArgString(targetArg, sizeof(targetArg));
        TrimString(targetArg);

        if (targetArg[0])
        {
            int candidate = FindTarget(client, targetArg, true, false);
            if (candidate > 0 && IsValidClient(candidate) && !IsFakeClient(candidate))
            {
                target = candidate;
            }
            else
            {
                CPrintToChat(client, "{blue}[WhaleTracker]{default} Could not find player '%s'.", targetArg);
                return Plugin_Handled;
            }
        }
    }

    SendMatchStatsMessage(client, target);
    return Plugin_Handled;
}

public Action Command_SaveAllStats(int client, int args)
{
    int saved = 0;

    for (int i = 1; i <= MaxClients; i++)
    {
        if (SaveClientStats(i, true))
        {
            saved++;
        }
    }

    if (client > 0 && IsClientInGame(client))
    {
        CPrintToChat(client, "{blue}[WhaleTracker]{default} Saved stats for %d player(s).", saved);
    }
    else
    {
        PrintToServer("[WhaleTracker] Saved stats for %d player(s).", saved);
    }

    return Plugin_Handled;
}

static bool IsValidClient(int client)
{
    return client > 0 && client <= MaxClients && IsClientConnected(client);
}
