# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- PHPUnit test suite (`phpunit.xml`, `tests/`) covering the pure conversion/rate-selection, sleep-request, and
  filename-sanitization helpers; `composer test` script and `phpunit/phpunit` dev dependency
- `UsbSetup.php` — installs Ventoy (MBR partition table, FAT32 data partition), copies a Debian live ISO, and sets up
  Ventoy persistence via a loop-mounted ext4 image and `ventoy.json`
- `docs/soundcloud-downloader.md` — full ddev command reference, `.env` options, output layout, Rekordbox import guide,
  and troubleshooting table
- `docs/usb-setup.md` — USB setup command reference, step-by-step explanation, partition layout, FAT32 limitations, and
  boot instructions
- `usb:setup` — `--downloads-file` option and shared `config/usb-setup.json` `download_sources` entry to copy
  additional software (Rekordbox, T-Racks 8x8 Matrix Digital Processor Editor, Ableton Live trial) onto the stick's
  `/software/` folder; `magnet:`/`urn:btmh:` link syntax is recognized and reserved for future torrent support but
  not yet downloaded
- `usb:setup` downloads file now also accepts local file/directory paths (copied as-is, no download), including a
  default `config/usb-manual-downloads/` folder for anything downloaded by hand (account-gated vendor pages, e.g.
  Traktor Pro, Traktor DJ2, Resolume Arena)
- `usb:setup` rejects downloads whose response has a `text/*` Content-Type (e.g. a login/session-gated HTML page)
  instead of silently copying it onto the stick as if it were the installer
- `usb:setup` downloads-file local directory entries support a `/**` suffix for recursive scanning (e.g.
  `config/usb-manual-downloads/**`), preserving each file's subfolder path under `/software/` on the stick
- `config/usb-setup.json.example`, `config/playlists.txt.example`, `config/cookies.txt.example` — checked-in
  starting points for the gitignored personal/machine-specific config files; `config/usb-downloads.txt` and
  `config/soundcloud-download.json` (no personal data in either) are now committed directly

### Changed

- `.gitignore` no longer blanket-excludes `config/`; only personal/machine-specific files (`usb-setup.json`,
  `playlists.txt`, `cookies.txt`, and anything under `usb-manual-downloads/`) stay gitignored

### Fixed

- `usb:setup` — Ventoy no longer fails with `mkexfatfs: command not found`; `.ddev/web-build/Dockerfile` symlinks the
  modern `exfatprogs` binaries (`mkfs.exfat` → `mkexfatfs`, `fsck.exfat` → `exfatfsck`) to the legacy names Ventoy
  probes for
- `soundcloud:download` — conversions now resample tracks whose sample rate is outside 44.1/48/96 kHz to the nearest
  supported rate ≥ source (capped at 96 kHz), fixing failed/broken exports for FLAC/ALAC/WAV/AIFF at odd rates and
  streaming/video-container sources; source rate is detected via `ffprobe` (`FFPROBE_BIN`, defaults to `ffprobe`)
- `soundcloud:download` — dropped `--add-metadata` from the originals download. It made yt-dlp remux the original to
  write tags, which failed (`Postprocessing: Conversion failed!`) for WAV/AIFF sources carrying an embedded cover
  image (the WAV muxer rejects the video stream), leaving those tracks unarchived, unconverted, and missing from the
  library/M3U8s on every run. Per-format metadata and cover art are already re-embedded by the conversion step from the
  `.info.json`/`.jpg` sidecars, so final MP3/WAV/FLAC outputs are unaffected
- `soundcloud:download` — the per-playlist summary no longer always reports `0 already in archive`. yt-dlp filters
  already-archived tracks during playlist enumeration (before any `--print` stage), so skipped tracks emit no output;
  the count is now derived by diffing the playlist against a snapshot of the download archive taken before the run.
  The summary also gained a `N failed` suffix for tracks that were neither downloaded nor previously archived (e.g.
  DRM-protected or geo-restricted). Covered by new `loadArchiveIds`/`countArchived` unit tests

## [0.2.0] - 2026-02-24

### Changed

- Playlist folder name now uses `<Uploader> - <Playlist Title>` only; per-playlist subdirectory creation removed in
  favour of writing M3U8 files directly into the unified playlists directory

### Fixed

- Minor code simplifications and added PHPDoc blocks to core functions

## [0.1.0] - 2025-09-18

### Added

- `SoundCloudPlaylistDownloader.php` — downloads playlists via yt-dlp into a shared audio library (deduplication via a
  single download archive), converts to MP3/WAV/FLAC locally with ffmpeg, and generates per-playlist M3U8 files
- `.env` support with variable expansion and CLI flag overrides
- Configurable output formats (`original`, `mp3`, `wav`, `flac`) via `FORMATS` env var
- Shared library layout: originals in `library/original/`, conversions in `library/<format>/`, M3U8s in `playlists/`
- Sidecar files (`.info.json`, `.jpg` thumbnail) written alongside each original
- Metadata and artwork embedding for MP3 and FLAC via ffmpeg
- Rate limiting and retry options (`LIMIT_RATE`, `SLEEP_REQUESTS`, `EXTRACTOR_RETRIES`, `RETRY_SLEEP`, `PAUSE_BETWEEN`)
- Cookie file support for authenticated / private content (`COOKIES_FILE`)
- `README.md` with project description, requirements, installation, configuration, usage, output layout, and
  troubleshooting

[Unreleased]: https://github.com/user/yt-dlp/compare/v0.2.0...HEAD

[0.2.0]: https://github.com/user/yt-dlp/compare/v0.1.0...v0.2.0

[0.1.0]: https://github.com/user/yt-dlp/releases/tag/v0.1.0
