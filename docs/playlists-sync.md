# Playlist Sync

Downloads SoundCloud/Spotify/YouTube playlists into a shared audio library, converts to MP3/WAV/FLAC with ffmpeg,
and generates per-playlist M3U8 files. SoundCloud/YouTube URLs are handled by yt-dlp; Spotify URLs by spotdl.

## Requirements

| Tool    | Min version | Install                                             |
|---------|-------------|-----------------------------------------------------|
| PHP     | 8.2         | ddev (auto)                                         |
| yt-dlp  | latest      | `ddev exec pip install yt-dlp`                      |
| spotdl  | latest      | ships in the ddev web image (`pip3 install spotdl`) |
| ffmpeg  | any recent  | `ddev exec apt install ffmpeg`                      |
| ffprobe | any recent  | ships with ffmpeg                                   |

`ffprobe` (bundled with ffmpeg) is used to detect each source's sample rate so conversions can be resampled to a
supported rate — see [Sample-rate normalization](#sample-rate-normalization).

## Configuration

`config/playlists.txt` (your playlist/likes URLs) and `config/cookies.txt` (exported browser cookies, only needed for
authenticated downloads) are gitignored since they're personal data. Copy the checked-in starting points before
first use:

```bash
cp config/playlists.txt.example config/playlists.txt
cp config/cookies.txt.example config/cookies.txt                   # optional
cp config/playlists-sync.json.example config/playlists-sync.json   # optional
```

### Parameter resolution

Every parameter resolves through the same layers — first hit wins:

1. **CLI option** (`--out downloads`)
2. **Env var** — from the shell or `.env` (`DOTENV_PATH` overrides the `.env` location)
3. **Config file** `config/playlists-sync.json`
4. **Interactive prompt** — only for the parameters whose *Prompted when* column in [Parameters](#parameters) says
   so; `-n`/`--no-interaction` skips all prompts
5. **Built-in default**

Only prompt answers are persisted to `config/playlists-sync.json` — CLI/env values are never written to it. Empty
env or config values count as unset.

## ddev commands

All commands run inside the `web` container via `ddev exec`. Paths are relative to the project root.

### Start ddev

```bash
ddev start
```

### Interactive run (default)

Prompts for any missing values. Besides input file, output directory, and formats, two quality-threshold prompts
can fire (see [Quality threshold](#quality-threshold)):

- **Output formats** — asked when `formats` is configured nowhere (no CLI option, no `FORMATS` env, no `formats`
  config key). Accepts a comma-separated list of `original`, `mp3`, `wav`, `flac`; the answer is persisted, so it
  is not asked again — delete the `formats` key from `config/playlists-sync.json` to re-trigger it.
- **Minimum source audio quality (estimated ODG)** — asked **once**, only when `min-odg` is configured nowhere (no
  CLI option, no `MIN_ODG` env, no `min_odg` config key). The prompt is guided: it lists quality tiers with
  real-world decision help — `[1]` Archive / Pro Club Standard (ODG ≥ -0.2 | ≈320 kbps MP3 / 256 AAC / 182 Opus,
  large venue PAs), `[2]` Semi-Pro Performance Minimum (ODG ≥ -1.0 | ≈192 kbps MP3 / 128 AAC / 96 Opus, minimum
  safe gig threshold), `[3]` Preview Only (ODG ≥ -2.0 | ≈128 kbps MP3 / 89 AAC / 64 Opus, casual listening),
  `[4]` Off — and accepts an option number, a custom ODG value between -4 and 0 (e.g. `-1.5`), or an empty answer
  for off. The answer (including "off") is persisted, so it is never asked again; delete the `min_odg` key from
  `config/playlists-sync.json` to re-trigger it.
- **Low-quality handling (`warn`/`filter`)** — asked **every interactive run while a minimum is active**,
  defaulting to the saved/env value. Passing `--min-odg-mode` suppresses the prompt; the answer is persisted as the
  next run's default.

```bash
ddev exec bin/console playlists:sync
```

Pre-fill some or all values — skips prompts for provided args:

```bash
ddev exec bin/console playlists:sync \
  -i config/playlists.txt \
  -o downloads
```

### Non-interactive run

`-n` skips all prompts (including the quality-threshold ones). All required args must be provided via flags, `.env`,
or the config file; exits immediately if any are missing:

```bash
ddev exec bin/console playlists:sync \
  -i config/playlists.txt \
  -o downloads \
  -n
```

### Run using .env

Put a `.env` file in the project root — ddev mounts it into the container automatically:

```bash
ddev exec bin/console playlists:sync
```

### Override formats

```bash
ddev exec env FORMATS=mp3,flac \
  bin/console playlists:sync \
  -i config/playlists.txt \
  -o downloads
```

### With cookies (authenticated / private playlists)

Cookies are picked up automatically from `config/cookies.txt` (Netscape format) when the file exists:

```bash
cp config/cookies.txt.example config/cookies.txt   # then paste your exported cookies
ddev exec bin/console playlists:sync \
  -i config/playlists.txt \
  -o downloads
```

### Custom playlists output directory

```bash
ddev exec bin/console playlists:sync \
  -i config/playlists.txt \
  -o downloads \
  --playlists-dir downloads/playlists
```

### Show full help

```bash
ddev exec bin/console playlists:sync --help
```

### SSH into the container (interactive)

```bash
ddev ssh
bin/console playlists:sync
```

## Parameters

Resolution order for every row: CLI option → env var → `config/playlists-sync.json` key → prompt (where marked) →
default (see [Parameter resolution](#parameter-resolution)).

| Parameter            | CLI option                                         | Env var                 | Config key              | Prompted when                                                              | Default                                        |
|----------------------|----------------------------------------------------|-------------------------|-------------------------|----------------------------------------------------------------------------|------------------------------------------------|
| Input file           | `--input` / `-i`                                   | `INPUT_FILE`            | `input_file`            | when unresolved, every interactive run                                     | `config/playlists.txt`                         |
| Output dir           | `--out` / `-o`                                     | `OUTPUT_DIR`            | `output_dir`            | when unresolved, every interactive run                                     | `./downloads`                                  |
| M3U8 dir             | `--playlists-dir`                                  | `PLAYLISTS_DIR`         | `playlists_dir`         | never                                                                      | `OUT/playlists`                                |
| Min est. ODG         | `--min-odg`                                        | `MIN_ODG`               | `min_odg`               | once, when configured nowhere (guided tier prompt; answer is persisted)    | off                                            |
| Min ODG mode         | `--min-odg-mode`                                   | `MIN_ODG_MODE`          | `min_odg_mode`          | every interactive run while a minimum is active and no CLI option is given | `warn`                                         |
| Formats              | `--formats`                                        | `FORMATS`               | `formats`               | when unresolved, every interactive run                                     | `original,mp3,wav,flac`                        |
| MP3 mode             | `--mp3-mode`                                       | `MP3_MODE`              | `mp3_mode`              | never                                                                      | `cbr`                                          |
| MP3 bitrate (CBR)    | `--mp3-bitrate`                                    | `MP3_BITRATE`           | `mp3_bitrate`           | never                                                                      | `320` kbps                                     |
| MP3 quality (VBR)    | `--mp3-quality`                                    | `MP3_QUALITY`           | `mp3_quality`           | never                                                                      | `0` (LAME VBR highest)                         |
| Reencode stale MP3   | `--reencode-stale-mp3` / `--no-reencode-stale-mp3` | —                       | —                       | never (CLI-only, not persisted)                                            | on                                             |
| Library dir          | `--library-dir`                                    | `LIBRARY_DIR`           | `library_dir`           | never                                                                      | `OUT/library`                                  |
| Archive dir          | `--archive-dir`                                    | `ARCHIVE_DIR`           | `archive_dir`           | never                                                                      | `OUT/.archive`                                 |
| Filename template    | `--lib-filename-template`                          | `LIB_FILENAME_TEMPLATE` | `lib_filename_template` | never                                                                      | `%(id)s - %(title)s`                           |
| yt-dlp binary        | `--ytdlp-bin`                                      | `YTDLP_BIN`             | `ytdlp_bin`             | never                                                                      | `yt-dlp`                                       |
| spotdl binary        | `--spotdl-bin`                                     | `SPOTDL_BIN`            | `spotdl_bin`            | never                                                                      | `spotdl`                                       |
| ffmpeg binary        | `--ffmpeg-bin`                                     | `FFMPEG_BIN`            | `ffmpeg_bin`            | never                                                                      | `ffmpeg`                                       |
| ffprobe binary       | `--ffprobe-bin`                                    | `FFPROBE_BIN`           | `ffprobe_bin`           | never                                                                      | `ffprobe`                                      |
| Cookies (both tools) | `--cookies`                                        | `COOKIES_FILE`          | `cookies_file`          | never                                                                      | `config/cookies.txt` (used if the file exists) |
| yt-dlp cookies       | `--ytdlp-cookies`                                  | `YTDLP_COOKIE_FILE`     | `ytdlp_cookie_file`     | never                                                                      | falls back to Cookies                          |
| spotdl cookies       | `--spotdl-cookies`                                 | `SPOTDL_COOKIE_FILE`    | `spotdl_cookie_file`    | never                                                                      | falls back to Cookies                          |
| Extractor retries    | `--extractor-retries`                              | `EXTRACTOR_RETRIES`     | `extractor_retries`     | never                                                                      | `10`                                           |
| Retry sleep          | `--retry-sleep`                                    | `RETRY_SLEEP`           | `retry_sleep`           | never                                                                      | `exp=2:10:120`                                 |
| Sleep requests       | `--sleep-requests`                                 | `SLEEP_REQUESTS`        | `sleep_requests`        | never                                                                      | `2`                                            |
| Rate limit           | `--limit-rate`                                     | `LIMIT_RATE`            | `limit_rate`            | never                                                                      | off                                            |
| Pause between        | `--pause-between`                                  | `PAUSE_BETWEEN`         | `pause_between`         | never                                                                      | `2`                                            |

One cookie file (Netscape format holds cookies for multiple domains — e.g. SoundCloud for yt-dlp plus YouTube Music
for spotdl) serves both tools by default; the per-tool parameters override it individually. A cookie file is only
passed on when it actually exists. **Breaking**: `config/spotdl-cookies.txt` is no longer a default — point
`--spotdl-cookies` / `SPOTDL_COOKIE_FILE` / `spotdl_cookie_file` at it or merge its cookies into
`config/cookies.txt`.

`-n`/`--no-interaction` skips all prompts (input/output are then required via CLI, env, or config);
`-h`/`--help` shows the full option reference.

## .env reference

Every env var also has a `config/playlists-sync.json` equivalent (snake_case key, see the table above); env wins
over the config file. `config/playlists-sync.json.example` is the checked-in starting point.

```dotenv
INPUT_FILE=config/playlists.txt
OUTPUT_DIR=./downloads

# Optional binary paths (fallback to PATH)
YTDLP_BIN=/usr/local/bin/yt-dlp
FFMPEG_BIN=ffmpeg
FFPROBE_BIN=ffprobe             # detects source sample rate for resampling

# Formats: any of original, mp3, wav, flac (comma-separated)
FORMATS=original,mp3,wav,flac

# MP3 encoding: cbr (default) or vbr. CBR is used by default because some DJ software (e.g.
# Rekordbox) misreports the bitrate of VBR MP3s — it reads the first frame instead of the true
# average, which can show a near-silent track intro's low bitrate as "the" bitrate.
MP3_MODE=cbr
MP3_BITRATE=320                 # CBR bitrate in kbps (MP3_MODE=cbr only)
MP3_QUALITY=0                   # LAME VBR quality — 0 = highest (~245 kbps), 9 = lowest (MP3_MODE=vbr only)

# Minimum estimated ODG, PEAQ scale -4..0 (unset = feature off)
# MIN_ODG=-2
# warn (default) = list low-quality tracks in the summary; filter = skip them at download
# MIN_ODG_MODE=warn

# Base filename template for library files — yt-dlp sources only;
# Spotify tracks always use "{track-id} - {title}" so the conversion loop finds them
LIB_FILENAME_TEMPLATE=%(id)s - %(title)s

# Cookies (Netscape format) — one file for both yt-dlp and spotdl, only used when it exists
COOKIES_FILE=config/cookies.txt
# Per-tool overrides (each falls back to COOKIES_FILE)
# YTDLP_COOKIE_FILE=config/cookies.txt
# With YT Music Premium cookies spotdl downloads 256 kbps m4a originals instead of 128 kbps
# SPOTDL_COOKIE_FILE=config/cookies.txt

# Spotify via spotdl
SPOTDL_BIN=spotdl

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

## Spotify support

Input-file lines matching `open.spotify.com/playlist|album|track` (also `intl-xx/` locale URLs and
`spotify:playlist:...` URIs) are routed to [spotdl](https://github.com/spotDL/spotify-downloader); everything else
goes to yt-dlp. spotdl matches each Spotify track on YouTube Music and downloads it as a best-quality m4a original
(`--bitrate disable`); the regular ffmpeg conversion and M3U8 pipeline then applies exactly as for yt-dlp sources.

- Deduplication uses a separate archive (`.archive/spotify.txt` — spotdl stores song URLs, yt-dlp stores
  `extractor id` pairs, so the files are never mixed).
- Optional YouTube Music cookies (Netscape format): with YT Music Premium spotdl downloads 256 kbps m4a instead of
  128 kbps. By default spotdl shares `config/cookies.txt` with yt-dlp (one Netscape file can hold cookies for both
  sites); use `--spotdl-cookies` / `SPOTDL_COOKIE_FILE` / `spotdl_cookie_file` for a separate file.
- spotdl embeds tags and cover art into the m4a originals itself. Converted mp3/wav/flac keep the tags (ffmpeg
  copies container metadata), but cover art is only present in the originals — Spotify sources have no `.jpg`
  sidecar to re-embed from.
- Spotify's API may throttle large batches; `PAUSE_BETWEEN` already sleeps between playlists.

## Output layout

```
downloads/
  .archive/
    original.txt          ← single dedup archive across all playlists (yt-dlp sources)
    spotify.txt           ← spotdl dedup archive (song URLs)
    spotify-save-<md5>.spotdl  ← per-URL playlist metadata from `spotdl save` (debug artifact)
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
    <Uploader> - <Playlist Title> - original.m3u8
    <Uploader> - <Playlist Title> - mp3.m3u8
    <Uploader> - <Playlist Title> - wav.m3u8
    <Uploader> - <Playlist Title> - flac.m3u8
```

`original` is a real output format like the others: including it in `FORMATS` writes a playlist referencing the
untouched downloaded files (whatever container yt-dlp/spotdl produced — `.m4a`, `.webm`, `.opus`, ...) alongside
whichever converted formats are also requested.

## Download summary

After downloading each playlist's originals, the command prints a one-line summary:

```
Download: 3 new, 30 already in archive, 2 failed
```

- **new** — tracks freshly downloaded this run.
- **already in archive** — tracks skipped because they are already recorded in `.archive/original.txt` (the shared
  dedup archive). yt-dlp drops these during playlist enumeration and prints nothing for them, so the count is derived
  by diffing the playlist against a snapshot of the archive taken *before* the run.
- **failed** — tracks that were neither downloaded nor already archived (e.g. DRM-protected or geo-restricted); the
  suffix is omitted when zero. yt-dlp prints the underlying `ERROR:` lines for these.

## Quality threshold

`--min-odg` / `MIN_ODG` / `min_odg` sets a minimum source audio quality as an **estimated ODG** (Objective
Difference Grade, the PEAQ scale: 0 = transparent … -4 = very annoying). The same perceptual quality needs
different bitrates per codec (≈192 kbps MP3 ≈ 128 kbps AAC ≈ 96 kbps Opus), so the raw bitrate alone is a poor
metric — a 128 kbps Opus source is *not* worse than a 160 kbps MP3. True PEAQ needs the lossless original as a
reference (unavailable for downloads), so the ODG is **estimated**: the source's codec + bitrate are looked up in a
per-codec calibration table (`mp3`, `aac`, `opus`, `vorbis` anchor points) with linear interpolation. Lossless
sources (FLAC/ALAC/PCM/…) are always ODG 0; unknown codecs use the conservative MP3 curve. Unset = feature off.

The bitrate is yt-dlp's `abr` (average audio bitrate) from the `.info.json`, falling back to `tbr` (total
bitrate); the codec comes from `acodec`. When the sidecar has no bitrate (always the case for spotdl tracks), a
single `ffprobe` call probes both codec and bitrate from the downloaded file.

When the minimum is configured nowhere (no CLI option, no env var, no config key), an interactive run asks for it
**once** and persists the answer to `config/playlists-sync.json` — an empty answer persists `"min_odg": null`
("off"), so the question is not repeated. Delete the `min_odg` key from the config file to re-trigger the prompt.

`--min-odg-mode` / `MIN_ODG_MODE` / `min_odg_mode` picks the behaviour. While a minimum is active, an interactive
run asks for the mode **every time** (saved/env value pre-selected as the default; `--min-odg-mode` suppresses the
prompt; `-n` skips it):

- `warn` (default): everything is downloaded; after conversion, tracks whose estimated ODG is
  below the threshold are listed in an end-of-run warning (raw kbps, codec, and estimated ODG shown).
- `filter`: yt-dlp cannot compute ODG, so the threshold is inverted per codec into a minimum
  bitrate and expressed as one format-selector branch per codec prefix, e.g. for ODG ≥ -1:
  `bestaudio[acodec^=opus][abr>=96]/bestaudio[acodec^=mp4a][abr>=128]/…/bestaudio[acodec^=mp3][abr>=192]/…`
  (lossless codecs always pass; codecs that cannot reach the threshold at any bitrate get no
  branch). The filter is fail-closed: formats with *unknown* bitrate or codec do not match, so
  nothing silently falls through. Skipped tracks are reported separately from failed tracks and
  do not count as playlist errors.

Notes:

- Spotify downloads cannot be skipped — spotdl has no per-track quality filter, so the file
  is always downloaded first. Its codec + bitrate are then probed with `ffprobe`, and in filter mode
  tracks below the threshold are excluded from conversions and playlist files afterwards.
- Filter-skipped tracks are **not** added to the download archive, so they are re-checked on
  every run (a later re-upload or higher-quality format gets picked up automatically, at the
  cost of one extraction attempt per run).
- In filter mode, already-downloaded originals below the threshold (e.g. from runs before the
  threshold existed) are also excluded from conversions and playlist files. The original files
  stay on disk and are listed in the end-of-run warning.

## MP3 encoding

`--mp3-mode` / `MP3_MODE` / `mp3_mode` picks between `cbr` (constant bitrate, **default**) and `vbr` (variable
bitrate). CBR is the default because some DJ software (Rekordbox, at least) misreports the bitrate of VBR MP3s: it
displays the bitrate of the file's *first* frame rather than the true average, so a track with a quiet/near-silent
intro — which LAME's VBR encoder allocates very few bits to — can show a wildly low bitrate (e.g. "32 kbit/s") in
the DJ software even though the actual average is much higher. CBR sidesteps this entirely since every frame has
the same bitrate.

- `cbr`: `-b:a <bitrate>k`, bitrate from `--mp3-bitrate` / `MP3_BITRATE` / `mp3_bitrate` (default `320`).
- `vbr`: `-q:a <quality>`, quality from `--mp3-quality` / `MP3_QUALITY` / `mp3_quality` (default `0` = highest,
  ~245 kbps average; `9` = lowest). Smaller files than CBR at the same audible quality, at the cost of the
  bitrate-misreport risk above.

Normally an already-converted target is left untouched (see below). As an exception, when `--mp3-mode=cbr`
(the default), `--reencode-stale-mp3` (on by default; disable with `--no-reencode-stale-mp3`) probes each
existing mp3 target's actual average bitrate and, if it's off from `--mp3-bitrate` by more than 10% (or 8 kbps,
whichever is larger), deletes and reconverts it — no redownload, since the source stays archived. This fixes
mp3s converted before switching to CBR (or before this flag existed) without hunting down files by hand. Trade-off:
if you lower `--mp3-bitrate` later (e.g. to save space), the next sync will reencode the whole library against the
new target, not just genuinely stale files — a one-time cost of that setting change; pass `--no-reencode-stale-mp3`
to skip it.

## Sample-rate normalization

Some export targets (and DJ software) reject audio whose sample rate is outside 44.1/48/96 kHz, which previously broke
exports for odd-rate FLAC/ALAC/WAV/AIFF sources and some streaming/video-container tracks. Each conversion now detects
the source sample rate with `ffprobe` and, when it isn't already 44.1/48/96 kHz, resamples to the **nearest supported
rate ≥ the source** (capped at 96 kHz):

| Source rate     | Converted to |
|-----------------|--------------|
| 44.1/48/96 kHz  | unchanged    |
| 22.05 / 32 kHz  | 44.1 kHz     |
| 88.2 kHz        | 96 kHz       |
| 176.4 / 192 kHz | 96 kHz       |

If `ffprobe` can't read the source, the conversion falls back to 44.1 kHz so the track still exports. Existing
converted files are left untouched — delete a bad target and re-run to force reconversion (mp3 has an automatic
exception for stale bitrates, see [MP3 encoding](#mp3-encoding) above).

## Rekordbox import

In Rekordbox: **File → Import Playlist** → select the `.m3u8` for the format you want. Each playlist can be imported
independently; all audio is stored once in the shared library.

## Troubleshooting

| Symptom                                                             | Fix                                                                                                                           |
|---------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| 403/429 errors                                                      | Add `config/cookies.txt` + set `LIMIT_RATE`, `SLEEP_REQUESTS`, `EXTRACTOR_RETRIES` in `.env`                                  |
| Missing thumbnails / metadata                                       | Update yt-dlp: `ddev exec pip install -U yt-dlp`                                                                              |
| Want to add a format later                                          | Re-run with updated `FORMATS` — originals are cached, only new conversions run                                                |
| yt-dlp not found                                                    | `ddev exec pip install yt-dlp` or add it to `.ddev/web-build/Dockerfile`                                                      |
| Export rejects an odd-rate track                                    | Fixed automatically — see [Sample-rate normalization](#sample-rate-normalization); delete the stale converted file and re-run |
| Summary always says `0 already in archive`                          | Fixed — the count is now derived from the download archive, see [Download summary](#download-summary)                         |
| A WAV track keeps failing with `Postprocessing: Conversion failed!` | Fixed — `--add-metadata` was dropped; it choked on WAV/AIFF sources with an embedded cover. Re-run to pull the track in       |
