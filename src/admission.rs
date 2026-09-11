//! Atomic capacity and event-ID ownership across all queues, disk admission and
//! executing batches. A permit releases both only when its job is retired.
use crate::sql::Lane;
use std::{
    collections::{HashSet, VecDeque},
    sync::{Arc, Condvar, Mutex},
    time::{Duration, Instant},
};

#[derive(Debug, Default, Clone, Copy)]
pub struct Usage {
    pub rows: usize,
    pub bytes: usize,
    pub lanes: [usize; 3],
}

#[derive(Debug, Default)]
struct State {
    usage: Usage,
    active: HashSet<String>,
}

#[derive(Debug)]
pub struct Admission {
    state: Mutex<State>,
    row_limit: usize,
    byte_limit: usize,
}

pub struct Spec<'a> {
    pub id: &'a str,
    pub bytes: usize,
    pub lane: Lane,
}

#[derive(Debug)]
pub struct Permit {
    owner: Arc<Admission>,
    id: String,
    bytes: usize,
    lane: Lane,
}

impl Admission {
    pub fn new(row_limit: usize, byte_limit: usize) -> Arc<Self> {
        Arc::new(Self {
            state: Mutex::new(State::default()),
            row_limit,
            byte_limit,
        })
    }

    pub fn reserve(self: &Arc<Self>, specs: &[Spec<'_>]) -> Result<Vec<Permit>, &'static str> {
        let mut state = self.state.lock().expect("admission mutex poisoned");
        let bytes = specs
            .iter()
            .try_fold(0usize, |sum, item| sum.checked_add(item.bytes))
            .ok_or("queue byte limit")?;
        if specs.len() > self.row_limit.saturating_sub(state.usage.rows)
            || bytes > self.byte_limit.saturating_sub(state.usage.bytes)
        {
            return Err("queue full");
        }
        let mut unique = HashSet::with_capacity(specs.len());
        for item in specs {
            if !unique.insert(item.id) {
                return Err("duplicate event_id in batch");
            }
            if state.active.contains(item.id) {
                return Err("event still pending; retry later with the same IDs");
            }
        }
        let mut permits = Vec::with_capacity(specs.len());
        for item in specs {
            state.active.insert(item.id.to_string());
            state.usage.rows += 1;
            state.usage.bytes += item.bytes;
            state.usage.lanes[item.lane as usize] += 1;
            permits.push(Permit {
                owner: Arc::clone(self),
                id: item.id.to_string(),
                bytes: item.bytes,
                lane: item.lane,
            });
        }
        Ok(permits)
    }

    pub fn usage(&self) -> Usage {
        self.state.lock().expect("admission mutex poisoned").usage
    }
}

impl Drop for Permit {
    fn drop(&mut self) {
        let mut state = self.owner.state.lock().expect("admission mutex poisoned");
        assert!(state.active.remove(&self.id), "permit retired twice");
        state.usage.rows -= 1;
        state.usage.bytes -= self.bytes;
        state.usage.lanes[self.lane as usize] -= 1;
    }
}

#[derive(Default)]
struct CompletionState {
    remaining: usize,
    executed: usize,
}

pub struct Completion {
    state: Mutex<CompletionState>,
    changed: Condvar,
}

impl Completion {
    pub fn new(remaining: usize) -> Arc<Self> {
        Arc::new(Self {
            state: Mutex::new(CompletionState {
                remaining,
                executed: 0,
            }),
            changed: Condvar::new(),
        })
    }
    pub fn finish(&self) {
        let mut state = self.state.lock().expect("completion mutex poisoned");
        assert!(state.remaining > 0, "job completed twice");
        state.remaining -= 1;
        state.executed += 1;
        if state.remaining == 0 {
            self.changed.notify_all();
        }
    }
    pub fn wait(&self, timeout: Duration) -> Option<usize> {
        let deadline = Instant::now() + timeout;
        let mut state = self.state.lock().expect("completion mutex poisoned");
        while state.remaining > 0 {
            let remaining = deadline.saturating_duration_since(Instant::now());
            if remaining.is_zero() {
                return None;
            }
            state = self
                .changed
                .wait_timeout(state, remaining)
                .expect("completion wait failed")
                .0;
        }
        Some(state.executed)
    }
}

pub struct Dedupe {
    state: Mutex<(HashSet<String>, VecDeque<String>)>,
    capacity: usize,
}

impl Dedupe {
    pub fn new(capacity: usize) -> Self {
        Self {
            state: Mutex::new((HashSet::new(), VecDeque::new())),
            capacity,
        }
    }
    pub fn contains(&self, id: &str) -> bool {
        self.state
            .lock()
            .expect("dedupe mutex poisoned")
            .0
            .contains(id)
    }
    pub fn len(&self) -> usize {
        self.state.lock().expect("dedupe mutex poisoned").0.len()
    }
    pub fn remember(&self, id: &str) {
        if self.capacity == 0 {
            return;
        }
        let mut state = self.state.lock().expect("dedupe mutex poisoned");
        if !state.0.insert(id.to_string()) {
            return;
        }
        state.1.push_back(id.to_string());
        while state.1.len() > self.capacity {
            if let Some(oldest) = state.1.pop_front() {
                state.0.remove(&oldest);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn executing_jobs_keep_capacity_and_ids_reserved() {
        let limit = Admission::new(2, 100);
        let first = limit
            .reserve(&[Spec {
                id: "one",
                bytes: 60,
                lane: Lane::Stats,
            }])
            .unwrap();
        assert!(limit
            .reserve(&[Spec {
                id: "two",
                bytes: 50,
                lane: Lane::Logs
            }])
            .is_err());
        assert!(limit
            .reserve(&[Spec {
                id: "one",
                bytes: 1,
                lane: Lane::Online
            }])
            .is_err());
        assert_eq!(limit.usage().lanes, [0, 1, 0]);
        drop(first);
        assert_eq!(limit.usage().rows, 0);
        assert_eq!(limit.usage().bytes, 0);
        assert!(limit
            .reserve(&[Spec {
                id: "one",
                bytes: 60,
                lane: Lane::Stats
            }])
            .is_ok());
    }
    #[test]
    fn duplicate_batch_is_rejected_without_partial_reservation() {
        let limit = Admission::new(10, 100);
        assert!(limit
            .reserve(&[
                Spec {
                    id: "same",
                    bytes: 10,
                    lane: Lane::Stats
                },
                Spec {
                    id: "same",
                    bytes: 10,
                    lane: Lane::Stats
                }
            ])
            .is_err());
        assert_eq!(limit.usage().rows, 0);
    }
    #[test]
    fn timeout_does_not_cancel_accepted_work() {
        let completion = Completion::new(1);
        assert_eq!(completion.wait(Duration::ZERO), None);
        completion.finish();
        assert_eq!(completion.wait(Duration::ZERO), Some(1));
    }
    #[test]
    fn dedupe_eviction_is_bounded() {
        let cache = Dedupe::new(2);
        cache.remember("one");
        cache.remember("two");
        cache.remember("three");
        assert!(!cache.contains("one"));
        assert!(cache.contains("two"));
        assert_eq!(cache.len(), 2);
    }
}

#[cfg(test)]
mod concurrent_tests {
    use super::*;
    use std::{sync::Barrier, thread};
    #[test]
    fn all_three_lanes_share_one_atomic_reservation_budget() {
        let limit = Admission::new(12, 1200);
        let barrier = Arc::new(Barrier::new(33));
        let workers: Vec<_> = (0..32)
            .map(|number| {
                let limit = Arc::clone(&limit);
                let barrier = Arc::clone(&barrier);
                thread::spawn(move || {
                    let ids = [
                        format!("{number}-online"),
                        format!("{number}-stats"),
                        format!("{number}-logs"),
                    ];
                    let specs = [
                        Spec {
                            id: &ids[0],
                            bytes: 100,
                            lane: Lane::Online,
                        },
                        Spec {
                            id: &ids[1],
                            bytes: 100,
                            lane: Lane::Stats,
                        },
                        Spec {
                            id: &ids[2],
                            bytes: 100,
                            lane: Lane::Logs,
                        },
                    ];
                    let permits = limit.reserve(&specs);
                    barrier.wait();
                    barrier.wait();
                    drop(permits);
                })
            })
            .collect();
        barrier.wait();
        assert_eq!(limit.usage().rows, 12);
        assert_eq!(limit.usage().bytes, 1200);
        assert_eq!(limit.usage().lanes, [4, 4, 4]);
        barrier.wait();
        for worker in workers {
            worker.join().unwrap();
        }
        assert_eq!(limit.usage().rows, 0);
    }
}
