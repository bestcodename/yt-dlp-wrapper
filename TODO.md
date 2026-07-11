1. Split oversized Command classes (Usb ~2080 LOC, Playlists ~1810 LOC) by responsibility into
   invokable service classes the Command delegates to, keeping the Command thin (option parsing,
   prompts, orchestration).
   e.g. Usb -> VentoyInstaller, PartitionFormatter, IsoDownloader
   Playlists -> SpotdlDownloader, YtDlpDownloader, AudioConverter (ODG-quality logic)
   Needs new constructor wiring/DI and its own tests -- bigger, more invasive than simple dedup.

2. Testing
    1. Analyze test code coverage
    2. Add missing tests — remaining gaps after the `ProcessRunner` seam landed:
        - TODO: `getMountedPartitions` reads `/proc/1/mounts` directly — needs its own seam before the
          mounted-source/mounted-target warning paths can be unit-tested.
        - TODO: `hasVentoyPartition` fallback-true branch (lsblk empty + `/dev/…2` node exists) is untestable
          without creating device nodes; would need a `file_exists` seam.

3. usb:setup add multi usb drive (destination only) support

4. add support for user provided playlist aliases (e.g. defined by a specific comment before the playlists url in the
   url file)

5. playlists:sync add option to group playlist files inside downloads/playlists in directories in different ways.
    - one directory per playlist containing all file types
    - one directory per file type containing all playlists of that file type

6. usb:setup look into VentoyPlugson
    1. add ventoy theme install (e.g. dark theme, light theme) native theme is really bad
    2. investigate other ventoy plugins that could be interesting
    3. maybe make the usb:setup install and open ventoy plugson UI

7. update project name description and url to something more fitting (e.g. DJ USB Tools)

8. use symfony console sections to improve cli output

9. on cli command execution occasionally (e.g. daily or weekly) check and inform about available updates of this project

10. usb:setup add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt

11. Add torrent support to the download functionality of playlists:sync and usb:setup ("magnet:" and "urn:btmh:" links).
    Line syntax is already reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips
    these lines.

12. usb:setup
    1. make partition formatting (and optionally also partition table gpt or mbr) configurable
       FAT32 should be default
    2. add table with supported partition formats for each (pioneer) dj controller
       e.g. xdj-rr -> fat32

13. playlists:sync
    1. add more track conversion file formats
    2. add table with supported formats for each (pioneer) dj controller
       e.g. xdj-rr -> mp3, wav

14. usb:setup add option to update mode which keeps the data on the existing partition
    (both with and without ventoy install)

15. usb:setup Maybe add persistent windows live install option next to the debian one?
