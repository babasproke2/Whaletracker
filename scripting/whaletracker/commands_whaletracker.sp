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
    int playtime = (g_Stats[target].playtime > 0) ? g_Stats[target].playtime : 0;
    if (combined < WHALE_RANK_MIN_KD_SUM || playtime < WHALE_RANK_MIN_PLAYTIME_SECONDS)
    {
        char colorTagUnranked[32];
        GetClientFiltersNameColorTag(target, colorTagUnranked, sizeof(colorTagUnranked));
        float hours = float(playtime) / 3600.0;
        CPrintToChat(client, "{green}[WhaleTracker]{default} {%s}%N{default} is unranked until Kills + Deaths reaches at least %d and playtime reaches 3 hours (current: %d K+D, %.2f hours).", colorTagUnranked, target, WHALE_RANK_MIN_KD_SUM, combined, hours);
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
    CPrintToChat(client, "Score: {lightgreen}1000 * confidence * (5 * combat + pressure + support)");
    CPrintToChat(client, "Combat: {lightgreen}(kills + assists * 0.35) / (deaths + 20)");
    CPrintToChat(client, "Pressure: {lightgreen}ln(1 + damage / (150 * eng))");
    CPrintToChat(client, "Support: {lightgreen}0.60 * ln(1 + healing / (100 * eng)) + 0.90 * ln(1 + 60 * ubers / eng)");
    CPrintToChat(client, "Confidence: {axis}sqrt(eng / (eng + 400)) * (hours / (hours + 20))");
    CPrintToChat(client, "Where {lightgreen}eng = kills + deaths{default} and {lightgreen}hours = playtime / 3600");
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
    int playtime = (g_Stats[target].playtime > 0) ? g_Stats[target].playtime : 0;
    if (combined < WHALE_RANK_MIN_KD_SUM || playtime < WHALE_RANK_MIN_PLAYTIME_SECONDS)
    {
        char colorTagUnranked[32];
        GetClientFiltersNameColorTag(target, colorTagUnranked, sizeof(colorTagUnranked));
        float hours = float(playtime) / 3600.0;
        CPrintToChat(client, "{green}[WhaleTracker]{default} {%s}%N{default} is unranked until Kills + Deaths reaches at least %d and playtime reaches 3 hours (current: %d K+D, %.2f hours).", colorTagUnranked, target, WHALE_RANK_MIN_KD_SUM, combined, hours);
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

public Action Command_ShowBonusPoints(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Handled;
    }

    CPrintToChat(client, "{green}[WhaleTracker]{default} Bonus points: {magenta}%d{default}\nGet bonus points by killing players with a score above 15%% higher than yours;\nYou can also get bonus points from {magenta}!missions", g_Stats[client].bonusPoints);
    return Plugin_Handled;
}

public Action Command_SetFavoriteClass(int client, int args)
{
    if (client <= 0 || !IsClientInGame(client) || IsFakeClient(client))
    {
        return Plugin_Handled;
    }

    if (args >= 1)
    {
        char classArg[32];
        GetCmdArg(1, classArg, sizeof(classArg));
        TrimString(classArg);

        int favoriteClass = ParseFavoriteClassName(classArg);
        if (favoriteClass != CLASS_UNKNOWN)
        {
            SetFavoriteClassForClient(client, favoriteClass);
            return Plugin_Handled;
        }

        CPrintToChat(client, "{green}[WhaleTracker]{default} Unknown class '%s'.", classArg);
    }

    ShowFavoriteClassMenu(client);
    PrintCurrentFavoriteClass(client);
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

    char query[4096];
    Format(query, sizeof(query),
        "SELECT %s, "
        ... "COALESCE(NULLIF(p.newname,''), NULLIF(w.cached_personaname,''), NULLIF(w.personaname,''), w.steamid), "
        ... "COALESCE(NULLIF(f.color,''), 'gold') "
        ... "FROM whaletracker w "
        ... "LEFT JOIN prename_rules p ON p.pattern = w.steamid "
        ... "LEFT JOIN filters_namecolors f ON f.steamid = w.steamid "
        ... "WHERE ((CASE WHEN w.kills > 0 THEN w.kills ELSE 0 END) + (CASE WHEN w.deaths > 0 THEN w.deaths ELSE 0 END)) >= %d "
        ... "AND (CASE WHEN w.playtime > 0 THEN w.playtime ELSE 0 END) >= %d "
        ... "ORDER BY %s DESC, w.steamid ASC "
        ... "LIMIT %d OFFSET %d",
        WHALE_POINTS_SQL_EXPR,
        WHALE_RANK_MIN_KD_SUM,
        WHALE_RANK_MIN_PLAYTIME_SECONDS,
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

void ShowFavoriteClassMenu(int client)
{
    Menu menu = new Menu(MenuHandler_FavoriteClass);
    menu.SetTitle("Select Favorite Class");
    menu.AddItem("1", "Scout");
    menu.AddItem("3", "Soldier");
    menu.AddItem("7", "Pyro");
    menu.AddItem("4", "Demoman");
    menu.AddItem("6", "Heavy");
    menu.AddItem("9", "Engineer");
    menu.AddItem("5", "Medic");
    menu.AddItem("2", "Sniper");
    menu.AddItem("8", "Spy");
    menu.Pagination = 5;
    menu.ExitButton = true;
    menu.Display(client, MENU_TIME_FOREVER);
}

public int MenuHandler_FavoriteClass(Menu menu, MenuAction action, int client, int item)
{
    if (action == MenuAction_Select)
    {
        char info[8];
        menu.GetItem(item, info, sizeof(info));
        int favoriteClass = StringToInt(info);
        if (favoriteClass != CLASS_UNKNOWN)
        {
            SetFavoriteClassForClient(client, favoriteClass);
        }
    }
    else if (action == MenuAction_End)
    {
        delete menu;
    }

    return 0;
}

int ParseFavoriteClassName(const char[] className)
{
    if (StrEqual(className, "scout", false))
    {
        return CLASS_SCOUT;
    }
    if (StrEqual(className, "soldier", false))
    {
        return CLASS_SOLDIER;
    }
    if (StrEqual(className, "pyro", false))
    {
        return CLASS_PYRO;
    }
    if (StrEqual(className, "demo", false) || StrEqual(className, "demoman", false))
    {
        return CLASS_DEMOMAN;
    }
    if (StrEqual(className, "heavy", false))
    {
        return CLASS_HEAVY;
    }
    if (StrEqual(className, "engi", false) || StrEqual(className, "engineer", false))
    {
        return CLASS_ENGINEER;
    }
    if (StrEqual(className, "medic", false))
    {
        return CLASS_MEDIC;
    }
    if (StrEqual(className, "sniper", false))
    {
        return CLASS_SNIPER;
    }
    if (StrEqual(className, "spy", false))
    {
        return CLASS_SPY;
    }

    return CLASS_UNKNOWN;
}

void GetFavoriteClassDisplayName(int favoriteClass, char[] buffer, int maxlen)
{
    switch (favoriteClass)
    {
        case CLASS_SCOUT:
        {
            strcopy(buffer, maxlen, "Scout");
        }
        case CLASS_SOLDIER:
        {
            strcopy(buffer, maxlen, "Soldier");
        }
        case CLASS_PYRO:
        {
            strcopy(buffer, maxlen, "Pyro");
        }
        case CLASS_DEMOMAN:
        {
            strcopy(buffer, maxlen, "Demoman");
        }
        case CLASS_HEAVY:
        {
            strcopy(buffer, maxlen, "Heavy");
        }
        case CLASS_ENGINEER:
        {
            strcopy(buffer, maxlen, "Engineer");
        }
        case CLASS_MEDIC:
        {
            strcopy(buffer, maxlen, "Medic");
        }
        case CLASS_SNIPER:
        {
            strcopy(buffer, maxlen, "Sniper");
        }
        case CLASS_SPY:
        {
            strcopy(buffer, maxlen, "Spy");
        }
        default:
        {
            strcopy(buffer, maxlen, "Unknown");
        }
    }
}

void PrintCurrentFavoriteClass(int client)
{
    int favoriteClass = GetFavoriteClassForClient(client);
    if (favoriteClass == CLASS_UNKNOWN)
    {
        return;
    }

    char className[16];
    GetFavoriteClassDisplayName(favoriteClass, className, sizeof(className));
    CPrintToChat(client, "{green}[WhaleTracker]{default} Your favorite class: {gold}%s{default}", className);
}

int GetFavoriteClassForClient(int client)
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return CLASS_UNKNOWN;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return CLASS_UNKNOWN;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

    char query[192];
    Format(query, sizeof(query),
        "SELECT COALESCE(favorite_class, 0) FROM whaletracker WHERE steamid = '%s' LIMIT 1",
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));
        LogError("[WhaleTracker] Failed to load favorite class for %N: %s", client, error);
        return CLASS_UNKNOWN;
    }

    int favoriteClass = CLASS_UNKNOWN;
    if (SQL_HasResultSet(results) && results.FetchRow())
    {
        favoriteClass = results.FetchInt(0);
    }

    delete results;
    return favoriteClass;
}

void SetFavoriteClassForClient(int client, int favoriteClass)
{
    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Database is not ready.");
        return;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        CPrintToChat(client, "{green}[WhaleTracker]{default} Could not determine your SteamID yet.");
        return;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

    int firstSeen = g_Stats[client].firstSeenTimestamp;
    if (firstSeen <= 0)
    {
        firstSeen = GetTime();
    }

    char query[512];
    Format(query, sizeof(query),
        "INSERT INTO whaletracker (steamid, first_seen, favorite_class) "
        ... "VALUES ('%s', %d, %d) "
        ... "ON DUPLICATE KEY UPDATE "
        ... "first_seen = LEAST(first_seen, VALUES(first_seen)), "
        ... "favorite_class = VALUES(favorite_class)",
        escapedSteamId,
        firstSeen,
        favoriteClass);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));
        LogError("[WhaleTracker] Failed to set favorite class for %N: %s", client, error);
        CPrintToChat(client, "{green}[WhaleTracker]{default} Failed to save your favorite class.");
        return;
    }
    delete results;

    char className[16];
    GetFavoriteClassDisplayName(favoriteClass, className, sizeof(className));
    CPrintToChat(client, "{green}[WhaleTracker]{default} Your favorite class is now {gold}%s{default}!", className);
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
    int safeDeaths = (stats.deaths > 0) ? stats.deaths : 0;
    int safeAssists = (stats.totalAssists > 0) ? stats.totalAssists : 0;
    int safeTotalUbers = (stats.totalUbers > 0) ? stats.totalUbers : 0;
    int safeDamage = (stats.totalDamage > 0) ? stats.totalDamage : 0;
    int safeHealing = (stats.totalHealing > 0) ? stats.totalHealing : 0;
    int safePlaytime = (stats.playtime > 0) ? stats.playtime : 0;
    int safeEngagement = safeKills + safeDeaths;

    if (safeEngagement < WHALE_RANK_MIN_KD_SUM || safePlaytime < WHALE_RANK_MIN_PLAYTIME_SECONDS)
    {
        return 0;
    }

    float engagement = float(safeEngagement);
    float hours = float(safePlaytime) / 3600.0;
    float combat = (float(safeKills) + (float(safeAssists) * 0.35)) / (float(safeDeaths) + 20.0);
    float pressure = Logarithm(1.0 + (float(safeDamage) / (150.0 * engagement)), 2.718281828);
    float support =
        (0.60 * Logarithm(1.0 + (float(safeHealing) / (100.0 * engagement)), 2.718281828))
        + (0.90 * Logarithm(1.0 + ((60.0 * float(safeTotalUbers)) / engagement), 2.718281828));
    float confidence = SquareRoot(engagement / (engagement + 400.0)) * (hours / (hours + 20.0));

    float pointsFloat = 1000.0 * confidence * ((5.0 * combat) + pressure + support);
    if (pointsFloat < 0.0)
    {
        pointsFloat = 0.0;
    }
    if (pointsFloat > 2147483000.0)
    {
        pointsFloat = 2147483000.0;
    }

    int points = RoundToNearest(pointsFloat);
    if (points < 0)
    {
        points = 0;
    }
    return points;
}

int GetWhalePointsForClient(int client)
{
    if (client <= 0 || client > MaxClients || !IsClientInGame(client) || IsFakeClient(client))
    {
        return 0;
    }

    if (g_Stats[client].loaded)
    {
        return GetWhalePointsForStats(g_Stats[client]);
    }

    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        return 0;
    }

    EnsureClientSteamId(client);
    if (g_Stats[client].steamId[0] == '\0')
    {
        return 0;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(g_Stats[client].steamId, escapedSteamId, sizeof(escapedSteamId));

    char query[256];
    Format(query, sizeof(query),
        "SELECT kills, deaths, assists, total_ubers, damage_dealt, healing, playtime "
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

    WhaleStats pointStats;
    pointStats.kills = results.FetchInt(0);
    pointStats.deaths = results.FetchInt(1);
    pointStats.totalAssists = results.FetchInt(2);
    pointStats.totalUbers = results.FetchInt(3);
    pointStats.totalDamage = results.FetchInt(4);
    pointStats.totalHealing = results.FetchInt(5);
    pointStats.playtime = results.FetchInt(6);
    delete results;

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
        "SELECT first_seen, kills, deaths, healing, total_ubers, best_ubers_life, medic_drops, uber_drops, airshots, bonusPoints, medicKills, heavyKills, marketGardenHits, headshots, backstabs, best_killstreak, assists, playtime, damage_dealt, damage_taken, last_seen "
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
        g_Stats[client].bonusPoints = results.FetchInt(9);
        g_Stats[client].totalMedicKills = results.FetchInt(10);
        g_Stats[client].totalHeavyKills = results.FetchInt(11);
        g_Stats[client].totalMarketGardenHits = results.FetchInt(12);
        g_Stats[client].totalHeadshots = results.FetchInt(13);
        g_Stats[client].totalBackstabs = results.FetchInt(14);
        g_Stats[client].bestKillstreak = results.FetchInt(15);
        g_Stats[client].totalAssists = results.FetchInt(16);
        g_Stats[client].playtime = results.FetchInt(17);
        g_Stats[client].totalDamage = results.FetchInt(18);
        g_Stats[client].totalDamageTaken = results.FetchInt(19);
        g_Stats[client].lastSeen = results.FetchInt(20);
        g_Stats[client].loaded = true;
    }

    delete results;
}

int GetWhalePointsRankForClient(int client)
{
    if (client <= 0 || client > MaxClients || !IsClientConnected(client))
    {
        return 0;
    }

    if (!g_bDatabaseReady || g_hDatabase == null)
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
    int selfPlaytime = (g_Stats[client].playtime > 0) ? g_Stats[client].playtime : 0;
    if ((selfKills + selfDeaths) < WHALE_RANK_MIN_KD_SUM || selfPlaytime < WHALE_RANK_MIN_PLAYTIME_SECONDS)
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

    char query[4096];
    Format(query, sizeof(query),
        "SELECT 1 + COUNT(*) FROM whaletracker w "
        ... "WHERE ((CASE WHEN w.kills > 0 THEN w.kills ELSE 0 END) + (CASE WHEN w.deaths > 0 THEN w.deaths ELSE 0 END)) >= %d "
        ... "AND (CASE WHEN w.playtime > 0 THEN w.playtime ELSE 0 END) >= %d "
        ... "AND (((%s) > %d) "
        ... "OR (((%s) = %d) AND w.steamid < '%s'))",
        WHALE_RANK_MIN_KD_SUM,
        WHALE_RANK_MIN_PLAYTIME_SECONDS,
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
    return WhaleTracker_AreClientStatsReady(client);
}

public any Native_WhaleTracker_GetWhalePoints(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    return GetWhalePointsForClient(client);
}

public any Native_WhaleTracker_ApplyBonusPoints(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    int points = GetNativeCell(2);
    bool playSound = view_as<bool>(GetNativeCell(3));
    bool chatAlert = view_as<bool>(GetNativeCell(4));
    float randomChance = view_as<float>(GetNativeCell(5));

    char type[64];
    GetNativeString(6, type, sizeof(type));
    TrimString(type);

    int target = (numParams >= 7) ? GetNativeCell(7) : 0;
    return ApplyBonusPoints(client, points, playSound, chatAlert, randomChance, type, target);
}

public any Native_WhaleTracker_GiveBonusPoints(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    int amount = GetNativeCell(2);
    if (!ApplyBonusPoints(client, amount, false, false, 1.0))
        return 0;

    return g_Stats[client].bonusPoints;
}

public any Native_WhaleTracker_SpendBonusPoints(Handle plugin, int numParams)
{
    int client = GetNativeCell(1);
    int amount = GetNativeCell(2);
    if (amount <= 0)
        return false;

    return ApplyBonusPoints(client, -amount, false, false, 1.0);
}

public any Native_WhaleTracker_GetLastRecordedName(Handle plugin, int numParams)
{
    char steamId[STEAMID64_LEN];
    GetNativeString(1, steamId, sizeof(steamId));

    int maxlen = GetNativeCell(3);
    if (maxlen <= 0)
    {
        return false;
    }

    if (steamId[0] == '\0')
    {
        SetNativeString(2, "", maxlen, true);
        return false;
    }

    for (int i = 1; i <= MaxClients; i++)
    {
        if (!IsClientConnected(i) || IsFakeClient(i))
        {
            continue;
        }

        char clientSteamId[STEAMID64_LEN];
        if (!GetClientAuthId(i, AuthId_SteamID64, clientSteamId, sizeof(clientSteamId)))
        {
            continue;
        }

        if (!StrEqual(clientSteamId, steamId, false))
        {
            continue;
        }

        char connectedName[256];
        if (GetClientName(i, connectedName, sizeof(connectedName)))
        {
            TrimString(connectedName);
            if (connectedName[0] != '\0')
            {
                SetNativeString(2, connectedName, maxlen, true);
                return true;
            }
        }

        break;
    }

    if (!g_bDatabaseReady || g_hDatabase == null)
    {
        SetNativeString(2, "", maxlen, true);
        return false;
    }

    char escapedSteamId[STEAMID64_LEN * 2];
    EscapeSqlString(steamId, escapedSteamId, sizeof(escapedSteamId));

    char query[768];
    Format(query, sizeof(query),
        "SELECT COALESCE("
        ... "NULLIF(w.cached_personaname, ''), "
        ... "NULLIF(w.personaname, ''), "
        ... "NULLIF(pc.name, ''), "
        ... "''"
        ... ") "
        ... "FROM (SELECT '%s' AS steamid) s "
        ... "LEFT JOIN whaletracker w ON w.steamid = s.steamid "
        ... "LEFT JOIN whaletracker_points_cache pc ON pc.steamid = s.steamid "
        ... "LIMIT 1",
        escapedSteamId);

    DBResultSet results = SQL_Query(g_hDatabase, query);
    if (results == null)
    {
        char error[256];
        SQL_GetError(g_hDatabase, error, sizeof(error));
        LogError("[WhaleTracker] LastRecordedName query failed: %s", error);
        SetNativeString(2, "", maxlen, true);
        return false;
    }

    char name[256];
    name[0] = '\0';
    bool found = false;

    if (results.FetchRow())
    {
        results.FetchString(0, name, sizeof(name));
        TrimString(name);
        found = (name[0] != '\0');
    }

    delete results;
    SetNativeString(2, name, maxlen, true);
    return found;
}
