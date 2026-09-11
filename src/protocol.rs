//! Protocol v1, bounded JSON lines and bounded waits for commit confirmation.
use crate::{
    config::{now_ms, now_secs, Config},
    journal::StoredWrite,
    runtime_limits::DeadlineStream,
    sink::SqlSink,
};
use serde::{Deserialize, Serialize};
use std::{
    io::{self, BufRead, BufReader, Write},
    net::TcpStream,
    sync::{
        atomic::{AtomicU64, Ordering},
        Arc,
    },
    time::Duration,
};

static GENERATED_ID: AtomicU64 = AtomicU64::new(1);

#[derive(Debug, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum Inbound {
    Hello {
        service: Option<String>,
        proto: Option<u32>,
        server_id: Option<String>,
        auth: Option<String>,
        ts: Option<i64>,
    },
    SqlBatch {
        batch_id: Option<i64>,
        sent_at: Option<i64>,
        writes: Vec<InboundWrite>,
    },
    Health,
}

#[derive(Debug, Deserialize)]
#[serde(untagged)]
enum InboundWrite {
    Raw(RawWrite),
    Typed(TypedWrite),
}

#[derive(Debug, Deserialize)]
struct RawWrite {
    sql: String,
    #[serde(default)]
    user_id: Option<u32>,
    #[serde(default)]
    event_id: Option<String>,
    #[serde(default)]
    force_sync: Option<bool>,
}

#[derive(Debug, Deserialize)]
struct TypedWrite {
    kind: String,
    #[serde(default)]
    steamid: Option<String>,
    #[serde(default)]
    host_port: Option<u16>,
    #[serde(default)]
    user_id: Option<u32>,
    #[serde(default)]
    event_id: Option<String>,
}

impl InboundWrite {
    fn materialize(
        self,
        batch_id: Option<i64>,
        max_sql_bytes: usize,
    ) -> Result<StoredWrite, &'static str> {
        let raw = match self {
            Self::Raw(raw) => raw,
            Self::Typed(typed) => {
                let port = typed
                    .host_port
                    .filter(|port| *port > 0)
                    .ok_or("typed write requires host_port")?;
                let sql = match typed.kind.as_str() {
                    "online_remove" => {
                        let steam = typed
                            .steamid
                            .as_deref()
                            .ok_or("online_remove missing steamid")?;
                        if !(16..=20).contains(&steam.len())
                            || !steam.bytes().all(|byte| byte.is_ascii_digit())
                        {
                            return Err("invalid steamid64");
                        }
                        format!("DELETE FROM whaletracker_online WHERE steamid = '{steam}' AND host_port = {port}")
                    }
                    "online_clear_host" => {
                        format!("DELETE FROM whaletracker_online WHERE host_port = {port}")
                    }
                    "server_clear_port" => {
                        format!("DELETE FROM whaletracker_servers WHERE port = {port}")
                    }
                    _ => return Err("unknown typed write kind"),
                };
                RawWrite {
                    sql,
                    user_id: typed.user_id,
                    event_id: typed.event_id,
                    force_sync: None,
                }
            }
        };
        if raw.sql.len() > max_sql_bytes {
            return Err("sql too large");
        }
        // force_sync is compatibility metadata. Every successful ACK already
        // waits for SQL execution and the completion journal, not just enqueue.
        let _force_sync = raw.force_sync;
        let event_id = raw.event_id.unwrap_or_else(|| {
            format!(
                "rust-{}-{}-{}",
                std::process::id(),
                now_ms(),
                GENERATED_ID.fetch_add(1, Ordering::Relaxed)
            )
        });
        if event_id.is_empty()
            || event_id.len() > 192
            || event_id.bytes().any(|byte| byte.is_ascii_control())
        {
            return Err("invalid event_id");
        }
        Ok(StoredWrite {
            event_id,
            sql: raw.sql,
            user_id: raw.user_id,
            batch_id,
            ts_ms: now_ms(),
        })
    }
}

pub fn handle_client(stream: TcpStream, sink: Arc<SqlSink>, cfg: Config) -> io::Result<()> {
    stream.set_write_timeout(Some(Duration::from_secs(10)))?;
    stream.set_nodelay(true)?;
    let reader_stream = stream.try_clone()?;
    let mut reader = BufReader::new(DeadlineStream::new(reader_stream, cfg.frame_timeout));
    let mut writer = stream;
    let mut authenticated = cfg.auth_token.is_empty();
    let mut bytes = Vec::with_capacity(4096);
    loop {
        reader.get_mut().begin_frame(cfg.frame_timeout);
        match read_frame(&mut reader, cfg.max_frame_bytes, &mut bytes)? {
            FrameRead::Eof => return Ok(()),
            FrameRead::TooLong => {
                error(&mut writer, None, "frame too large")?;
                return Ok(());
            }
            FrameRead::Line => {}
        }
        if bytes.iter().all(u8::is_ascii_whitespace) {
            continue;
        }
        let message: Inbound = match serde_json::from_slice(&bytes) {
            Ok(message) => message,
            Err(_) => {
                sink.counters.parse_errors.fetch_add(1, Ordering::Relaxed);
                // Never echo a malformed hello/authentication token to the log.
                error(&mut writer, None, "invalid JSON message")?;
                return Ok(());
            }
        };
        match message {
            Inbound::Hello {
                service,
                proto,
                server_id,
                auth,
                ts,
            } => {
                if !cfg.auth_token.is_empty() && auth.as_deref() != Some(cfg.auth_token.as_str()) {
                    error(&mut writer, None, "unauthorized")?;
                    return Ok(());
                }
                if proto.is_some_and(|version| version != 1) {
                    error(&mut writer, None, "unsupported protocol version")?;
                    return Ok(());
                }
                authenticated = true;
                if cfg.debug {
                    eprintln!(
                        "[sql-sink] hello service={service:?} server_id={server_id:?} ts={ts:?}"
                    );
                }
                send_line(
                    &mut writer,
                    &serde_json::json!({"type":"hello_ack", "service":"whaletracker_sql_sink", "proto":1, "ts":now_secs()}),
                )?;
            }
            Inbound::SqlBatch {
                batch_id,
                sent_at,
                writes,
            } => {
                if !authenticated {
                    error(&mut writer, batch_id, "hello required")?;
                    return Ok(());
                }
                if writes.len() > cfg.max_inbound_writes {
                    error(&mut writer, batch_id, "too many writes")?;
                    return Ok(());
                }
                let materialized: Result<Vec<_>, _> = writes
                    .into_iter()
                    .map(|write| write.materialize(batch_id, cfg.max_sql_bytes))
                    .collect();
                let writes = match materialized {
                    Ok(writes) => writes,
                    Err(message) => {
                        error(&mut writer, batch_id, message)?;
                        return Ok(());
                    }
                };
                let requested = writes.len();
                let accepted = match sink.enqueue(writes) {
                    Ok(accepted) => accepted,
                    Err(message) => {
                        sink.counters
                            .rejected
                            .fetch_add(requested as u64, Ordering::Relaxed);
                        error(&mut writer, batch_id, message)?;
                        return Ok(());
                    }
                };
                let Some(executed) = accepted.completion.wait(cfg.ack_timeout) else {
                    // Accepted writes keep their permits and IDs. A client timeout
                    // does not cancel or falsely acknowledge unfinished SQL.
                    error(
                        &mut writer,
                        batch_id,
                        "commit acknowledgement timed out; retry with the same event IDs",
                    )?;
                    return Ok(());
                };
                if cfg.debug {
                    eprintln!("[sql-sink] batch={batch_id:?} accepted={} confirmed={executed} sent_at={sent_at:?}", accepted.count);
                }
                send_line(
                    &mut writer,
                    &serde_json::json!({
                        "type":"ack", "batch_id":batch_id, "accepted":accepted.count, "executed":executed,
                        "db_errors":0, "queue_depth":sink.usage().rows, "ts":now_secs(),
                    }),
                )?;
            }
            Inbound::Health => {
                if !authenticated {
                    error(&mut writer, None, "hello required")?;
                    return Ok(());
                }
                let usage = sink.usage();
                let stats = &sink.counters;
                send_line(
                    &mut writer,
                    &serde_json::json!({
                        "type":"health", "queue_depth":usage.rows, "queue_bytes":usage.bytes,
                        "online_queue_depth":usage.lanes[0], "stats_queue_depth":usage.lanes[1], "logs_queue_depth":usage.lanes[2],
                        "dedupe_events":sink.dedupe.len(), "accepted_writes":stats.accepted.load(Ordering::Relaxed),
                        "executed_writes":stats.executed.load(Ordering::Relaxed), "db_errors":stats.db_errors.load(Ordering::Relaxed),
                        "parse_errors":stats.parse_errors.load(Ordering::Relaxed), "dropped_writes":stats.rejected.load(Ordering::Relaxed),
                        "journal_pending_startup":stats.journal_pending_startup.load(Ordering::Relaxed),
                        "journal_replayed_startup":stats.journal_replayed_startup.load(Ordering::Relaxed),
                        "journal_done_records_startup":stats.journal_done_startup.load(Ordering::Relaxed),
                        "journal_bad_lines_startup":0, "journal_compactions":stats.compactions.load(Ordering::Relaxed), "ts":now_secs(),
                    }),
                )?;
            }
        }
    }
}

fn error(stream: &mut TcpStream, batch_id: Option<i64>, message: &str) -> io::Result<()> {
    send_line(
        stream,
        &serde_json::json!({"type":"error", "batch_id":batch_id, "message":message, "ts":now_secs()}),
    )
}

fn send_line(stream: &mut TcpStream, message: &impl Serialize) -> io::Result<()> {
    let mut bytes = serde_json::to_vec(message).map_err(io::Error::other)?;
    bytes.push(b'\n');
    stream.write_all(&bytes)
}

#[derive(Debug, PartialEq, Eq)]
pub enum FrameRead {
    Line,
    Eof,
    TooLong,
}

pub fn read_frame<R: BufRead>(
    reader: &mut R,
    maximum: usize,
    output: &mut Vec<u8>,
) -> io::Result<FrameRead> {
    output.clear();
    loop {
        let bytes = reader.fill_buf()?;
        if bytes.is_empty() {
            if output.is_empty() {
                return Ok(FrameRead::Eof);
            }
            return Err(io::Error::new(
                io::ErrorKind::UnexpectedEof,
                "unterminated protocol frame",
            ));
        }
        let newline = bytes.iter().position(|byte| *byte == b'\n');
        let count = newline.unwrap_or(bytes.len());
        if output.len().saturating_add(count) > maximum {
            output.clear();
            return Ok(FrameRead::TooLong);
        }
        output.extend_from_slice(&bytes[..count]);
        reader.consume(count + usize::from(newline.is_some()));
        if newline.is_some() {
            if output.last() == Some(&b'\r') {
                output.pop();
            }
            return Ok(FrameRead::Line);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn frames_are_bounded_and_newline_terminated() {
        let mut out = Vec::new();
        let mut reader = io::Cursor::new(b"ok\r\n");
        assert_eq!(
            read_frame(&mut reader, 4, &mut out).unwrap(),
            FrameRead::Line
        );
        assert_eq!(out, b"ok");
        assert_eq!(
            read_frame(&mut io::Cursor::new(b"abcdef\n"), 4, &mut out).unwrap(),
            FrameRead::TooLong
        );
        assert!(read_frame(&mut io::Cursor::new(b"partial"), 32, &mut out).is_err());
    }
    #[test]
    fn typed_write_and_limits_are_preserved() {
        let typed = InboundWrite::Typed(TypedWrite {
            kind: "online_remove".into(),
            steamid: Some("76561198115534197".into()),
            host_port: Some(27015),
            user_id: Some(7),
            event_id: Some("test".into()),
        });
        let write = typed.materialize(Some(10), 8192).unwrap();
        assert_eq!(write.sql, "DELETE FROM whaletracker_online WHERE steamid = '76561198115534197' AND host_port = 27015");
        let bad = InboundWrite::Raw(RawWrite {
            sql: "abcdef".into(),
            user_id: None,
            event_id: Some("test".into()),
            force_sync: None,
        });
        assert!(bad.materialize(None, 4).is_err());
    }
}
