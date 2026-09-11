//! A stable sidecar lock survives replacement of the journal's inode.
//! Never unlink the lock file: a second inode would allow two exclusive owners.
use std::{
    fs::{self, File, OpenOptions},
    path::{Path, PathBuf},
};

pub struct JournalLock {
    _file: File,
}

impl JournalLock {
    pub fn acquire(path: &Path) -> Result<Self, String> {
        if fs::symlink_metadata(path).is_ok_and(|meta| meta.file_type().is_symlink()) {
            return Err("journal paths must not be symbolic links".into());
        }
        let parent = path
            .parent()
            .filter(|p| !p.as_os_str().is_empty())
            .unwrap_or(Path::new("."));
        let parent = fs::canonicalize(parent).map_err(|err| err.to_string())?;
        let name = path.file_name().ok_or("journal path has no filename")?;
        let mut lock_name = name.to_os_string();
        lock_name.push(".lock");
        let lock_path: PathBuf = parent.join(lock_name);
        let mut options = OpenOptions::new();
        options.create(true).read(true).write(true).truncate(false);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            options.mode(0o600);
        }
        let file = options.open(&lock_path).map_err(|err| err.to_string())?;
        file.try_lock()
            .map_err(|err| format!("cannot exclusively own journal {}: {err}", path.display()))?;
        Ok(Self { _file: file })
    }
}
