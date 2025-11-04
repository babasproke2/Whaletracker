#include <sourcemod>
#include <sdktools>
#pragma semicolon 1
#pragma newdecls required

#define PLUGIN_VERSION "1.0"

public Plugin myinfo = 
{
    name = "[TF2] Whale Stats MOTD",
    author = "Your Name",
    description = "Opens whale stats page for the player in MOTD",
    version = PLUGIN_VERSION,
    url = ""
};

public void OnPluginStart()
{
    RegConsoleCmd("sm_whale", Command_Whale, "Opens your whale stats");
    RegConsoleCmd("sm_ss", Command_Whale, "Opens your whale stats");
    AddCommandListener(WhaleChatListener, "say");
    AddCommandListener(WhaleChatListener, "say_team");
}

public Action Command_Whale(int client, int args)
{
    if (client == 0)
    {
        ReplyToCommand(client, "[Whale] This command can only be used in-game.");
        return Plugin_Handled;
    }
    
    char steamId[32];
    if (!GetClientAuthId(client, AuthId_SteamID64, steamId, sizeof(steamId)))
    {
        ReplyToCommand(client, "[Whale] Failed to get your Steam ID.");
        return Plugin_Handled;
    }
    
    char url[256];
    Format(url, sizeof(url), "https://kogasa.tf/stats/index.php?q=logs&show=hide#current");
    
    Handle kv = CreateKeyValues("data");
    KvSetString(kv, "title", "Whale Stats");
    KvSetString(kv, "type", "2");
    KvSetString(kv, "msg", url);
    KvSetNum(kv, "customsvr", 1);
    
    ShowVGUIPanel(client, "info", kv);
    CloseHandle(kv);
    
    return Plugin_Handled;
}

public Action WhaleChatListener(int client, const char[] command, int argc)
{
    if (client <= 0)
    {
        return Plugin_Continue;
    }

    char message[192];
    GetCmdArgString(message, sizeof(message));
    StripQuotes(message);
    TrimString(message);

    if (message[0] == '\0')
    {
        return Plugin_Continue;
    }

    if (StrEqual(message, ".ss", false) || StrEqual(message, "!ss", false))
    {
        Command_Whale(client, 0);
        return Plugin_Handled;
    }

    return Plugin_Continue;
}
