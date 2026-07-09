# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Spotify support via [spotdl](https://github.com/spotDL/spotify-downloader): `open.spotify.com` playlist/album/track
  URLs (and `spotify:` URIs) in the input file are routed to spotdl, which matches tracks on YouTube Music and
  downloads best-quality m4a originals (`--bitrate disable`) named `{track-id} - {title}` into the shared library;
  playlist identity/entries come from `spotdl save` metadata; deduplication via a separate `.archive/spotify.txt`
  (progress counted by archive diff); the existing ffmpeg conversion + M3U8 pipeline applies unchanged to all
  sources. New env vars `SPOTDL_BIN` and `SPOTDL_COOKIE_FILE` (optional YT Music Premium cookies → 256k m4a);
  spotdl installed in the ddev web image

- `App\Process\ProcessRunner` interface + `ProcOpenProcessRunner` implementation — injectable process-execution seam
  (optional constructor argument on both commands, defaulting to the real runner) extracted from the two duplicated
  `runCmd` `proc_open` loops; `UsbSetupCommand::deviceNameCheckOutcome` pure helper extracted from
  `checkAndRecordDeviceName` (silent / warn-missing / warn-mismatch decision)
- Tests for the process-dependent paths via `FakeProcessRunner`/`TestableUsbSetupCommand` test doubles:
  `ProcOpenProcessRunner` (real subprocesses), `lsblkInfo`, `hasVentoyPartition`, `isFat32Ventoy`, `installVentoy`
  outcome verification (`-I`/`-u` flag, tool-failure detection, missing VTOYEFI partition), `validateSourceDevice`,
  `mirrorDataPartition` (dry-run preview/confirm/decline), `downloadDebianIso` (cache hit/corrupt/re-download),
  `SoundCloudDownloadCommand::probeSampleRate` and its `runCmd` line splitting, and `deviceNameCheckOutcome`
- GitHub Actions workflow (`.github/workflows/tests.yml`) running the PHPUnit suite on every push (PHP 8.3,
  ubuntu-latest, plain `composer install` + `composer test` — no ddev needed, the suite is self-contained)
- `usb:setup` — free-space preflight for the configuration path: after ISO/software sizes are known and before the
  stick is touched, a warning (+ confirmation unless `--yes`) appears when ISO + persistence + software may not fit
  the target data partition (duplicate mode already had a hard-fail preflight; both now share
  `targetDataCapacityBytes`)
- `soundcloud:download` flow tests through CommandTester (`tests/Command/SoundCloudDownloadCommandFlowTest.php`):
  download/convert/M3U8 happy path, playlist-fetch failure, yt-dlp nonzero exit, missing-options failure — all in a
  temp workspace with `DOTENV_PATH` pinned so no real environment leaks in; plus unit tests for `ensureConverted`
  (existing target short-circuit, missing source, probe→ffmpeg pipeline, ffmpeg failure) and `requireBinary`
- Interactive `usb:setup` flow regression tests through `CommandTester::setInputs()`
  (`tests/Command/UsbSetupCommandFlowTest.php`): `device_name` survives the final batched config save (stale-merge
  clobber), device-name mismatch warning + abort, mode default follows detected stick state, update mode falls back
  to a full Ventoy install (`-I`) when Ventoy is missing

- `usb:setup` — `--source-device` option (and interactive "Payload" prompt) to duplicate an already-set-up Ventoy
  stick onto the target: after the normal Ventoy install, the source's data partition (ISO, `ventoy/ventoy.json`,
  `persistence.dat` including its user data, `/software/`) is mirrored via rsync instead of downloaded/created from
  configuration; explicitly passed `--debian-iso`/`--downloads-file` still apply additively on top. Includes a
  free-space preflight (fails before anything is wiped), a read-only source mount, an oversized-file (FAT32 >4 GiB)
  warning, source/target swap detection via recorded device names, and an `--update` re-sync mode that previews
  deletions (rsync dry run) and asks for confirmation. New config keys: `payload_source`, `source_device`,
  `source_device_name`; `rsync` added to the ddev web image
- Broadened unit-test coverage: extracted behavior-preserving pure helpers and tested them —
  `SoundCloudDownloadCommand::buildFfmpegArgs` (locks in audio-only `0:a:0` mapping, cover-for-mp3/flac-only, `-ar`
  resample selection, and metadata args), `mapInfoJsonToTags`, `relativeFromParts`; and
  `UsbSetupCommand::parseChecksum`,
  `classifyDownloadLine` (http/torrent/local/recursive/invalid), `shouldRejectAsHtml`, `parseLastContentType`. Added
  `tests/Command/UsbSetupCommandTest.php`
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

- `playlists:sync` — ffmpeg conversions now run with `-loglevel error` instead of `-loglevel warning`, silencing
  harmless warning noise (e.g. "timescale not set", "encoding as 24 bits-per-sample") that garbled the progress bars
- **Breaking**: `soundcloud:download` renamed to `playlists:sync` (no alias) — the command already handled YouTube
  and now Spotify, so the old name was misleading. Class `SoundCloudDownloadCommand` → `PlaylistsSyncCommand`,
  config `config/soundcloud-download.json` → `config/playlists-sync.json` (existing legacy config is read once as
  a fallback and migrated on the next interactive save), docs `docs/soundcloud-downloader.md` →
  `docs/playlists-sync.md`
- PHP requirement corrected from `>=8.1` to `>=8.2` — symfony/console ^7.0 and phpunit ^11 already require 8.2,
  so 8.1 could never install the project; composer.lock content-hash refreshed
- `soundcloud:download` — `requireBinary` now runs through the `ProcessRunner` seam instead of raw `@exec`
- `usb:setup` — the "Software downloads file" prompt is asked only on the first run: any saved `download_sources`
  answer (including `-` for "none", which is now persisted) is reused silently with an informational note;
  `--downloads-file` or a config edit changes it later. Duplicate mode no longer prompts — downloads apply there
  only via an explicit `--downloads-file`
- `.gitignore` no longer blanket-excludes `config/`; only personal/machine-specific files (`usb-setup.json`,
  `playlists.txt`, `cookies.txt`, and anything under `usb-manual-downloads/`) stay gitignored

### Fixed

- `soundcloud:download` — no longer crashes with a ProgressBar `LogicException` when every playlist fetch fails or
  all playlists are empty (overall progress bar with `%remaining%` and 0 max steps)
- `usb:setup` — partition paths are now derived correctly for devices whose name ends in a digit
  (`/dev/nvme0n1` → `nvme0n1p1`/`nvme0n1p2`, mmcblk/loop likewise) via a new `partitionPath` helper; previously naive
  `…1`/`…2` concatenation produced wrong node names (`nvme0n11`) on NVMe targets and sources
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
