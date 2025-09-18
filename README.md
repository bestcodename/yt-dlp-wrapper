# SoundCloud Playlist Downloader (yt-dlp + ffmpeg)

A pragmatic utility to download SoundCloud (and other supported sites) playlists using yt-dlp, store a single
bestaudio/original copy per track in a shared library, convert locally to MP3/WAV/FLAC with ffmpeg, and generate
per-playlist M3U8 files that reference the shared library (so you don’t keep duplicate audio files for the same track
across playlists).
Key goals:

- One download per track across all playlists (deduplicated via a single download archive).
- Keep source files + sidecars (info.json, thumbnail) for reliable metadata and artwork.
- Convert locally to multiple formats without redownloading.
- Generate clean M3U8 playlists that point to the shared library.

## Requirements

- PHP 8.1+ (8.3 recommended)
- yt-dlp (latest recommended)
- ffmpeg
- A text file with playlist URLs (e.g., playlists.txt)

Optional:

- Cookies file for authenticated content (e.g., SoundCloud likes, private playlists)
- ddev/Docker or any environment that can run PHP and the binaries

## Installation

1. Ensure yt-dlp and ffmpeg are installed and available in PATH.
2. Copy the script into your project root as SoundCloudPlaylistDownloader.php.
3. Create a .env file (see Configuration) or pass CLI arguments.
4. Create a playlists.txt file with one playlist URL per line.

Example playlists.txt:

``` text
https://soundcloud.com/username/sets/some-playlist
# Comments are allowed and ignored
https://soundcloud.com/username/likes
```

## Configuration

Configure via a .env file in project root (or specify DOTENV_PATH) or via CLI flags. CLI flags take precedence over .env
values where specified.
Common .env options:

``` dotenv
# Required if not passing CLI args
INPUT_FILE=playlists.txt
OUTPUT_DIR=./downloads

# Optional paths (fallback to PATH if not set)
YTDLP_BIN=/usr/local/bin/yt-dlp
FFMPEG_BIN=ffmpeg

# What to produce: comma-separated list of formats from: original, mp3, wav, flac
FORMATS=original,mp3,wav,flac

# LAME VBR quality (0 = highest quality, ~245 kbps)
MP3_QUALITY=0

# Base filename template for library originals (applies to shared library)
LIB_FILENAME_TEMPLATE=%(id)s - %(title)s

# Per-playlist file naming (for display/metadata; conversion uses library name)
FILENAME_TEMPLATE=%(playlist_index)03d - %(title)s

# How to infer album tag during conversion, by priority
ALBUM_FROM=playlist_title|title

# Authentication for private/liked content
COOKIES_FILE=cookies.txt

# Unified playlists directory (where .m3u8 files go). Defaults to OUTPUT_DIR/playlists
# PLAYLISTS_DIR=/absolute/or/relative/path

# Advanced: shared library and archive directories (default under OUTPUT_DIR)
# LIBRARY_DIR=./downloads/library
# ARCHIVE_DIR=./downloads/.archive

# Rate limiting and retries (help avoid 429 and flakiness)
# EXTRACTOR_RETRIES=10
# RETRY_SLEEP=exp=2:10:120
# SLEEP_REQUESTS=1-3
# LIMIT_RATE=1M

# Pause in seconds between playlists
# PAUSE_BETWEEN=2

# Optionally point to a custom .env location
# DOTENV_PATH=/absolute/path/to/.env
```

Notes:

- FORMATS controls which outputs you want. original stores the bestaudio file. mp3/wav/flac are produced locally with
  ffmpeg using the downloaded original. You can safely re-run with different FORMATS to add formats later without
  re-downloading.
- COOKIES_FILE is often required for liked tracks or private playlists on SoundCloud. Export cookies from your browser (
  Netscape format) and pass the file path.

## Usage

Basic:

``` bash
php SoundCloudPlaylistDownloader.php --input playlists.txt --out ./downloads
```

Using environment (.env) only:

``` bash
php SoundCloudPlaylistDownloader.php
```

Specifying a unified playlists directory (where .m3u8 files are written):

``` bash
php SoundCloudPlaylistDownloader.php --input playlists.txt --out ./downloads --playlists-dir ./downloads/playlists
```

Example with a custom cookies file and formats:

``` bash
export COOKIES_FILE="$PWD/cookies.txt"
export FORMATS="original,mp3,flac"
php SoundCloudPlaylistDownloader.php --input playlists.txt --out ./downloads
```

ddev example (if applicable to your setup):

``` bash
ddev exec -s web php /var/www/html/SoundCloudPlaylistDownloader.php
```

## What it does

- Queries each playlist once to enumerate entries (id + title).
- Downloads bestaudio/best only once per track into a shared library directory (downloads/library/original), using a
  single download archive (downloads/.archive/original.txt) to prevent re-fetches across all playlists and runs.
- Writes yt-dlp sidecars (info.json, thumbnail jpg).
- Uses ffmpeg to convert locally into mp3, wav, and/or flac, embedding metadata and artwork (when available).
- Generates per-playlist M3U8 files for each requested format (except original), stored in a unified playlists
  directory (default: OUTPUT_DIR/playlists). M3U8 entries are relative paths pointing to the shared library—no on-disk
  duplication.

## Output layout (default)

- downloads/
    - .archive/
        - original.txt

    - library/
        - original/
            -
                - .
