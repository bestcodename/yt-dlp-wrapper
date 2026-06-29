# yt-dlp Tools

A set of CLI tools for SoundCloud playlist downloading and USB stick setup, built on Symfony Console and ddev.

## Tools

| Command               | Description                                                                       |
|-----------------------|-----------------------------------------------------------------------------------|
| `soundcloud:download` | Download SoundCloud playlists via yt-dlp, convert to MP3/WAV/FLAC, generate M3U8s |
| `usb:setup`           | Install Ventoy on a USB stick, optionally copy a Debian live ISO with persistence |

## Requirements

All commands are run via the CLI (terminal / command prompt).

- [Docker](https://docs.ddev.com/en/stable/users/install/docker-installation/) — required by ddev
- [ddev](https://docs.ddev.com/en/stable/users/install/ddev-installation/) — all other dependencies (PHP, yt-dlp,
  ffmpeg, Ventoy, disk tools) are installed automatically on `ddev start`

## Setup

> **Install all requirements above before proceeding.**

```bash
ddev start
```

That's it. Composer dependencies are installed automatically via a post-start hook.

## Usage

```bash
# SoundCloud downloader (interactive)
ddev exec bin/console soundcloud:download

# USB setup (interactive, requires root)
ddev exec sudo bin/console usb:setup

# List all commands
ddev exec bin/console list
```

---

## soundcloud:download

Downloads playlists into a shared audio library, converts to MP3/WAV/FLAC with ffmpeg, and generates per-playlist M3U8
files.

### Design

- Each track is downloaded **once** across all playlists via a shared library and a single deduplication archive — no
  re-downloads across runs or playlists.
- Conversions (MP3/WAV/FLAC) are done locally from the cached original — no re-downloading for format changes.
- Per-playlist M3U8 files reference the shared library with relative paths — no duplicate audio on disk, and each
  playlist can be imported independently into Rekordbox.

### Quick start

```bash
ddev exec bin/console soundcloud:download \
  -i config/playlists.txt \
  -o downloads
```

Or configure via `.env` and just run:

```bash
ddev exec bin/console soundcloud:download
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

# Formats: original, mp3, wav, flac (comma-separated)
FORMATS=original,mp3,wav,flac

MP3_QUALITY=0                   # LAME VBR: 0 = highest (~245 kbps), 9 = lowest
LIB_FILENAME_TEMPLATE=%(id)s - %(title)s

COOKIES_FILE=cookies.txt        # Netscape format — needed for private/liked content

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

All required tools (dosfstools, e2fsprogs, util-linux, Ventoy) are installed automatically in the ddev container.

### Quick start

```bash
ddev exec sudo bin/console usb:setup
```

Interactive mode lists detected block devices and prompts for each option. Answers are saved to `.usb-setup.json` and
pre-filled on the next run.

### Options

| Flag                 | Short | Default                           | Description                               |
|----------------------|-------|-----------------------------------|-------------------------------------------|
| `--device`           |       | prompted                          | Target USB block device (e.g. `/dev/sdb`) |
| `--debian-iso`       |       | prompted (download or local path) | Debian live ISO                           |
| `--persistence-size` |       | prompted (default 2048)           | Persistence image size in MiB             |
| `--ventoy-bin`       |       | auto-detected                     | Path to `Ventoy2Disk.sh`                  |
| `--yes`              | `-y`  | —                                 | Skip confirmation prompts                 |
| `--no-interaction`   | `-n`  | —                                 | Require all args via flags, no prompts    |

### ISO download

When prompted, choose **download** to fetch the latest Debian live ISO directly from `cdimage.debian.org`. Available
variants: `standard`, `gnome`, `kde`, `cinnamon`, `lxde`, `lxqt`, `mate`, `xfce`. Downloaded ISOs are cached in
`.cache/iso/` and reused on subsequent runs.

### Persistent config

Interactive answers are saved to `.usb-setup.json` and used as defaults on the next run. Edit the file directly to
change the ISO cache directory or other defaults.

---

## Troubleshooting

| Symptom                           | Fix                                                                                        |
|-----------------------------------|--------------------------------------------------------------------------------------------|
| 403/429 errors                    | Add `COOKIES_FILE` + set `LIMIT_RATE`, `SLEEP_REQUESTS`, `EXTRACTOR_RETRIES` in `.env`     |
| Missing metadata / thumbnails     | `ddev exec pip install -U yt-dlp`                                                          |
| Want to add a format later        | Re-run with updated `FORMATS` — originals are cached, only new conversions run             |
| USB device not found in container | Run `ddev restart` — privileged mode is enabled via `.ddev/docker-compose.privileged.yaml` |
