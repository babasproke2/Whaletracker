//! Backward-compatible JSON-lines journal. Admission is durably appended in one
//! batch. Queue locks are never held during file I/O. Corruption fails closed.
use crate::{config::now_ms, journal_lock::JournalLock};
use serde::{Deserialize, Serialize};
use std::{
    collections::{HashMap, HashSet, VecDeque},
    fs::{self, File, OpenOptions},
    io::{BufRead, BufReader, Write},
    path::{Path, PathBuf},
    sync::{
        atomic::{AtomicU64, Ordering},
        Mutex,
    },
};

static TEMP_SERIAL: AtomicU64 = AtomicU64::new(1);
const MAX_JOURNAL_LINE: usize = 4 * 1024 * 1024;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct StoredWrite {
    pub event_id: String,
    pub sql: String,
    pub user_id: Option<u32>,
    pub batch_id: Option<i64>,
    pub ts_ms: u64,
}

#[derive(Serialize, Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum Record {
    Pending(StoredWrite),
    Done { event_id: String, ts_ms: u64 },
}

#[derive(Serialize)]
struct PendingLine<'a> {
    r#type: &'static str,
    #[serde(flatten)]
    write: &'a StoredWrite,
}

#[derive(Default)]
pub struct Replay {
    pub pending: Vec<StoredWrite>,
    pub done: Vec<String>,
    pub done_records: usize,
}

pub struct Journal {
    file: Mutex<Option<File>>,
    path: Option<PathBuf>,
    _process_lock: Option<JournalLock>,
}

fn private_options() -> OpenOptions {
    let mut options = OpenOptions::new();
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    options
}

impl Journal {
    pub fn open(path: &str) -> Result<Self, String> {
        if path.trim().is_empty() {
            return Ok(Self {
                file: Mutex::new(None),
                path: None,
                _process_lock: None,
            });
        }
        let path = PathBuf::from(path);
        let process_lock = JournalLock::acquire(&path)?;
        let file = private_options()
            .create(true)
            .append(true)
            .read(true)
            .open(&path)
            .map_err(|err| err.to_string())?;
        file.sync_all().map_err(|err| err.to_string())?;
        sync_parent(&path)?;
        Ok(Self {
            file: Mutex::new(Some(file)),
            path: Some(path),
            _process_lock: Some(process_lock),
        })
    }

    pub fn append_pending(&self, writes: &[StoredWrite]) -> Result<(), String> {
        if self.path.is_none() || writes.is_empty() {
            return Ok(());
        }
        let mut bytes = Vec::new();
        for write in writes {
            serde_json::to_writer(
                &mut bytes,
                &PendingLine {
                    r#type: "pending",
                    write,
                },
            )
            .map_err(|err| err.to_string())?;
            bytes.push(b'\n');
        }
        self.append_bytes(&bytes)
    }

    pub fn append_done(&self, ids: &[&str]) -> Result<(), String> {
        if self.path.is_none() || ids.is_empty() {
            return Ok(());
        }
        let mut bytes = Vec::new();
        let timestamp = now_ms();
        for id in ids {
            serde_json::to_writer(
                &mut bytes,
                &Record::Done {
                    event_id: (*id).to_string(),
                    ts_ms: timestamp,
                },
            )
            .map_err(|err| err.to_string())?;
            bytes.push(b'\n');
        }
        self.append_bytes(&bytes)
    }

    fn append_bytes(&self, bytes: &[u8]) -> Result<(), String> {
        let mut guard = self.file.lock().expect("journal mutex poisoned");
        let Some(file) = guard.as_mut() else {
            return Ok(());
        };
        let before = file.metadata().map_err(|err| err.to_string())?.len();
        if let Err(err) = file.write_all(bytes).and_then(|_| file.sync_data()) {
            if let Err(rollback) = file.set_len(before).and_then(|_| file.sync_data()) {
                // Continuing could concatenate a torn record with the next batch.
                eprintln!("journal append failed: {err}; rollback failed: {rollback}");
                std::process::abort();
            }
            return Err(err.to_string());
        }
        Ok(())
    }

    pub fn replay(
        &self,
        dedupe_capacity: usize,
        max_rows: usize,
        max_bytes: usize,
    ) -> Result<Replay, String> {
        let _guard = self.file.lock().expect("journal mutex poisoned");
        match &self.path {
            Some(path) => read_state(path, dedupe_capacity, max_rows, max_bytes),
            None => Ok(Replay::default()),
        }
    }

    pub fn compact(
        &self,
        dedupe_capacity: usize,
        minimum_bytes: u64,
        max_rows: usize,
        max_bytes: usize,
    ) -> Result<bool, String> {
        if minimum_bytes == 0 {
            return Ok(false);
        }
        let Some(path) = &self.path else {
            return Ok(false);
        };
        let mut guard = self.file.lock().expect("journal mutex poisoned");
        let Some(file) = guard.as_mut() else {
            return Ok(false);
        };
        if file.metadata().map_err(|err| err.to_string())?.len() < minimum_bytes {
            return Ok(false);
        }
        let state = read_state(path, dedupe_capacity, max_rows, max_bytes)?;
        let suffix = TEMP_SERIAL.fetch_add(1, Ordering::Relaxed);
        let mut temp = path.as_os_str().to_os_string();
        temp.push(format!(".compact.{}.{suffix}", std::process::id()));
        let temp = PathBuf::from(temp);
        let result = (|| {
            let mut replacement = private_options()
                .create_new(true)
                .append(true)
                .read(true)
                .open(&temp)
                .map_err(|err| err.to_string())?;
            {
                let mut writer = std::io::BufWriter::new(&mut replacement);
                for write in &state.pending {
                    serde_json::to_writer(
                        &mut writer,
                        &PendingLine {
                            r#type: "pending",
                            write,
                        },
                    )
                    .map_err(|err| err.to_string())?;
                    writer.write_all(b"\n").map_err(|err| err.to_string())?;
                }
                for event_id in &state.done {
                    serde_json::to_writer(
                        &mut writer,
                        &Record::Done {
                            event_id: event_id.clone(),
                            ts_ms: now_ms(),
                        },
                    )
                    .map_err(|err| err.to_string())?;
                    writer.write_all(b"\n").map_err(|err| err.to_string())?;
                }
                writer.flush().map_err(|err| err.to_string())?;
            }
            replacement.sync_all().map_err(|err| err.to_string())?;
            fs::rename(&temp, path).map_err(|err| err.to_string())?;
            // Never reopen after rename: a reopen failure would leave the writer
            // appending to the unlinked old inode.
            *file = replacement;
            sync_parent(path)?;
            Ok(true)
        })();
        if result.is_err() {
            let _ = fs::remove_file(&temp);
        }
        result
    }
}

fn sync_parent(path: &Path) -> Result<(), String> {
    #[cfg(unix)]
    {
        let parent = path
            .parent()
            .filter(|p| !p.as_os_str().is_empty())
            .unwrap_or(Path::new("."));
        File::open(parent)
            .and_then(|file| file.sync_all())
            .map_err(|err| err.to_string())?;
    }
    Ok(())
}

pub fn read_state(
    path: &Path,
    dedupe_capacity: usize,
    max_rows: usize,
    max_bytes: usize,
) -> Result<Replay, String> {
    let file =
        File::open(path).map_err(|err| format!("cannot read journal {}: {err}", path.display()))?;
    let mut reader = BufReader::new(file);
    let mut pending: HashMap<String, (usize, StoredWrite)> = HashMap::new();
    let mut pending_bytes = 0usize;
    let mut done = VecDeque::new();
    let mut done_set = HashSet::new();
    let mut done_records = 0;
    let mut ordinal = 0;
    let mut line = Vec::new();
    loop {
        line.clear();
        // Bound allocations before reading a possibly damaged line.
        loop {
            let bytes = reader.fill_buf().map_err(|err| err.to_string())?;
            if bytes.is_empty() {
                break;
            }
            let newline = bytes.iter().position(|byte| *byte == b'\n');
            let count = newline.map(|at| at + 1).unwrap_or(bytes.len());
            if line.len().saturating_add(count) > MAX_JOURNAL_LINE {
                return Err(format!(
                    "journal line {} exceeds the recovery limit; file preserved",
                    ordinal + 1
                ));
            }
            line.extend_from_slice(&bytes[..count]);
            reader.consume(count);
            if newline.is_some() {
                break;
            }
        }
        if line.is_empty() {
            break;
        }
        ordinal += 1;
        if line.last() != Some(&b'\n') {
            return Err(format!(
                "torn journal line {ordinal}; file preserved for repair"
            ));
        }
        if line.iter().all(u8::is_ascii_whitespace) {
            continue;
        }
        let record: Record = serde_json::from_slice(&line)
            .map_err(|err| format!("corrupt journal line {ordinal}: {err}; file preserved"))?;
        match record {
            Record::Pending(write) => {
                if write.event_id.is_empty() || write.event_id.len() > 192 {
                    return Err(format!("invalid journal event ID on line {ordinal}"));
                }
                if done_set.contains(&write.event_id) {
                    continue;
                }
                if let Some((_, previous)) = pending.get(&write.event_id) {
                    if previous.sql != write.sql || previous.user_id != write.user_id {
                        return Err(format!(
                            "conflicting journal payload for {}",
                            write.event_id
                        ));
                    }
                } else {
                    let bytes = write
                        .sql
                        .len()
                        .saturating_add(write.event_id.len())
                        .saturating_add(256);
                    if pending.len() >= max_rows || bytes > max_bytes.saturating_sub(pending_bytes)
                    {
                        return Err("journal recovery exceeds configured capacity; file preserved; raise the recovery/queue limits before retrying".into());
                    }
                    pending_bytes += bytes;
                    pending.insert(write.event_id.clone(), (ordinal, write));
                }
            }
            Record::Done { event_id, .. } => {
                if event_id.is_empty() || event_id.len() > 192 {
                    return Err(format!("invalid completion event ID on line {ordinal}"));
                }
                if let Some((_, removed)) = pending.remove(&event_id) {
                    pending_bytes -= removed
                        .sql
                        .len()
                        .saturating_add(removed.event_id.len())
                        .saturating_add(256);
                }
                done_records += 1;
                if dedupe_capacity > 0 && done_set.insert(event_id.clone()) {
                    done.push_back(event_id);
                    while done.len() > dedupe_capacity {
                        if let Some(old) = done.pop_front() {
                            done_set.remove(&old);
                        }
                    }
                }
            }
        }
    }
    let mut ordered: Vec<_> = pending.into_values().collect();
    // Physical journal order, not wall-clock timestamps or lexical event IDs.
    ordered.sort_unstable_by_key(|(ordinal, _)| *ordinal);
    Ok(Replay {
        pending: ordered.into_iter().map(|(_, write)| write).collect(),
        done: done.into_iter().collect(),
        done_records,
    })
}

pub struct DeadLetters {
    file: Mutex<Option<File>>,
}

impl DeadLetters {
    pub fn open(path: &str) -> Result<Self, String> {
        let file = if path.trim().is_empty() {
            None
        } else {
            Some(
                private_options()
                    .create(true)
                    .append(true)
                    .open(path)
                    .map_err(|err| err.to_string())?,
            )
        };
        Ok(Self {
            file: Mutex::new(file),
        })
    }

    pub fn record(&self, reason: &str, detail: &str, write: &StoredWrite) {
        let entry = serde_json::json!({ "ts_ms": now_ms(), "reason": reason, "detail": detail,
            "sql": write.sql, "event_id": write.event_id, "user_id": write.user_id, "batch_id": write.batch_id });
        let mut guard = self.file.lock().expect("dead-letter mutex poisoned");
        if let Some(file) = guard.as_mut() {
            let result = serde_json::to_writer(&mut *file, &entry)
                .map_err(std::io::Error::other)
                .and_then(|_| file.write_all(b"\n"))
                .and_then(|_| file.flush());
            if let Err(err) = result {
                eprintln!("dead-letter diagnostic failed: {err}");
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    struct Fixture {
        path: PathBuf,
    }
    impl Fixture {
        fn new() -> Self {
            Self {
                path: std::env::temp_dir().join(format!(
                    "wt-refactor-{}-{}.jsonl",
                    std::process::id(),
                    TEMP_SERIAL.fetch_add(1, Ordering::Relaxed)
                )),
            }
        }
        fn journal(&self) -> Journal {
            Journal::open(self.path.to_str().unwrap()).unwrap()
        }
    }
    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_file(&self.path);
            let mut lock = self.path.as_os_str().to_os_string();
            lock.push(".lock");
            let _ = fs::remove_file(PathBuf::from(lock));
        }
    }
    fn write(id: &str) -> StoredWrite {
        StoredWrite {
            event_id: id.into(),
            sql: "UPDATE whaletracker SET kills=5".into(),
            user_id: None,
            batch_id: Some(1),
            ts_ms: 123,
        }
    }
    #[test]
    fn replay_preserves_physical_order_with_equal_timestamps() {
        let fixture = Fixture::new();
        let journal = fixture.journal();
        journal
            .append_pending(&[write("id-2"), write("id-10"), write("id-1")])
            .unwrap();
        let replay = journal.replay(10, 10, 100_000).unwrap();
        let ids: Vec<_> = replay
            .pending
            .iter()
            .map(|record| record.event_id.as_str())
            .collect();
        assert_eq!(ids, ["id-2", "id-10", "id-1"]);
    }
    #[test]
    fn done_markers_and_compaction_keep_the_replacement_writable() {
        let fixture = Fixture::new();
        let journal = fixture.journal();
        journal.append_pending(&[write("a"), write("b")]).unwrap();
        journal.append_done(&["a"]).unwrap();
        assert!(journal.compact(10, 1, 10, 100_000).unwrap());
        journal.append_pending(&[write("c")]).unwrap();
        let replay = journal.replay(10, 10, 100_000).unwrap();
        assert_eq!(
            replay
                .pending
                .iter()
                .map(|item| item.event_id.as_str())
                .collect::<Vec<_>>(),
            ["b", "c"]
        );
        assert_eq!(replay.done, ["a"]);
    }
    #[test]
    fn corrupt_or_torn_journals_are_not_compacted() {
        let fixture = Fixture::new();
        let journal = fixture.journal();
        journal.append_pending(&[write("a")]).unwrap();
        journal.append_bytes(b"{torn").unwrap();
        let before = fs::read(&fixture.path).unwrap();
        assert!(journal.compact(10, 1, 10, 100_000).is_err());
        assert_eq!(fs::read(&fixture.path).unwrap(), before);
    }
    #[test]
    fn recovery_capacity_is_checked_during_parsing() {
        let fixture = Fixture::new();
        let journal = fixture.journal();
        journal.append_pending(&[write("a"), write("b")]).unwrap();
        assert!(journal.replay(10, 1, 100_000).is_err());
        assert!(journal.replay(10, 10, 1).is_err());
        assert_eq!(journal.replay(10, 10, 100_000).unwrap().pending.len(), 2);
    }
    #[test]
    fn two_writers_cannot_own_the_same_journal() {
        let fixture = Fixture::new();
        let journal = fixture.journal();
        assert!(Journal::open(fixture.path.to_str().unwrap()).is_err());
        drop(journal);
        assert!(Journal::open(fixture.path.to_str().unwrap()).is_ok());
    }
    #[test]
    fn conflicting_pending_payload_fails_closed() {
        let fixture = Fixture::new();
        let journal = fixture.journal();
        journal.append_pending(&[write("same")]).unwrap();
        let mut conflict = write("same");
        conflict.sql = "UPDATE whaletracker SET kills=6".into();
        journal.append_pending(&[conflict]).unwrap();
        assert!(journal.replay(10, 10, 100_000).is_err());
    }
}
