1. usb:setup fix bug: sticks set up via the non-ventoy path can come out unrecognized on some hosts — a MacBook did not
   recognize the last such stick. This is a correctness bug in usb:setup's core job (producing usable DJ USB drives):
   the tool can silently output a drive that some hardware won't read/boot, so its results can't be trusted until it's
   fixed. Latent and systemic, not a one-off — any stick prepared the same (non-ventoy) way is affected and it will
   recur. The original stick is gone, but it is reproducible: set one up the same way and re-test on the failing host.
   Investigate root cause (partition table gpt/mbr, format, boot flags, filesystem compatibility).

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
    stefanr@stefan-desktop:~/PhpstormProjects/yt-dlp$ ddev exec bin/console playlists:sync
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
