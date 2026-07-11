1. generalize more code from both commands if there still is potential (the classes are slowly growing more and more
   LOCs)

2. Testing
    1. Analyze test code coverage
    2. Add missing tests — remaining gaps after the `ProcessRunner` seam landed:
        - TODO: `getMountedPartitions` reads `/proc/1/mounts` directly — needs its own seam before the
          mounted-source/mounted-target warning paths can be unit-tested.
        - TODO: `hasVentoyPartition` fallback-true branch (lsblk empty + `/dev/…2` node exists) is untestable
          without creating device nodes; would need a `file_exists` seam.

3. usb:setup add multi usb drive (destination) support

4. usb:setup look into VentoyPlugson
    1. add ventoy theme install (e.g. dark theme, light theme) native theme is really bad
    2. investigate other ventoy plugins that could be interesting
    3. maybe make the usb:setup install and open ventoy plugson UI

5. update project name description and url to something more fitting (e.g. DJ USB Tools)

6. on cli command execution occasionally (e.g. daily or weekly) check and inform about available updates of this project

7. usb:setup add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt

8. Add torrent support to the download functionality of playlists:sync and usb:setup ("magnet:" and "urn:btmh:" links).
   Line syntax is already reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips
   these lines.

9. usb:setup
    1. make partition formatting (and optionally also partition table gpt or mbr) configurable
       FAT32 should be default
    2. add table with supported partition formats for each (pioneer) dj controller
       e.g. xdj-rr -> fat32

10. playlists:sync
    1. add more track conversion file formats
    2. add table with supported formats for each (pioneer) dj controller
       e.g. xdj-rr -> mp3, wav

11. usb:setup add option to update mode which keeps the data on the existing partition
    (both with and without ventoy install)

12. usb:setup Maybe add persistent windows live install option next to the debian one?
