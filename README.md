# yt-dlp Tools

A set of CLI tools for playlist downloading (SoundCloud/Spotify/YouTube) and USB stick setup, built on Symfony
Console and ddev.

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

`config/` is gitignored except for a few shipped defaults and `.example` templates. Copy the templates you need to
their real (gitignored) names and fill in machine-specific values before first use:

```bash
cp config/usb-setup.json.example config/usb-setup.json        # optional — usb:setup works without it
cp config/playlists.txt.example config/playlists.txt          # required by playlists:sync
cp config/cookies.txt.example config/cookies.txt               # only needed for authenticated downloads
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

Unit tests use PHPUnit. The ddev post-start hook runs `composer install --no-dev`, so dev dependencies are stripped
on every `ddev start`/`ddev restart` — re-install them before running the suite:

```bash
ddev composer install            # re-adds phpunit (dev deps)
ddev exec vendor/bin/phpunit     # or: ddev exec composer test
```

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
- Conversions normalize the sample rate: rates outside 44.1/48/96 kHz are resampled to the nearest supported rate ≥
  the source (capped at 96 kHz), so odd-rate FLAC/ALAC/WAV/AIFF and streaming/video-container sources export cleanly.
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

### Options

| Flag               | Short | Default                 | Description                                                                                    |
|--------------------|-------|-------------------------|------------------------------------------------------------------------------------------------|
| `--input`          | `-i`  | prompted / `INPUT_FILE` | File with playlist URLs (one per line, `#` comments allowed) — default: `config/playlists.txt` |
| `--out`            | `-o`  | prompted / `OUTPUT_DIR` | Base output directory                                                                          |
| `--playlists-dir`  |       | `OUTPUT_DIR/playlists`  | Directory for M3U8 files                                                                       |
| `--no-interaction` | `-n`  | —                       | Fail if required args are missing; no prompts                                                  |

### .env reference

```dotenv
INPUT_FILE=config/playlists.txt
OUTPUT_DIR=./downloads

YTDLP_BIN=/usr/local/bin/yt-dlp
FFMPEG_BIN=ffmpeg
FFPROBE_BIN=ffprobe             # used to detect source sample rate for resampling

# Formats: original, mp3, wav, flac (comma-separated)
FORMATS=original,mp3,wav,flac

MP3_QUALITY=0                   # LAME VBR: 0 = highest (~245 kbps), 9 = lowest
LIB_FILENAME_TEMPLATE=%(id)s - %(title)s   # yt-dlp sources only; Spotify always uses "{track-id} - {title}"

COOKIES_FILE=cookies.txt        # Netscape format — needed for private/liked content

SPOTDL_BIN=spotdl
SPOTDL_COOKIE_FILE=config/spotdl-cookies.txt   # optional YT Music cookies → 256k m4a with Premium

# LIBRARY_DIR=./downloads/library
# ARCHIVE_DIR=./downloads/.archive
# PLAYLISTS_DIR=./downloads/playlists

EXTRACTOR_RETRIES=10
RETRY_SLEEP=exp=2:10:120
SLEEP_REQUESTS=1-3
LIMIT_RATE=1M
PAUSE_BETWEEN=2
```

### Output layout

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
  playlists/
    <Uploader> - <Playlist Title> - mp3.m3u8
    <Uploader> - <Playlist Title> - wav.m3u8
    <Uploader> - <Playlist Title> - flac.m3u8
```

### Rekordbox import

**File → Import Playlist** → select the `.m3u8` for the format you want. Each playlist imports independently; audio is
stored once in the shared library.

---

## usb:setup

Installs Ventoy (MBR, FAT32) on a USB stick, optionally downloads a Debian live ISO and configures Ventoy persistence.
Can also duplicate an already-set-up stick onto a new one of any size ≥ the used payload (`--source-device`): Ventoy
is installed on the target, then the source's data partition (ISO, persistence incl. user data, `/software/`) is
mirrored via rsync from a read-only mount.

All required tools (dosfstools, e2fsprogs, util-linux, Ventoy) are installed automatically in the ddev container.

### Quick start

```bash
ddev exec sudo bin/console usb:setup
```

Interactive mode lists detected block devices and prompts for each option. Answers are saved to
`config/usb-setup.json` and pre-filled on the next run.

### Options

| Flag                 | Short | Default                                       | Description                                                   |
|----------------------|-------|-----------------------------------------------|---------------------------------------------------------------|
| `--device`           |       | prompted                                      | Target USB block device (e.g. `/dev/sdb`)                     |
| `--source-device`    |       | prompted ("Payload" choice)                   | Duplicate payload from this already-set-up Ventoy stick       |
| `--debian-iso`       |       | prompted (download or local path)             | Debian live ISO                                               |
| `--persistence-size` |       | prompted (default 2048)                       | Persistence image size in MiB                                 |
| `--ventoy-bin`       |       | auto-detected                                 | Path to `Ventoy2Disk.sh`                                      |
| `--downloads-file`   |       | prompted (default `config/usb-downloads.txt`) | File listing software URLs/local paths to copy onto the stick |
| `--yes`              | `-y`  | —                                             | Skip confirmation prompts                                     |
| `--no-interaction`   | `-n`  | —                                             | Require all args via flags, no prompts                        |

### ISO download

When prompted, choose **download** to fetch the latest Debian live ISO directly from `cdimage.debian.org`. Available
variants: `standard`, `gnome`, `kde`, `cinnamon`, `lxde`, `lxqt`, `mate`, `xfce`. Downloaded ISOs are cached in
`.cache/` and reused on subsequent runs.

### Software downloads

By default, additional software (Rekordbox, the T-Racks 8x8 Matrix Digital Processor Editor, and an Ableton Live
trial) can be queued for copying onto the stick's `/software/` folder. Entries are read from
`config/usb-downloads.txt`, one per line (`#` comments allowed):

- `https://...` / `http://...` — downloaded and copied onto the stick.
- `magnet:`/`urn:btmh:` — reserved for future torrent support; currently just logged and skipped.
- A local file or directory path — copied onto the stick as-is, no download needed. The default file already lists
  `config/usb-manual-downloads/` for anything you download by hand (e.g. Traktor Pro via Native Access, Traktor DJ2
  via a third-party mirror, Resolume Arena) — just drop the installer in there. Add `/**` to a directory path (e.g.
  `config/usb-manual-downloads/**`) to scan it recursively, preserving subfolder structure under `/software/`.

Override the file with `--downloads-file`, or type `-` at the prompt to skip software provisioning entirely.

### Persistent config

Interactive answers are saved to `config/usb-setup.json` and used as defaults on the next run. Edit the file directly
to change the cache directory, downloads file, or other defaults.

---

## Troubleshooting

| Symptom                           | Fix                                                                                        |
|-----------------------------------|--------------------------------------------------------------------------------------------|
| 403/429 errors                    | Add `COOKIES_FILE` + set `LIMIT_RATE`, `SLEEP_REQUESTS`, `EXTRACTOR_RETRIES` in `.env`     |
| Missing metadata / thumbnails     | `ddev exec pip install -U yt-dlp`                                                          |
| Want to add a format later        | Re-run with updated `FORMATS` — originals are cached, only new conversions run             |
| USB device not found in container | Run `ddev restart` — privileged mode is enabled via `.ddev/docker-compose.privileged.yaml` |
