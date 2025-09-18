# SoundCloud Playlist Downloader (yt-dlp + ffmpeg)

A pragmatic utility to download SoundCloud (and other supported sites) playlists using yt-dlp, store a single
bestaudio/original copy per track in a shared library, convert locally to MP3/WAV/FLAC with ffmpeg, and generate
per-playlist M3U8 files that reference the shared library (so you don’t keep duplicate audio files for the same track
across playlists).

## Quick start

1) Install prerequisites: yt-dlp and ffmpeg must be on your PATH; PHP 8.1+ installed.
2) Create a playlists.txt file with one URL per line (comments with `#` allowed).
3) Run:

```bash
php SoundCloudPlaylistDownloader.php --input playlists.txt --out ./downloads
```

That’s it. Originals are downloaded once into a shared library, conversions (mp3/wav/flac) are done locally, and per-playlist M3U8 files are created.

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

Tip: Check versions

```bash
yt-dlp --version
ffmpeg -version
php -v
```

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

# Playlist file naming (for generated .m3u8 files)
# Filenames are derived from: "<Uploader> - <Playlist Title> [<id>] - <format>.m3u8"
# This pattern is currently not configurable.

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

```
downloads/
  .archive/
    original.txt
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
    <Uploader> - <Playlist Title> [<id>] - mp3.m3u8
    <Uploader> - <Playlist Title> [<id>] - wav.m3u8
    <Uploader> - <Playlist Title> [<id>] - flac.m3u8
```

### Customize the layout
- OUTPUT_DIR controls the base output folder.
- LIBRARY_DIR overrides the shared library location; originals go to LIBRARY_DIR/original and conversions to LIBRARY_DIR/<format>.
- ARCHIVE_DIR overrides where the single download archive (original.txt) is stored.
- PLAYLISTS_DIR overrides where .m3u8 files are written; by default it is OUTPUT_DIR/playlists.
- LIB_FILENAME_TEMPLATE controls the base filename for originals (and thus converted files), default: "%(id)s - %(title)s".
- Thumbnails are converted to .jpg and saved next to the original, along with .info.json from yt-dlp.

## Troubleshooting / FAQ

- 403/429 errors or missing tracks
  - Use a cookies file for authenticated/liked/private content: export browser cookies in Netscape format and set COOKIES_FILE or pass via env.
  - Add gentle rate limits in .env (e.g., LIMIT_RATE=1M, SLEEP_REQUESTS=1-3, EXTRACTOR_RETRIES=10, RETRY_SLEEP=exp=2:10:120).
- Thumbnails or metadata missing
  - Ensure yt-dlp is up-to-date. Sidecars (info.json, jpg) are saved next to the original; conversions embed tags/artwork when available.
- I changed FORMATS and want to add another format later
  - Re-run with the new FORMATS. Originals are cached; only conversions run.
- Different .env location
  - Set DOTENV_PATH=/absolute/path/to/.env or pass options via CLI flags (CLI takes precedence).
- Where are playlists (.m3u8) stored?
  - By default OUTPUT_DIR/playlists, or override with --playlists-dir or PLAYLISTS_DIR.
