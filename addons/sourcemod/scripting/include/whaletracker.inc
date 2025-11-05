#if defined _whaletracker_inc_
  #endinput
#endif
#define _whaletracker_inc_

stock void GetWeaponNameFromDefIndex(int defIndex, char[] buffer, int maxlen)
{
    switch(defIndex)
    {
        case 9, 10, 11, 12: strcopy(buffer, maxlen, "Shotgun");
        case 13: strcopy(buffer, maxlen, "Scattergun");
        case 14: strcopy(buffer, maxlen, "Sniper Rifle");
        case 15: strcopy(buffer, maxlen, "Minigun");
        case 16: strcopy(buffer, maxlen, "SMG");
        case 17: strcopy(buffer, maxlen, "Syringe Gun");
        case 18: strcopy(buffer, maxlen, "Rocket Launcher");
        case 19: strcopy(buffer, maxlen, "Grenade Launcher");
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
        case 56: strcopy(buffer, maxlen, "Huntsman");
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
        case 222: strcopy(buffer, maxlen, "Mad Milk");
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
        case 834: strcopy(buffer, maxlen, "Neon Annihilator");
        case 851: strcopy(buffer, maxlen, "AWPer Hand");
        default: strcopy(buffer, maxlen, "Unknown");
    }
}

static bool IsValidClient(int client)
{
    return client > 0 && client <= MaxClients && IsClientConnected(client);
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

// For ArrayList, StringMap, or other objects created with `new`
stock void SafeDelete(ArrayList &obj)
{
    if (obj != null)
    {
        delete obj;
        obj = null;
    }
}

// Overload for StringMap, Trie, etc. if needed
stock void SafeDeleteMap(StringMap &obj)
{
    if (obj != null)
    {
        delete obj;
        obj = null;
    }
}

// For Timers or other Handle-based values
stock void SafeClose(Handle &hndl)
{
    if (hndl != null)
    {
        CloseHandle(hndl);
        hndl = null;
    }
}