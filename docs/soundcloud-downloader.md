# SoundCloud Playlist Downloader

Downloads playlists via yt-dlp into a shared audio library, converts to MP3/WAV/FLAC with ffmpeg, and generates
per-playlist M3U8 files.

## Requirements

| Tool   | Min version | Install                        |
|--------|-------------|--------------------------------|
| PHP    | 8.1         | ddev (auto)                    |
| yt-dlp | latest      | `ddev exec pip install yt-dlp` |
| ffmpeg | any recent  | `ddev exec apt install ffmpeg` |

## Configuration

`config/playlists.txt` (your playlist/likes URLs) and `config/cookies.txt` (exported browser cookies, only needed for
authenticated downloads) are gitignored since they're personal data. Copy the checked-in starting points before
first use:

```bash
cp config/playlists.txt.example config/playlists.txt
cp config/cookies.txt.example config/cookies.txt   # optional
```

## ddev commands

All commands run inside the `web` container via `ddev exec`. Paths are relative to the project root.

### Start ddev

```bash
ddev start
```

### Interactive run (default)

Prompts for any missing values:

```bash
ddev exec bin/console soundcloud:download
```

Pre-fill some or all values — skips prompts for provided args:

```bash
ddev exec bin/console soundcloud:download \
  -i config/playlists.txt \
  -o downloads
```

### Non-interactive run

All required args must be provided via flags or `.env`; exits immediately if any are missing:

```bash
ddev exec bin/console soundcloud:download \
  -i config/playlists.txt \
  -o downloads \
  -n
```

### Run using .env

Put a `.env` file in the project root — ddev mounts it into the container automatically:

```bash
ddev exec bin/console soundcloud:download
```

### Override formats

```bash
ddev exec env FORMATS=mp3,flac \
  bin/console soundcloud:download \
  -i config/playlists.txt \
  -o downloads
```

### With cookies (authenticated / private playlists)

```bash
ddev exec env COOKIES_FILE=cookies.txt \
  bin/console soundcloud:download \
  -i config/playlists.txt \
  -o downloads
```

### Custom playlists output directory

```bash
ddev exec bin/console soundcloud:download \
  -i config/playlists.txt \
  -o downloads \
  --playlists-dir downloads/playlists
```

### Show full help

```bash
ddev exec bin/console soundcloud:download --help
```

### SSH into the container (interactive)

```bash
ddev ssh
bin/console soundcloud:download
```

## Options

| Flag               | Short | Default                 | Description                                    |
|--------------------|-------|-------------------------|------------------------------------------------|
| `--input`          | `-i`  | prompted / `INPUT_FILE` | Path to file with playlist URLs                |
| `--out`            | `-o`  | prompted / `OUTPUT_DIR` | Base output directory                          |
| `--playlists-dir`  |       | `OUTPUT_DIR/playlists`  | Directory for M3U8 files                       |
| `--no-interaction` | `-n`  | —                       | Non-interactive: fail if required args missing |
| `--help`           | `-h`  | —                       | Show full option reference                     |

## .env reference

```dotenv
INPUT_FILE=config/playlists.txt
OUTPUT_DIR=./downloads

# Optional binary paths (fallback to PATH)
YTDLP_BIN=/usr/local/bin/yt-dlp
FFMPEG_BIN=ffmpeg

# Formats: any of original, mp3, wav, flac (comma-separated)
FORMATS=original,mp3,wav,flac

# LAME VBR quality — 0 = highest (~245 kbps), 9 = lowest
MP3_QUALITY=0

# Base filename template for library files
LIB_FILENAME_TEMPLATE=%(id)s - %(title)s

# Cookies for private / liked content (Netscape format)
COOKIES_FILE=cookies.txt

# Shared library and archive dirs (default under OUTPUT_DIR)
# LIBRARY_DIR=./downloads/library
# ARCHIVE_DIR=./downloads/.archive

# Where .m3u8 files go (default: OUTPUT_DIR/playlists)
# PLAYLISTS_DIR=./downloads/playlists

# Rate limiting and retry behaviour
EXTRACTOR_RETRIES=10
RETRY_SLEEP=exp=2:10:120
SLEEP_REQUESTS=1-3
LIMIT_RATE=1M
PAUSE_BETWEEN=2
```

## Output layout

```
downloads/
  .archive/
    original.txt          ← single dedup archive across all playlists
  library/
    original/
      <id> - <title>.<ext>
      <id> - <title>.info.json
      <id> - <title>.jpg
    mp3/
      <id> - <title>.mp3
    wav/
      <id> - <title>.wav
    flac/
      <id> - <title>.flac
  playlists/
    <Uploader> - <Playlist Title> - mp3.m3u8
    <Uploader> - <Playlist Title> - wav.m3u8
    <Uploader> - <Playlist Title> - flac.m3u8
```

## Rekordbox import

In Rekordbox: **File → Import Playlist** → select the `.m3u8` for the format you want. Each playlist can be imported
independently; all audio is stored once in the shared library.

## Troubleshooting

| Symptom                       | Fix                                                                                    |
|-------------------------------|----------------------------------------------------------------------------------------|
| 403/429 errors                | Add `COOKIES_FILE` + set `LIMIT_RATE`, `SLEEP_REQUESTS`, `EXTRACTOR_RETRIES` in `.env` |
| Missing thumbnails / metadata | Update yt-dlp: `ddev exec pip install -U yt-dlp`                                       |
| Want to add a format later    | Re-run with updated `FORMATS` — originals are cached, only new conversions run         |
| yt-dlp not found              | `ddev exec pip install yt-dlp` or add it to `.ddev/web-build/Dockerfile`               |
