# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `playlists:sync` — `original` is now a real output format: including it in `--formats` / `FORMATS` / `formats`
  writes a `<Playlist> - original.m3u8` playlist referencing the untouched downloaded files, same as `mp3`/`wav`/
  `flac` already did. Previously the entries were collected internally but the playlist was never written — the
  data was there, the write loop just never included `original`
- `playlists:sync` — MP3 encoding mode (`--mp3-mode` / `MP3_MODE` / `mp3_mode`): `cbr` (constant bitrate,
  **new default**, bitrate via `--mp3-bitrate` / `MP3_BITRATE` / `mp3_bitrate`, default `320`) or `vbr` (the
  previous behaviour, quality via the existing `--mp3-quality`). CBR is now the default because some DJ software
  (Rekordbox) misreports VBR MP3 bitrate — it reads the first frame's bitrate instead of the true average, so a
  track with a quiet intro can display a wildly low bitrate (e.g. "32 kbit/s") despite a much higher real average.
  Never prompted (E-category parameter, like the other ffmpeg/binary options)
- `playlists:sync` — `--reencode-stale-mp3` / `--no-reencode-stale-mp3` (on by default): under `--mp3-mode=cbr`,
  probes each existing mp3 target's average bitrate and, if it's off from `--mp3-bitrate` by more than 10%
  (or 8 kbps), deletes and reconverts it — no redownload needed, the source stays archived. Fixes mp3s
  converted before switching to CBR (the same "32 kbit/s" scenario above) without manually deleting files
- `playlists:sync` — CLI options for every parameter (`--formats`, `--mp3-quality`, `--library-dir`,
  `--archive-dir`, `--lib-filename-template`, `--ytdlp-bin`, `--spotdl-bin`, `--ffmpeg-bin`, `--ffprobe-bin`,
  `--extractor-retries`, `--retry-sleep`, `--sleep-requests`, `--limit-rate`, `--pause-between`); resolution stays
  CLI → env → config file → default
- `playlists:sync` — unified cookies: one shared Netscape cookie file for both yt-dlp and spotdl
  (`--cookies` / `COOKIES_FILE` / `cookies_file`, default `config/cookies.txt`) with per-tool overrides
  (`--ytdlp-cookies` / `YTDLP_COOKIE_FILE` / `ytdlp_cookie_file` and
  `--spotdl-cookies` / `SPOTDL_COOKIE_FILE` / `spotdl_cookie_file`)
- `playlists:sync` — the output formats question (`--formats` / `FORMATS` / `formats`) is now prompted when
  configured nowhere, same as input file/output directory: a comma-separated list of `original`, `mp3`, `wav`,
  `flac`, validated (`PlaylistsSyncCommand::parseFormatsAnswer` is a pure, unit-tested parser rejecting empty or
  unknown entries) and persisted so it is asked only once; fixed a latent stale-config-merge bug in the
  input-file/output-dir prompt save that this exposed (it now reloads the config file fresh instead of reusing
  the copy read at the start of the run, so an earlier prompt answer in the same run is no longer clobbered)
- `playlists:sync` — codec-aware quality threshold on the PEAQ ODG scale (0 = transparent … -4 = very annoying):
  each source's estimated ODG is interpolated from a per-codec calibration table (`mp3`/`aac`/`opus`/`vorbis`
  anchor points; lossless = 0, unknown codecs use the conservative MP3 curve), so e.g. 128 kbps Opus passes a
  threshold that 128 kbps MP3 fails; configured via `--min-odg` / `MIN_ODG` / `min_odg` with
  `--min-odg-mode` / `MIN_ODG_MODE` / `min_odg_mode` (`warn`/`filter`); in filter mode the threshold is inverted
  per codec into minimum bitrates and expressed as a fail-closed yt-dlp format selector with one branch per codec
  prefix (lossless always passes); the post-download check reads `acodec` from the `.info.json` or probes
  codec + bitrate with a single `ffprobe` call (spotdl tracks); warn/skip messages show raw kbps, codec, and
  estimated ODG; a track appearing in several playlists is evaluated and warn-listed once (per-file cache,
  deduped by track id); the warn line's title falls back to the `.info.json` title and then to the
  `{id} - {title}` library filename when the playlist entry carries no title (SoundCloud set entries,
  pre-sidecar downloads)
- `playlists:sync` — the min-odg question is a guided prompt: quality tiers with decision help
  ([1] Archive / Pro Club Standard ODG ≥ -0.2, [2] Semi-Pro Performance Minimum ODG ≥ -1.0, [3] Preview Only
  ODG ≥ -2.0, [4] Off — hints show per-codec bitrate equivalents), accepting an option number, a custom
  ODG value in [-4, 0], or empty for off (`PlaylistsSyncCommand::parseMinOdgAnswer` is a pure, unit-tested parser)
- `usb:setup` — env vars for the existing options, acting like the CLI option (they suppress the prompt):
  `USB_DEVICE`, `USB_SOURCE_DEVICE`, `USB_DEBIAN_ISO`, `USB_PERSISTENCE_SIZE`, `USB_DOWNLOADS_FILE`,
  `USB_UPDATE` (for `--update`), `USB_YES` (for `--yes`); new parameters `--cache-dir` / `USB_CACHE_DIR`,
  `--install-ventoy` / `USB_INSTALL_VENTOY` (yes/no, skips the Ventoy prompt) and `--iso-variant` /
  `USB_ISO_VARIANT` (implies ISO source "download", skips the ISO prompts, works non-interactively too);
  usb:setup now reads `.env` (loader moved to `BaseCommand`)

- `playlists:sync` — config-file fallback layer for every parameter: all env vars now have a snake_case equivalent
  in `config/playlists-sync.json` (resolution order: CLI option → env var → config file → prompt → default); new
  `config/playlists-sync.json.example`
- `playlists:sync` — interactive quality-threshold prompts: the minimum estimated ODG (`min_odg`) is asked **once**
  when configured nowhere (empty answer persists `"min_odg": null` = off; delete the key to re-trigger), the
  `warn`/`filter` mode (`min_odg_mode`) is asked **every interactive run while a minimum is active** with the
  saved/env value as default (`--min-odg-mode` suppresses it); `-n` skips both, only prompt answers are persisted
- `BaseCommand` prompt/resolution primitives shared by both commands: `askText` (free text with saved default +
  validator), `askChoice` (choice with saved value/index default), `askConfirmation` (confirmation honouring a
  skip-confirm flag), `resolveParam` (CLI → env → config → default)
- README/docs — shared "Configuration & parameter resolution" overview (evaluation order, persistence rule) plus
  unified per-command parameter tables (param, CLI option, env var, config key, prompted-when, default) for
  `playlists:sync` and `usb:setup`

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

- `usb:setup` — the ISO/software download cache moved from top-level `.cache/` to `downloads/.cache/`, so all
  generated/downloaded content lives under the one gitignored `downloads/` tree; default `--cache-dir` /
  `USB_CACHE_DIR` / `cache_dir` updated accordingly (existing cached files should be moved manually, or just
  re-downloaded — nothing migrates automatically)
- **Breaking**: `config/spotdl-cookies.txt` is no longer a spotdl default — point
  `--spotdl-cookies` / `SPOTDL_COOKIE_FILE` / `spotdl_cookie_file` at it or merge its cookies into
  `config/cookies.txt`; by default spotdl now shares `config/cookies.txt` with yt-dlp
- `usb:setup` — mode/Ventoy/payload/ISO/variant/persistence/downloads prompts and the execute-flow confirmations
  refactored onto the shared `BaseCommand` helpers (prompt texts and behaviour unchanged)
- Empty env-var values are now treated as unset for all resolver-routed `playlists:sync` parameters (previously
  `MP3_QUALITY=""` was used verbatim)
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
- `playlists:sync` — progress bars no longer garble under `docker exec`/`ddev exec` (no pty). Symfony's `ProgressBar`
  silently redirects to `$output->getErrorOutput()` for any `ConsoleOutputInterface` — invisible on a real terminal
  since stdout/stderr share one tty there, but under a pty-less `exec` they're two independently-buffered pipes
  (stderr unbuffered, stdout block-buffered) that desync when merged for display. New `barOutput()` helper forces
  all three progress bars (`fetchBar`/`overallBar`/`convBar`) onto the same stream as the rest of the command's
  output instead

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
