# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `UsbSetup.php` — installs Ventoy (MBR partition table, FAT32 data partition), copies a Debian live ISO, and sets up
  Ventoy persistence via a loop-mounted ext4 image and `ventoy.json`
- `docs/soundcloud-downloader.md` — full ddev command reference, `.env` options, output layout, Rekordbox import guide,
  and troubleshooting table
- `docs/usb-setup.md` — USB setup command reference, step-by-step explanation, partition layout, FAT32 limitations, and
  boot instructions

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
