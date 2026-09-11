#!/usr/bin/env python3
"""Compile/test the overlaid checkout with externally installed toolchains.

No compiler or binary is bundled. --rust runs native Cargo tests; --spcomp
compiles the plugin with supplied SourceMod and third-party include directories.
Run --rust before deploying either daemon. Requires Python 3.11+.
"""
from __future__ import annotations
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tomllib

def main() -> int:
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--root',type=Path,default=Path(__file__).resolve().parents[1])
    p.add_argument('--rust',action='store_true')
    p.add_argument('--spcomp',help='Path to a SourcePawn compiler')
    p.add_argument('--sm-include',type=Path,help='SourceMod SDK scripting/include directory')
    p.add_argument('--include',type=Path,action='append',default=[],help='Additional third-party include directory; repeat as needed')
    args=p.parse_args()
    if not args.rust and not args.spcomp: p.error('select --rust and/or --spcomp')
    root=args.root.resolve()
    try:
        metadata=tomllib.loads((root/'Cargo.toml').read_text())
        whale=metadata['package']['name']=='whaletracker-rust'
        if args.rust:
            if not (root/'Cargo.lock').is_file():
                raise ValueError('Cargo.lock missing: extract the overlay into the complete pinned checkout')
            cargo=shutil.which('cargo')
            if cargo is None: raise ValueError('Cargo is not installed; use Rust 1.89 or newer')
            subprocess.run([cargo,'test','--locked','--all-targets'],cwd=root,check=True)
        if args.spcomp:
            compiler=shutil.which(args.spcomp)
            if compiler is None: raise ValueError('SourcePawn compiler not found')
            compiler=str(Path(compiler).resolve())
            if not args.sm_include: raise ValueError('--sm-include is required for SourcePawn compilation')
            if whale:
                # Refuses an unrecognized/local-modified upstream helper; no network.
                subprocess.run([sys.executable,str(root/'tools/apply_helpers.py'),str(root)],check=True)
                scripts=root/'scripting'; entry=scripts/'whaletracker.sp'
            else:
                scripts=root/'sourcemod/scripting'; entry=scripts/'plugin_statistics.sp'
            if not entry.is_file(): raise ValueError(f'missing entrypoint: {entry}')
            includes=[scripts,scripts/'include',args.sm_include.resolve(),*[path.resolve() for path in args.include]]
            for directory in includes:
                if not directory.is_dir(): raise ValueError(f'include directory is missing: {directory}')
            out=root/'target/refactor-sourcepawn'; out.mkdir(parents=True,exist_ok=True)
            cmd=[compiler,str(entry),*[f'-i{directory}' for directory in includes],f'-o{out/entry.with_suffix(".smx").name}']
            subprocess.run(cmd,cwd=scripts,check=True)
        return 0
    except (OSError,ValueError,subprocess.CalledProcessError) as err:
        print(f'Build checks failed or unavailable: {err}',file=sys.stderr)
        return 1

if __name__=='__main__': raise SystemExit(main())
