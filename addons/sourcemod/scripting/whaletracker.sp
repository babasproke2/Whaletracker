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

stock bool CheckIfAfterburn(int damagecustom)
{
    return (damagecustom == TF_CUSTOM_BURNING || damagecustom == TF_CUSTOM_BURNING_FLARE);
}

stock bool CheckIfBleedDmg(int damageType)
{
    return (damageType & DMG_SLASH) != 0;
}

#define WT_CLASS_OFFSET 1
#define WT_CLASS_COUNT 9
#define WT_MAX_WEAPON_NAME 128
#define WT_MAX_TOP_WEAPONS 6
#define WT_MAX_ESCAPED_NAME (MAX_NAME_LENGTH * 2)

const int WT_ONLINE_CACHE_MAX_AGE = 60;

enum struct WeaponAggregate
{
    char name[WT_MAX_WEAPON_NAME];
    int shots;
    int hits;
    int damage;
    int defIndex;
}

enum struct WeaponAccuracyEntry
{
    char name[WT_MAX_WEAPON_NAME];
    float accuracy;
    int shots;
    int hits;
}

static bool WT_TryGetWeaponDefIndex(int entity, int &defIndex)
{
    defIndex = 0;

    if (entity <= MaxClients || !IsValidEntity(entity))
    {
        return false;
    }

    if (!HasEntProp(entity, Prop_Send, "m_iItemDefinitionIndex"))
    {
        return false;
    }

    defIndex = GetEntProp(entity, Prop_Send, "m_iItemDefinitionIndex");
    return defIndex > 0;
}

static int WT_ResolveWeaponDefIndex(int client, int weapon, int inflictor)
{
    int defIndex = 0;
    if (WT_TryGetWeaponDefIndex(weapon, defIndex))
    {
        return defIndex;
    }
    if (WT_TryGetWeaponDefIndex(inflictor, defIndex))
    {
        return defIndex;
    }

    if (IsValidClient(client) && !IsFakeClient(client))
    {
        int activeWeapon = GetEntPropEnt(client, Prop_Send, "m_hActiveWeapon");
        if (WT_TryGetWeaponDefIndex(activeWeapon, defIndex))
        {
            return defIndex;
        }
    }

    return 0;
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
        case 44: strcopy(buffer, maxlen, "Sandman");
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
        case 221: strcopy(buffer, maxlen, "Holy Mackerel");
        case 224: strcopy(buffer, maxlen, "L'Etranger");
        case 225: strcopy(buffer, maxlen, "Your Eternal Reward");
        case 226: strcopy(buffer, maxlen, "Battalion's Backup");
        case 228: strcopy(buffer, maxlen, "Black Box");
        case 230: strcopy(buffer, maxlen, "Sydney Sleeper");
        case 232: strcopy(buffer, maxlen, "Bushwacka");
        case 237: strcopy(buffer, maxlen, "Rocket Jumper");
        case 239: strcopy(buffer, maxlen, "Gloves of Running Urgently");
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
        case 310: strcopy(buffer, maxlen, "Warrior's Spirit");
        case 311: strcopy(buffer, maxlen, "Buffalo Steak Sandvich");
        case 312: strcopy(buffer, maxlen, "Brass Beast");
        case 351: strcopy(buffer, maxlen, "Detonator");
        case 355: strcopy(buffer, maxlen, "Fan O'War");
        case 402: strcopy(buffer, maxlen, "Bazaar Bargain");
        case 404: strcopy(buffer, maxlen, "Persian Persuader");
        case 412: strcopy(buffer, maxlen, "Overdose");
        case 413: strcopy(buffer, maxlen, "Solemn Vow");
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
        case 813: strcopy(buffer, maxlen, "Neon Annihilator");
        case 831: strcopy(buffer, maxlen, "Red-Tape Recorder");
        case 832: strcopy(buffer, maxlen, "Huo-Long Heater");
        case 833: strcopy(buffer, maxlen, "Flying Guillotine");
        case 851: strcopy(buffer, maxlen, "AWPer Hand");
        default: strcopy(buffer, maxlen, "Unknown");
    }
}

#define STEAMID64_LEN 32
#define MENU_TITLE "Whale Tracker Stats"
#define DB_CONFIG_DEFAULT "default"
#define SAVE_QUERY_MAXLEN 4096
#define MAX_CONCURRENT_SAVE_QUERIES 4

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
    int classShots[WT_CLASS_COUNT + WT_CLASS_OFFSET];
    int classHits[WT_CLASS_COUNT + WT_CLASS_OFFSET];
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
    int favoriteClass;
    float bestWeaponAccuracy;
    char bestWeapon[64];
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

    StringMap weaponShots;
    StringMap weaponHits;
    StringMap weaponDamage;
    StringMap weaponNames;
    bool isAdmin;
}

WhaleStats g_Stats[MAXPLAYERS + 1];
WhaleStats g_MapStats[MAXPLAYERS + 1];
int g_KillSaveCounter[MAXPLAYERS + 1];

Database g_hDatabase = null;
ConVar g_CvarDatabase = null;
ConVar g_hVisibleMaxPlayers = null;
ConVar g_hEnabled = null;
ConVar g_hServerTag = null;
bool g_bDatabaseReady = false;
bool g_bReconnectScheduled = false;
bool g_bEnabled = true;

char g_sEnabled[] = "sm_whaletracker_enabled";
char g_sServerTag[32];

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
    MatchStat_Hits,
    MatchStat_BestStreak,
    MatchStat_BestHeadshotsLife,
    MatchStat_BestBackstabsLife,
    MatchStat_BestScoreLife,
    MatchStat_BestKillsLife,
    MatchStat_BestAssistsLife,
    MatchStat_BestUbersLife,
    MatchStat_ClassMask,
    MatchStat_IsAdmin,
    MatchStat_Count
};

#define MATCH_STAT_COUNT MatchStat_Count

StringMap g_DisconnectedStats = null;
StringMap g_MatchNames = null;
StringMap g_MatchWeaponTotals = null;

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

ArrayList g_OnlineCache = null;
int g_OnlineCacheTimestamp = 0;

// Accuracy tracking helpers (adapted from Supplemental Stats v2)
const int SHOT_ROCKET = 0;
const int SHOT_NEEDLE = 1;
const int SHOT_HEALINGBOLT = 2;
const int SHOT_PIPE = 3;
const int SHOT_STICKY = 4;
const int SHOT_PROJECTILE_MAX = 8; // exclusive upper bound for projectile types
const int SHOT_HITSCAN = 16;
const int SHOT_HITSCAN_MAX = 32; // exclusive upper bound for hitscan types

StringMap g_ShotTypeLookup = null;

bool g_RocketHurtSelf[MAXPLAYERS + 1];
bool g_RocketHurtEnemy[MAXPLAYERS + 1];
Handle g_RocketShotTimer[MAXPLAYERS + 1];
char g_RocketPendingWeapon[MAXPLAYERS + 1][64];
int g_RocketPendingDefIndex[MAXPLAYERS + 1];
Handle g_LoadRetryTimer[MAXPLAYERS + 1];

float g_LastHitscanHit[MAXPLAYERS + 1];

static void Accuracy_Init()
{
    if (g_ShotTypeLookup == null)
    {
        g_ShotTypeLookup = new StringMap();
    }
    else
    {
        g_ShotTypeLookup.Clear();
    }

    g_ShotTypeLookup.SetValue("tf_weapon_rocketlauncher", SHOT_ROCKET);
    g_ShotTypeLookup.SetValue("tf_weapon_particle_cannon", SHOT_ROCKET);
    g_ShotTypeLookup.SetValue("tf_weapon_rocketlauncher_directhit", SHOT_ROCKET);
    g_ShotTypeLookup.SetValue("tf_projectile_rocket", SHOT_ROCKET);
    g_ShotTypeLookup.SetValue("tf_projectile_energy_ball", SHOT_ROCKET);

    g_ShotTypeLookup.SetValue("tf_weapon_grenadelauncher", SHOT_PIPE);
    g_ShotTypeLookup.SetValue("tf_projectile_pipe", SHOT_PIPE);
    g_ShotTypeLookup.SetValue("tf_projectile_pipe_remote", SHOT_STICKY);

    g_ShotTypeLookup.SetValue("tf_weapon_syringegun_medic", SHOT_NEEDLE);
    g_ShotTypeLookup.SetValue("tf_weapon_crossbow", SHOT_HEALINGBOLT);
    g_ShotTypeLookup.SetValue("tf_projectile_healing_bolt", SHOT_HEALINGBOLT);

    g_ShotTypeLookup.SetValue("tf_weapon_scattergun", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_pep_brawler_blaster", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_handgun_scout_primary", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_soda_popper", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_shotgun_soldier", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_shotgun_primary", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_shotgun_hwg", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_shotgun_pyro", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_pistol_scout", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_handgun_scout_secondary", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_pistol", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_smg", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_sniperrifle", SHOT_HITSCAN);
    g_ShotTypeLookup.SetValue("tf_weapon_revolver", SHOT_HITSCAN);

    for (int i = 1; i <= MaxClients; i++)
    {
        g_RocketHurtSelf[i] = false;
        g_RocketHurtEnemy[i] = false;
        g_RocketPendingWeapon[i][0] = '\0';
        g_RocketPendingDefIndex[i] = 0;
        g_LastHitscanHit[i] = 0.0;
        if (g_RocketShotTimer[i] != null)
        {
            CloseHandle(g_RocketShotTimer[i]);
            g_RocketShotTimer[i] = null;
        }
        CancelLoadRetryTimer(i);
    }
}

static void Accuracy_ResetClientState(int client)
{
    if (client <= 0 || client > MaxClients)
        return;

    g_RocketHurtSelf[client] = false;
   g_RocketHurtEnemy[client] = false;
   g_RocketPendingWeapon[client][0] = '\0';
    g_RocketPendingDefIndex[client] = 0;
    g_LastHitscanHit[client] = 0.0;

    if (g_RocketShotTimer[client] != null)
    {
        CloseHandle(g_RocketShotTimer[client]);
        g_RocketShotTimer[client] = null;
    }
}

static int Accuracy_GetShotType(const char[] classname)
{
    if (g_ShotTypeLookup == null)
    {
        return -1;
    }

    int shotType = -1;
    if (g_ShotTypeLookup.GetValue(classname, shotType))
    {
        return shotType;
    }

    return -1;
}

static void Accuracy_MarkSelfDamage(int client, int inflictor)
{
    if (inflictor <= MaxClients || !IsValidEntity(inflictor))
        return;

    char classname[64];
    GetEntityClassname(inflictor, classname, sizeof(classname));
    int shotType = Accuracy_GetShotType(classname);
    if (shotType == SHOT_ROCKET)
    {
        g_RocketHurtSelf[client] = true;
    }
}

static void Accuracy_MarkEnemyHit(int attacker, int inflictor)
{
    if (inflictor <= MaxClients || !IsValidEntity(inflictor))
        return;

    char classname[64];
    GetEntityClassname(inflictor, classname, sizeof(classname));
    int shotType = Accuracy_GetShotType(classname);

    if (shotType == SHOT_ROCKET)
    {
        g_RocketHurtEnemy[attacker] = true;
    }
}

static void Accuracy_LogShot(int client, const char[] weapon, int defIndex)
{
    if (!weapon[0])
        return;
    RecordShotForClient(client, weapon, defIndex);
}

static void Accuracy_LogHit(int client, const char[] weapon, int defIndex)
{
    if (!weapon[0])
        return;
    RecordHitForClient(client, weapon, defIndex);
}

static void Accuracy_ScheduleRocketShot(int client, const char[] weapon, int defIndex)
{
    g_RocketHurtSelf[client] = false;
    g_RocketHurtEnemy[client] = false;
    strcopy(g_RocketPendingWeapon[client], sizeof(g_RocketPendingWeapon[]), weapon);
    g_RocketPendingDefIndex[client] = defIndex;

    if (g_RocketShotTimer[client] != null)
    {
        CloseHandle(g_RocketShotTimer[client]);
        g_RocketShotTimer[client] = null;
    }

    int userId = GetClientUserId(client);
    g_RocketShotTimer[client] = CreateTimer(0.15, Accuracy_RocketShotTimer, userId, TIMER_FLAG_NO_MAPCHANGE);
}

public Action Accuracy_RocketShotTimer(Handle timer, any userId)
{
    int client = GetClientOfUserId(userId);
    if (client <= 0 || client > MaxClients)
    {
        return Plugin_Stop;
    }

    g_RocketShotTimer[client] = null;

    if (!IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Stop;
    }

    if (!g_RocketHurtSelf[client] || g_RocketHurtEnemy[client])
    {
        Accuracy_LogShot(client, g_RocketPendingWeapon[client], g_RocketPendingDefIndex[client]);
    }

    g_RocketPendingWeapon[client][0] = '\0';
    g_RocketPendingDefIndex[client] = 0;
    g_RocketHurtSelf[client] = false;
    g_RocketHurtEnemy[client] = false;
    return Plugin_Stop;
}

static void Accuracy_OnWeaponFired(int client, const char[] weaponClassname, const char[] weaponLogName, int defIndex)
{
    int shotType = Accuracy_GetShotType(weaponClassname);

    if (shotType == SHOT_ROCKET)
    {
        Accuracy_ScheduleRocketShot(client, weaponLogName, defIndex);
        return;
    }

    // Sticky shots are logged immediately. Hit logging still occurs when the sticky detonates.
    Accuracy_LogShot(client, weaponLogName, defIndex);
}

public void OnEntityDestroyed(int entity)
{
    // no-op for accuracy helpers (placeholder to allow future extensions)
}

public void ConVarChanged_Enabled(ConVar convar, const char[] oldValue, const char[] newValue)
{
    bool newState = convar.BoolValue;
    if (newState == g_bEnabled) {
        return;
    }

    g_bEnabled = newState;

    if (g_bEnabled) {
        FinalizeCurrentMatch(false);
        ResetMatchStorage();
        BeginMatchTracking();
    } else {
        FinalizeCurrentMatch(false);
        ClearOnlineStats();
        ResetMatchStorage();
        g_sCurrentLogId[0] = '\0';
    }
}

static void EnsureWeaponMaps(WhaleStats stats)
{
    if (stats.weaponShots == null)
    {
        stats.weaponShots = new StringMap();
    }
    if (stats.weaponHits == null)
    {
        stats.weaponHits = new StringMap();
    }
    if (stats.weaponDamage == null)
    {
        stats.weaponDamage = new StringMap();
    }
    if (stats.weaponNames == null)
    {
        stats.weaponNames = new StringMap();
    }
}

static bool UpdateAdminStatus(int client)
{
    bool newStatus = CheckCommandAccess(client, "sm_kick", ADMFLAG_KICK, false);
    bool changed = (g_Stats[client].isAdmin != newStatus);
    g_Stats[client].isAdmin = newStatus;
    g_MapStats[client].isAdmin = newStatus;
    return changed;
}

static void ClearWeaponMaps(WhaleStats stats)
{
    EnsureWeaponMaps(stats);
    stats.weaponShots.Clear();
    stats.weaponHits.Clear();
    stats.weaponDamage.Clear();
    stats.weaponNames.Clear();
}

static void DeleteWeaponMaps(WhaleStats stats)
{
    if (stats.weaponShots != null)
    {
        delete stats.weaponShots;
        stats.weaponShots = null;
    }
    if (stats.weaponHits != null)
    {
        delete stats.weaponHits;
        stats.weaponHits = null;
    }
    if (stats.weaponDamage != null)
    {
        delete stats.weaponDamage;
        stats.weaponDamage = null;
    }
    if (stats.weaponNames != null)
    {
        delete stats.weaponNames;
        stats.weaponNames = null;
    }
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
    for (int classIndex = 0; classIndex < WT_CLASS_COUNT + WT_CLASS_OFFSET; classIndex++)
    {
        stats.classShots[classIndex] = 0;
        stats.classHits[classIndex] = 0;
    }
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
    if (resetIdentity)
    {
        stats.favoriteClass = TFClass_Unknown;
    }
    stats.bestWeaponAccuracy = 0.0;
    stats.bestWeapon[0] = '\0';
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
    ClearWeaponMaps(stats);
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
    g_MapStats[client].bestWeaponAccuracy = g_Stats[client].bestWeaponAccuracy;
    strcopy(g_MapStats[client].bestWeapon, sizeof(g_MapStats[client].bestWeapon), g_Stats[client].bestWeapon);
    g_MapStats[client].isAdmin = g_Stats[client].isAdmin;
    g_MapStats[client].favoriteClass = g_Stats[client].favoriteClass;
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

static void EnsureOnlineCache()
{
    if (g_OnlineCache == null)
    {
        g_OnlineCache = new ArrayList();
    }
}

static void ResetOnlineCache()
{
    if (g_OnlineCache == null)
    {
        g_OnlineCache = new ArrayList();
        g_OnlineCacheTimestamp = 0;
        return;
    }

    for (int i = g_OnlineCache.Length - 1; i >= 0; i--)
    {
        DataPack pack = view_as<DataPack>(g_OnlineCache.Get(i));
        if (pack != null)
        {
            delete pack;
        }
    }

    g_OnlineCache.Clear();
    g_OnlineCacheTimestamp = 0;
}

void ClearOnlineStats(bool resetCache = true)
{
    static const char deleteQuery[] = "DELETE FROM whaletracker_online";
    QueueSaveQuery(deleteQuery, 0, false);

    if (resetCache)
    {
        ResetOnlineCache();
    }
}

static bool OnlineCacheIsFresh()
{
    if (g_OnlineCacheTimestamp <= 0)
    {
        return false;
    }

    int now = GetTime();
    return (now - g_OnlineCacheTimestamp) <= WT_ONLINE_CACHE_MAX_AGE;
}

static void OnlineCacheRemoveBySteamId(const char[] steamId)
{
    if (g_OnlineCache == null || steamId[0] == '\0')
    {
        return;
    }

    char cachedId[STEAMID64_LEN];

    for (int i = g_OnlineCache.Length - 1; i >= 0; i--)
    {
        DataPack pack = view_as<DataPack>(g_OnlineCache.Get(i));
        if (pack == null)
        {
            continue;
        }

        pack.Reset();
        pack.ReadString(cachedId, sizeof(cachedId));

        if (StrEqual(cachedId, steamId, false))
        {
            delete pack;
            g_OnlineCache.Erase(i);
            break;
        }
    }

    if (g_OnlineCache.Length == 0)
    {
        g_OnlineCacheTimestamp = 0;
    }
}

static bool RepublishOnlineCache()
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return false;
    }

    EnsureOnlineCache();

    if (g_OnlineCache.Length == 0)
    {
        return false;
    }

    if (!OnlineCacheIsFresh())
    {
        ResetOnlineCache();
        return false;
    }

    ClearOnlineStats(false);

    char steamId[STEAMID64_LEN];
    char escapedName[WT_MAX_ESCAPED_NAME];
    char query[SAVE_QUERY_MAXLEN];

    int now = GetTime();

    for (int i = 0; i < g_OnlineCache.Length; i++)
    {
        DataPack pack = view_as<DataPack>(g_OnlineCache.Get(i));
        if (pack == null)
        {
            continue;
        }

        pack.Reset();
        pack.ReadString(steamId, sizeof(steamId));
        pack.ReadString(escapedName, sizeof(escapedName));
        int tfClass = pack.ReadCell();
        int team = pack.ReadCell();
        int alive = pack.ReadCell();
        int spectator = pack.ReadCell();
        int kills = pack.ReadCell();
        int deaths = pack.ReadCell();
        int assists = pack.ReadCell();
        int damage = pack.ReadCell();
        int damageTaken = pack.ReadCell();
        int healing = pack.ReadCell();
        int headshots = pack.ReadCell();
        int backstabs = pack.ReadCell();
        int shots = pack.ReadCell();
        int hits = pack.ReadCell();
        int playtime = pack.ReadCell();
        int totalUbers = pack.ReadCell();
        int bestStreak = pack.ReadCell();
        int classMask = pack.ReadCell();
        int visibleMax = pack.ReadCell();
        int timeConnected = pack.ReadCell();
        int bestClassId = pack.ReadCell();
        float bestClassAccuracy = pack.ReadFloat();
        int bestClassShots = pack.ReadCell();
        int bestClassHits = pack.ReadCell();

        char weaponNamesEscaped[WT_MAX_TOP_WEAPONS][WT_MAX_WEAPON_NAME * 2];
        char weaponNameBuffer[WT_MAX_WEAPON_NAME * 2];
        float weaponAccuracies[WT_MAX_TOP_WEAPONS];
        int weaponShots[WT_MAX_TOP_WEAPONS];
        int weaponHits[WT_MAX_TOP_WEAPONS];
        for (int weaponIndex = 0; weaponIndex < WT_MAX_TOP_WEAPONS; weaponIndex++)
        {
            pack.ReadString(weaponNameBuffer, sizeof(weaponNameBuffer));
            weaponAccuracies[weaponIndex] = pack.ReadFloat();
            weaponShots[weaponIndex] = pack.ReadCell();
            weaponHits[weaponIndex] = pack.ReadCell();

            if (weaponNameBuffer[0] == '\0')
            {
                weaponNamesEscaped[weaponIndex][0] = '\0';
            }
            else
            {
                if (g_hDatabase != null)
                {
                    SQL_EscapeString(g_hDatabase, weaponNameBuffer, weaponNamesEscaped[weaponIndex], sizeof(weaponNamesEscaped[]));
                }
                else
                {
                    strcopy(weaponNamesEscaped[weaponIndex], sizeof(weaponNamesEscaped[]), weaponNameBuffer);
                }
            }
        }

        Format(query, sizeof(query),
            "REPLACE INTO whaletracker_online "
            ... "(steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits, best_class_id, best_class_accuracy, best_class_shots, best_class_hits, "
            ... "weapon1_name, weapon1_accuracy, weapon1_shots, weapon1_hits, weapon2_name, weapon2_accuracy, weapon2_shots, weapon2_hits, weapon3_name, weapon3_accuracy, weapon3_shots, weapon3_hits, "
            ... "weapon4_name, weapon4_accuracy, weapon4_shots, weapon4_hits, weapon5_name, weapon5_accuracy, weapon5_shots, weapon5_hits, weapon6_name, weapon6_accuracy, weapon6_shots, weapon6_hits, "
            ... "playtime, total_ubers, best_streak, classes_mask, visible_max, time_connected, last_update) "
            ... "VALUES ('%s', '%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %.6f, %d, %d, "
            ... "'%s', %.6f, %d, %d, '%s', %.6f, %d, %d, '%s', %.6f, %d, %d, "
            ... "'%s', %.6f, %d, %d, '%s', %.6f, %d, %d, '%s', %.6f, %d, %d, "
            ... "%d, %d, %d, %d, %d, %d, %d)",
            steamId,
            escapedName,
            tfClass,
            team,
            alive,
            spectator,
            kills,
            deaths,
            assists,
            damage,
            damageTaken,
            healing,
            headshots,
            backstabs,
            shots,
            hits,
            bestClassId,
            bestClassAccuracy,
            bestClassShots,
            bestClassHits,
            weaponNamesEscaped[0],
            weaponAccuracies[0],
            weaponShots[0],
            weaponHits[0],
            weaponNamesEscaped[1],
            weaponAccuracies[1],
            weaponShots[1],
            weaponHits[1],
            weaponNamesEscaped[2],
            weaponAccuracies[2],
            weaponShots[2],
            weaponHits[2],
            weaponNamesEscaped[3],
            weaponAccuracies[3],
            weaponShots[3],
            weaponHits[3],
            weaponNamesEscaped[4],
            weaponAccuracies[4],
            weaponShots[4],
            weaponHits[4],
            weaponNamesEscaped[5],
            weaponAccuracies[5],
            weaponShots[5],
            weaponHits[5],
            playtime,
            totalUbers,
            bestStreak,
            classMask,
            visibleMax,
            timeConnected,
            now);

        QueueSaveQuery(query, 0, false);
    }

    g_OnlineCacheTimestamp = now;
    return true;
}

void RemoveOnlineStats(int client)
{
    char steamId[STEAMID64_LEN];

if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
{
    if (!GetClientAuthId(client, AuthId_Steam2, steamId, sizeof(steamId)))
    {
        return;
    }
}

    char query[128];
    Format(query, sizeof(query), "DELETE FROM whaletracker_online WHERE steamid = '%s'", steamId);
    QueueSaveQuery(query, 0, false);

    OnlineCacheRemoveBySteamId(steamId);
}

public Action Timer_UpdateOnlineStats(Handle timer, any data)
{
    if (!g_bEnabled)
        return Plugin_Continue;

    if (!g_bDatabaseReady || g_hDatabase == null)
        return Plugin_Continue;

    EnsureOnlineCache();
    ResetOnlineCache();

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
    char escapedName[WT_MAX_ESCAPED_NAME];
    char query[SAVE_QUERY_MAXLEN];

    for (int client = 1; client <= MaxClients; client++)
    {
        if (!IsClientInGame(client) || IsFakeClient(client))
            continue;


if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
{
    if (!GetClientAuthId(client, AuthId_Steam2, steamId, sizeof(steamId)))
    {
        continue;
    }
}

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

        int bestClassId = 0;
        float bestClassAccuracy = 0.0;
        int bestClassShots = 0;
        int bestClassHits = 0;
        WT_GetBestClassAccuracy(g_MapStats[client], bestClassId, bestClassAccuracy, bestClassShots, bestClassHits);

        WeaponAccuracyEntry topAccuracy[WT_MAX_TOP_WEAPONS];
        int topWeaponCount = WT_GetTopWeaponAccuracy(g_MapStats[client], topAccuracy);

        char weaponNames[WT_MAX_TOP_WEAPONS][WT_MAX_WEAPON_NAME];
        char weaponNamesEscaped[WT_MAX_TOP_WEAPONS][WT_MAX_WEAPON_NAME * 2];
        float weaponAccuracies[WT_MAX_TOP_WEAPONS];
        int weaponShots[WT_MAX_TOP_WEAPONS];
        int weaponHits[WT_MAX_TOP_WEAPONS];

        for (int weaponIndex = 0; weaponIndex < WT_MAX_TOP_WEAPONS; weaponIndex++)
        {
            if (weaponIndex < topWeaponCount)
            {
                strcopy(weaponNames[weaponIndex], sizeof(weaponNames[]), topAccuracy[weaponIndex].name);
                weaponAccuracies[weaponIndex] = topAccuracy[weaponIndex].accuracy;
                weaponShots[weaponIndex] = topAccuracy[weaponIndex].shots;
                weaponHits[weaponIndex] = topAccuracy[weaponIndex].hits;
                if (g_hDatabase != null)
                {
                    SQL_EscapeString(g_hDatabase, weaponNames[weaponIndex], weaponNamesEscaped[weaponIndex], sizeof(weaponNamesEscaped[]));
                }
                else
                {
                    strcopy(weaponNamesEscaped[weaponIndex], sizeof(weaponNamesEscaped[]), weaponNames[weaponIndex]);
                }
            }
            else
            {
                weaponNames[weaponIndex][0] = '\0';
                weaponAccuracies[weaponIndex] = 0.0;
                weaponShots[weaponIndex] = 0;
                weaponHits[weaponIndex] = 0;
                weaponNamesEscaped[weaponIndex][0] = '\0';
            }
        }

        Format(query, sizeof(query),
            "REPLACE INTO whaletracker_online "
            ... "(steamid, personaname, class, team, alive, is_spectator, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, shots, hits, best_class_id, best_class_accuracy, best_class_shots, best_class_hits, "
            ... "weapon1_name, weapon1_accuracy, weapon1_shots, weapon1_hits, weapon2_name, weapon2_accuracy, weapon2_shots, weapon2_hits, weapon3_name, weapon3_accuracy, weapon3_shots, weapon3_hits, "
            ... "weapon4_name, weapon4_accuracy, weapon4_shots, weapon4_hits, weapon5_name, weapon5_accuracy, weapon5_shots, weapon5_hits, weapon6_name, weapon6_accuracy, weapon6_shots, weapon6_hits, "
            ... "playtime, total_ubers, best_streak, classes_mask, visible_max, time_connected, last_update) "
            ... "VALUES ('%s', '%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %.6f, %d, %d, "
            ... "'%s', %.6f, %d, %d, '%s', %.6f, %d, %d, '%s', %.6f, %d, %d, "
            ... "'%s', %.6f, %d, %d, '%s', %.6f, %d, %d, '%s', %.6f, %d, %d, "
            ... "%d, %d, %d, %d, %d, %d, %d)",
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
            g_MapStats[client].totalShots,
            g_MapStats[client].totalHits,
            bestClassId,
            bestClassAccuracy,
            bestClassShots,
            bestClassHits,
            weaponNamesEscaped[0],
            weaponAccuracies[0],
            weaponShots[0],
            weaponHits[0],
            weaponNamesEscaped[1],
            weaponAccuracies[1],
            weaponShots[1],
            weaponHits[1],
            weaponNamesEscaped[2],
            weaponAccuracies[2],
            weaponShots[2],
            weaponHits[2],
            weaponNamesEscaped[3],
            weaponAccuracies[3],
            weaponShots[3],
            weaponHits[3],
            weaponNamesEscaped[4],
            weaponAccuracies[4],
            weaponShots[4],
            weaponHits[4],
            weaponNamesEscaped[5],
            weaponAccuracies[5],
            weaponShots[5],
            weaponHits[5],
            playtime,
            g_MapStats[client].totalUbers,
            g_MapStats[client].bestKillstreak,
            g_MapStats[client].damageClassesMask,
            visibleMax,
            playtime,
            now);

        QueueSaveQuery(query, 0, false);

        DataPack cachePack = new DataPack();
        cachePack.WriteString(steamId);
        cachePack.WriteString(escapedName);
        cachePack.WriteCell(tfClass);
        cachePack.WriteCell(team);
        cachePack.WriteCell(alive ? 1 : 0);
        cachePack.WriteCell(spectator ? 1 : 0);
        cachePack.WriteCell(g_MapStats[client].kills);
        cachePack.WriteCell(g_MapStats[client].deaths);
        cachePack.WriteCell(g_MapStats[client].totalAssists);
        cachePack.WriteCell(g_MapStats[client].totalDamage);
        cachePack.WriteCell(g_MapStats[client].totalDamageTaken);
        cachePack.WriteCell(g_MapStats[client].totalHealing);
        cachePack.WriteCell(g_MapStats[client].totalHeadshots);
        cachePack.WriteCell(g_MapStats[client].totalBackstabs);
        cachePack.WriteCell(g_MapStats[client].totalShots);
        cachePack.WriteCell(g_MapStats[client].totalHits);
        cachePack.WriteCell(playtime);
        cachePack.WriteCell(g_MapStats[client].totalUbers);
        cachePack.WriteCell(g_MapStats[client].bestKillstreak);
        cachePack.WriteCell(g_MapStats[client].damageClassesMask);
        cachePack.WriteCell(visibleMax);
        cachePack.WriteCell(playtime);
        cachePack.WriteCell(bestClassId);
        cachePack.WriteFloat(bestClassAccuracy);
        cachePack.WriteCell(bestClassShots);
        cachePack.WriteCell(bestClassHits);
        for (int weaponIndex = 0; weaponIndex < WT_MAX_TOP_WEAPONS; weaponIndex++)
        {
            cachePack.WriteString(weaponNames[weaponIndex]);
            cachePack.WriteFloat(weaponAccuracies[weaponIndex]);
            cachePack.WriteCell(weaponShots[weaponIndex]);
            cachePack.WriteCell(weaponHits[weaponIndex]);
        }
        cachePack.Reset();
        g_OnlineCache.Push(view_as<any>(cachePack));
    }

    g_OnlineCacheTimestamp = now;

    Format(query, sizeof(query), "DELETE FROM whaletracker_online WHERE last_update < %d", now - WT_ONLINE_CACHE_MAX_AGE);
    QueueSaveQuery(query, 0, false);

    return Plugin_Continue;
}

static bool WT_ParseFavoriteClassArg(const char[] input, TFClassType &classType)
{
    if (!input[0])
    {
        return false;
    }

    char lower[32];
    strcopy(lower, sizeof(lower), input);
    StrToLower(lower);

    if (StrEqual(lower, "none", false) || StrEqual(lower, "auto", false) || StrEqual(lower, "clear", false))
    {
        classType = TFClass_Unknown;
        return true;
    }

    if (StrEqual(lower, "scout", false))
    {
        classType = TFClass_Scout;
        return true;
    }
    if (StrEqual(lower, "sniper", false))
    {
        classType = TFClass_Sniper;
        return true;
    }
    if (StrEqual(lower, "soldier", false))
    {
        classType = TFClass_Soldier;
        return true;
    }
    if (StrEqual(lower, "demo", false) || StrEqual(lower, "demoman", false))
    {
        classType = TFClass_DemoMan;
        return true;
    }
    if (StrEqual(lower, "medic", false))
    {
        classType = TFClass_Medic;
        return true;
    }
    if (StrEqual(lower, "heavy", false) || StrEqual(lower, "heavyweapons", false))
    {
        classType = TFClass_Heavy;
        return true;
    }
    if (StrEqual(lower, "pyro", false))
    {
        classType = TFClass_Pyro;
        return true;
    }
    if (StrEqual(lower, "spy", false))
    {
        classType = TFClass_Spy;
        return true;
    }
    if (StrEqual(lower, "engi", false) || StrEqual(lower, "engineer", false))
    {
        classType = TFClass_Engineer;
        return true;
    }

    return false;
}

static void WT_GetClassDisplayName(TFClassType classType, char[] buffer, int maxlen)
{
    static const char classNames[][] =
    {
        "Auto",
        "Scout",
        "Sniper",
        "Soldier",
        "Demoman",
        "Medic",
        "Heavy",
        "Pyro",
        "Spy",
        "Engineer"
    };

    int index = view_as<int>(classType);
    if (index < 0 || index >= sizeof(classNames))
    {
        index = 0;
    }

    strcopy(buffer, maxlen, classNames[index]);
}

static void StrToLower(char[] buffer)
{
    for (int i = 0; buffer[i] != '\0'; i++)
    {
        buffer[i] = CharToLower(buffer[i]);
    }
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
    if (g_MatchWeaponTotals == null)
    {
        g_MatchWeaponTotals = new StringMap();
    }
}

static void ResetMatchStorage()
{
    EnsureMatchStorage();
    g_DisconnectedStats.Clear();
    g_MatchNames.Clear();
    if (g_MatchWeaponTotals != null)
    {
        StringMapSnapshot weaponSnap = g_MatchWeaponTotals.Snapshot();
        if (weaponSnap != null)
        {
            char steamId[STEAMID64_LEN];
            for (int i = 0; i < weaponSnap.Length; i++)
            {
                weaponSnap.GetKey(i, steamId, sizeof(steamId));
                any handleValue;
                if (g_MatchWeaponTotals.GetValue(steamId, handleValue))
                {
                    StringMap weaponMap = view_as<StringMap>(handleValue);
                    if (weaponMap != null)
                    {
                        delete weaponMap;
                    }
                }
            }
            delete weaponSnap;
        }
        g_MatchWeaponTotals.Clear();
    }
}

static void WT_SanitizeServerTag(char[] tag, int maxlen)
{
    for (int i = 0; i < maxlen && tag[i] != '\0'; i++)
    {
        char c = tag[i];
        if (c >= 'A' && c <= 'Z')
        {
            tag[i] = c + 32;
            continue;
        }
        bool allowed = (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '-' || c == '_';
        if (!allowed)
        {
            tag[i] = '_';
        }
    }
}

static void WT_UpdateServerTag()
{
    g_sServerTag[0] = '\0';

    if (g_hServerTag != null)
    {
        g_hServerTag.GetString(g_sServerTag, sizeof(g_sServerTag));
        TrimString(g_sServerTag);
    }

    if (!g_sServerTag[0])
    {
        ConVar hostPort = FindConVar("hostport");
        if (hostPort != null)
        {
            int portVal = hostPort.IntValue;
            if (portVal > 0)
            {
                Format(g_sServerTag, sizeof(g_sServerTag), "p%d", portVal);
            }
        }
    }

    if (!g_sServerTag[0])
    {
        int randomPart = GetURandomInt() & 0xFFFF;
        Format(g_sServerTag, sizeof(g_sServerTag), "id%04X", randomPart);
    }

    WT_SanitizeServerTag(g_sServerTag, sizeof(g_sServerTag));
}

public void ConVarChanged_ServerTag(ConVar convar, const char[] oldValue, const char[] newValue)
{
    WT_UpdateServerTag();
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
    data[MatchStat_ClassMask] = stats.damageClassesMask;
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

    base[MatchStat_ClassMask] |= delta[MatchStat_ClassMask];

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
        for (MatchStatField field = MatchStat_Kills; field < MatchStat_Count; field++)
        {
            aggregate[field] = data[field];
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
    stats.damageClassesMask = data[MatchStat_ClassMask];
    stats.isAdmin = (data[MatchStat_IsAdmin] != 0);
    stats.loaded = true;
}

static void CancelLoadRetryTimer(int client)
{
    if (client <= 0 || client > MaxClients)
        return;

    Handle timer = g_LoadRetryTimer[client];
    if (timer == null)
        return;

    if (!IsValidHandle(timer))
    {
        g_LoadRetryTimer[client] = null;
        return;
    }

    CloseHandle(timer);
    g_LoadRetryTimer[client] = null;
}

static void ScheduleLoadRetry(int client)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return;

    if (g_LoadRetryTimer[client] != null)
        return;

    int userId = GetClientUserId(client);
    if (userId <= 0)
        return;

    g_LoadRetryTimer[client] = CreateTimer(5.0, Timer_RetryLoadStats, userId, TIMER_FLAG_NO_MAPCHANGE);
}

static bool WT_ShouldReconnect(const char[] error)
{
    if (!error[0])
        return false;

    if (StrContains(error, "server has gone away", false) != -1)
        return true;
    if (StrContains(error, "Lost connection", false) != -1)
        return true;
    if (StrContains(error, "server closed the connection", false) != -1)
        return true;

    return false;
}

static void WT_ScheduleReconnect()
{
    if (g_bReconnectScheduled)
        return;

    g_bReconnectScheduled = true;
    CreateTimer(1.0, Timer_AttemptReconnect, 0, TIMER_FLAG_NO_MAPCHANGE);
}

static void WT_HandleDatabaseError(const char[] error)
{
    if (!WT_ShouldReconnect(error))
        return;

    g_bDatabaseReady = false;
    WT_ScheduleReconnect();
}

static void WT_PruneExpiredLogRange(int minPlayers, int maxPlayers, int cutoff)
{
    if (cutoff <= 0 || !g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }
    char condition[96];
    if (maxPlayers < 0)
    {
        Format(condition, sizeof(condition), "player_count >= %d", minPlayers);
    }
    else
    {
        Format(condition, sizeof(condition), "player_count >= %d AND player_count < %d", minPlayers, maxPlayers);
    }
    char query[512];
    Format(query, sizeof(query), "DELETE lp FROM whaletracker_log_players lp JOIN whaletracker_logs l ON l.log_id = lp.log_id WHERE %s AND l.updated_at < %d", condition, cutoff);
    SQL_FastQuery(g_hDatabase, query);
    Format(query, sizeof(query), "DELETE FROM whaletracker_logs WHERE %s AND updated_at < %d", condition, cutoff);
    SQL_FastQuery(g_hDatabase, query);
}

static void WT_PruneExpiredLogs()
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }

    int now = GetTime();
    int cutoffDay = now - 86400;
    int cutoffWeek = now - (7 * 86400);
    int cutoffMonth = now - (30 * 86400);

    WT_PruneExpiredLogRange(0, 6, cutoffDay);
    WT_PruneExpiredLogRange(6, 14, cutoffWeek);
    WT_PruneExpiredLogRange(14, -1, cutoffMonth);
}

static int WT_CountActiveHumanPlayers()
{
    int count = 0;
    for (int i = 1; i <= MaxClients; i++)
    {
        if (IsClientInGame(i) && !IsFakeClient(i))
        {
            count++;
        }
    }
    return count;
}

static void BeginMatchTracking(bool forceStart = false)
{
    if (!g_bEnabled)
        return;

    WT_PruneExpiredLogs();

    if (g_sCurrentLogId[0])
        return;

    if (!forceStart && WT_CountActiveHumanPlayers() < 2)
        return;

    ResetMatchStorage();

    g_iMatchStartTime = GetTime();
    GetCurrentMap(g_sCurrentMap, sizeof(g_sCurrentMap));
    if (!g_sCurrentMap[0])
    {
        strcopy(g_sCurrentMap, sizeof(g_sCurrentMap), "unknown");
    }

    int randomPart = GetURandomInt() & 0xFFFF;
    if (g_sServerTag[0])
    {
        Format(g_sCurrentLogId, sizeof(g_sCurrentLogId), "%d_%04X_%s", g_iMatchStartTime, randomPart, g_sServerTag);
    }
    else
    {
        Format(g_sCurrentLogId, sizeof(g_sCurrentLogId), "%d_%04X", g_iMatchStartTime, randomPart);
    }

    g_bMatchFinalized = false;

    int now = g_iMatchStartTime > 0 ? g_iMatchStartTime : GetTime();
    InsertMatchLogRecord(now, 0, 0, false);
}

static void InsertPlayerLogRecord(const int data[MATCH_STAT_COUNT], const char[] steamId, const char[] name, int timestamp, bool forceSync)
{
    if (!g_sCurrentLogId[0] || steamId[0] == '\0')
        return;

    char escapedName[256];
    EscapeSqlString(name, escapedName, sizeof(escapedName));

    WeaponAggregate topWeapons[WT_MAX_TOP_WEAPONS];
    for (int i = 0; i < WT_MAX_TOP_WEAPONS; i++)
    {
        topWeapons[i].name[0] = '\0';
        topWeapons[i].shots = 0;
        topWeapons[i].hits = 0;
        topWeapons[i].damage = 0;
        topWeapons[i].defIndex = 0;
    }
    int topCount = CollectTopWeaponsForSteamId(steamId, topWeapons, WT_MAX_TOP_WEAPONS);

    char weaponNameEscaped[WT_MAX_TOP_WEAPONS][128];
    for (int i = 0; i < WT_MAX_TOP_WEAPONS; i++)
    {
        if (i < topCount && topWeapons[i].name[0])
        {
            EscapeSqlString(topWeapons[i].name, weaponNameEscaped[i], sizeof(weaponNameEscaped[]));
        }
        else
        {
            weaponNameEscaped[i][0] = '\0';
            topWeapons[i].shots = 0;
            topWeapons[i].hits = 0;
            topWeapons[i].damage = 0;
            topWeapons[i].defIndex = 0;
        }
    }

    char query[SAVE_QUERY_MAXLEN];
    Format(query, sizeof(query),
        "INSERT INTO whaletracker_log_players "
        ... "(log_id, steamid, personaname, kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, total_ubers, playtime, medic_drops, uber_drops, airshots, shots, hits, best_streak, best_headshots_life, best_backstabs_life, best_score_life, best_kills_life, best_assists_life, best_ubers_life, "
        ... "weapon1_name, weapon1_shots, weapon1_hits, weapon1_damage, weapon1_defindex, "
        ... "weapon2_name, weapon2_shots, weapon2_hits, weapon2_damage, weapon2_defindex, "
        ... "weapon3_name, weapon3_shots, weapon3_hits, weapon3_damage, weapon3_defindex, "
        ... "weapon4_name, weapon4_shots, weapon4_hits, weapon4_damage, weapon4_defindex, "
        ... "weapon5_name, weapon5_shots, weapon5_hits, weapon5_damage, weapon5_defindex, "
        ... "weapon6_name, weapon6_shots, weapon6_hits, weapon6_damage, weapon6_defindex, "
        ... "classes_mask, is_admin, last_updated) "
        ... "VALUES ('%s', '%s', '%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, "
        ... "'%s', %d, %d, %d, %d, "
        ... "'%s', %d, %d, %d, %d, "
        ... "'%s', %d, %d, %d, %d, "
        ... "'%s', %d, %d, %d, %d, "
        ... "'%s', %d, %d, %d, %d, "
        ... "'%s', %d, %d, %d, %d, "
        ... "%d, %d, %d) "
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
        ... "shots = VALUES(shots), "
        ... "hits = VALUES(hits), "
        ... "best_streak = VALUES(best_streak), "
        ... "best_headshots_life = VALUES(best_headshots_life), "
        ... "best_backstabs_life = VALUES(best_backstabs_life), "
        ... "best_score_life = VALUES(best_score_life), "
        ... "best_kills_life = VALUES(best_kills_life), "
        ... "best_assists_life = VALUES(best_assists_life), "
        ... "best_ubers_life = VALUES(best_ubers_life), "
        ... "weapon1_name = VALUES(weapon1_name), "
        ... "weapon1_shots = VALUES(weapon1_shots), "
        ... "weapon1_hits = VALUES(weapon1_hits), "
        ... "weapon1_damage = VALUES(weapon1_damage), "
        ... "weapon1_defindex = VALUES(weapon1_defindex), "
        ... "weapon2_name = VALUES(weapon2_name), "
        ... "weapon2_shots = VALUES(weapon2_shots), "
        ... "weapon2_hits = VALUES(weapon2_hits), "
        ... "weapon2_damage = VALUES(weapon2_damage), "
        ... "weapon2_defindex = VALUES(weapon2_defindex), "
        ... "weapon3_name = VALUES(weapon3_name), "
        ... "weapon3_shots = VALUES(weapon3_shots), "
        ... "weapon3_hits = VALUES(weapon3_hits), "
        ... "weapon3_damage = VALUES(weapon3_damage), "
        ... "weapon3_defindex = VALUES(weapon3_defindex), "
        ... "weapon4_name = VALUES(weapon4_name), "
        ... "weapon4_shots = VALUES(weapon4_shots), "
        ... "weapon4_hits = VALUES(weapon4_hits), "
        ... "weapon4_damage = VALUES(weapon4_damage), "
        ... "weapon4_defindex = VALUES(weapon4_defindex), "
        ... "weapon5_name = VALUES(weapon5_name), "
        ... "weapon5_shots = VALUES(weapon5_shots), "
        ... "weapon5_hits = VALUES(weapon5_hits), "
        ... "weapon5_damage = VALUES(weapon5_damage), "
        ... "weapon5_defindex = VALUES(weapon5_defindex), "
        ... "weapon6_name = VALUES(weapon6_name), "
        ... "weapon6_shots = VALUES(weapon6_shots), "
        ... "weapon6_hits = VALUES(weapon6_hits), "
        ... "weapon6_damage = VALUES(weapon6_damage), "
        ... "weapon6_defindex = VALUES(weapon6_defindex), "
        ... "classes_mask = VALUES(classes_mask), "
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
        data[MatchStat_Shots],
        data[MatchStat_Hits],
        data[MatchStat_BestStreak],
        data[MatchStat_BestHeadshotsLife],
        data[MatchStat_BestBackstabsLife],
        data[MatchStat_BestScoreLife],
        data[MatchStat_BestKillsLife],
        data[MatchStat_BestAssistsLife],
        data[MatchStat_BestUbersLife],
        weaponNameEscaped[0],
        topWeapons[0].shots,
        topWeapons[0].hits,
        topWeapons[0].damage,
        topWeapons[0].defIndex,
        weaponNameEscaped[1],
        topWeapons[1].shots,
        topWeapons[1].hits,
        topWeapons[1].damage,
        topWeapons[1].defIndex,
        weaponNameEscaped[2],
        topWeapons[2].shots,
        topWeapons[2].hits,
        topWeapons[2].damage,
        topWeapons[2].defIndex,
        weaponNameEscaped[3],
        topWeapons[3].shots,
        topWeapons[3].hits,
        topWeapons[3].damage,
        topWeapons[3].defIndex,
        weaponNameEscaped[4],
        topWeapons[4].shots,
        topWeapons[4].hits,
        topWeapons[4].damage,
        topWeapons[4].defIndex,
        weaponNameEscaped[5],
        topWeapons[5].shots,
        topWeapons[5].hits,
        topWeapons[5].damage,
        topWeapons[5].defIndex,
        data[MatchStat_ClassMask],
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
        RefreshBestWeaponAccuracy(g_MapStats[i]);
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

    if (snap.Length < 2)
    {
        delete snap;
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
{
    if (!GetClientAuthId(client, AuthId_Steam2, steamId, sizeof(steamId)))
    {
        return;
    }
}

    strcopy(g_Stats[client].steamId, sizeof(g_Stats[client].steamId), steamId);
    strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), steamId);
}

static void HookClientDamage(int client)
{
    if (!IsValidClient(client) || !IsClientInGame(client))
        return;
    SDKHook(client, SDKHook_OnTakeDamage, OnPlayerTakeDamage);
}

static void NormalizeWeaponKey(const char[] weapon, char[] key, int maxlen)
{
    strcopy(key, maxlen, weapon);
    StrToLower(key);
}

static void RecordShot(WhaleStats stats, const char[] weapon)
{

    if (!weapon[0])
        return;

    stats.totalShots++;

    EnsureWeaponMaps(stats);

    char key[WT_MAX_WEAPON_NAME];
    NormalizeWeaponKey(weapon, key, sizeof(key));

    int shots = 0;
    stats.weaponShots.GetValue(key, shots);
    stats.weaponShots.SetValue(key, shots + 1);
    stats.weaponNames.SetString(key, weapon);
}

static void RecordHit(WhaleStats stats, const char[] weapon)
{

    if (!weapon[0])
        return;

    stats.totalHits++;

    EnsureWeaponMaps(stats);

    char key[WT_MAX_WEAPON_NAME];
    NormalizeWeaponKey(weapon, key, sizeof(key));

    int hits = 0;
    stats.weaponHits.GetValue(key, hits);
    stats.weaponHits.SetValue(key, hits + 1);
    stats.weaponNames.SetString(key, weapon);
}

static void RecordWeaponDamage(WhaleStats stats, const char[] weapon, int damage)
{

    if (!weapon[0] || damage <= 0)
        return;

    EnsureWeaponMaps(stats);

    char key[WT_MAX_WEAPON_NAME];
    NormalizeWeaponKey(weapon, key, sizeof(key));

    int total = 0;
    stats.weaponDamage.GetValue(key, total);
    stats.weaponDamage.SetValue(key, total + damage);
    stats.weaponNames.SetString(key, weapon);
}

static bool GetClientSteamIdSafe(int client, char[] buffer, int maxlen)
{
    if (!IsValidClient(client) || IsFakeClient(client) || maxlen <= 0)
        return false;

    if (g_MapStats[client].steamId[0] != '\0')
    {
        strcopy(buffer, maxlen, g_MapStats[client].steamId);
        return true;
    }

    if (!GetClientAuthId(client, AuthId_SteamID64, buffer, maxlen))
    {
        return false;
    }

    strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), buffer);
    strcopy(g_Stats[client].steamId, sizeof(g_Stats[client].steamId), buffer);
    return true;
}

static StringMap EnsureMatchWeaponMap(const char[] steamId)
{
    if (!steamId[0])
        return null;

    EnsureMatchStorage();

    if (g_MatchWeaponTotals == null)
    {
        g_MatchWeaponTotals = new StringMap();
    }

    any handleValue;
    if (!g_MatchWeaponTotals.GetValue(steamId, handleValue))
    {
        StringMap newMap = new StringMap();
        g_MatchWeaponTotals.SetValue(steamId, newMap);
        return newMap;
    }

    return view_as<StringMap>(handleValue);
}

static void MatchWeapons_UpdateTotalsForClient(int client, const char[] weapon, int defIndex, int shotsDelta, int hitsDelta, int damageDelta)
{
    char steamId[STEAMID64_LEN];
    if (!GetClientSteamIdSafe(client, steamId, sizeof(steamId)))
        return;

    StringMap weaponMap = EnsureMatchWeaponMap(steamId);
    if (weaponMap == null)
        return;

    char key[WT_MAX_WEAPON_NAME];
    NormalizeWeaponKey(weapon, key, sizeof(key));

    WeaponAggregate aggregate;
    bool haveExisting = weaponMap.GetArray(key, aggregate, sizeof(aggregate));
    if (!haveExisting)
    {
        aggregate.name[0] = '\0';
        aggregate.shots = 0;
        aggregate.hits = 0;
        aggregate.damage = 0;
        aggregate.defIndex = 0;
    }

    if (!aggregate.name[0] && weapon[0])
    {
        strcopy(aggregate.name, sizeof(aggregate.name), weapon);
    }

    if (defIndex > 0)
    {
        aggregate.defIndex = defIndex;
    }

    aggregate.shots += shotsDelta;
    aggregate.hits += hitsDelta;
    aggregate.damage += damageDelta;

    if (aggregate.shots < 0)
        aggregate.shots = 0;
    if (aggregate.hits < 0)
        aggregate.hits = 0;
    if (aggregate.damage < 0)
        aggregate.damage = 0;

    weaponMap.SetArray(key, aggregate, sizeof(aggregate));
}

static void RecordShotForClient(int client, const char[] weapon, int defIndex)
{
    if (!IsValidClient(client) || IsFakeClient(client) || !weapon[0])
        return;

    int classIndex = view_as<int>(TF2_GetPlayerClass(client));

    RecordShot(g_Stats[client], weapon);
    RecordShot(g_MapStats[client], weapon);
    if (classIndex >= WT_CLASS_OFFSET && classIndex < (WT_CLASS_OFFSET + WT_CLASS_COUNT))
    {
        g_Stats[client].classShots[classIndex]++;
        g_MapStats[client].classShots[classIndex]++;
    }
    MatchWeapons_UpdateTotalsForClient(client, weapon, defIndex, 1, 0, 0);
}

static void RecordHitForClient(int client, const char[] weapon, int defIndex)
{
    if (!IsValidClient(client) || IsFakeClient(client) || !weapon[0])
        return;

    int classIndex = view_as<int>(TF2_GetPlayerClass(client));

    RecordHit(g_Stats[client], weapon);
    RecordHit(g_MapStats[client], weapon);
    if (classIndex >= WT_CLASS_OFFSET && classIndex < (WT_CLASS_OFFSET + WT_CLASS_COUNT))
    {
        g_Stats[client].classHits[classIndex]++;
        g_MapStats[client].classHits[classIndex]++;
    }
    MatchWeapons_UpdateTotalsForClient(client, weapon, defIndex, 0, 1, 0);
}

static void RecordWeaponDamageForClient(int client, const char[] weapon, int defIndex, int damage)
{
    if (!IsValidClient(client) || IsFakeClient(client) || !weapon[0] || damage <= 0)
        return;

    RecordWeaponDamage(g_Stats[client], weapon, damage);
    RecordWeaponDamage(g_MapStats[client], weapon, damage);
    MatchWeapons_UpdateTotalsForClient(client, weapon, defIndex, 0, 0, damage);
}

static void MatchAirshots_AddForClient(int client, TFClassType classType)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return;

    if (classType != TFClass_Soldier && classType != TFClass_DemoMan && classType != TFClass_Medic)
    {
        return;
    }

    char steamId[STEAMID64_LEN];
    if (!GetClientSteamIdSafe(client, steamId, sizeof(steamId)))
        return;

    g_Stats[client].totalAirshots++;
    g_MapStats[client].totalAirshots++;
}

static int CollectTopWeaponsForSteamId(const char[] steamId, WeaponAggregate output[WT_MAX_TOP_WEAPONS], int maxEntries)
{
    if (!steamId[0] || maxEntries <= 0)
        return 0;

    if (maxEntries > WT_MAX_TOP_WEAPONS)
    {
        maxEntries = WT_MAX_TOP_WEAPONS;
    }

    if (g_MatchWeaponTotals == null)
        return 0;

    any handleValue;
    if (!g_MatchWeaponTotals.GetValue(steamId, handleValue))
        return 0;

    StringMap weaponMap = view_as<StringMap>(handleValue);
    if (weaponMap == null)
        return 0;

    StringMapSnapshot snap = weaponMap.Snapshot();
    if (snap == null)
        return 0;

    WeaponAggregate top[WT_MAX_TOP_WEAPONS];
    int count = 0;

    char key[WT_MAX_WEAPON_NAME];
    WeaponAggregate aggregate;

    for (int i = 0; i < snap.Length; i++)
    {
        snap.GetKey(i, key, sizeof(key));
        if (!weaponMap.GetArray(key, aggregate, sizeof(aggregate)))
            continue;

        bool hasActivity = (aggregate.damage > 0) || (aggregate.hits > 0) || (aggregate.shots > 0);
        if (!hasActivity)
            continue;

        if (!aggregate.name[0] || StrEqual(aggregate.name, "Unknown", false))
        {
            strcopy(aggregate.name, sizeof(aggregate.name), key);
        }

        if (count < maxEntries)
        {
            top[count] = aggregate;
            count++;

            for (int j = count - 1; j > 0; j--)
            {
                if (top[j].damage > top[j - 1].damage)
                {
                    WeaponAggregate temp;
                    temp = top[j];
                    top[j] = top[j - 1];
                    top[j - 1] = temp;
                }
                else
                {
                    break;
                }
            }
        }
        else if (aggregate.damage > top[count - 1].damage)
        {
            top[count - 1] = aggregate;
            for (int j = count - 1; j > 0; j--)
            {
                if (top[j].damage > top[j - 1].damage)
                {
                    WeaponAggregate temp;
                    temp = top[j];
                    top[j] = top[j - 1];
                    top[j - 1] = temp;
                }
                else
                {
                    break;
                }
            }
        }
    }

    delete snap;

    for (int i = 0; i < count && i < maxEntries; i++)
    {
        output[i] = top[i];
    }

    return count;
}

static void RefreshBestWeaponAccuracy(WhaleStats stats)
{

    stats.bestWeaponAccuracy = 0.0;
    stats.bestWeapon[0] = '\0';

    if (stats.weaponShots == null || stats.weaponHits == null)
        return;

    StringMapSnapshot snap = stats.weaponShots.Snapshot();
    if (snap == null)
        return;

    char weapon[64];
    int shots;
    int hits;

    for (int i = 0; i < snap.Length; i++)
    {
        snap.GetKey(i, weapon, sizeof(weapon));
        if (!stats.weaponShots.GetValue(weapon, shots) || shots <= 0)
            continue;

        hits = 0;
        stats.weaponHits.GetValue(weapon, hits);

        float accuracy = float(hits) / float(shots);
        if (accuracy > stats.bestWeaponAccuracy)
        {
            stats.bestWeaponAccuracy = accuracy;
            char display[WT_MAX_WEAPON_NAME];
            if (stats.weaponNames != null && stats.weaponNames.GetString(weapon, display, sizeof(display)))
            {
                strcopy(stats.bestWeapon, sizeof(stats.bestWeapon), display);
            }
            else
            {
                strcopy(stats.bestWeapon, sizeof(stats.bestWeapon), weapon);
            }
        }
    }

    delete snap;
}

static void WT_GetBestClassAccuracy(const WhaleStats stats, int &classId, float &accuracy, int &shots, int &hits)
{
    classId = 0;
    accuracy = 0.0;
    shots = 0;
    hits = 0;

    TFClassType preferred = view_as<TFClassType>(stats.favoriteClass);
    if (preferred >= TFClass_Scout && preferred <= TFClass_Engineer)
    {
        classId = view_as<int>(preferred);
        shots = stats.classShots[classId];
        hits = stats.classHits[classId];
        if (shots > 0)
        {
            accuracy = (float(hits) / float(shots)) * 100.0;
        }
        else
        {
            accuracy = 0.0;
        }
        return;
    }

    for (int classIndex = WT_CLASS_OFFSET; classIndex < WT_CLASS_OFFSET + WT_CLASS_COUNT; classIndex++)
    {
        int classShots = stats.classShots[classIndex];
        if (classShots <= 0)
        {
            continue;
        }

        int classHits = stats.classHits[classIndex];
        float classAccuracy = (float(classHits) / float(classShots)) * 100.0;

        if (classAccuracy > accuracy)
        {
            accuracy = classAccuracy;
            classId = classIndex;
            shots = classShots;
            hits = classHits;
        }
    }
}

static int WT_GetTopWeaponAccuracy(const WhaleStats stats, WeaponAccuracyEntry output[WT_MAX_TOP_WEAPONS])
{
    if (stats.weaponShots == null || stats.weaponHits == null)
    {
        return 0;
    }

    StringMapSnapshot snap = stats.weaponShots.Snapshot();
    if (snap == null)
    {
        return 0;
    }

    WeaponAccuracyEntry top[WT_MAX_TOP_WEAPONS];
    int topCount = 0;

    char key[WT_MAX_WEAPON_NAME];

    for (int i = 0; i < snap.Length; i++)
    {
        snap.GetKey(i, key, sizeof(key));

        int shots = 0;
        if (!stats.weaponShots.GetValue(key, shots) || shots <= 0)
        {
            continue;
        }

        int hits = 0;
        stats.weaponHits.GetValue(key, hits);

        float accuracy = (float(hits) / float(shots)) * 100.0;

        char display[WT_MAX_WEAPON_NAME];
        if (stats.weaponNames != null && stats.weaponNames.GetString(key, display, sizeof(display)))
        {
            // display already filled
        }
        else
        {
            strcopy(display, sizeof(display), key);
        }

        WeaponAccuracyEntry candidate;
        strcopy(candidate.name, sizeof(candidate.name), display);
        candidate.accuracy = accuracy;
        candidate.shots = shots;
        candidate.hits = hits;

        int insertPos = topCount;
        for (int j = 0; j < topCount; j++)
        {
            if (candidate.accuracy > top[j].accuracy || (FloatCompare(candidate.accuracy, top[j].accuracy) == 0 && candidate.hits > top[j].hits))
            {
                insertPos = j;
                break;
            }
        }

        if (insertPos < WT_MAX_TOP_WEAPONS)
        {
            int shiftEnd = topCount;
            if (shiftEnd >= WT_MAX_TOP_WEAPONS)
            {
                shiftEnd = WT_MAX_TOP_WEAPONS - 1;
            }

            for (int j = shiftEnd; j > insertPos; j--)
            {
                top[j] = top[j - 1];
            }
            top[insertPos] = candidate;
            if (topCount < WT_MAX_TOP_WEAPONS)
            {
                topCount++;
            }
        }
    }

    delete snap;

    int count = 0;
    for (int i = 0; i < topCount && i < WT_MAX_TOP_WEAPONS; i++)
    {
        if (top[i].shots <= 0)
        {
            continue;
        }
        output[count++] = top[i];
    }

    return count;
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

static void ApplyKillStats(WhaleStats stats, bool headshot, bool backstab, bool medicDrop, bool airshot)
{
    stats.kills++;

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
    if (headshot)
    {
        stats.totalHeadshots++;
        stats.currentHeadshotsLife++;
        if (stats.currentHeadshotsLife > stats.bestHeadshotsLife)
        {
            stats.bestHeadshotsLife = stats.currentHeadshotsLife;
        }
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
    if (airshot)
    {
        stats.totalAirshots++;
    }
}

static void ApplyAssistStats(WhaleStats stats)
{
    stats.totalAssists++;

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

        WT_HandleDatabaseError(error);

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
    author = "Codex",
    description = "The Youkai Pound's player stats system",
    version = "1.0.0",
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

    EnsureOnlineCache();
    ResetOnlineCache();

    g_CvarDatabase = CreateConVar("sm_whaletracker_database", DB_CONFIG_DEFAULT, "Databases.cfg entry to use for WhaleTracker");
    g_CvarDatabase.GetString(g_sDatabaseConfig, sizeof(g_sDatabaseConfig));

    g_hEnabled = CreateConVar(g_sEnabled, "1", "Enable WhaleTracker functionality (0 = off, 1 = on)", FCVAR_NONE, true, 0.0, true, 1.0);
    g_hEnabled.AddChangeHook(ConVarChanged_Enabled);
    g_bEnabled = g_hEnabled.BoolValue;

    g_hServerTag = CreateConVar("sm_whaletracker_servertag", "", "Optional identifier appended to WhaleTracker log IDs", FCVAR_NONE);
    g_hServerTag.AddChangeHook(ConVarChanged_ServerTag);

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
    RegConsoleCmd("sm_fav", Command_SetFavoriteClass, "Set your WhaleTracker favorite class.");
    RegConsoleCmd("sm_favorite", Command_SetFavoriteClass, "Set your WhaleTracker favorite class.");
    RegAdminCmd("sm_startlog", Command_StartLog, ADMFLAG_GENERIC, "Force start a new WhaleTracker log");

    EnsureMatchStorage();
    ResetMatchStorage();
    g_sCurrentLogId[0] = '\0';

    WT_UpdateServerTag();

    if (g_hGameModeCvar == null)
    {
        g_hGameModeCvar = FindConVar("sm_gamemode");
    }

    WhaleTracker_SQLConnect();

    if (g_hOnlineTimer != null)
    {
        CloseHandle(g_hOnlineTimer);
    }
    ClearOnlineStats();

    Accuracy_Init();

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

public void OnConfigsExecuted()
{
    g_hOnlineTimer = CreateTimer(2.0, Timer_UpdateOnlineStats, _, TIMER_REPEAT | TIMER_FLAG_NO_MAPCHANGE);

    if (g_bEnabled)
    {
        if (!g_sCurrentLogId[0])
        {
            BeginMatchTracking();
        }
    }
    else
    {
        ResetMatchStorage();
    }
}

public void OnMapStart()
{
    if (g_bEnabled)
    {
        FinalizeCurrentMatch(false);
        BeginMatchTracking();
    }
    else
    {
        ResetMatchStorage();
        g_sCurrentLogId[0] = '\0';
    }

    if (!RepublishOnlineCache())
    {
        ClearOnlineStats();
    }
    for (int i = 1; i <= MaxClients; i++)
    {
        ResetMapStats(i);
        if (IsClientInGame(i))
        {
            g_MapStats[i].connectTime = GetEngineTime();
        }
        g_KillSaveCounter[i] = 0;
        Accuracy_ResetClientState(i);
    }
}

public void OnMapEnd()
{
    if (!g_bEnabled)
        return;

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

    if (g_OnlineCache != null)
    {
        delete g_OnlineCache;
        g_OnlineCache = null;
    }
    g_OnlineCacheTimestamp = 0;

    if (g_SaveQueue != null)
    {
        delete g_SaveQueue;
        g_SaveQueue = null;
    }

    if (g_ShotTypeLookup != null)
    {
        delete g_ShotTypeLookup;
        g_ShotTypeLookup = null;
    }

    g_hVisibleMaxPlayers = null;

    for (int i = 1; i <= MaxClients; i++)
    {
        if (IsClientInGame(i) && !IsFakeClient(i))
        {
            SDKUnhook(i, SDKHook_OnTakeDamage, OnPlayerTakeDamage);
        }

        DeleteWeaponMaps(g_Stats[i]);
        DeleteWeaponMaps(g_MapStats[i]);

        if (g_RocketShotTimer[i] != null)
        {
            CloseHandle(g_RocketShotTimer[i]);
            g_RocketShotTimer[i] = null;
        }
        CancelLoadRetryTimer(i);
    }
}

public void OnClientPutInServer(int client)
{
    if (IsFakeClient(client))
        return;

    CancelLoadRetryTimer(client);

    ResetRuntimeStats(client);
    g_Stats[client].connectTime = GetEngineTime();
    g_MapStats[client].connectTime = GetEngineTime();
    g_KillSaveCounter[client] = 0;

    ResetMapStats(client);
    EnsureMatchStorage();
    EnsureClientSteamId(client);
    Accuracy_ResetClientState(client);

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

    HookClientDamage(client);
    UpdateAdminStatus(client);
    TouchClientLastSeen(client);

    if (AreClientCookiesCached(client))
    {
        LoadClientStats(client);
    }

    BeginMatchTracking();
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
        SDKUnhook(client, SDKHook_OnTakeDamage, OnPlayerTakeDamage);
    }

    CancelLoadRetryTimer(client);

    AccumulatePlaytime(client);
    SaveClientStats(client, true);
    RemoveOnlineStats(client);
    ResetAllStats(client);
    g_KillSaveCounter[client] = 0;
    Accuracy_ResetClientState(client);
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

public Action Timer_RetryLoadStats(Handle timer, any userId)
{
    int client = GetClientOfUserId(userId);
    if (client <= 0 || client > MaxClients)
    {
        return Plugin_Stop;
    }

    g_LoadRetryTimer[client] = null;

    if (!IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Stop;
    }

    if (!AreClientCookiesCached(client))
    {
        ScheduleLoadRetry(client);
        return Plugin_Stop;
    }

    LoadClientStats(client);
    return Plugin_Stop;
}

public Action Timer_AttemptReconnect(Handle timer, any data)
{
    g_bReconnectScheduled = false;
    WhaleTracker_SQLConnect();
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
        WT_ScheduleReconnect();
        return;
    }

    g_hDatabase = db;
    g_bDatabaseReady = true;

    if (!g_hDatabase.SetCharset("utf8mb4"))
    {
        LogError("[WhaleTracker] Failed to set database charset to utf8mb4, names may be truncated.");
    }

    char query[SAVE_QUERY_MAXLEN];
    static const char sWhaleTableColumns[][] =
    {
        "`steamid` VARCHAR(32) PRIMARY KEY",
        "`personaname` VARCHAR(128) DEFAULT ''",
        "`first_seen` INTEGER",
        "`kills` INTEGER DEFAULT 0",
        "`deaths` INTEGER DEFAULT 0",
        "`shots` INTEGER DEFAULT 0",
        "`hits` INTEGER DEFAULT 0",
        "`shots_scout` INTEGER DEFAULT 0",
        "`hits_scout` INTEGER DEFAULT 0",
        "`shots_sniper` INTEGER DEFAULT 0",
        "`hits_sniper` INTEGER DEFAULT 0",
        "`shots_soldier` INTEGER DEFAULT 0",
        "`hits_soldier` INTEGER DEFAULT 0",
        "`shots_demoman` INTEGER DEFAULT 0",
        "`hits_demoman` INTEGER DEFAULT 0",
        "`shots_medic` INTEGER DEFAULT 0",
        "`hits_medic` INTEGER DEFAULT 0",
        "`shots_heavy` INTEGER DEFAULT 0",
        "`hits_heavy` INTEGER DEFAULT 0",
        "`shots_pyro` INTEGER DEFAULT 0",
        "`hits_pyro` INTEGER DEFAULT 0",
        "`shots_spy` INTEGER DEFAULT 0",
        "`hits_spy` INTEGER DEFAULT 0",
        "`shots_engineer` INTEGER DEFAULT 0",
        "`hits_engineer` INTEGER DEFAULT 0",
        "`healing` INTEGER DEFAULT 0",
        "`total_ubers` INTEGER DEFAULT 0",
        "`best_ubers_life` INTEGER DEFAULT 0",
        "`medic_drops` INTEGER DEFAULT 0",
        "`uber_drops` INTEGER DEFAULT 0",
        "`airshots` INTEGER DEFAULT 0",
        "`headshots` INTEGER DEFAULT 0",
        "`backstabs` INTEGER DEFAULT 0",
        "`best_headshots_life` INTEGER DEFAULT 0",
        "`best_backstabs_life` INTEGER DEFAULT 0",
        "`best_kills_life` INTEGER DEFAULT 0",
        "`best_killstreak` INTEGER DEFAULT 0",
        "`best_score_life` INTEGER DEFAULT 0",
        "`assists` INTEGER DEFAULT 0",
        "`best_assists_life` INTEGER DEFAULT 0",
        "`playtime` INTEGER DEFAULT 0",
        "`best_weapon` VARCHAR(64) DEFAULT ''",
        "`best_weapon_accuracy` FLOAT DEFAULT 0",
        "`damage_dealt` INTEGER DEFAULT 0",
        "`damage_taken` INTEGER DEFAULT 0",
        "`last_seen` INTEGER DEFAULT 0",
        "`favorite_class` TINYINT DEFAULT 0",
        "`is_admin` TINYINT(1) DEFAULT 0"
    };

    query[0] = '\0';
    StrCat(query, sizeof(query), "CREATE TABLE IF NOT EXISTS `whaletracker` (");
    for (int i = 0; i < sizeof(sWhaleTableColumns); i++)
    {
        if (i > 0)
        {
            StrCat(query, sizeof(query), ", ");
        }
        StrCat(query, sizeof(query), sWhaleTableColumns[i]);
    }
    StrCat(query, sizeof(query), ")");
    g_hDatabase.Query(WhaleTracker_CreateTable, query);

    static const char sOnlineTableColumns[][] =
    {
        "`steamid` VARCHAR(32) PRIMARY KEY",
        "`personaname` VARCHAR(128) DEFAULT ''",
        "`class` TINYINT DEFAULT 0",
        "`team` TINYINT DEFAULT 0",
        "`alive` TINYINT DEFAULT 0",
        "`is_spectator` TINYINT DEFAULT 0",
        "`kills` INTEGER DEFAULT 0",
        "`deaths` INTEGER DEFAULT 0",
        "`assists` INTEGER DEFAULT 0",
        "`damage` INTEGER DEFAULT 0",
        "`damage_taken` INTEGER DEFAULT 0",
        "`healing` INTEGER DEFAULT 0",
        "`headshots` INTEGER DEFAULT 0",
        "`backstabs` INTEGER DEFAULT 0",
        "`shots` INTEGER DEFAULT 0",
        "`hits` INTEGER DEFAULT 0",
        "`best_class_id` TINYINT DEFAULT 0",
        "`best_class_accuracy` FLOAT DEFAULT 0",
        "`best_class_shots` INTEGER DEFAULT 0",
        "`best_class_hits` INTEGER DEFAULT 0",
        "`weapon1_name` VARCHAR(64) DEFAULT ''",
        "`weapon1_accuracy` FLOAT DEFAULT 0",
        "`weapon1_shots` INTEGER DEFAULT 0",
        "`weapon1_hits` INTEGER DEFAULT 0",
        "`weapon2_name` VARCHAR(64) DEFAULT ''",
        "`weapon2_accuracy` FLOAT DEFAULT 0",
        "`weapon2_shots` INTEGER DEFAULT 0",
        "`weapon2_hits` INTEGER DEFAULT 0",
        "`weapon3_name` VARCHAR(64) DEFAULT ''",
        "`weapon3_accuracy` FLOAT DEFAULT 0",
        "`weapon3_shots` INTEGER DEFAULT 0",
        "`weapon3_hits` INTEGER DEFAULT 0",
        "`weapon4_name` VARCHAR(64) DEFAULT ''",
        "`weapon4_accuracy` FLOAT DEFAULT 0",
        "`weapon4_shots` INTEGER DEFAULT 0",
        "`weapon4_hits` INTEGER DEFAULT 0",
        "`weapon5_name` VARCHAR(64) DEFAULT ''",
        "`weapon5_accuracy` FLOAT DEFAULT 0",
        "`weapon5_shots` INTEGER DEFAULT 0",
        "`weapon5_hits` INTEGER DEFAULT 0",
        "`weapon6_name` VARCHAR(64) DEFAULT ''",
        "`weapon6_accuracy` FLOAT DEFAULT 0",
        "`weapon6_shots` INTEGER DEFAULT 0",
        "`weapon6_hits` INTEGER DEFAULT 0",
        "`playtime` INTEGER DEFAULT 0",
        "`total_ubers` INTEGER DEFAULT 0",
        "`best_streak` INTEGER DEFAULT 0",
        "`classes_mask` INTEGER DEFAULT 0",
        "`visible_max` INTEGER DEFAULT 0",
        "`time_connected` INTEGER DEFAULT 0",
        "`last_update` INTEGER DEFAULT 0"
    };

    query[0] = '\0';
    StrCat(query, sizeof(query), "CREATE TABLE IF NOT EXISTS `whaletracker_online` (");
    for (int i = 0; i < sizeof(sOnlineTableColumns); i++)
    {
        if (i > 0)
        {
            StrCat(query, sizeof(query), ", ");
        }
        StrCat(query, sizeof(query), sOnlineTableColumns[i]);
    }
    StrCat(query, sizeof(query), ")");
    g_hDatabase.Query(WhaleTracker_CreateOnlineTable, query);

    static const char sLogTableColumns[][] =
    {
        "`log_id` VARCHAR(64) PRIMARY KEY",
        "`map` VARCHAR(64) DEFAULT ''",
        "`gamemode` VARCHAR(64) DEFAULT 'Unknown'",
        "`started_at` INTEGER DEFAULT 0",
        "`ended_at` INTEGER DEFAULT 0",
        "`duration` INTEGER DEFAULT 0",
        "`player_count` INTEGER DEFAULT 0",
        "`created_at` INTEGER DEFAULT 0",
        "`updated_at` INTEGER DEFAULT 0"
    };

    query[0] = '\0';
    StrCat(query, sizeof(query), "CREATE TABLE IF NOT EXISTS `whaletracker_logs` (");
    for (int i = 0; i < sizeof(sLogTableColumns); i++)
    {
        if (i > 0)
        {
            StrCat(query, sizeof(query), ", ");
        }
        StrCat(query, sizeof(query), sLogTableColumns[i]);
    }
    StrCat(query, sizeof(query), ")");
    g_hDatabase.Query(WhaleTracker_CreateLogsTable, query);

    static const char sLogPlayersColumns[][] =
    {
        "`log_id` VARCHAR(64) NOT NULL",
        "`steamid` VARCHAR(32) NOT NULL",
        "`personaname` VARCHAR(128) DEFAULT ''",
        "`kills` INTEGER DEFAULT 0",
        "`deaths` INTEGER DEFAULT 0",
        "`assists` INTEGER DEFAULT 0",
        "`damage` INTEGER DEFAULT 0",
        "`damage_taken` INTEGER DEFAULT 0",
        "`healing` INTEGER DEFAULT 0",
        "`headshots` INTEGER DEFAULT 0",
        "`backstabs` INTEGER DEFAULT 0",
        "`total_ubers` INTEGER DEFAULT 0",
        "`playtime` INTEGER DEFAULT 0",
        "`medic_drops` INTEGER DEFAULT 0",
        "`uber_drops` INTEGER DEFAULT 0",
        "`airshots` INTEGER DEFAULT 0",
        "`shots` INTEGER DEFAULT 0",
        "`hits` INTEGER DEFAULT 0",
        "`best_streak` INTEGER DEFAULT 0",
        "`best_headshots_life` INTEGER DEFAULT 0",
        "`best_backstabs_life` INTEGER DEFAULT 0",
        "`best_score_life` INTEGER DEFAULT 0",
        "`best_kills_life` INTEGER DEFAULT 0",
        "`best_assists_life` INTEGER DEFAULT 0",
        "`best_ubers_life` INTEGER DEFAULT 0",
        "`weapon1_name` VARCHAR(128) DEFAULT ''",
        "`weapon1_shots` INTEGER DEFAULT 0",
        "`weapon1_hits` INTEGER DEFAULT 0",
        "`weapon1_damage` INTEGER DEFAULT 0",
        "`weapon1_defindex` INTEGER DEFAULT 0",
        "`weapon2_name` VARCHAR(128) DEFAULT ''",
        "`weapon2_shots` INTEGER DEFAULT 0",
        "`weapon2_hits` INTEGER DEFAULT 0",
        "`weapon2_damage` INTEGER DEFAULT 0",
        "`weapon2_defindex` INTEGER DEFAULT 0",
        "`weapon3_name` VARCHAR(128) DEFAULT ''",
        "`weapon3_shots` INTEGER DEFAULT 0",
        "`weapon3_hits` INTEGER DEFAULT 0",
        "`weapon3_damage` INTEGER DEFAULT 0",
        "`weapon3_defindex` INTEGER DEFAULT 0",
        "`weapon4_name` VARCHAR(128) DEFAULT ''",
        "`weapon4_shots` INTEGER DEFAULT 0",
        "`weapon4_hits` INTEGER DEFAULT 0",
        "`weapon4_damage` INTEGER DEFAULT 0",
        "`weapon4_defindex` INTEGER DEFAULT 0",
        "`weapon5_name` VARCHAR(128) DEFAULT ''",
        "`weapon5_shots` INTEGER DEFAULT 0",
        "`weapon5_hits` INTEGER DEFAULT 0",
        "`weapon5_damage` INTEGER DEFAULT 0",
        "`weapon5_defindex` INTEGER DEFAULT 0",
        "`weapon6_name` VARCHAR(128) DEFAULT ''",
        "`weapon6_shots` INTEGER DEFAULT 0",
        "`weapon6_hits` INTEGER DEFAULT 0",
        "`weapon6_damage` INTEGER DEFAULT 0",
        "`weapon6_defindex` INTEGER DEFAULT 0",
        "`airshots_soldier` INTEGER DEFAULT 0",
        "`airshots_soldier_height` INTEGER DEFAULT 0",
        "`airshots_demoman` INTEGER DEFAULT 0",
        "`airshots_demoman_height` INTEGER DEFAULT 0",
        "`airshots_sniper` INTEGER DEFAULT 0",
        "`airshots_sniper_height` INTEGER DEFAULT 0",
        "`airshots_medic` INTEGER DEFAULT 0",
        "`airshots_medic_height` INTEGER DEFAULT 0",
        "`classes_mask` INTEGER DEFAULT 0",
        "`is_admin` TINYINT DEFAULT 0",
        "`last_updated` INTEGER DEFAULT 0",
        "PRIMARY KEY (`log_id`, `steamid`)"
    };

    query[0] = '\0';
    StrCat(query, sizeof(query), "CREATE TABLE IF NOT EXISTS `whaletracker_log_players` (");
    for (int i = 0; i < sizeof(sLogPlayersColumns); i++)
    {
        if (i > 0)
        {
            StrCat(query, sizeof(query), ", ");
        }
        StrCat(query, sizeof(query), sLogPlayersColumns[i]);
    }
    StrCat(query, sizeof(query), ")");
    g_hDatabase.Query(WhaleTracker_CreateLogPlayersTable, query);

    SQL_FastQuery(g_hDatabase, "DROP TABLE IF EXISTS `whaletracker_mapstats`");
}

public void WhaleTracker_CreateTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create table: %s", error);
        WT_HandleDatabaseError(error);
    }

    PumpSaveQueue();

    static const char alterQueries[][128] =
    {
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS personaname VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS hits INTEGER DEFAULT 0",
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
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS best_weapon VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS best_weapon_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS damage_dealt INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS damage_taken INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS uber_drops INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS last_seen INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) DEFAULT 0",
        "ALTER TABLE whaletracker ADD COLUMN IF NOT EXISTS favorite_class TINYINT DEFAULT 0"
    };

    for (int i = 0; i < sizeof(alterQueries); i++)
    {
        g_hDatabase.Query(WhaleTracker_AlterCallback, alterQueries[i]);
    }

    static const char optionalAlterQueries[][] =
    {
        "ALTER TABLE whaletracker ADD COLUMN uber_drops INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN uber_drops INTEGER DEFAULT 0"
    };

    for (int i = 0; i < sizeof(optionalAlterQueries); i++)
    {
        g_hDatabase.Query(WhaleTracker_AlterOptionalCallback, optionalAlterQueries[i]);
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
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS best_class_id TINYINT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS best_class_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS best_class_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS best_class_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon1_name VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon1_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon1_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon1_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon2_name VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon2_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon2_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon2_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon3_name VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon3_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon3_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon3_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon4_name VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon4_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon4_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon4_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon5_name VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon5_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon5_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon5_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon6_name VARCHAR(64) DEFAULT ''",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon6_accuracy FLOAT DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon6_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS weapon6_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS playtime INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS total_ubers INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS best_streak INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS classes_mask INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS visible_max INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_online ADD COLUMN IF NOT EXISTS time_connected INTEGER DEFAULT 0",
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
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_streak INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_headshots_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_backstabs_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_score_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_kills_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_assists_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS best_ubers_life INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon1_name VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon1_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon1_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon1_damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon1_defindex INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon2_name VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon2_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon2_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon2_damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon2_defindex INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon3_name VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon3_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon3_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon3_damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon3_defindex INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon4_name VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon4_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon4_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon4_damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon4_defindex INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon5_name VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon5_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon5_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon5_damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon5_defindex INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon6_name VARCHAR(128) DEFAULT ''",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon6_shots INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon6_hits INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon6_damage INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS weapon6_defindex INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_soldier INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_soldier_height INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_demoman INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_demoman_height INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_sniper INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_sniper_height INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_medic INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS airshots_medic_height INTEGER DEFAULT 0",
        "ALTER TABLE whaletracker_log_players ADD COLUMN IF NOT EXISTS classes_mask INTEGER DEFAULT 0",
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
        WT_HandleDatabaseError(error);
    }
}

public void WhaleTracker_CreateLogsTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create logs table: %s", error);
        WT_HandleDatabaseError(error);
    }
}

public void WhaleTracker_CreateLogPlayersTable(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to create log players table: %s", error);
        WT_HandleDatabaseError(error);
    }
}

public void WhaleTracker_AlterCallback(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to alter table: %s", error);
        WT_HandleDatabaseError(error);
    }
}

public void WhaleTracker_AlterOptionalCallback(Database db, DBResultSet results, const char[] error, any data)
{
    if (error[0] == '\0')
    {
        return;
    }

    if (StrContains(error, "Duplicate column name", false) != -1)
    {
        return;
    }

    WT_HandleDatabaseError(error);
    LogError("[WhaleTracker] Failed optional table migration: %s", error);
}

static void LoadClientStats(int client)
{
    if (!IsClientInGame(client) || !g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }

    CancelLoadRetryTimer(client);

    char steamId[STEAMID64_LEN];
    if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
    {
        if (!GetClientAuthId(client, AuthId_Steam2, steamId, sizeof(steamId)))
        {
            return;
        }
    }

    strcopy(g_Stats[client].steamId, sizeof(g_Stats[client].steamId), steamId);
    strcopy(g_MapStats[client].steamId, sizeof(g_MapStats[client].steamId), steamId);

    g_Stats[client].loaded = false;
    g_MapStats[client].loaded = false;

    EnsureWeaponMaps(g_Stats[client]);
    EnsureWeaponMaps(g_MapStats[client]);

    char escapedSteamId[STEAMID64_LEN * 2];
    if (g_hDatabase != null)
    {
        SQL_EscapeString(g_hDatabase, steamId, escapedSteamId, sizeof(escapedSteamId));
    }
    else
    {
        strcopy(escapedSteamId, sizeof(escapedSteamId), steamId);
    }

    char query[1024];
    Format(query, sizeof(query),
        "SELECT first_seen, kills, deaths, shots, hits, "
        ... "shots_scout, hits_scout, shots_sniper, hits_sniper, shots_soldier, hits_soldier, shots_demoman, hits_demoman, "
        ... "shots_medic, hits_medic, shots_heavy, hits_heavy, shots_pyro, hits_pyro, shots_spy, hits_spy, shots_engineer, hits_engineer, "
        ... "healing, total_ubers, best_ubers_life, medic_drops, uber_drops, airshots, headshots, backstabs, best_headshots_life, best_backstabs_life, best_kills_life, best_killstreak, best_score_life, assists, best_assists_life, playtime, best_weapon, best_weapon_accuracy, damage_dealt, damage_taken, last_seen, is_admin, favorite_class "
        ... "FROM whaletracker WHERE steamid = '%s'", escapedSteamId);

    g_hDatabase.Query(WhaleTracker_LoadCallback, query, client);
}

public void WhaleTracker_LoadCallback(Database db, DBResultSet results, const char[] error, any client)
{
    int index = client;
    if (!IsValidClient(index))
    {
        return;
    }

    CancelLoadRetryTimer(index);

    if (error[0] != '\0')
    {
        LogError("[WhaleTracker] Failed to load stats for %N: %s", index, error);
        WT_HandleDatabaseError(error);
        ScheduleLoadRetry(index);
        return;
    }

    if (results == null || results.FieldCount <= 0)
    {
        LogError("[WhaleTracker] Aborting load for %N: no result set returned.", index);
        g_bDatabaseReady = false;
        WT_ScheduleReconnect();
        ScheduleLoadRetry(index);
        return;
    }

    if (results.FetchRow())
    {
        int column = 0;
        g_Stats[index].firstSeenTimestamp = results.FetchInt(column++);
        FormatTime(g_Stats[index].firstSeen, sizeof(g_Stats[index].firstSeen), "%Y-%m-%d", g_Stats[index].firstSeenTimestamp);
        g_Stats[index].kills = results.FetchInt(column++);
        g_Stats[index].deaths = results.FetchInt(column++);
        g_Stats[index].totalShots = results.FetchInt(column++);
        g_Stats[index].totalHits = results.FetchInt(column++);
        for (int classIndex = WT_CLASS_OFFSET; classIndex < (WT_CLASS_OFFSET + WT_CLASS_COUNT); classIndex++)
        {
            g_Stats[index].classShots[classIndex] = results.FetchInt(column++);
            g_Stats[index].classHits[classIndex] = results.FetchInt(column++);
        }
        g_Stats[index].totalHealing = results.FetchInt(column++);
        g_Stats[index].totalUbers = results.FetchInt(column++);
        g_Stats[index].bestUbersLife = results.FetchInt(column++);
        g_Stats[index].totalMedicDrops = results.FetchInt(column++);
        g_Stats[index].totalUberDrops = results.FetchInt(column++);
        g_Stats[index].totalAirshots = results.FetchInt(column++);
        g_Stats[index].totalHeadshots = results.FetchInt(column++);
        g_Stats[index].totalBackstabs = results.FetchInt(column++);
        g_Stats[index].bestHeadshotsLife = results.FetchInt(column++);
        g_Stats[index].bestBackstabsLife = results.FetchInt(column++);
        g_Stats[index].bestKillsLife = results.FetchInt(column++);
        g_Stats[index].bestKillstreak = results.FetchInt(column++);
        g_Stats[index].bestScoreLife = results.FetchInt(column++);
        g_Stats[index].totalAssists = results.FetchInt(column++);
        g_Stats[index].bestAssistsLife = results.FetchInt(column++);
        g_Stats[index].playtime = results.FetchInt(column++);
        results.FetchString(column++, g_Stats[index].bestWeapon, sizeof(g_Stats[index].bestWeapon));
        g_Stats[index].bestWeaponAccuracy = results.FetchFloat(column++);
        g_Stats[index].totalDamage = results.FetchInt(column++);
        g_Stats[index].totalDamageTaken = results.FetchInt(column++);
        g_Stats[index].lastSeen = results.FetchInt(column++);
        g_Stats[index].isAdmin = results.FetchInt(column++) != 0;
        g_Stats[index].favoriteClass = results.FetchInt(column++);
        g_Stats[index].loaded = true;
        g_MapStats[index].loaded = true;
        g_MapStats[index].isAdmin = g_Stats[index].isAdmin;
        g_MapStats[index].bestWeaponAccuracy = g_Stats[index].bestWeaponAccuracy;
        strcopy(g_MapStats[index].bestWeapon, sizeof(g_MapStats[index].bestWeapon), g_Stats[index].bestWeapon);
        g_MapStats[index].totalUberDrops = g_Stats[index].totalUberDrops;
        g_MapStats[index].favoriteClass = g_Stats[index].favoriteClass;
    }
    else
    {
        g_Stats[index].firstSeenTimestamp = GetTime();
        FormatTime(g_Stats[index].firstSeen, sizeof(g_Stats[index].firstSeen), "%Y-%m-%d", g_Stats[index].firstSeenTimestamp);
        g_Stats[index].loaded = true;
        g_MapStats[index].loaded = true;
        g_MapStats[index].isAdmin = g_Stats[index].isAdmin;
        g_MapStats[index].bestWeaponAccuracy = g_Stats[index].bestWeaponAccuracy;
        g_MapStats[index].bestWeapon[0] = '\0';
        g_Stats[index].favoriteClass = TFClass_Unknown;
        g_MapStats[index].favoriteClass = TFClass_Unknown;
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

    RefreshBestWeaponAccuracy(g_MapStats[client]);

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
        LogError("[WhaleTracker] Refusing to save stats for %N because load failed earlier.", client);
        ScheduleLoadRetry(client);
        return false;
    }

    if (!g_MapStats[client].loaded)
    {
        LogError("[WhaleTracker] Refusing to save map stats for %N because map stats not loaded.", client);
        includeMapStats = false;
        ScheduleLoadRetry(client);
    }

    g_KillSaveCounter[client] = 0;

    TouchClientLastSeen(client);
    RefreshBestWeaponAccuracy(g_Stats[client]);
    g_MapStats[client].bestWeaponAccuracy = g_Stats[client].bestWeaponAccuracy;
    strcopy(g_MapStats[client].bestWeapon, sizeof(g_MapStats[client].bestWeapon), g_Stats[client].bestWeapon);
    g_MapStats[client].favoriteClass = g_Stats[client].favoriteClass;

    char escapedWeapon[128];
    EscapeSqlString(g_Stats[client].bestWeapon, escapedWeapon, sizeof(escapedWeapon));

    char name[MAX_NAME_LENGTH];
    if (IsClientInGame(client))
    {
        GetClientName(client, name, sizeof(name));
    }
    else if (!GetStoredMatchPlayerName(g_Stats[client].steamId, name, sizeof(name)))
    {
        strcopy(name, sizeof(name), g_Stats[client].steamId);
    }

    char escapedName[MAX_NAME_LENGTH * 2];
    if (g_hDatabase != null)
    {
        SQL_EscapeString(g_hDatabase, name, escapedName, sizeof(escapedName));
    }
    else
    {
        strcopy(escapedName, sizeof(escapedName), name);
    }

    char query[SAVE_QUERY_MAXLEN];
    Format(query, sizeof(query),
        "REPLACE INTO whaletracker "
        ... "(steamid, personaname, first_seen, kills, deaths, shots, hits, "
        ... "shots_scout, hits_scout, shots_sniper, hits_sniper, shots_soldier, hits_soldier, shots_demoman, hits_demoman, "
        ... "shots_medic, hits_medic, shots_heavy, hits_heavy, shots_pyro, hits_pyro, shots_spy, hits_spy, shots_engineer, hits_engineer, "
        ... "healing, total_ubers, best_ubers_life, medic_drops, uber_drops, airshots, headshots, backstabs, best_headshots_life, best_backstabs_life, best_kills_life, best_killstreak, best_score_life, assists, best_assists_life, playtime, best_weapon, best_weapon_accuracy, damage_dealt, damage_taken, last_seen, is_admin, favorite_class) "
        ... "VALUES ('%s', '%s', %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, %d, '%s', %.6f, %d, %d, %d, %d, %d)",
        g_Stats[client].steamId,
        escapedName,
        g_Stats[client].firstSeenTimestamp,
        g_Stats[client].kills,
        g_Stats[client].deaths,
        g_Stats[client].totalShots,
        g_Stats[client].totalHits,
        g_Stats[client].classShots[TFClass_Scout],
        g_Stats[client].classHits[TFClass_Scout],
        g_Stats[client].classShots[TFClass_Sniper],
        g_Stats[client].classHits[TFClass_Sniper],
        g_Stats[client].classShots[TFClass_Soldier],
        g_Stats[client].classHits[TFClass_Soldier],
        g_Stats[client].classShots[TFClass_DemoMan],
        g_Stats[client].classHits[TFClass_DemoMan],
        g_Stats[client].classShots[TFClass_Medic],
        g_Stats[client].classHits[TFClass_Medic],
        g_Stats[client].classShots[TFClass_Heavy],
        g_Stats[client].classHits[TFClass_Heavy],
        g_Stats[client].classShots[TFClass_Pyro],
        g_Stats[client].classHits[TFClass_Pyro],
        g_Stats[client].classShots[TFClass_Spy],
        g_Stats[client].classHits[TFClass_Spy],
        g_Stats[client].classShots[TFClass_Engineer],
        g_Stats[client].classHits[TFClass_Engineer],
        g_Stats[client].totalHealing,
        g_Stats[client].totalUbers,
        g_Stats[client].bestUbersLife,
        g_Stats[client].totalMedicDrops,
        g_Stats[client].totalUberDrops,
        g_Stats[client].totalAirshots,
        g_Stats[client].totalHeadshots,
        g_Stats[client].totalBackstabs,
        g_Stats[client].bestHeadshotsLife,
        g_Stats[client].bestBackstabsLife,
        g_Stats[client].bestKillsLife,
        g_Stats[client].bestKillstreak,
        g_Stats[client].bestScoreLife,
        g_Stats[client].totalAssists,
        g_Stats[client].bestAssistsLife,
        g_Stats[client].playtime,
        escapedWeapon,
        g_Stats[client].bestWeaponAccuracy,
        g_Stats[client].totalDamage,
        g_Stats[client].totalDamageTaken,
        g_Stats[client].lastSeen,
        g_Stats[client].isAdmin ? 1 : 0,
        g_Stats[client].favoriteClass);

    int userId = GetClientUserId(client);
    QueueSaveQuery(query, userId, false);

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
        WT_HandleDatabaseError(error);
        int client = (userId > 0) ? GetClientOfUserId(userId) : 0;

        if (client > 0 && IsValidClient(client))
        {
            LogError("[WhaleTracker] Failed to save stats for %N: %s", client, error);
        }
        else if (userId > 0)
        {
            LogError("[WhaleTracker] Failed to save stats (userid %d): %s", userId, error);
        }
        else
        {
            LogError("[WhaleTracker] Failed to save stats: %s", error);
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
    if (!g_bEnabled)
        return;

    int client = GetClientOfUserId(event.GetInt("userid"));
    if (!IsValidClient(client))
        return;

    ResetLifeCounters(g_Stats[client]);
    ResetLifeCounters(g_MapStats[client]);
}

public void Event_PlayerDeath(Event event, const char[] name, bool dontBroadcast)
{
    if (!g_bEnabled)
        return;

    int victim = GetClientOfUserId(event.GetInt("userid"));
    int attacker = GetClientOfUserId(event.GetInt("attacker"));
    int assister = GetClientOfUserId(event.GetInt("assister"));

    if (IsValidClient(attacker) && attacker != victim)
    {
        int custom = event.GetInt("customkill");
        bool headshot = (custom == TF_CUSTOM_HEADSHOT || custom == TF_CUSTOM_HEADSHOT_DECAPITATION);
        bool backstab = (custom == TF_CUSTOM_BACKSTAB);
        bool medicDrop = IsMedicDrop(victim);
        TFClassType classType = TF2_GetPlayerClass(attacker);
        int airshotHeight = 0;
        bool airshot = IsProjectileAirshot(attacker, victim, airshotHeight);
        if (airshot)
        {
            MatchAirshots_AddForClient(attacker, classType);
        }

        ApplyKillStats(g_Stats[attacker], headshot, backstab, medicDrop, airshot);
        ApplyKillStats(g_MapStats[attacker], headshot, backstab, medicDrop, airshot);

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

public Action OnPlayerTakeDamage(int victim, int &attacker, int &inflictor, float &damage, int &damagetype, int &weapon, float damageForce[3], float damagePosition[3], int damagecustom)
{
    if (!g_bEnabled)
        return Plugin_Continue;

    if (damage <= 0.0)
        return Plugin_Continue;

    if (attacker == victim)
        return Plugin_Continue;

    int damageInt = RoundToFloor(damage);
    if (damageInt < 0)
    {
        damageInt = 0;
    }

    if (IsValidClient(attacker) && !IsFakeClient(attacker))
    {
        g_Stats[attacker].totalDamage += damageInt;
        g_MapStats[attacker].totalDamage += damageInt;

        TFClassType classType = TF2_GetPlayerClass(attacker);
        if (classType >= TFClass_Scout && classType <= TFClass_Engineer)
        {
            int bit = 1 << (view_as<int>(classType) - 1);
            g_Stats[attacker].damageClassesMask |= bit;
            g_MapStats[attacker].damageClassesMask |= bit;
        }
    }

    if (IsValidClient(victim) && !IsFakeClient(victim))
    {
        g_Stats[victim].totalDamageTaken += damageInt;
        g_MapStats[victim].totalDamageTaken += damageInt;
    }

    if (!IsValidClient(attacker) || IsFakeClient(attacker))
        return Plugin_Continue;

    if (attacker == victim)
    {
        Accuracy_MarkSelfDamage(attacker, inflictor);
        return Plugin_Continue;
    }

    int weaponDefIndex = WT_ResolveWeaponDefIndex(attacker, weapon, inflictor);
    char weaponName[WT_MAX_WEAPON_NAME];
    weaponName[0] = '\0';

    if (weaponDefIndex > 0)
    {
        GetWeaponNameFromDefIndex(weaponDefIndex, weaponName, sizeof(weaponName));
        if (!weaponName[0] || StrEqual(weaponName, "Unknown", false))
        {
            weaponName[0] = '\0';
        }
    }

    bool skipAccuracyLogging = CheckIfAfterburn(damagecustom) || CheckIfBleedDmg(damagetype);

    Accuracy_MarkEnemyHit(attacker, inflictor);

    if (weaponName[0])
    {
        if (!skipAccuracyLogging)
        {
            Accuracy_LogHit(attacker, weaponName, weaponDefIndex);
        }
        RecordWeaponDamageForClient(attacker, weaponName, weaponDefIndex, damageInt);
    }

    return Plugin_Continue;
}

public Action TF2_CalcIsAttackCritical(int client, int weapon, char[] weaponname, bool& result)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return Plugin_Continue;

    int weaponDefIndex = WT_ResolveWeaponDefIndex(client, weapon, -1);
    char weaponName[WT_MAX_WEAPON_NAME];
    weaponName[0] = '\0';

    if (weaponDefIndex > 0)
    {
        GetWeaponNameFromDefIndex(weaponDefIndex, weaponName, sizeof(weaponName));
        if (!weaponName[0] || StrEqual(weaponName, "Unknown", false))
        {
            weaponName[0] = '\0';
        }
    }

    if (!weaponName[0])
    {
        return Plugin_Continue;
    }

    Accuracy_OnWeaponFired(client, weaponname, weaponName, weaponDefIndex);

    return Plugin_Continue;
}

public void Event_PlayerHealed(Event event, const char[] name, bool dontBroadcast)
{
    if (!g_bEnabled)
        return;

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
    if (!g_bEnabled)
        return;

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

static bool IsAllowedAirshotWeapon(int client, int weapon, int &slotIndex)
{
    if (!IsValidClient(client) || IsFakeClient(client))
        return false;

    if (weapon <= MaxClients || !IsValidEntity(weapon))
        return false;

    TFClassType classType = TF2_GetPlayerClass(client);
    int slots[3];
    int count = 0;

    slotIndex = -1;

    if (classType == TFClass_Soldier)
    {
        slots[count++] = 0;
    }
    else if (classType == TFClass_DemoMan)
    {
        slots[count++] = 0;
        slots[count++] = 1;
    }
    else if (classType == TFClass_Medic)
    {
        slots[count++] = 0;
    }
    else
    {
        return false;
    }

    for (int i = 0; i < count; i++)
    {
        int slotWeapon = GetPlayerWeaponSlot(client, slots[i]);
        if (slotWeapon > MaxClients && IsValidEntity(slotWeapon) && slotWeapon == weapon)
        {
            slotIndex = slots[i];
            return true;
        }
    }

    return false;
}

static bool IsProjectileAirshot(int attacker, int victim, int &slotOut)
{
    slotOut = -1;

    if (!IsValidClient(attacker) || IsFakeClient(attacker) || !IsValidClient(victim) || IsFakeClient(victim))
        return false;

    int flags = GetEntityFlags(victim);
    if ((flags & FL_ONGROUND) != 0)
    {
        return false;
    }

    int activeWeapon = GetEntPropEnt(attacker, Prop_Send, "m_hActiveWeapon");
    if (!IsAllowedAirshotWeapon(attacker, activeWeapon, slotOut))
        return false;

    return true;
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

static void SendMatchStatsMessage(int viewer, int target)
{
    if (!IsValidClient(viewer) || IsFakeClient(viewer))
        return;

    if (!IsValidClient(target) || IsFakeClient(target))
    {
        CPrintToChat(viewer, "{green}[WhaleTracker]{default} No valid player selected.");
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
        CPrintToChat(viewer, "{green}[WhaleTracker]{default} %N has no current match data.", target);
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
    float minutes = (matchStats.playtime > 0) ? float(matchStats.playtime) / 60.0 : 0.0;
    float dpm = (minutes > 0.0) ? float(damage) / minutes : 0.0;
    float dtpm = (minutes > 0.0) ? float(damageTaken) / minutes : 0.0;

    char timeBuffer[32];
    FormatMatchDuration(matchStats.playtime, timeBuffer, sizeof(timeBuffer));

    CPrintToChat(viewer, "{green}[WhaleTracker]{default} %s — Match: K %d | D %d | KD %.2f | A %d | Dmg %d | Dmg/min %.1f",
        playerName, kills, deaths, kd, assists, damage, dpm);
    CPrintToChat(viewer, "{green}[WhaleTracker]{default} Taken %d | Taken/min %.1f | Heal %d | HS %d | BS %d | Ubers %d | Time %s",
        damageTaken, dtpm, healing, headshots, backstabs, ubers, timeBuffer);

    WeaponAggregate topWeapons[WT_MAX_TOP_WEAPONS];
    int topWeaponCount = CollectTopWeaponsForSteamId(matchStats.steamId, topWeapons, WT_MAX_TOP_WEAPONS);
    if (topWeaponCount > 0)
    {
        char weaponBuffer[256];
        weaponBuffer[0] = '\0';
        float totalAccuracy = 0.0;
        int accuracySamples = 0;

        for (int i = 0; i < topWeaponCount; i++)
        {
            float weaponAccuracy = 0.0;
            if (topWeapons[i].shots > 0)
            {
                weaponAccuracy = float(topWeapons[i].hits) / float(topWeapons[i].shots) * 100.0;
                totalAccuracy += weaponAccuracy;
                accuracySamples++;
            }

            char segment[128];
            Format(segment, sizeof(segment), "%s%s (%.1f%%)",
                weaponBuffer[0] ? ", " : "",
                topWeapons[i].name,
                weaponAccuracy);
            StrCat(weaponBuffer, sizeof(weaponBuffer), segment);
        }

        float avgAccuracy = (accuracySamples > 0) ? (totalAccuracy / float(accuracySamples)) : 0.0;
        if (!weaponBuffer[0])
        {
            strcopy(weaponBuffer, sizeof(weaponBuffer), "No weapon accuracy recorded");
        }

        CPrintToChat(viewer, "{green}[WhaleTracker]{default} Accuracy Avg %.1f%% — %s", avgAccuracy, weaponBuffer);
    }

    CPrintToChat(viewer, "{green}[WhaleTracker]{default} Lifetime Kills %d | Deaths %d", lifetimeKills, lifetimeDeaths);
    CPrintToChat(viewer, "{green}[WhaleTracker]{default} Visit {gold}kogasa.tf/stats{default} for way more!");
}

public Action Command_SetFavoriteClass(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        ReplyToCommand(client, "[WhaleTracker] This command can only be used in-game.");
        return Plugin_Handled;
    }

    if (args < 1)
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Usage: !fav <scout|soldier|pyro|demo|demoman|heavy|medic|sniper|spy|engi|engineer|none>");
        return Plugin_Handled;
    }

    char arg[32];
    GetCmdArg(1, arg, sizeof(arg));
    TrimString(arg);

    TFClassType parsedClass;
    if (!WT_ParseFavoriteClassArg(arg, parsedClass))
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Unknown class '%s'. Try scout, soldier, pyro, demo, heavy, medic, sniper, spy, engi, or none.", arg);
        return Plugin_Handled;
    }

    g_Stats[client].favoriteClass = view_as<int>(parsedClass);
    g_MapStats[client].favoriteClass = view_as<int>(parsedClass);

    char className[32];
    WT_GetClassDisplayName(parsedClass, className, sizeof(className));

    if (parsedClass == TFClass_Unknown)
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Favorite class cleared. Using best accuracy automatically.");
    }
    else
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Favorite class set to %s.", className);
    }

    if (g_Stats[client].loaded)
    {
        SaveClientStats(client, false);
    }
    else
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Stats are still loading; favorite will be saved once they finish loading.");
    }

    return Plugin_Handled;
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
                CPrintToChat(client, "{green}[WhaleTracker]{default} Could not find player '%s'.", targetArg);
                return Plugin_Handled;
            }
        }
    }

    SendMatchStatsMessage(client, target);
    return Plugin_Handled;
}

public Action Command_StartLog(int client, int args)
{
    if (!g_bEnabled)
    {
        ReplyToCommand(client, "[WhaleTracker] Tracking is disabled. Set %s to 1 to enable.", g_sEnabled);
        return Plugin_Handled;
    }

    FinalizeCurrentMatch(false);
    BeginMatchTracking(true);
    ClearOnlineStats();

    if (client > 0 && IsClientInGame(client))
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Started a new match log.");
    }
    else
    {
        ReplyToCommand(client, "[WhaleTracker] Started a new match log.");
    }

    return Plugin_Handled;
}

static bool IsValidClient(int client)
{
    return client > 0 && client <= MaxClients && IsClientConnected(client);
}
