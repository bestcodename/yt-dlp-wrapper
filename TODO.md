1. usb:setup Mac counter-test for the non-Ventoy fresh MBR (ca. 15 Minuten). Since 0.10.2, scratch mode with Ventoy
   skipped writes a fresh MBR with one FAT32 LBA partition (type `0x0c`). Before that, a stick kept its old layout, and
   a MacBook did not recognize one such stick. The suspected cause, FAT32 behind partition type `0x07`, is unconfirmed.
   Set up one stick with `--install-ventoy no` and plug it into a Mac. If it mounts, the cause is confirmed. If it does
   not, look at the boot sector next (`mkfs.fat` options, sector size). Also open is whether Ventoy's own data
   partition, which is FAT32 behind type `0x07` as well, has the same problem on macOS.

2. update project name description and url to something more fitting (e.g. DJ USB Tools)

3. usb:setup look into VentoyPlugson
    1. add ventoy theme install (e.g. dark theme, light theme) native theme is really bad
    2. investigate other ventoy plugins that could be interesting
    3. maybe make the usb:setup install and open VentoyPlugson UI (possibly wont work in ddev though)

4. use symfony console sections to improve cli output (seems like output still garbled e.g. if lines are too long and
   wrap)
   in the following example copied from terminal output the window width was 75 chars (seemingly also noticed with the
   progress bars during track downloading):
   ```
    $ ddev exec bin/console playlists:sync
    Xdebug: [Step Debug] Could not connect to debugging client. Tried: host.docker.internal:9003 (fallback through xdebug.client_host/xdebug.client_port).
    Low-quality handling (warn = list in summary, filter = exclude): [warn]
    [0] warn
    [1] filter
    >
    Fetching playlist metadata...
    1/27 [=>--------------------------]   3% /stefan-ripper/sets/dj-set-hardt  2/27 [==>-------------------------]   7% /stefan-ripper/sets/set-happy-te  3/27 [===>------------------------]  11% /stefan-ripper/sets/set-frenchco  4/27 [====>-----------------------]  14% /stefan-ripper/sets/set-dark-tek  5/27 [=====>----------------------]  18% /stefan-ripper/sets/set-chill-af 11/27 [===========>----------------]  40% /stefan-ripper/sets/scheibensamm 16/27 [================>-----------]  59% /stefan-ripper/sets/forest-darkp 23/27 [=======================>----]  85% /sara-zironi/sets/nagtek
   ```
   also the "tracks" and the "overall" progress bars seem to get drawn over each other (seemingly one is getting drawn
   over the other when the line is too long) but they should always be displayed simultaneously if possible and it makes
   sense (maybe this is a usage problem and it works correctly with ddev ssh, which wasnt tested yet, but not with ddev
   exec, optimally everything should work in both cases)

   to fix the output the stderr was rerouted to stdout before which should be reversed and only if really needed for
   ddev exec or ddev ssh, and cant be activated automatically — only when needed, then it should become a parameter
   defaulting to off

   the tracks below quality threshold report at the end uses the symfony console warning() function, but the list can
   get long and the function adds line spacing, which makes it really unübersichtlich — especially since the group
   headings and the tracks have the same one-line spacing before and after, so there's no visual difference between
   them; additionally the "self made" indentation doesn't work when a line is too long and wraps around, which happens
   on many lines, making the group headings even harder to spot

   fyi table:

   | Function      | Stream Routing | Line Spacing                |
            |---------------|----------------|-----------------------------|
   | **success()** | STDOUT         | Blank line before and after |
   | **info()**    | STDOUT         | Blank line before and after |
   | **note()**    | STDOUT         | Blank line before and after |
   | **warning()** | STDERR         | Blank line before and after |
   | **error()**   | STDERR         | Blank line before and after |
   | **caution()** | STDERR         | Blank line before and after |
   | **comment()** | STDOUT         | No extra blank lines        |
   | **text()**    | STDOUT         | No extra blank lines        |

5. on cli command execution occasionally (e.g. daily or weekly) check and inform about available updates of this project
    + bonus: update automatically too

6. playlists:sync add option to warn or filter tracks above a certain length

7. playlists:sync
    - add (configurable) option to force redownloading and or reconversion of tracks (only "overwriting" existing
      tracks)
      with additional configurability filtering by oldest allowed track download / conversion time / age
    - add (configurable) option to delete stale playlists and or tracks (over all playlists and for the downloaded ones
      only) after the sync process (dont redownload already downloaded tracks though) with additional configurability
      filtering by oldest allowed track download / conversion time / age

8. usb:setup add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt

9. Add torrent support to the download functionality of playlists:sync and usb:setup ("magnet:" and "urn:btmh:" links).
   Line syntax is already reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips
   these lines.

10. usb:setup
    1. make partition formatting (and optionally also partition table gpt or mbr) configurable FAT32 should be default
    2. add table with supported partition formats for each (pioneer) dj controller e.g. xdj-rr -> fat32

11. playlists:sync
    1. add more track conversion file formats
    2. add table with supported formats for each (pioneer) dj controller e.g. xdj-rr -> mp3, wav

12. usb:setup add option to update mode which keeps the data on the existing partition
    (both with and without ventoy install)

13. usb:setup add persistent windows live install option next to the debian one?

14. usb:setup `udevadm` is not installed in the ddev container, so every `udevadm settle` call is a no-op, and its
    `sh: 1: udevadm: not found` line reaches the output despite `2>/dev/null`. Either install `udev` in
    `.ddev/web-build/Dockerfile` or drop the calls.

15. usb:setup `--downloads-file -` is rejected with "Downloads file not found: -", while the free-text prompt accepts
    `-` as skip. The CLI option and `USB_DOWNLOADS_FILE` should accept `-` too.

16. usb:export write the Rekordbox USB export ourselves, without Rekordbox (`PIONEER/rekordbox/export.pdb`, the `ANLZ`
    analysis files and `Contents/`). Rekordbox in the win11 VM needed ca. 8 hours to analyse 1100 WAV tracks on
    2026-11-04 before it could export. Start from the existing reverse-engineering work:
    1. [Deep Symmetry crate-digger](https://github.com/Deep-Symmetry/crate-digger) and its `rekordbox_pdb.ksy`, the
       reference description of the PDB and ANLZ formats
    2. [kimtore/rex](https://github.com/kimtore/rex) (Go), which writes `export.pdb` from a Mixxx library but has no
       waveforms, beat grids or hot cues; last pushed in October 2023 and marked unfit for live use by its author
    3. [pyrekordbox](https://pypi.org/project/pyrekordbox/), check whether its PDB support writes or only reads
    4. the export must carry the analysis (BPM, beat grid, key, waveforms, cue points in the ANLZ files), by whichever
       route works: own analysis with the analysers Mixxx uses (qm-dsp for beats, libKeyFinder for key), essentia or
       aubio, compared against Rekordbox's results on the same tracks; or reuse of the ANLZ files Rekordbox already
       keeps on the PC (`%APPDATA%\Pioneer\rekordbox\share\PIONEER\USBANLZ`), which would keep Rekordbox only as
       the analyser
    5. own analysis runs one track per core in parallel. Rekordbox analysed 2-3 tracks per minute on 2026-11-04 while
       the VM used about 2 of its 7 vCPUs (qemu at 190% on the 28-thread desktop), so the speedup is the main gain
       of this route; it could also run during `playlists:sync` right after each download
    6. approach: write PDB and ANLZ from the Deep Symmetry docs and check them with pyrekordbox; analyse with open-source
       tools (Beat This!, madmom or essentia for beats, libKeyFinder or essentia for key, own DSP for waveforms fitted
       against the stored ones); measure against Rekordbox's results on the same tracks (beats within 70 ms, BPM within
       0.1, key exact or related)
    7. open question: which ANLZ tags the XDJ-RR reads, so the analysis covers those first
    8. keep the XDJ-RR at hand while working on this item, for a short real test cycle after each change: export a
       small test playlist, plug the stick into the XDJ-RR, check loading, waveform, beat grid, key and sync

17. playlists:sync (proposal) stop with an error when two URLs map to the same playlist name. On 2026-11-04 the
    SoundCloud set and the YouTube playlist "Samples" both became `Stefan Ripper - Samples.m3u8`, and the second write
    silently overwrote the first. On 2026-11-13 the same happened to "Scheibensammlung" (SoundCloud 56 entries,
    then YouTube 55), so the Rekordbox export carried only the YouTube list until an alias was added. The check runs
    after the fetch phase, where `folder` is known for every playlist, and names both URLs and the `# alias:` fix
    (ca. 45 Minuten)

18. playlists:sync (proposal) resample WAV files outside 44.1, 48 and 96 kHz, which the XDJ-RR cannot play. On
    2026-11-04 one track at 44000 Hz (Tim Oskar, Serotonin) was skipped by the Rekordbox export. Delete its WAV and
    re-sync, so `AudioConverter` resamples it; Rekordbox may keep the old sample rate cached, so it needs a re-import
    test (ca. 15 Minuten)

19. playlists:sync (proposal) write a `rekordbox.xml` next to the M3U8 files. Rekordbox imports it as one library with
    all playlists, which sidesteps M3U parsing (the dropped `#` entries) and the per-playlist import dialogs
    (ca. 4 Stunden)

20. playlists:sync (proposal) add an XDJ passthrough format that keeps MP3/AAC/WAV/AIFF sources as they are and
    converts only what the XDJ-RR cannot play, such as FLAC or Opus, instead of converting every track to WAV
    (ca. 3 Stunden)

21. playlists:sync (proposal) fall back to YouTube for SoundCloud tracks that fail with "This video is DRM protected".
    On 2026-11-13, 16 SoundCloud IDs (15 distinct tracks) failed this way, 8 of them by Androgyn Network. 6 were
    already in the library from YouTube or spotdl with the exact same duration, and the other 9 had a match on YouTube,
    mostly on the artist's "- Topic" channel within 1 second of the SoundCloud duration. The fallback searches
    `ytsearch5:<artist> <title>`, prefers a "- Topic" channel, accepts only a duration within ±3 seconds, records the
    mapping so the search runs once per track, and writes the YouTube file into the SoundCloud playlist's M3U8 at the
    original position. The 10 replacements found on 2026-11-13 were added by hand to the YouTube counterparts listed
    in `config/playlist-map.json` (see item 23), which the fallback can use as its first source (ca. 3 Stunden)

22. usb:setup (proposal) replace an older Debian live ISO in `--update` mode instead of adding the new one next to it.
    On 2026-11-13 an update from 13.5.0 to 13.7.0 left the 13.5.0 ISO (3.9 GiB) on the stick, and `writeVentoyJson()`
    in `src/UsbSetup/IsoPayloadManager.php` appended a second persistence entry for the same `persistence.dat`, so
    both ISOs booted into one persistence image. Remove other `debian-live-*.iso` files of the same variant after a
    successful copy and replace their `ventoy.json` entries (ca. 45 Minuten)

23. playlists:sync (proposal) sync SoundCloud and YouTube playlist pairs both ways. `config/playlist-map.json` on the
    desktop holds the pairs (`pairs`, `youtube_only`, `youtube_excluded_non_music`), created on 2026-11-13 with 9
    pairs and 4 YouTube-only playlists; 18 own SoundCloud sets still have no YouTube counterpart. Apart from the
    shared names the two sides held different music on 2026-11-13, so the sync matches every track on the other
    platform (yt-dlp search, "- Topic" first, duration within ±3 seconds, chromaprint check where durations differ)
    and records matches so each track is searched once. Open points:
    1. YouTube quota: `playlists.insert` and `playlistItems.insert` cost 50 units each against 10,000 per day by
       Google's documentation, so about 200 writes a day; the ca. 1000 SoundCloud tracks need several days or a quota
       increase. Searching goes through yt-dlp, not `search.list` (100 units)
    2. SoundCloud writes: check whether SoundCloud accepts new API app registrations; the session token in
       `config/cookies.txt` works only against the internal `api-v2`, which is a workaround, not an API
    3. YouTube access: OAuth scope `youtube` through `bestcodename/ai/scripts/google-oauth/google_oauth.py`, client
       `~/.config/todo-sync/google-client.json` (project `private-automation-511903`, YouTube Data API v3 enabled on
       2026-11-13), token `~/.config/youtube-playlists/google-token.json`; a playlist created seconds earlier answers
       `playlistItems.list` with 404 for a while
    4. names of mapped pairs stay equal on both sides (ca. 8 Stunden)

24. usb:export (proposal) fix the two tracks still off in the Rekordbox export of 2026-11-13. The half-tempo BPMs
    came from Rekordbox's Analysis Mode "Automatisch", which ignores the BPM range 128-255; with mode "normal"
    (Preferences → Analyse, now set in the win11 VM) a re-analysis brought ca. 23 tekno tracks to full tempo (LENZ
    91.67 → 210.00). Still off are "XENROX- ENERGY 204", exported at 136 BPM because a BPM typed into Grid Edit changes
    the deck but not the track's BPM field, and "Toktok & Moonraker - Trampolin EP - Tokmoon (Ab)" (YouTube Tekno
    Collection), auto-analysed at 100.33 before the mode switch. Set or re-analyse both, re-export their playlists
    and mirror to the other sticks (ca. 20 Minuten)

25. usb:export (proposal) keep Rekordbox off the Ventoy EFI partition. Passed through to the win11 VM, the stick
    shows as E:VENTOY and F:VTOYEFI, and Rekordbox offers both as export targets. On 2026-11-13 one export went to F:
    and left a `PIONEER/rekordbox` library with an empty playlist on the 32 MB partition, removed by hand afterwards.
    Remove the drive letter of VTOYEFI in the VM (`mountvol F: /D`) or note it in the export steps (ca. 10 Minuten)
