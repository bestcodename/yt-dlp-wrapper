1. (maybe already fixed) some tracks have mp3 tag titles with number prefixes like their filename e.g "29397716 - Mala -
   La Mescalina" "downloads/library/mp3/29397716 - Mala - La Mescalina..mp3"

2. playlists:sync add option to warn or filter tracks above a certain length

3. usb:setup fix bug: last stick that i set up (without ventoy) wasn't being recognized by my friends macbook
   investigate what the possible cause could have been (i don't have the usb stick anymore though, but theoretically we
   could set one of mine up the same way if necessary)

4. when asking interactive prompts also display the respective CLI option (or Env var or Config key)

5. update project name description and url to something more fitting (e.g. DJ USB Tools)

6. usb:setup look into VentoyPlugson
    1. add ventoy theme install (e.g. dark theme, light theme) native theme is really bad
    2. investigate other ventoy plugins that could be interesting
    3. maybe make the usb:setup install and open VentoyPlugson UI

7. use symfony console sections to improve cli output (seems like output still garbled if lines too long or is it
   supposed to look like this?)
   in the following example the window width was 75 chars (i also noticed it with the progress bars during tracks
   download though):
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
   also the "tracks" and the "overall" progress bars seem to get drawn over each other (one is seemingly getting drawn
   over the other when the line is too long) but should always be displayed simultaneously if possible

8. on cli command execution occasionally (e.g. daily or weekly) check and inform about available updates of this project
    + bonus: update automatically too

9. usb:setup add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt

10. Add torrent support to the download functionality of playlists:sync and usb:setup ("magnet:" and "urn:btmh:" links).
    Line syntax is already reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips
    these lines.

11. usb:setup
    1. make partition formatting (and optionally also partition table gpt or mbr) configurable FAT32 should be default
    2. add table with supported partition formats for each (pioneer) dj controller e.g. xdj-rr -> fat32

12. playlists:sync
    1. add more track conversion file formats
    2. add table with supported formats for each (pioneer) dj controller e.g. xdj-rr -> mp3, wav

13. usb:setup add option to update mode which keeps the data on the existing partition
    (both with and without ventoy install)

14. usb:setup Maybe add persistent windows live install option next to the debian one?

15. playlists:sync maybe add option to force redownloading and or reconversion of tracks maybe with additional option
    for oldest allowed track download / conversion time / age
