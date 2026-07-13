# yt-dlp Tools

A set of CLI tools for playlist downloading (SoundCloud/Spotify/YouTube) and USB stick setup, built on Symfony Console
and ddev.

## Tools

| Command          | Description                                                                                              |
|------------------|----------------------------------------------------------------------------------------------------------|
| `playlists:sync` | Download SoundCloud/Spotify/YouTube playlists via yt-dlp/spotdl, convert to MP3/WAV/FLAC, generate M3U8s |
| `usb:setup`      | Install Ventoy on a USB stick, copy a Debian live ISO with persistence, or duplicate an existing stick   |

## Requirements

All commands are run via the CLI (terminal / command prompt).

- [Docker](https://docs.ddev.com/en/stable/users/install/docker-installation/) — required by ddev
- [ddev](https://docs.ddev.com/en/stable/users/install/ddev-installation/) — all other dependencies (PHP, yt-dlp,
  spotdl, ffmpeg, Ventoy, disk tools) are installed automatically on `ddev start`

## Setup

> **Install all requirements above before proceeding.**

```bash
ddev start
```

Composer dependencies are installed automatically via a post-start hook.

`config/` is gitignored except for a few shipped defaults and `.example` templates. Copy the templates you need to their
real (gitignored) names and fill in machine-specific values before first use:

```bash
cp config/usb-setup.json.example config/usb-setup.json                # optional — usb:setup works without it
cp config/playlists-sync.json.example config/playlists-sync.json      # optional — playlists:sync works without it
cp config/playlists.txt.example config/playlists.txt                  # required by playlists:sync
cp config/cookies.txt.example config/cookies.txt                       # only needed for authenticated downloads
```

## Usage

```bash
# Playlist sync (interactive)
ddev exec bin/console playlists:sync

# USB setup (interactive, requires root)
ddev exec sudo bin/console usb:setup

# List all commands
ddev exec bin/console list
```

## Development

Unit tests use PHPUnit. The ddev post-start hook runs `composer install --no-dev`, so dev dependencies are stripped on
every `ddev start`/`ddev restart` — re-install them before running the suite:

```bash
ddev composer install            # re-adds phpunit (dev deps)
ddev exec vendor/bin/phpunit     # or: ddev composer test
```

---

## Configuration & parameter resolution

Both commands resolve every parameter through the same layers — first hit wins:

1. **CLI option** — e.g. `--out downloads`
2. **Environment variable** — from the shell or `.env` (`DOTENV_PATH` overrides the `.env` location; both commands read
   it)
3. **Config file** — `config/playlists-sync.json` / `config/usb-setup.json`
4. **Interactive prompt** — only for the parameters whose *Prompted when* column below says so;
   `-n`/`--no-interaction` skips all prompts (missing required values then fail the run)
5. **Built-in default**

Rules:

- **Only prompt answers are persisted** to the config file — values given via CLI or env are never written to it.
- Empty env or config values count as unset (the next layer applies).
- To re-trigger a "once"-type prompt (e.g. `min_odg`), delete its key from the config file.
- **usb:setup exception**: its config keys are *prompt defaults*, not resolved values — the device/ISO/persistence
  questions deliberately fire every run, pre-filled from the config file, and `USB_*` env vars act like the CLI option (
  they suppress the prompt). Only `cache_dir` resolves through the config file. playlists:sync uses the full chain for
  every parameter.

The per-command tables below list every parameter with its CLI option, env var, config key, prompt behaviour, and
default.

---

## playlists:sync

Downloads playlists into a shared audio library, converts to MP3/WAV/FLAC with ffmpeg, and generates per-playlist M3U8
files. SoundCloud/YouTube URLs are handled by yt-dlp; Spotify URLs (`open.spotify.com/playlist|album|track`,
`spotify:` URIs) are handled by spotdl, which matches tracks on YouTube Music and downloads best-quality m4a originals.

### Design

- Each track is downloaded **once** across all playlists via a shared library and a single deduplication archive — no
  re-downloads across runs or playlists.
- Conversions (MP3/WAV/FLAC) are done locally from the cached original — no re-downloading for format changes.
- Per-playlist M3U8 files reference the shared library with relative paths — no duplicate audio on disk, and each
  playlist can be imported independently into Rekordbox.
- Conversions normalize the sample rate: rates outside 44.1/48/96 kHz are resampled to the nearest supported rate ≥ the
  source (capped at 96 kHz), so odd-rate FLAC/ALAC/WAV/AIFF and streaming/video-container sources export cleanly.
- Each playlist prints a `Download: N new, N already in archive, N failed` summary, with the archive count derived from
  the dedup archive (see the [downloader docs](docs/playlists-sync.md#download-summary)).

### Quick start

```bash
ddev exec bin/console playlists:sync \
  -i config/playlists.txt \
  -o downloads
```

Or configure via `.env` and just run:

```bash
ddev exec bin/console playlists:sync
```

### Parameters

Resolution order for every row: CLI option → env var → `config/playlists-sync.json` key → prompt (where marked) →
default (see [Configuration & parameter resolution](#configuration--parameter-resolution)).

| Parameter            | CLI option                | Env var                 | Config key              | Prompted when                                                              | Default                                        |
|----------------------|---------------------------|-------------------------|-------------------------|----------------------------------------------------------------------------|------------------------------------------------|
| Input file           | `--input` / `-i`          | `INPUT_FILE`            | `input_file`            | when unresolved, every interactive run                                     | `config/playlists.txt`                         |
| Output dir           | `--out` / `-o`            | `OUTPUT_DIR`            | `output_dir`            | when unresolved, every interactive run                                     | `./downloads`                                  |
| M3U8 dir             | `--playlists-dir`         | `PLAYLISTS_DIR`         | `playlists_dir`         | never                                                                      | `OUT/playlists`                                |
| Playlist layout      | `--playlist-layout`       | `PLAYLIST_LAYOUT`       | `playlist_layout`       | when unresolved, every interactive run                                     | `flat`                                         |
| Min est. ODG         | `--min-odg`               | `MIN_ODG`               | `min_odg`               | once, when configured nowhere (guided tier prompt; answer is persisted)    | off                                            |
| Min ODG mode         | `--min-odg-mode`          | `MIN_ODG_MODE`          | `min_odg_mode`          | every interactive run while a minimum is active and no CLI option is given | `warn`                                         |
| Formats              | `--formats`               | `FORMATS`               | `formats`               | every interactive run unless `--formats` is given                          | `original,mp3,wav,flac`                        |
| MP3 mode             | `--mp3-mode`              | `MP3_MODE`              | `mp3_mode`              | never                                                                      | `cbr`                                          |
| MP3 bitrate (CBR)    | `--mp3-bitrate`           | `MP3_BITRATE`           | `mp3_bitrate`           | never                                                                      | `320` kbps                                     |
| MP3 quality (VBR)    | `--mp3-quality`           | `MP3_QUALITY`           | `mp3_quality`           | never                                                                      | `0` (LAME VBR highest)                         |
| Library dir          | `--library-dir`           | `LIBRARY_DIR`           | `library_dir`           | never                                                                      | `OUT/library`                                  |
| Archive dir          | `--archive-dir`           | `ARCHIVE_DIR`           | `archive_dir`           | never                                                                      | `OUT/.archive`                                 |
| Filename template    | `--lib-filename-template` | `LIB_FILENAME_TEMPLATE` | `lib_filename_template` | never                                                                      | `%(id)s - %(title)s`                           |
| yt-dlp binary        | `--ytdlp-bin`             | `YTDLP_BIN`             | `ytdlp_bin`             | never                                                                      | `yt-dlp`                                       |
| spotdl binary        | `--spotdl-bin`            | `SPOTDL_BIN`            | `spotdl_bin`            | never                                                                      | `spotdl`                                       |
| ffmpeg binary        | `--ffmpeg-bin`            | `FFMPEG_BIN`            | `ffmpeg_bin`            | never                                                                      | `ffmpeg`                                       |
| ffprobe binary       | `--ffprobe-bin`           | `FFPROBE_BIN`           | `ffprobe_bin`           | never                                                                      | `ffprobe`                                      |
| Cookies (both tools) | `--cookies`               | `COOKIES_FILE`          | `cookies_file`          | never                                                                      | `config/cookies.txt` (used if the file exists) |
| yt-dlp cookies       | `--ytdlp-cookies`         | `YTDLP_COOKIE_FILE`     | `ytdlp_cookie_file`     | never                                                                      | falls back to Cookies                          |
| spotdl cookies       | `--spotdl-cookies`        | `SPOTDL_COOKIE_FILE`    | `spotdl_cookie_file`    | never                                                                      | falls back to Cookies                          |
| Extractor retries    | `--extractor-retries`     | `EXTRACTOR_RETRIES`     | `extractor_retries`     | never                                                                      | `10`                                           |
| Retry sleep          | `--retry-sleep`           | `RETRY_SLEEP`           | `retry_sleep`           | never                                                                      | `exp=2:10:120`                                 |
| Sleep requests       | `--sleep-requests`        | `SLEEP_REQUESTS`        | `sleep_requests`        | never                                                                      | `2`                                            |
| Rate limit           | `--limit-rate`            | `LIMIT_RATE`            | `limit_rate`            | never                                                                      | off                                            |
| Pause between        | `--pause-between`         | `PAUSE_BETWEEN`         | `pause_between`         | never                                                                      | `2`                                            |
| JS runtimes          | `--js-runtimes`           | `JS_RUNTIMES`           | `js_runtimes`           | never                                                                      | `node`                                         |

One cookie file (Netscape format holds cookies for multiple domains — e.g. SoundCloud for yt-dlp plus YouTube Music for
spotdl) serves both tools by default; the per-tool parameters override it individually. A cookie file is only passed on
when it actually exists.

`-n`/`--no-interaction` skips all prompts; `--input`/`--out` are then required (via CLI, env, or config).

YouTube requires solving an n-sig JS challenge (yt-dlp's "EJS" system) to unlock non-image formats; without a JS runtime
enabled, yt-dlp only auto-enables `deno` (not installed here), so downloads silently fall back to images-only and fail
with "Requested format is not available". `js_runtimes` is passed to yt-dlp as
`--js-runtimes` and defaults to `node`, which ships with the ddev webimage.

### .env reference

Every env var below also has a `config/playlists-sync.json` equivalent (snake_case key, see the table above); env wins
over the config file.

```dotenv
INPUT_FILE=config/playlists.txt
OUTPUT_DIR=./downloads

YTDLP_BIN=/usr/local/bin/yt-dlp
FFMPEG_BIN=ffmpeg
FFPROBE_BIN=ffprobe             # used to detect source sample rate for resampling

# Formats: original, mp3, wav, flac (comma-separated), or "all" for every format
FORMATS=original,mp3,wav,flac

MP3_MODE=cbr                    # cbr (default) or vbr — some DJ software misreports VBR MP3 bitrate
MP3_BITRATE=320                 # CBR bitrate in kbps (MP3_MODE=cbr only)
MP3_QUALITY=0                   # LAME VBR: 0 = highest (~245 kbps), 9 = lowest (MP3_MODE=vbr only)
MIN_ODG=-2                      # minimum estimated ODG, PEAQ scale -4..0 (unset = off)
MIN_ODG_MODE=warn               # warn (default) or filter — filter keeps low-quality tracks out of playlists
LIB_FILENAME_TEMPLATE=%(id)s - %(title)s   # yt-dlp sources only; Spotify always uses "{track-id} - {title}"

COOKIES_FILE=config/cookies.txt  # one Netscape cookie file for both yt-dlp and spotdl
# YTDLP_COOKIE_FILE=...           # per-tool overrides, fall back to COOKIES_FILE
# SPOTDL_COOKIE_FILE=...          # YT Music Premium cookies → 256k m4a from spotdl

SPOTDL_BIN=spotdl

# LIBRARY_DIR=./downloads/library
# ARCHIVE_DIR=./downloads/.archive
# PLAYLISTS_DIR=./downloads/playlists
# PLAYLIST_LAYOUT=flat            # flat (default), per-playlist, or per-format

EXTRACTOR_RETRIES=10
RETRY_SLEEP=exp=2:10:120
SLEEP_REQUESTS=1-3
LIMIT_RATE=1M
PAUSE_BETWEEN=2
JS_RUNTIMES=node                # JS runtime yt-dlp uses to solve YouTube's n-sig/EJS challenge
```

### Output layout

`--playlist-layout` (`PLAYLIST_LAYOUT` / `playlist_layout`) controls how the `playlists/` directory is organized.
Default is `flat` (unchanged from before this option existed). Like `--formats`, it's prompted once interactively when
unset anywhere, then the answer is persisted and never asked again:

```
downloads/
  .archive/
    original.txt              ← single dedup archive across all playlists
  library/
    original/
      <id> - <title>.<ext>
      <id> - <title>.info.json
      <id> - <title>.jpg
    mp3/  wav/  flac/
      <id> - <title>.<ext>
  playlists/                  ← flat (default)
    <Uploader> - <Playlist Title> - original.m3u8
    <Uploader> - <Playlist Title> - mp3.m3u8
    <Uploader> - <Playlist Title> - wav.m3u8
    <Uploader> - <Playlist Title> - flac.m3u8
```

`per-playlist` groups every requested format for one playlist together:

```
  playlists/
    <Uploader> - <Playlist Title>/
      original.m3u8
      mp3.m3u8
      wav.m3u8
      flac.m3u8
```

`per-format` groups every playlist of one format together:

```
  playlists/
    mp3/
      <Uploader> - <Playlist Title>.m3u8
    wav/
      <Uploader> - <Playlist Title>.m3u8
```

### Playlist aliases

By default a playlist's name (used for its `.m3u8` file(s)) is auto-derived as `<Uploader> - <Playlist Title>` from the
source API. Override it by placing a `# alias: <name>` comment directly above that playlist's URL in the input file —
nothing (blank line, other comment) may sit in between, and it only applies to the very next URL:

```
# alias: My Chill Mix
https://soundcloud.com/stefan-ripper/sets/tek
```

Plain `#` comments (no `alias:` marker) are unaffected and keep working exactly as before — purely human-readable notes
with no effect on naming, e.g. the section headers already used in `config/playlists.txt`.

### Rekordbox import

**File → Import Playlist** → select the `.m3u8` for the format you want. Each playlist imports independently; audio is
stored once in the shared library.

---

## usb:setup

Installs Ventoy (MBR, FAT32) on a USB stick, optionally downloads a Debian live ISO and configures Ventoy persistence.
Can also duplicate an already-set-up stick onto a new one of any size ≥ the used payload (`--source-device`): Ventoy is
installed on the target, then the source's data partition (ISO, persistence incl. user data, `/software/`) is mirrored
via rsync from a read-only mount.

Multiple target devices are supported in a single run: pass a comma-separated list (`--device /dev/sdb,/dev/sdc`) or
select several from the interactive multi-select prompt. Devices are set up **sequentially, not in parallel** — the ISO
download and software downloads are still fetched only once and reused for every device. One device failing
(e.g. a partition that never appears) does not abort the rest of the batch; a per-device pass/fail summary is printed at
the end and the command exits non-zero if any device failed.

All required tools (dosfstools, e2fsprogs, util-linux, Ventoy) are installed automatically in the ddev container.

### Quick start

```bash
ddev exec sudo bin/console usb:setup
```

Interactive mode lists detected block devices and prompts for each option. Answers are saved to
`config/usb-setup.json` and pre-filled on the next run.

### Parameters

Resolution: CLI option → env var → prompt → default. **usb:setup env vars behave like their CLI option — they suppress
the prompt.** The config keys are deliberately *not* part of value resolution: they only pre-fill the prompt defaults (
the device/ISO/persistence questions fire every run on purpose), and prompt answers are written back to
`config/usb-setup.json`. Only `cache_dir` resolves through the config file too (it is never prompted). usb:setup reads
the same `.env` file as playlists:sync.

| Parameter          | CLI option           | Env var                | Config key                             | Prompted when                                                                     | Default                            |
|--------------------|----------------------|------------------------|----------------------------------------|-----------------------------------------------------------------------------------|------------------------------------|
| Target device(s)   | `--device`           | `USB_DEVICE`           | `devices` (prompt default only)        | every interactive run (multi-select device list)                                  | —                                  |
| Mode (update/redo) | `--update`           | `USB_UPDATE`           | —                                      | every interactive run unless CLI/env value                                        | auto (update when Ventoy detected) |
| Ventoy install     | `--install-ventoy`   | `USB_INSTALL_VENTOY`   | `install_ventoy` (prompt default only) | every interactive run unless CLI/env value                                        | install/update                     |
| Payload source     | `--source-device`    | `USB_SOURCE_DEVICE`    | `payload_source`, `source_device`      | every interactive run unless `--source-device`                                    | configuration                      |
| Debian ISO         | `--debian-iso`       | `USB_DEBIAN_ISO`       | `iso_source`, `iso_path`               | every interactive run unless duplicating or CLI/env value                         | download                           |
| ISO variant        | `--iso-variant`      | `USB_ISO_VARIANT`      | `iso_variant` (prompt default only)    | with the ISO prompt; a CLI/env value implies "download" and skips the ISO prompts | `standard`                         |
| Persistence size   | `--persistence-size` | `USB_PERSISTENCE_SIZE` | `persistence_mib`                      | every interactive run when an ISO is used and no CLI/env value                    | `2048` MiB                         |
| Ventoy binary      | `--ventoy-bin`       | `VENTOY_BIN`           | —                                      | never (auto-detected)                                                             | auto                               |
| Downloads file     | `--downloads-file`   | `USB_DOWNLOADS_FILE`   | `download_sources`                     | every interactive run unless CLI/env value or duplicate mode                      | `config/usb-downloads.txt`         |
| Cache dir          | `--cache-dir`        | `USB_CACHE_DIR`        | `cache_dir`                            | never                                                                             | `downloads/.cache`                 |
| Skip confirmations | `--yes` / `-y`       | `USB_YES`              | —                                      | —                                                                                 | off                                |
| Device name check  | —                    | —                      | `device_name`, `source_device_name`    | never (recorded automatically each interactive run)                               | —                                  |

`--yes`/`-y`/`USB_YES` skips confirmation prompts (wipe/update/continue-anyway); `-n`/`--no-interaction` skips
**all** prompts and implies `-y` — the device is then required (via `--device` or `USB_DEVICE`).

`--device`/`USB_DEVICE` accept a comma-separated list (e.g. `--device /dev/sdb,/dev/sdc`) to set up several sticks in
one run; per-device name history is recorded under `dev_<name>_device`/`dev_<name>_device_name` config keys.

### ISO download

When prompted, choose **download** to fetch the latest Debian live ISO directly from `cdimage.debian.org`. Available
variants: `standard`, `gnome`, `kde`, `cinnamon`, `lxde`, `lxqt`, `mate`, `xfce`. Passing `--iso-variant` (or
`USB_ISO_VARIANT`) picks the variant without prompting — also in non-interactive mode. Downloaded ISOs are cached in
`downloads/.cache/` and reused on subsequent runs.

### Software downloads

By default, additional software (Rekordbox, the T-Racks 8x8 Matrix Digital Processor Editor, and an Ableton Live trial)
can be queued for copying onto the stick's `/software/` folder. Entries are read from
`config/usb-downloads.txt`, one per line (`#` comments allowed):

- `https://...` / `http://...` — downloaded and copied onto the stick.
- `magnet:`/`urn:btmh:` — reserved for future torrent support; currently just logged and skipped.
- A local file or directory path — copied onto the stick as-is, no download needed. The default file already lists
  `config/usb-manual-downloads/` for anything you download by hand (e.g. Traktor Pro via Native Access, Traktor DJ2 via
  a third-party mirror, Resolume Arena) — just drop the installer in there. Add `/**` to a directory path (e.g.
  `config/usb-manual-downloads/**`) to scan it recursively, preserving subfolder structure under `/software/`.

Override the file with `--downloads-file`, or type `-` at the prompt to skip software provisioning entirely.

### Persistent config

Interactive answers are saved to `config/usb-setup.json` and used as defaults on the next run. Edit the file directly to
change the cache directory, downloads file, or other defaults.

---

## Troubleshooting

| Symptom                                                                     | Fix                                                                                                                   |
|-----------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------|
| 403/429 errors                                                              | Add `config/cookies.txt` + set `LIMIT_RATE`, `SLEEP_REQUESTS`, `EXTRACTOR_RETRIES` in `.env`                          |
| Missing metadata / thumbnails                                               | `ddev exec pip install -U yt-dlp`                                                                                     |
| YouTube: "n challenge solving failed" / "Requested format is not available" | Set `JS_RUNTIMES` (default `node`) in `.env` — YouTube requires a working JS runtime to solve its n-sig/EJS challenge |
| Want to add a format later                                                  | Re-run with updated `FORMATS` — originals are cached, only new conversions run                                        |
| USB device not found in container                                           | Run `ddev restart` — privileged mode is enabled via `.ddev/docker-compose.privileged.yaml`                            |
