//! One bounded pool shared by the three writers and the cache worker.
use crate::config::number;
use mysql::{prelude::Queryable, OptsBuilder, Pool, PoolConstraints, PoolOpts, PooledConn};
use std::{env, time::Duration};

pub fn connect_pool() -> Result<Pool, String> {
    let driver = env::var("WT_DB_DRIVER").unwrap_or_else(|_| "mysql".into());
    if !driver.eq_ignore_ascii_case("mysql") {
        return Err(format!(
            "unsupported WT_DB_DRIVER {driver}; only mysql is supported"
        ));
    }
    let timeout = Duration::from_secs(number("WT_DB_IO_TIMEOUT_SECS", 30).clamp(1, 3600));
    let options = OptsBuilder::new()
        .ip_or_hostname(Some(
            env::var("WT_DB_HOST").unwrap_or_else(|_| "127.0.0.1".into()),
        ))
        .tcp_port(number("WT_DB_PORT", 3306).clamp(1, 65535) as u16)
        .db_name(Some(
            env::var("WT_DB_NAME").unwrap_or_else(|_| "appdb".into()),
        ))
        .user(Some(
            env::var("WT_DB_USER").unwrap_or_else(|_| "dbuser".into()),
        ))
        .pass(Some(env::var("WT_DB_PASS").unwrap_or_default()))
        .tcp_connect_timeout(Some(Duration::from_secs(5)))
        .read_timeout(Some(timeout))
        .write_timeout(Some(timeout))
        .pool_opts(
            PoolOpts::default()
                .with_constraints(PoolConstraints::new(1, 4).expect("valid pool bounds")),
        );
    Pool::new(options).map_err(|err| err.to_string())
}

// The four long-lived workers have no nested pool acquisition. Database socket
// timeouts bound stalled I/O; ACK deadlines separately bound network handlers.
pub fn connection(pool: &Pool) -> Result<PooledConn, String> {
    pool.get_conn().map_err(|err| err.to_string())
}

// A failed release must not return a still-locked session to the pool.
struct NamedLock {
    conn: Option<PooledConn>,
    name: String,
}

impl NamedLock {
    fn release(&mut self) -> Result<(), String> {
        let Some(mut conn) = self.conn.take() else {
            return Ok(());
        };
        let result: Result<Option<u8>, mysql::Error> = conn.exec_first(
            "SELECT RELEASE_LOCK(CONCAT(?, ':', MD5(DATABASE())))",
            (&self.name,),
        );
        match result {
            Ok(Some(1)) => Ok(()),
            other => {
                // Unwrap removes the physical connection from the pool. Dropping
                // it closes the session (and its locks), including on I/O failure.
                drop(conn.unwrap());
                Err(format!("database named lock release failed: {other:?}"))
            }
        }
    }
}

impl Drop for NamedLock {
    fn drop(&mut self) {
        if let Err(err) = self.release() {
            eprintln!("{err}");
        }
    }
}

pub fn with_named_lock<T>(
    mut conn: PooledConn,
    lock_kind: &str,
    timeout_seconds: u32,
    operation: impl FnOnce(&mut PooledConn) -> Result<T, String>,
) -> Result<Option<T>, String> {
    let acquired: Result<Option<u8>, mysql::Error> = conn.exec_first(
        "SELECT GET_LOCK(CONCAT(?, ':', MD5(DATABASE())), ?)",
        (lock_kind, timeout_seconds),
    );
    match acquired {
        Ok(Some(1)) => {}
        Ok(Some(0)) => return Ok(None),
        other => {
            // An I/O failure may hide a successful GET_LOCK on the server. Never
            // return that possibly locked physical session to the shared pool.
            drop(conn.unwrap());
            return Err(format!("database named lock acquisition failed: {other:?}"));
        }
    }
    let mut locked = NamedLock {
        conn: Some(conn),
        name: lock_kind.to_string(),
    };
    // NamedLock's Drop also releases ownership during unwinding.
    let result = operation(locked.conn.as_mut().expect("owned lock connection"));
    let released = locked.release();
    match result {
        Ok(value) => released.map(|()| Some(value)),
        Err(err) => Err(err),
    }
}
