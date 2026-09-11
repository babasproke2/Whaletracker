//! Bounded connection admission and deadline-aware TCP reads.
//! No database or game-specific state lives here. A permit is released on all exits,
//! including a failed thread spawn or unwinding handler.
use std::io::{self, Read};
use std::net::TcpStream;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::time::{Duration, Instant};

#[derive(Debug)]
pub struct ConnectionLimit {
    active: AtomicUsize,
    maximum: usize,
}

impl ConnectionLimit {
    pub fn new(maximum: usize) -> Arc<Self> {
        assert!(maximum > 0);
        Arc::new(Self {
            active: AtomicUsize::new(0),
            maximum,
        })
    }

    pub fn try_acquire(self: &Arc<Self>) -> Option<ConnectionPermit> {
        self.active
            .fetch_update(Ordering::AcqRel, Ordering::Acquire, |active| {
                (active < self.maximum).then_some(active + 1)
            })
            .ok()?;
        Some(ConnectionPermit(Arc::clone(self)))
    }
}

pub struct ConnectionPermit(Arc<ConnectionLimit>);

impl Drop for ConnectionPermit {
    fn drop(&mut self) {
        self.0.active.fetch_sub(1, Ordering::AcqRel);
    }
}

/// Reset the deadline once per frame, not once per fragment. A peer cannot extend
/// the timeout indefinitely by sending a byte just before each socket timeout.
pub struct DeadlineStream {
    stream: TcpStream,
    deadline: Instant,
}

impl DeadlineStream {
    pub fn new(stream: TcpStream, timeout: Duration) -> Self {
        Self {
            stream,
            deadline: Instant::now() + timeout,
        }
    }

    pub fn begin_frame(&mut self, timeout: Duration) {
        self.deadline = Instant::now() + timeout;
    }
}

impl Read for DeadlineStream {
    fn read(&mut self, output: &mut [u8]) -> io::Result<usize> {
        if output.is_empty() {
            return Ok(0);
        }
        let remaining = self.deadline.saturating_duration_since(Instant::now());
        if remaining.is_zero() {
            return Err(io::Error::new(
                io::ErrorKind::TimedOut,
                "frame deadline exceeded",
            ));
        }
        self.stream.set_read_timeout(Some(remaining))?;
        self.stream.read(output)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Barrier;
    use std::thread;

    #[test]
    fn admission_is_bounded_and_drop_releases_capacity() {
        let limit = ConnectionLimit::new(2);
        let first = limit.try_acquire().unwrap();
        let second = limit.try_acquire().unwrap();
        assert!(limit.try_acquire().is_none());
        drop(first);
        assert!(limit.try_acquire().is_some());
        drop(second);
        assert_eq!(limit.active.load(Ordering::Acquire), 0);
    }

    #[test]
    fn concurrent_admission_never_exceeds_limit() {
        let limit = ConnectionLimit::new(4);
        let barrier = Arc::new(Barrier::new(17));
        let mut workers = Vec::new();
        for _ in 0..16 {
            let limit = Arc::clone(&limit);
            let barrier = Arc::clone(&barrier);
            workers.push(thread::spawn(move || {
                let permit = limit.try_acquire();
                barrier.wait();
                barrier.wait();
                drop(permit);
            }));
        }
        barrier.wait();
        assert_eq!(limit.active.load(Ordering::Acquire), 4);
        barrier.wait();
        for worker in workers {
            worker.join().unwrap();
        }
        assert_eq!(limit.active.load(Ordering::Acquire), 0);
    }
}
