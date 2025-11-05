<img width="1920" height="919" alt="image" src="https://github.com/user-attachments/assets/14d1401e-1bc3-4fbf-985e-25d3a7cfa23a" />

WhaleTracker is a stats tracking system used by the kogasatopia site to collect and display player statistics from a Team Fortress 2 server. The system has two main parts:

- Data collection (server-side plugin): a SourceMod plugin (WhaleTracker) that runs on the game server to record in-game events and periodically persist those to a MySQL/MariaDB database.
- Web UI (PHP + JS + CSS): a set of PHP endpoints and static assets under the webroot (primarily `/stats`) that present leaderboards, online players, player detail pages, and small widgets used in other pages like `playercount_widget` and `leaderboard`.

This README describes the on-disk layout, the data flow between the plugin and the web UI and important files & functions.

Relevant Paths

- /var/www/kogasatopia/stats/ — main web UI for WhaleTracker
  - index.php — top-level web UI for WhaleTracker; contains the HTML structure, includes, JS that fetches logs/online stats and renders cards
  - config.php — web configuration constants (DB table names, etc.) used by the PHP UI
  - functions.php — shared helper functions used by the PHP pages (database helpers, table name getters, sanitizers, etc.)
  - playerList.php, online.php — small include/partial PHP files that render parts of the UI (player lists, school labels, online players, etc.)
  - css/whaletracker.css — stylesheet for the WhaleTracker web UI
  - whaletracker_logo.png, whaletracker_footer.png — logo/footer images used throughout

Database tables (from reading the plugin SQL queries)

The SourceMod script creates/uses the following tables (names as seen in the plugin; the web UI expects env/config table names that default to these):

- whaletracker — persistent player stats (lifetime metrics)
- whaletracker_online — transient table with online players and their current-match counters and last_update timestamp
- whaletracker_logs — aggregated match/session logs (per-match meta data)
- whaletracker_log_players — per-player-per-match snapshot rows (for historical logs)
- whaletracker_mapstats

These tables contain many columns: kills, deaths, assists, damage, damage_taken, healing, headshots, backstabs, playtime, total_ubers, best_streak, time_connected, last_update, personaname, class, team, alive, is_spectator and many lifetime fields like damage_dealt/taken and best weapon fields.
The web UI reads from these tables to produce the display. Whaletracker is compatible with Redis and other frameworks compatible with Redis.

This repository contains a backup of our DB and cache files.
