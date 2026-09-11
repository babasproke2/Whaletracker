/** Rust outlet: one session, one in-flight batch, FIFO retries and a local-write barrier.
 * Requires the small, hash-checked helper patch in tools/apply_helpers.py.
 * Runtime disconnects never assume that a missing ACK means SQL did not commit.
 * See the combined report before hot-unloading with pending writes.
 */
#include <socket>
#if !defined WT_CONCURRENCY_HELPERS
    #error Run tools/apply_helpers.py in the pinned WhaleTracker checkout before compiling.
#endif

#define WT_RUST_SQL_MAX_BATCH_JSON 32768
#define WT_RUST_SQL_MAX_LINE 2048
#define WT_RUST_RECORD_JSON_MAX ((SAVE_QUERY_MAXLEN * 2) + 768)
#define WT_RUST_CONNECT_TIMEOUT 10.0
#define WT_RUST_ACK_TIMEOUT 45.0
#define WT_RUST_FLUSH_INTERVAL 0.10

enum struct WTRustWrite
{
    char Query[SAVE_QUERY_MAXLEN];
    char TypedFields[SAVE_QUERY_MAXLEN];
    char EventId[192];
    int UserId;
}

ConVar g_hRustSqlOutletEnabled;
ConVar g_hRustSqlHost;
ConVar g_hRustSqlPort;
ConVar g_hRustSqlQueueMax;
ConVar g_hRustSqlBatchMax;
ConVar g_hRustSqlServerId;
ConVar g_hRustSqlAuthToken;
ConVar g_hRustSqlDebug;
Socket g_hRustSqlSocket = null;
ArrayList g_hRustSqlQueue = null;
ArrayList g_hRustSqlInflight = null;
Handle g_hRustSqlFlushTimer = null;
Handle g_hRustSqlReconnectTimer = null;
bool g_bRustSqlConnecting;
bool g_bRustSqlConnected;
bool g_bRustSqlHelloReady;
bool g_bRustSqlAwaitingAck;
bool g_bRustSqlFlushQueued;
bool g_bRustSqlChangingConfig;
bool g_bRustSqlShutdownLocalFallback;
bool g_bRustSqlDraining;
int g_iRustSqlGeneration;
int g_iRustSqlNextBatchId = 1;
int g_iRustSqlInflightBatchId;
int g_iRustSqlNextEventId = 1;
char g_sRustSqlEventPrefix[96];
char g_sRustSqlRecvBuffer[WT_RUST_SQL_MAX_LINE];
int g_iRustSqlRecvBufferLen;
float g_fRustSqlDeadline;
float g_fRustSqlReconnectDelay = 2.0;
float g_fRustSqlPressureLogAt;

#include "rust_response_parser.sp"
#include "rust_json.sp"

bool WhaleTracker_RustSocketApiAvailable()
{
    return GetFeatureStatus(FeatureType_Native, "Socket.Connect") == FeatureStatus_Available;
}

bool WhaleTracker_UseRustSqlOutlet()
{
    return g_hRustSqlOutletEnabled != null && g_hRustSqlOutletEnabled.BoolValue
        && WhaleTracker_RustSocketApiAvailable();
}

bool WhaleTracker_RustHasPendingWrites()
{
    return (g_hRustSqlQueue != null && g_hRustSqlQueue.Length > 0)
        || (g_hRustSqlInflight != null && g_hRustSqlInflight.Length > 0);
}

// Called by the patched local save helpers. Unknown remote outcomes hold the
// barrier until a matching success ACK, not merely until the socket disconnects.
bool WhaleTracker_RustCanPumpLocal()
{
    return g_bRustSqlShutdownLocalFallback || !WhaleTracker_RustHasPendingWrites();
}

bool WhaleTracker_RustHasLocalWork()
{
    return g_PendingSaveQueries > 0 || (g_SaveQueue != null && g_SaveQueue.Length > 0);
}

void WhaleTracker_RustEnsureQueues()
{
    if (g_hRustSqlQueue == null) { g_hRustSqlQueue = new ArrayList(sizeof(WTRustWrite)); }
    if (g_hRustSqlInflight == null) { g_hRustSqlInflight = new ArrayList(sizeof(WTRustWrite)); }
}

void WhaleTracker_RustCancelTimer(Handle &timer)
{
    Handle previous = timer;
    timer = null;
    delete previous;
}

void WhaleTracker_BuildRustServerId(char[] buffer, int maxlen)
{
    g_hRustSqlServerId.GetString(buffer, maxlen);
    TrimString(buffer);
    if (buffer[0]) { return; }
    char hostname[96];
    ConVar host = FindConVar("hostname");
    if (host != null) { host.GetString(hostname, sizeof(hostname)); }
    else { strcopy(hostname, sizeof(hostname), "unknown"); }
    Format(buffer, maxlen, "%s:%d", hostname, g_iHostPort);
}

void WhaleTracker_RustNewEventPrefix()
{
    FormatEx(g_sRustSqlEventPrefix, sizeof(g_sRustSqlEventPrefix), "wt:%d:%d:%08x:%08x",
        g_iHostPort, GetTime(), GetURandomInt(), GetURandomInt());
    g_iRustSqlNextEventId = 1;
}

void WhaleTracker_BuildRustEventId(char[] buffer, int maxlen)
{
    if (!g_sRustSqlEventPrefix[0] || g_iRustSqlNextEventId >= 2147483647) { WhaleTracker_RustNewEventPrefix(); }
    FormatEx(buffer, maxlen, "%s:%d", g_sRustSqlEventPrefix, g_iRustSqlNextEventId++);
}

public void WhaleTracker_RustInit()
{
    g_hRustSqlOutletEnabled = CreateConVar("sm_whaletracker_rust_sql_outlet", "1", "Enable Rust outlet; drain pending work before changing.", _, true, 0.0, true, 1.0);
    g_hRustSqlHost = CreateConVar("sm_whaletracker_rust_host", "127.0.0.1", "Rust SQL outlet host");
    g_hRustSqlPort = CreateConVar("sm_whaletracker_rust_port", "28017", "Rust SQL outlet port", _, true, 1.0, true, 65535.0);
    g_hRustSqlQueueMax = CreateConVar("sm_whaletracker_rust_queue_max", "4096", "Maximum Rust-owned writes, including unacknowledged writes; overflow uses the ordered local queue.", _, true, 1.0, true, 65536.0);
    g_hRustSqlBatchMax = CreateConVar("sm_whaletracker_rust_batch_max", "64", "Maximum writes per batch", _, true, 1.0, true, 256.0);
    g_hRustSqlServerId = CreateConVar("sm_whaletracker_rust_server_id", "", "Optional hello identity");
    g_hRustSqlAuthToken = CreateConVar("sm_whaletracker_rust_auth_token", "", "Shared secret; match literally", FCVAR_PROTECTED);
    g_hRustSqlDebug = CreateConVar("sm_whaletracker_rust_sql_debug", "0", "Enable outlet transport diagnostics", _, true, 0.0, true, 1.0);
    g_hRustSqlOutletEnabled.AddChangeHook(WhaleTracker_RustEndpointChanged);
    g_hRustSqlHost.AddChangeHook(WhaleTracker_RustEndpointChanged);
    g_hRustSqlPort.AddChangeHook(WhaleTracker_RustEndpointChanged);
    g_hRustSqlAuthToken.AddChangeHook(WhaleTracker_RustEndpointChanged);
    g_hRustSqlServerId.AddChangeHook(WhaleTracker_RustEndpointChanged);
    RegAdminCmd("sm_wt_outlet_status", WhaleTracker_RustStatusCommand, ADMFLAG_ROOT, "Show Rust and local write ownership.");
    RegAdminCmd("sm_wt_outlet_drain", WhaleTracker_RustDrainCommand, ADMFLAG_ROOT, "Stop new Rust admissions and drain pending batches; use 0 to resume.");
    WhaleTracker_RustEnsureQueues();
    WhaleTracker_RustNewEventPrefix();
    WhaleTracker_RustCancelTimer(g_hRustSqlFlushTimer);
    g_hRustSqlFlushTimer = CreateTimer(WT_RUST_FLUSH_INTERVAL, WhaleTracker_RustFlushTimer, _, TIMER_REPEAT);
    WhaleTracker_RustConnectSocket();
    // Do not modify Socket's global CallbacksPerFrame/ConcatenateCallbacks here;
    // those settings are shared with unrelated plugins on the server.
}

public void WhaleTracker_RustEndpointChanged(ConVar convar, const char[] oldValue, const char[] newValue)
{
    if (g_bRustSqlChangingConfig || StrEqual(oldValue, newValue)) { return; }
    if (WhaleTracker_RustHasPendingWrites())
    {
        g_bRustSqlChangingConfig = true;
        convar.SetString(oldValue);
        g_bRustSqlChangingConfig = false;
        LogError("[WhaleTracker] Drain Rust-owned writes before changing outlet endpoint, identity, auth, or enable state.");
        return;
    }
    WhaleTracker_RustCancelTimer(g_hRustSqlReconnectTimer);
    WhaleTracker_RustDisconnectSocket();
    g_fRustSqlReconnectDelay = 2.0;
    WhaleTracker_RustConnectSocket();
}

public Action WhaleTracker_RustStatusCommand(int client, int args)
{
    ReplyToCommand(client, "Rust: queued=%d inflight=%d batch=%d hello=%d draining=%d; local: queued=%d executing=%d",
        g_hRustSqlQueue.Length, g_hRustSqlInflight.Length, g_iRustSqlInflightBatchId,
        g_bRustSqlHelloReady, g_bRustSqlDraining, g_SaveQueue != null ? g_SaveQueue.Length : 0, g_PendingSaveQueries);
    return Plugin_Handled;
}

public Action WhaleTracker_RustDrainCommand(int client, int args)
{
    char arg[8];
    if (args > 0) { GetCmdArg(1, arg, sizeof(arg)); }
    g_bRustSqlDraining = args == 0 || StringToInt(arg) != 0;
    ReplyToCommand(client, "Rust admissions %s; existing work retains ownership until acknowledged.", g_bRustSqlDraining ? "paused" : "resumed");
    return Plugin_Handled;
}

public bool WhaleTracker_RustQueueSqlWrite(const char[] query, int userId, bool forceSync)
{
    return WhaleTracker_RustQueueWrite(query, "", userId, forceSync);
}

public bool WhaleTracker_RustQueueTypedWrite(const char[] query, const char[] fieldsJson, int userId, bool forceSync)
{
    if (!fieldsJson[0]) { return false; }
    return WhaleTracker_RustQueueWrite(query, fieldsJson, userId, forceSync);
}

bool WhaleTracker_RustQueueWrite(const char[] query, const char[] fieldsJson, int userId, bool forceSync)
{
    if (!WhaleTracker_UseRustSqlOutlet() || g_bRustSqlDraining || forceSync || g_bShuttingDown
        || WhaleTracker_RustHasLocalWork()) { return false; }
    // Fresh work can use the legacy local queue when no remote writes own a
    // barrier. Otherwise retain it behind the uncertain remote writes.
    if ((!g_bRustSqlConnected || !g_bRustSqlHelloReady) && !WhaleTracker_RustHasPendingWrites()) { return false; }
    WhaleTracker_RustEnsureQueues();
    if (!query[0] || strlen(query) >= SAVE_QUERY_MAXLEN || strlen(fieldsJson) >= SAVE_QUERY_MAXLEN) { return false; }
    int owned = g_hRustSqlQueue.Length + g_hRustSqlInflight.Length;
    if (owned >= g_hRustSqlQueueMax.IntValue)
    {
        if (GetEngineTime() >= g_fRustSqlPressureLogAt)
        {
            LogError("[WhaleTracker] Rust capacity reached; new work deferred to the local queue behind the remote barrier.");
            g_fRustSqlPressureLogAt = GetEngineTime() + 10.0;
        }
        return false; // The patched caller queues locally WITHOUT overtaking us.
    }
    WTRustWrite record;
    strcopy(record.Query, sizeof(record.Query), query);
    strcopy(record.TypedFields, sizeof(record.TypedFields), fieldsJson);
    record.UserId = userId;
    WhaleTracker_BuildRustEventId(record.EventId, sizeof(record.EventId));
    g_hRustSqlQueue.PushArray(record);
    // The periodic/coalesced flush collects a burst rather than sending one TCP
    // batch for every synchronous gameplay callback.
    return true;
}

void WhaleTracker_RustClearInflight()
{
    g_bRustSqlAwaitingAck = false;
    g_iRustSqlInflightBatchId = 0;
    if (g_hRustSqlInflight != null) { g_hRustSqlInflight.Clear(); }
}

void WhaleTracker_RustRequeueInflight()
{
    g_bRustSqlAwaitingAck = false;
    g_iRustSqlInflightBatchId = 0;
    if (g_hRustSqlInflight == null || g_hRustSqlInflight.Length == 0) { return; }
    int prefix = g_hRustSqlInflight.Length;
    int queued = g_hRustSqlQueue.Length;
    g_hRustSqlQueue.Resize(queued + prefix);
    WTRustWrite record;
    for (int i = queued - 1; i >= 0; i--)
    {
        g_hRustSqlQueue.GetArray(i, record);
        g_hRustSqlQueue.SetArray(i + prefix, record);
    }
    for (int i = 0; i < prefix; i++)
    {
        g_hRustSqlInflight.GetArray(i, record);
        g_hRustSqlQueue.SetArray(i, record);
    }
    g_hRustSqlInflight.Clear();
}

void WhaleTracker_RustDisconnectSocket()
{
    WhaleTracker_RustRequeueInflight();
    g_iRustSqlGeneration++;
    g_bRustSqlConnecting = false;
    g_bRustSqlConnected = false;
    g_bRustSqlHelloReady = false;
    g_bRustSqlFlushQueued = false;
    g_fRustSqlDeadline = 0.0;
    g_iRustSqlRecvBufferLen = 0;
    Socket previous = g_hRustSqlSocket;
    g_hRustSqlSocket = null;
    delete previous;
}

void WhaleTracker_RustScheduleReconnect()
{
    if (g_bShuttingDown || !WhaleTracker_UseRustSqlOutlet() || g_hRustSqlReconnectTimer != null) { return; }
    g_hRustSqlReconnectTimer = CreateTimer(g_fRustSqlReconnectDelay + GetRandomFloat(0.0, 1.0), WhaleTracker_RustReconnectTimer);
    g_fRustSqlReconnectDelay *= 2.0;
    if (g_fRustSqlReconnectDelay > 30.0) { g_fRustSqlReconnectDelay = 30.0; }
}

void WhaleTracker_RustConnectSocket()
{
    if (g_bShuttingDown || !WhaleTracker_UseRustSqlOutlet() || g_bRustSqlConnecting || g_bRustSqlConnected
        || g_hRustSqlReconnectTimer != null) { return; }
    WhaleTracker_RustDisconnectSocket();
    char host[128];
    g_hRustSqlHost.GetString(host, sizeof(host));
    g_hRustSqlSocket = new Socket(SOCKET_TCP, WhaleTracker_RustOnSocketError);
    if (g_hRustSqlSocket == null) { WhaleTracker_RustScheduleReconnect(); return; }
    g_hRustSqlSocket.SetOption(SocketKeepAlive, 1);
    g_hRustSqlSocket.SetOption(SocketSendBuffer, 65536);
    g_hRustSqlSocket.SetOption(SocketReceiveBuffer, 65536);
    g_bRustSqlConnecting = true;
    g_fRustSqlDeadline = GetEngineTime() + WT_RUST_CONNECT_TIMEOUT;
    g_hRustSqlSocket.Connect(WhaleTracker_RustOnSocketConnected, WhaleTracker_RustOnSocketReceive,
        WhaleTracker_RustOnSocketDisconnected, host, g_hRustSqlPort.IntValue);
}

public Action WhaleTracker_RustReconnectTimer(Handle timer, any data)
{
    if (timer != g_hRustSqlReconnectTimer) { return Plugin_Stop; }
    g_hRustSqlReconnectTimer = null;
    WhaleTracker_RustConnectSocket();
    return Plugin_Stop;
}

public Action WhaleTracker_RustFlushTimer(Handle timer, any data)
{
    if (timer != g_hRustSqlFlushTimer) { return Plugin_Stop; }
    if (g_fRustSqlDeadline > 0.0 && GetEngineTime() >= g_fRustSqlDeadline)
    {
        LogError("[WhaleTracker] Rust handshake/ACK timeout; retaining stable IDs for retry.");
        WhaleTracker_RustDisconnectSocket();
        WhaleTracker_RustScheduleReconnect();
    }
    WhaleTracker_RustFlushSqlBatch();
    return Plugin_Continue;
}

public void WhaleTracker_RustOnSocketConnected(Socket socket, any data)
{
    if (socket != g_hRustSqlSocket || !g_bRustSqlConnecting) { return; }
    g_bRustSqlConnecting = false;
    g_bRustSqlConnected = true;
    g_fRustSqlDeadline = GetEngineTime() + WT_RUST_CONNECT_TIMEOUT;
    if (g_hRustSqlDebug.BoolValue)
    {
        LogMessage("[WhaleTracker] Rust outlet connected; waiting for hello acknowledgement.");
    }
    char serverId[128], escapedServer[257], auth[192], escapedAuth[385], hello[1024];
    WhaleTracker_BuildRustServerId(serverId, sizeof(serverId));
    g_hRustSqlAuthToken.GetString(auth, sizeof(auth));
    WhaleTracker_RustJsonEscape(serverId, escapedServer, sizeof(escapedServer));
    WhaleTracker_RustJsonEscape(auth, escapedAuth, sizeof(escapedAuth));
    int length = FormatEx(hello, sizeof(hello),
        "{\"type\":\"hello\",\"service\":\"whaletracker_sql_outlet\",\"proto\":1,\"server_id\":\"%s\",\"auth\":\"%s\",\"ts\":%d}\n",
        escapedServer, escapedAuth, GetTime());
    socket.Send(hello, length);
}

public void WhaleTracker_RustOnSocketDisconnected(Socket socket, any data)
{
    if (socket != g_hRustSqlSocket) { return; }
    WhaleTracker_RustDisconnectSocket();
    WhaleTracker_RustScheduleReconnect();
}

public void WhaleTracker_RustOnSocketError(Socket socket, const int errorType, const int errorNum, any data)
{
    if (socket != g_hRustSqlSocket) { return; }
    LogError("[WhaleTracker] Rust outlet socket error type=%d errno=%d; retained for retry", errorType, errorNum);
    WhaleTracker_RustDisconnectSocket();
    WhaleTracker_RustScheduleReconnect();
}

public void WhaleTracker_RustOnSocketReceive(Socket socket, const char[] bytes, const int length, any data)
{
    if (socket != g_hRustSqlSocket || length <= 0) { return; }
    int generation = g_iRustSqlGeneration;
    for (int i = 0; i < length; i++)
    {
        if (socket != g_hRustSqlSocket || generation != g_iRustSqlGeneration) { return; }
        if (bytes[i] == '\0' || g_iRustSqlRecvBufferLen >= sizeof(g_sRustSqlRecvBuffer) - 1)
        {
            WhaleTracker_RustProtocolFault();
            return;
        }
        if (bytes[i] == '\n')
        {
            char line[WT_RUST_SQL_MAX_LINE];
            g_sRustSqlRecvBuffer[g_iRustSqlRecvBufferLen] = '\0';
            strcopy(line, sizeof(line), g_sRustSqlRecvBuffer);
            g_iRustSqlRecvBufferLen = 0;
            TrimString(line);
            if (line[0]) { WhaleTracker_RustHandleBackendLine(line); }
        }
        else { g_sRustSqlRecvBuffer[g_iRustSqlRecvBufferLen++] = bytes[i]; }
    }
}

void WhaleTracker_RustProtocolFault()
{
    LogError("[WhaleTracker] Rust protocol failure; no automatic local replay of uncertain writes.");
    WhaleTracker_RustDisconnectSocket();
    WhaleTracker_RustScheduleReconnect();
}

void WhaleTracker_RustHandleBackendLine(const char[] line)
{
    WTRustResponse response;
    if (!WTResponse_Parse(line, response)) { WhaleTracker_RustProtocolFault(); return; }
    if (response.Kind == 1)
    {
        if (!g_bRustSqlConnected || g_bRustSqlHelloReady) { return; }
        g_bRustSqlHelloReady = true;
        g_fRustSqlDeadline = 0.0;
        WhaleTracker_RustRequestFlush();
    }
    else if (response.Kind == 2)
    {
        if (!g_bRustSqlAwaitingAck || !response.HasBatchId || response.BatchId != g_iRustSqlInflightBatchId) { return; }
        if (!response.HasAccepted || !response.HasExecuted || !response.HasDbErrors || response.DbErrors != 0
            || response.Accepted != response.Executed || response.Accepted > g_hRustSqlInflight.Length)
        {
            WhaleTracker_RustProtocolFault();
            return;
        }
        WhaleTracker_RustClearInflight();
        g_fRustSqlDeadline = 0.0;
        g_fRustSqlReconnectDelay = 2.0;
        if (!WhaleTracker_RustHasPendingWrites()) { RequestPumpSaveQueue(); }
        WhaleTracker_RustRequestFlush();
    }
    else if (response.Kind == 3)
    {
        if (response.HasBatchId && (!g_bRustSqlAwaitingAck || response.BatchId != g_iRustSqlInflightBatchId)) { return; }
        WhaleTracker_RustProtocolFault();
    }
}

void WhaleTracker_RustRequestFlush()
{
    if (g_bRustSqlFlushQueued) { return; }
    g_bRustSqlFlushQueued = true;
    RequestFrame(WhaleTracker_RustFlushFrame, g_iRustSqlGeneration);
}

public void WhaleTracker_RustFlushFrame(any generation)
{
    if (generation != g_iRustSqlGeneration) { return; }
    g_bRustSqlFlushQueued = false;
    WhaleTracker_RustFlushSqlBatch();
}

public void WhaleTracker_RustFlushSqlBatch()
{
    if (g_bShuttingDown || !WhaleTracker_UseRustSqlOutlet() || g_bRustSqlAwaitingAck) { return; }
    if (!g_bRustSqlConnected || !g_bRustSqlHelloReady || g_hRustSqlSocket == null) { WhaleTracker_RustConnectSocket(); return; }
    if (g_hRustSqlQueue == null || g_hRustSqlQueue.Length == 0) { return; }
    char output[WT_RUST_SQL_MAX_BATCH_JSON], encoded[WT_RUST_RECORD_JSON_MAX];
    char sql[(SAVE_QUERY_MAXLEN * 2) + 1], eventId[385];
    int batchId = g_iRustSqlNextBatchId++;
    if (g_iRustSqlNextBatchId <= 0) { g_iRustSqlNextBatchId = 1; }
    int position = FormatEx(output, sizeof(output), "{\"type\":\"sql_batch\",\"batch_id\":%d,\"sent_at\":%d,\"writes\":[", batchId, GetTime());
    int count = 0;
    WTRustWrite record;
    for (int i = 0; i < g_hRustSqlQueue.Length && count < g_hRustSqlBatchMax.IntValue; i++)
    {
        g_hRustSqlQueue.GetArray(i, record);
        WhaleTracker_RustJsonEscape(record.EventId, eventId, sizeof(eventId));
        int length;
        if (record.TypedFields[0])
        {
            length = FormatEx(encoded, sizeof(encoded), "{\"event_id\":\"%s\",\"user_id\":%d,%s}", eventId, record.UserId, record.TypedFields);
        }
        else
        {
            WhaleTracker_RustJsonEscape(record.Query, sql, sizeof(sql));
            length = FormatEx(encoded, sizeof(encoded), "{\"sql\":\"%s\",\"user_id\":%d,\"event_id\":\"%s\"}", sql, record.UserId, eventId);
        }
        if (length <= 0 || length >= sizeof(encoded) - 1 || position + length + 5 >= sizeof(output)) { break; }
        if (count > 0) { output[position++] = ','; }
        strcopy(output[position], sizeof(output) - position, encoded);
        position += length;
        g_hRustSqlInflight.PushArray(record);
        count++;
    }
    if (count == 0) { LogError("[WhaleTracker] Queue head cannot fit an outlet frame; retained, not silently truncated."); return; }
    position += FormatEx(output[position], sizeof(output) - position, "]}\n");
    int remaining = g_hRustSqlQueue.Length - count;
    for (int i = 0; i < remaining; i++)
    {
        g_hRustSqlQueue.GetArray(i + count, record);
        g_hRustSqlQueue.SetArray(i, record);
    }
    g_hRustSqlQueue.Resize(remaining);
    g_iRustSqlInflightBatchId = batchId;
    g_bRustSqlAwaitingAck = true;
    g_fRustSqlDeadline = GetEngineTime() + WT_RUST_ACK_TIMEOUT;
    // Ownership and queue removal precede any reentrant extension callback.
    g_hRustSqlSocket.Send(output, position);
}

public void WhaleTracker_RustShutdown()
{
    WhaleTracker_RustCancelTimer(g_hRustSqlFlushTimer);
    WhaleTracker_RustCancelTimer(g_hRustSqlReconnectTimer);
    bool uncertain = g_bRustSqlAwaitingAck || WhaleTracker_RustHasPendingWrites();
    WhaleTracker_RustDisconnectSocket();
    if (uncertain)
    {
        LogError("[WhaleTracker] Unloading with remote-owned writes: legacy synchronous local fallback may duplicate/reorder remotely committed SQL. Drain first with sm_wt_outlet_drain and verify sm_wt_outlet_status.");
    }
    // Compatibility escape hatch ONLY at unload. There is no way to establish
    // exactly-once handoff from raw SQL plus a missing ACK during plugin teardown.
    g_bRustSqlShutdownLocalFallback = true;
    WTRustWrite record;
    if (g_hRustSqlQueue != null)
    {
        for (int i = 0; i < g_hRustSqlQueue.Length; i++)
        {
            g_hRustSqlQueue.GetArray(i, record);
            QueueLocalSaveQuery(record.Query, record.UserId, true);
        }
    }
    delete g_hRustSqlQueue;
    delete g_hRustSqlInflight;
    // Deferred local writes were enqueued after the remote writes.
    FlushSaveQueueSync();
}
