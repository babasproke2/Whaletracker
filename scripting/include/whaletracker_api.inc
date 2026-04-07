#if defined _whaletracker_api_included
  #endinput
#endif
#define _whaletracker_api_included

#include <sourcemod>

/**
 * Returns a client's cumulative tracked kills.
 *
 * @param client        Client index.
 * @return              Cumulative kills, or 0 if unavailable.
 */
native int WhaleTracker_GetCumulativeKills(int client);

/**
 * Returns whether a client's WhaleTracker stats are loaded and ready.
 *
 * @param client        Client index.
 * @return              True if ready.
 */
native bool WhaleTracker_AreStatsLoaded(int client);

/**
 * Returns a client's current Whale Points total.
 *
 * @param client        Client index.
 * @return              Whale Points, or 0 if unavailable.
 */
native int WhaleTracker_GetWhalePoints(int client);

/**
 * Adds bonus points to a client and returns the new total.
 *
 * @param client        Client index.
 * @param amount        Bonus points to add. Negative values are allowed.
 * @return              New bonus point total, or 0 if unavailable.
 */
native int WhaleTracker_GiveBonusPoints(int client, int amount);

/**
 * Spends bonus points from a client if they have enough.
 *
 * @param client        Client index.
 * @param amount        Bonus points to spend. Must be positive.
 * @return              True on success.
 */
native bool WhaleTracker_SpendBonusPoints(int client, int amount);

/**
 * Resolves the best recorded name for a SteamID64.
 *
 * Lookup order:
 * 1. Connected live client name
 * 2. whaletracker.cached_personaname
 * 3. whaletracker.personaname
 * 4. whaletracker_points_cache.name
 *
 * @param steamId64     SteamID64 to look up.
 * @param buffer        Output buffer.
 * @param maxlen        Output buffer size.
 * @return              True if a non-empty name was found.
 */
native bool WhaleTracker_GetLastRecordedName(const char[] steamId64, char[] buffer, int maxlen);

/**
 * Fired when WhaleTracker records an airshot.
 *
 * @param attacker      Attacker client index.
 * @param victim        Victim client index.
 */
forward void WhaleTracker_OnAirshot(int attacker, int victim);
