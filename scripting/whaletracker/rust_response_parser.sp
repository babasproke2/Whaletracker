/** Strict flat JSON response parser for the daemon protocol (no substring matching). */
enum struct WTRustResponse
{
    int Kind; // 1 hello_ack, 2 ack, 3 error, 4 health
    int BatchId;
    int Accepted;
    int Executed;
    int DbErrors;
    bool HasBatchId;
    bool HasAccepted;
    bool HasExecuted;
    bool HasDbErrors;
}

void WTResponse_SkipSpace(const char[] text, int &at)
{
    while (text[at] == ' ' || text[at] == '\t' || text[at] == '\r' || text[at] == '\n') { at++; }
}

bool WTResponse_IsHex(int c)
{
    return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F');
}

bool WTResponse_ReadString(const char[] text, int &at, char[] value, int maxlen)
{
    if (text[at] != '"' || maxlen < 1) { return false; }
    at++;
    int written = 0;
    while (text[at] && text[at] != '"')
    {
        int c = view_as<int>(text[at++]) & 0xFF;
        if (c < 32) { return false; }
        if (c == '\\')
        {
            c = text[at++];
            if (c == 'u')
            {
                int decoded = 0;
                for (int i = 0; i < 4; i++)
                {
                    int digit = text[at++];
                    if (!WTResponse_IsHex(digit)) { return false; }
                    decoded = decoded * 16 + (digit <= '9' ? digit - '0' : (digit <= 'F' ? digit - 'A' + 10 : digit - 'a' + 10));
                }
                // Decode ASCII escapes in keys as well: escaped spellings must
                // not bypass duplicate-key checks. Never embed a NUL terminator.
                c = decoded > 0 && decoded < 128 ? decoded : '?';
            }
            else if (c == 'n') { c = '\n'; }
            else if (c == 'r') { c = '\r'; }
            else if (c == 't') { c = '\t'; }
            else if (c == 'b') { c = 8; }
            else if (c == 'f') { c = 12; }
            else if (c != '"' && c != '\\' && c != '/') { return false; }
        }
        if (written < maxlen - 1) { value[written++] = view_as<char>(c); }
    }
    if (text[at] != '"') { return false; }
    at++;
    value[written] = '\0';
    return true;
}

bool WTResponse_ReadNumber(const char[] text, int &at, bool requireCell, int &value)
{
    int start = at;
    bool negative = text[at] == '-';
    if (negative) { at++; }
    if (text[at] < '0' || text[at] > '9') { return false; }
    bool leadingZero = text[at] == '0';
    value = 0;
    int digits = 0;
    while (text[at] >= '0' && text[at] <= '9')
    {
        if (leadingZero && digits > 0) { return false; }
        int digit = text[at++] - '0';
        if (requireCell)
        {
            // ACK counters and IDs are non-negative signed SourcePawn cells.
            if (value > (2147483647 - digit) / 10) { return false; }
            value = value * 10 + digit;
        }
        digits++;
    }
    if (requireCell) { return !negative; }
    if (text[at] == '.')
    {
        at++;
        if (text[at] < '0' || text[at] > '9') { return false; }
        while (text[at] >= '0' && text[at] <= '9') { at++; }
    }
    if (text[at] == 'e' || text[at] == 'E')
    {
        at++;
        if (text[at] == '+' || text[at] == '-') { at++; }
        if (text[at] < '0' || text[at] > '9') { return false; }
        while (text[at] >= '0' && text[at] <= '9') { at++; }
    }
    return at > start;
}

bool WTResponse_Parse(const char[] text, WTRustResponse response)
{
    response.Kind = 0;
    response.BatchId = 0;
    response.Accepted = 0;
    response.Executed = 0;
    response.DbErrors = 0;
    response.HasBatchId = false;
    response.HasAccepted = false;
    response.HasExecuted = false;
    response.HasDbErrors = false;
    int seen = 0;
    int at = 0;
    WTResponse_SkipSpace(text, at);
    if (text[at++] != '{') { return false; }
    WTResponse_SkipSpace(text, at);
    if (text[at] == '}') { return false; }
    while (text[at])
    {
        char key[64];
        if (!WTResponse_ReadString(text, at, key, sizeof(key))) { return false; }
        WTResponse_SkipSpace(text, at);
        if (text[at++] != ':') { return false; }
        WTResponse_SkipSpace(text, at);
        int field = 0;
        if (StrEqual(key, "type")) { field = 1; }
        else if (StrEqual(key, "batch_id")) { field = 2; }
        else if (StrEqual(key, "accepted")) { field = 4; }
        else if (StrEqual(key, "executed")) { field = 8; }
        else if (StrEqual(key, "db_errors")) { field = 16; }
        if (field && (seen & field)) { return false; }
        seen |= field;
        if (text[at] == '"')
        {
            char value[32];
            if (!WTResponse_ReadString(text, at, value, sizeof(value))) { return false; }
            if (field == 1)
            {
                if (StrEqual(value, "hello_ack")) { response.Kind = 1; }
                else if (StrEqual(value, "ack")) { response.Kind = 2; }
                else if (StrEqual(value, "error")) { response.Kind = 3; }
                else if (StrEqual(value, "health")) { response.Kind = 4; }
                else { return false; }
            }
            else if (field) { return false; }
        }
        else if (strncmp(text[at], "null", 4) == 0)
        {
            if (field && field != 2) { return false; }
            at += 4;
        }
        else if (strncmp(text[at], "true", 4) == 0 || strncmp(text[at], "false", 5) == 0)
        {
            if (field) { return false; }
            at += text[at] == 't' ? 4 : 5;
        }
        else
        {
            if (field == 1) { return false; }
            int number;
            if (!WTResponse_ReadNumber(text, at, field != 0, number)) { return false; }
            if (field == 2) { response.BatchId = number; response.HasBatchId = true; }
            else if (field == 4) { response.Accepted = number; response.HasAccepted = true; }
            else if (field == 8) { response.Executed = number; response.HasExecuted = true; }
            else if (field == 16) { response.DbErrors = number; response.HasDbErrors = true; }
        }
        WTResponse_SkipSpace(text, at);
        if (text[at] == '}')
        {
            at++;
            WTResponse_SkipSpace(text, at);
            return text[at] == '\0' && response.Kind != 0;
        }
        if (text[at++] != ',') { return false; }
        WTResponse_SkipSpace(text, at);
    }
    return false;
}
