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

public Action Command_SaveAllStats(int client, int args)
{
    int saved = 0;

    for (int i = 1; i <= MaxClients; i++)
    {
        if (SaveClientStats(i, true, true))
        {
            saved++;
        }
    }

    if (client > 0 && IsClientInGame(client))
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Saved stats for %d player(s).", saved);
    }
    else
    {
        PrintToServer("[WhaleTracker] Saved stats for %d player(s).", saved);
    }

    return Plugin_Handled;
}

public Action Command_ShowPoints(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Handled;
    }
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
    EnsureClientStatsLoadedForPoints(target);
    int combined = g_Stats[target].kills + g_Stats[target].deaths;
    if (combined <= WHALE_POINTS_MIN_KD_SUM)
    {
        char colorTagUnranked[32];
        GetClientFiltersNameColorTag(target, colorTagUnranked, sizeof(colorTagUnranked));
        CPrintToChat(client, "{green}[WhaleTracker]{default} {%s}%N{default} is unranked until Kills + Deaths exceeds %d (current: %d).", colorTagUnranked, target, WHALE_POINTS_MIN_KD_SUM, combined);
        return Plugin_Handled;
    }
    int points = GetWhalePointsForClient(target);
    int rank = GetWhalePointsRankForClient(target);
    int lifetimeKills = g_Stats[target].kills;
    int lifetimeDeaths = g_Stats[target].deaths;
    float lifetimeKd = (lifetimeDeaths > 0) ? float(lifetimeKills) / float(lifetimeDeaths) : float(lifetimeKills);
    char colorTag[32];
    GetClientFiltersNameColorTag(target, colorTag, sizeof(colorTag));
    char playerName[MAX_NAME_LENGTH];
    GetClientName(target, playerName, sizeof(playerName));
    CPrintToChatAll("{gold}[Whaletracker]{default} {%s}%s{default}'s Points: %d, Rank #%d", colorTag, playerName, points, rank);
    CPrintToChat(client, "Kill/Death ratio: %.2f", lifetimeKd);
    CPrintToChat(client, "Calculation: {lightgreen}((damage / 300) + (healing / 400) + (floor(kills * 1.5 + assists * 0.5)) + backstabs + headshots + (airshots * 3) + medic kills + heavy kills + (market gardens * 5) + (ubers * 10)){default} / {axis}(deaths + (damage taken / 500)){default} * 10000");
    CPrintToChat(client, "Use {gold}!ranks{default} to view the leaderboard!");
    return Plugin_Handled;
}

public Action Command_ShowPointsMe(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Handled;
    }
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
    EnsureClientStatsLoadedForPoints(target);
    int combined = g_Stats[target].kills + g_Stats[target].deaths;
    if (combined <= WHALE_POINTS_MIN_KD_SUM)
    {
        char colorTagUnranked[32];
        GetClientFiltersNameColorTag(target, colorTagUnranked, sizeof(colorTagUnranked));
        CPrintToChat(client, "{green}[WhaleTracker]{default} {%s}%N{default} is unranked until Kills + Deaths exceeds %d (current: %d).", colorTagUnranked, target, WHALE_POINTS_MIN_KD_SUM, combined);
        return Plugin_Handled;
    }
    int points = GetWhalePointsForClient(target);
    int rank = GetWhalePointsRankForClient(target);
    int lifetimeKills = g_Stats[target].kills;
    int lifetimeDeaths = g_Stats[target].deaths;
    float lifetimeKd = (lifetimeDeaths > 0) ? float(lifetimeKills) / float(lifetimeDeaths) : float(lifetimeKills);
    char colorTag[32];
    GetClientFiltersNameColorTag(target, colorTag, sizeof(colorTag));
    char playerName[MAX_NAME_LENGTH];
    GetClientName(target, playerName, sizeof(playerName));
    CPrintToChat(client, "{gold}[Whaletracker]{default} {%s}%s{default}'s Points: %d, Rank #%d", colorTag, playerName, points, rank);
    CPrintToChat(client, "Kill/Death ratio: %.2f", lifetimeKd);
    return Plugin_Handled;
}

public Action Command_ShowMarketGardens(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Handled;
    }

    CPrintToChat(client, "{green}[WhaleTracker]{default} Market gardens: {gold}%d {default}| Airshots: {gold}%d", g_Stats[client].totalMarketGardenHits, g_Stats[client].totalAirshots);
    return Plugin_Handled;
}

public Action Command_ShowLeaderboard(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Handled;
    }

    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Database is not ready.");
        return Plugin_Handled;
    }

    int page = 1;
    if (args >= 1)
    {
        char arg[16];
        GetCmdArg(1, arg, sizeof(arg));
        int parsed = StringToInt(arg);
        if (parsed > 0)
        {
            page = parsed;
        }
    }

    int offset = (page - 1) * WHALE_LEADERBOARD_PAGE_SIZE;

    char query[2048];
    Format(query, sizeof(query),
        "SELECT %s, "
        ... "COALESCE(NULLIF(p.newname,''), NULLIF(w.cached_personaname,''), NULLIF(w.personaname,''), w.steamid), "
        ... "COALESCE(NULLIF(f.color,''), 'gold') "
        ... "FROM whaletracker w "
        ... "LEFT JOIN prename_rules p ON p.pattern = w.steamid "
        ... "LEFT JOIN filters_namecolors f ON f.steamid = w.steamid "
        ... "WHERE (GREATEST(w.kills,0) + GREATEST(w.deaths,0)) > %d "
        ... "ORDER BY %s DESC, w.steamid ASC "
        ... "LIMIT %d OFFSET %d",
        WHALE_POINTS_SQL_EXPR,
        WHALE_POINTS_MIN_KD_SUM,
        WHALE_POINTS_SQL_EXPR,
        WHALE_LEADERBOARD_PAGE_SIZE,
        offset);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));
        CPrintToChat(client, "{green}[WhaleTracker]{default} Failed to load leaderboard.");
        LogError("[WhaleTracker] Failed to load leaderboard: %s", error);
        return Plugin_Handled;
    }

    int rows = 0;

    while (results.FetchRow())
    {
        int points = results.FetchInt(0);

        char displayName[128];
        char colorTag[32];
        results.FetchString(1, displayName, sizeof(displayName));
        results.FetchString(2, colorTag, sizeof(colorTag));
        TrimString(displayName);
        TrimString(colorTag);

        if (displayName[0] == '\0')
        {
            strcopy(displayName, sizeof(displayName), "Unknown");
        }
        if (colorTag[0] == '\0')
        {
            strcopy(colorTag, sizeof(colorTag), "gold");
        }

        rows++;
        int rank = offset + rows;
        CPrintToChat(client, "#%d {%s}%s{default} %d", rank, colorTag, displayName, points);
    }
    delete results;

    if (rows == 0)
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} No leaderboard entries on page %d.", page);
        return Plugin_Handled;
    }

    CPrintToChat(client, "Use !{gold}ranks %d{default} to view the next 10 ranks!", page + 1);
    return Plugin_Handled;
}

void GetNameColorTagForSteamId(const char[] steamId, char[] colorTag, int maxlen)
{
    strcopy(colorTag, maxlen, "gold");

    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }

    if (steamId[0] == '\0')
    {
        return;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(steamId, escapedSteamId, sizeof(escapedSteamId));

    char query[192];
    Format(query, sizeof(query),
        "SELECT color FROM filters_namecolors WHERE steamid = '%s' LIMIT 1",
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results != null && SQL_HasResultSet(results) && results.FetchRow())
    {
        results.FetchString(0, colorTag, maxlen);
        TrimString(colorTag);
    }
    delete results;

    if (colorTag[0] != '\0')
    {
        return;
    }

    Format(query, sizeof(query),
        "SELECT name_color FROM whaletracker_points_cache WHERE steamid = '%s' LIMIT 1",
        escapedSteamId);

    results = SQL_Query(g_hDatabase, query);
    if (results != null && SQL_HasResultSet(results) && results.FetchRow())
    {
        results.FetchString(0, colorTag, maxlen);
        TrimString(colorTag);
    }
    delete results;
}

void CacheWhalePointsSnapshot(const char[] steamId, int points, const char[] playerName, const char[] knownColor = "")
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }

    if (steamId[0] == '\0')
    {
        return;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(steamId, escapedSteamId, sizeof(escapedSteamId));

    char nameColor[32];
    if (knownColor[0] != '\0')
    {
        strcopy(nameColor, sizeof(nameColor), knownColor);
    }
    else
    {
        GetNameColorTagForSteamId(steamId, nameColor, sizeof(nameColor));
    }

    char escapedNameColor[64];
    EscapeSqlString(nameColor, escapedNameColor, sizeof(escapedNameColor));

    char fallbackName[MAX_NAME_LENGTH];
    if (playerName[0] != '\0')
    {
        strcopy(fallbackName, sizeof(fallbackName), playerName);
    }
    else
    {
        strcopy(fallbackName, sizeof(fallbackName), steamId);
    }

    char escapedName[(MAX_NAME_LENGTH * 2) + 1];
    EscapeSqlString(fallbackName, escapedName, sizeof(escapedName));

    if (points < 0)
    {
        points = 0;
    }

    char query[1600];
    Format(query, sizeof(query),
        "INSERT INTO whaletracker_points_cache (steamid, points, name, name_color, prename, updated_at) "
        ... "VALUES ('%s', %d, '%s', '%s', COALESCE((SELECT newname FROM prename_rules WHERE pattern = '%s' LIMIT 1), ''), %d) "
        ... "ON DUPLICATE KEY UPDATE "
        ... "points = VALUES(points), "
        ... "name = VALUES(name), "
        ... "name_color = VALUES(name_color), "
        ... "prename = COALESCE((SELECT newname FROM prename_rules WHERE pattern = '%s' LIMIT 1), prename), "
        ... "updated_at = VALUES(updated_at)",
        escapedSteamId,
        points,
        escapedName,
        escapedNameColor,
        escapedSteamId,
        GetTime(),
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));
        LogError("[WhaleTracker] Failed to update points cache: %s | Query: %s", error, query);
        return;
    }
    delete results;
}

void CacheWhalePointsOnDisconnect(int client)
{
    if (client <= 0 || client > MaxClients || IsFakeClient(client))
    {
        return;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return;
    }

    char clientName[MAX_NAME_LENGTH];
    GetClientName(client, clientName, sizeof(clientName));

    char colorTag[32];
    GetNameColorTagForSteamId(g_Stats[client].steamId, colorTag, sizeof(colorTag));

    int points = GetWhalePointsForStats(g_Stats[client]);
    CacheWhalePointsSnapshot(g_Stats[client].steamId, points, clientName, colorTag);
}

bool IsValidClient(int client)
{
    return client > 0 && client <= MaxClients && IsClientConnected(client);
}

void GetClientFiltersNameColorTag(int client, char[] colorTag, int maxlen)
{
    strcopy(colorTag, maxlen, "gold");

    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return;
    }

    if (client <= 0 || client > MaxClients || !IsClientConnected(client))
    {
        return;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

    char query[192];
    Format(query, sizeof(query),
        "SELECT color FROM filters_namecolors WHERE steamid = '%s' LIMIT 1",
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results != null && SQL_HasResultSet(results) && results.FetchRow())
    {
        results.FetchString(0, colorTag, maxlen);
        TrimString(colorTag);
    }
    delete results;

    if (colorTag[0] != '\0')
    {
        return;
    }

    // Fallback: use WhaleTracker points cache color when filters table has no row.
    GetNameColorTagForSteamId(g_Stats[client].steamId, colorTag, maxlen);

    if (colorTag[0] == '\0')
    {
        strcopy(colorTag, maxlen, "gold");
    }
}

int GetWhalePointsForStats(const WhaleStats stats)
{
    int safeKills = (stats.kills > 0) ? stats.kills : 0;
    int safeAssists = (stats.totalAssists > 0) ? stats.totalAssists : 0;
    int safeBackstabs = (stats.totalBackstabs > 0) ? stats.totalBackstabs : 0;
    int safeHeadshots = (stats.totalHeadshots > 0) ? stats.totalHeadshots : 0;
    int safeMarketGardenHits = (stats.totalMarketGardenHits > 0) ? stats.totalMarketGardenHits : 0;
    int safeMedicKills = (stats.totalMedicKills > 0) ? stats.totalMedicKills : 0;
    int safeHeavyKills = (stats.totalHeavyKills > 0) ? stats.totalHeavyKills : 0;
    int safeAirshots = (stats.totalAirshots > 0) ? stats.totalAirshots : 0;
    int safeTotalUbers = (stats.totalUbers > 0) ? stats.totalUbers : 0;
    int safeDamage = (stats.totalDamage > 0) ? stats.totalDamage : 0;
    int safeDeaths = (stats.deaths > 0) ? stats.deaths : 0;
    int safeHealing = (stats.totalHealing > 0) ? stats.totalHealing : 0;
    int safeDamageTaken = (stats.totalDamageTaken > 0) ? stats.totalDamageTaken : 0;

    if ((safeKills + safeDeaths) <= WHALE_POINTS_MIN_KD_SUM)
    {
        return 0;
    }

    float positive = 0.0;
    positive += float(safeDamage) / 300.0;
    positive += float(safeHealing) / 400.0;
    positive += float(RoundToFloor(float(safeKills) * 1.5 + float(safeAssists) * 0.5));
    positive += float(safeBackstabs);
    positive += float(safeHeadshots);
    positive += float(safeMarketGardenHits * 5);
    positive += float(safeMedicKills);
    positive += float(safeHeavyKills);
    positive += float(safeAirshots * 3);
    positive += float(safeTotalUbers * 10);
    if (positive < 0.0)
    {
        positive = 0.0;
    }
    if (positive > 2147483000.0)
    {
        positive = 2147483000.0;
    }

    int denominatorBase = RoundToFloor(safeDeaths + safeDamageTaken / 500.0);
    if (denominatorBase < 1)
    {
        denominatorBase = 1;
    }

    float pointsFloat = (positive / float(denominatorBase)) * 10000.0;
    if (pointsFloat < 0.0)
    {
        pointsFloat = 0.0;
    }
    if (pointsFloat > 2147483000.0)
    {
        pointsFloat = 2147483000.0;
    }

    int points = RoundToCeil(pointsFloat);
    if (points < 0)
    {
        points = 0;
    }
    return points;
}

int GetWhalePointsForClient(int client)
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return 0;
    }

    if (client <= 0 || client > MaxClients || !IsClientInGame(client) || IsFakeClient(client))
    {
        return 0;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return 0;
    }

    int kills;
    int deaths;
    int assists;
    int backstabs;
    int headshots;
    int medicKills;
    int heavyKills;
    int airshots;
    int marketGardenHits;
    int totalUbers;
    int damage;
    int healing;
    int damageTaken;

    if (g_Stats[client].loaded)
    {
        kills = g_Stats[client].kills;
        deaths = g_Stats[client].deaths;
        assists = g_Stats[client].totalAssists;
        backstabs = g_Stats[client].totalBackstabs;
        headshots = g_Stats[client].totalHeadshots;
        medicKills = g_Stats[client].totalMedicKills;
        heavyKills = g_Stats[client].totalHeavyKills;
        airshots = g_Stats[client].totalAirshots;
        marketGardenHits = g_Stats[client].totalMarketGardenHits;
        totalUbers = g_Stats[client].totalUbers;
        damage = g_Stats[client].totalDamage;
        healing = g_Stats[client].totalHealing;
        damageTaken = g_Stats[client].totalDamageTaken;
    }
    else
    {
        char escapedSteamId[STEAMID64_LEN * 2];
        EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

        char query[256];
        Format(query, sizeof(query),
            "SELECT kills, deaths, assists, backstabs, headshots, medicKills, heavyKills, airshots, marketGardenHits, total_ubers, damage_dealt, healing, damage_taken "
            ... "FROM whaletracker WHERE steamid = '%s' LIMIT 1",
            escapedSteamId);

        DBResultSet results = SQL_Query(g_hDatabase, query);
        if (results == null)
        {
            char error[256];
            SQL_GetError(g_hDatabase, error, sizeof(error));
            LogError("[WhaleTracker] WhalePoints query failed: %s", error);
            return 0;
        }

        if (!results.FetchRow())
        {
            delete results;
            return 0;
        }

        kills = results.FetchInt(0);
        deaths = results.FetchInt(1);
        assists = results.FetchInt(2);
        backstabs = results.FetchInt(3);
        headshots = results.FetchInt(4);
        medicKills = results.FetchInt(5);
        heavyKills = results.FetchInt(6);
        airshots = results.FetchInt(7);
        marketGardenHits = results.FetchInt(8);
        totalUbers = results.FetchInt(9);
        damage = results.FetchInt(10);
        healing = results.FetchInt(11);
        damageTaken = results.FetchInt(12);
        delete results;
    }

    WhaleStats pointStats;
    pointStats.kills = kills;
    pointStats.deaths = deaths;
    pointStats.totalAssists = assists;
    pointStats.totalBackstabs = backstabs;
    pointStats.totalHeadshots = headshots;
    pointStats.totalMedicKills = medicKills;
    pointStats.totalHeavyKills = heavyKills;
    pointStats.totalAirshots = airshots;
    pointStats.totalMarketGardenHits = marketGardenHits;
    pointStats.totalUbers = totalUbers;
    pointStats.totalDamage = damage;
    pointStats.totalHealing = healing;
    pointStats.totalDamageTaken = damageTaken;
    return GetWhalePointsForStats(pointStats);
}

void EnsureClientStatsLoadedForPoints(int client)
{
    if (client <= 0 || client > MaxClients || !IsClientConnected(client))
    {
        return;
    }

    if (!g_bDatabaseReady || g_hDatabase == null || g_Stats[client].loaded)
    {
        return;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

    char query[512];
    Format(query, sizeof(query),
        "SELECT first_seen, kills, deaths, healing, total_ubers, best_ubers_life, medic_drops, uber_drops, airshots, medicKills, heavyKills, marketGardenHits, headshots, backstabs, best_killstreak, assists, playtime, damage_dealt, damage_taken, last_seen "
        ... "FROM whaletracker WHERE steamid = '%s' LIMIT 1",
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        return;
    }

    if (results.FetchRow())
    {
        g_Stats[client].firstSeenTimestamp = results.FetchInt(0);
        FormatTime(g_Stats[client].firstSeen, sizeof(g_Stats[client].firstSeen), "%Y-%m-%d", g_Stats[client].firstSeenTimestamp);
        g_Stats[client].kills = results.FetchInt(1);
        g_Stats[client].deaths = results.FetchInt(2);
        g_Stats[client].totalHealing = results.FetchInt(3);
        g_Stats[client].totalUbers = results.FetchInt(4);
        g_Stats[client].bestUbersLife = results.FetchInt(5);
        g_Stats[client].totalMedicDrops = results.FetchInt(6);
        g_Stats[client].totalUberDrops = results.FetchInt(7);
        g_Stats[client].totalAirshots = results.FetchInt(8);
        g_Stats[client].totalMedicKills = results.FetchInt(9);
        g_Stats[client].totalHeavyKills = results.FetchInt(10);
        g_Stats[client].totalMarketGardenHits = results.FetchInt(11);
        g_Stats[client].totalHeadshots = results.FetchInt(12);
        g_Stats[client].totalBackstabs = results.FetchInt(13);
        g_Stats[client].bestKillstreak = results.FetchInt(14);
        g_Stats[client].totalAssists = results.FetchInt(15);
        g_Stats[client].playtime = results.FetchInt(16);
        g_Stats[client].totalDamage = results.FetchInt(17);
        g_Stats[client].totalDamageTaken = results.FetchInt(18);
        g_Stats[client].lastSeen = results.FetchInt(19);
        g_Stats[client].loaded = true;
    }

    delete results;
}

int GetWhalePointsRankForClient(int client)
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return 0;
    }

    if (client <= 0 || client > MaxClients || !IsClientConnected(client))
    {
        return 0;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return 0;
    }

    EnsureClientStatsLoadedForPoints(client);

    int selfKills = (g_Stats[client].kills > 0) ? g_Stats[client].kills : 0;
    int selfDeaths = (g_Stats[client].deaths > 0) ? g_Stats[client].deaths : 0;
    if ((selfKills + selfDeaths) <= WHALE_POINTS_MIN_KD_SUM)
    {
        return 0;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

    int selfPoints = GetWhalePointsForClient(client);
    if (selfPoints < 0)
    {
        selfPoints = 0;
    }

    char query[1600];
    Format(query, sizeof(query),
        "SELECT 1 + COUNT(*) FROM whaletracker w "
        ... "WHERE (GREATEST(w.kills,0) + GREATEST(w.deaths,0)) > %d "
        ... "AND (((%s) > %d) "
        ... "OR (((%s) = %d) AND w.steamid < '%s'))",
        WHALE_POINTS_MIN_KD_SUM,
        WHALE_POINTS_SQL_EXPR,
        selfPoints,
        WHALE_POINTS_SQL_EXPR,
        selfPoints,
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));
        LogError("[WhaleTracker] WhalePoints rank query failed: %s", error);
        return 0;
    }

    if (!SQL_HasResultSet(results) || !results.FetchRow())
    {
        delete results;
        return 0;
    }

    int rank = results.FetchInt(0);
    delete results;
    if (rank < 1)
    {
        rank = 1;
    }
    return rank;
}

public any Native_WhaleTracker_GetCumulativeKills(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    if (client <= 0 || client > MaxClients)
    {
        return 0;
    }

    return g_Stats[client].kills;
}

public any Native_WhaleTracker_AreStatsLoaded(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    return (client > 0 && client <= MaxClients && g_Stats[client].loaded);
}

public any Native_WhaleTracker_GetWhalePoints(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    return GetWhalePointsForClient(client);
}
