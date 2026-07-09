1. update project name description and url to something more fitting (e.g. DJ USB Tools)

2. fix: currently playlists inside other playlists (e.g. soundcloud likes containing a liked playlist) are correctly not
   downloaded recursively but they are handled as failed tracks e.g.:
   ```
   [1/1] https://soundcloud.com/sara-zironi/likes
    ----------------------------------------------

   Playlist: SoundCloud - Ziri _ _Likes_
   Downloading originals...
   WARNING: [soundcloud] 809649217: hls_aac format not found
   WARNING: [soundcloud] 809649217: http_aac format not found
   WARNING: [soundcloud] 809649217: hls_mp3 format not found
   WARNING: [soundcloud] 809649217: http_mp3 format not found
   ERROR: [soundcloud] 809649217: This video is DRM protected
   WARNING: [soundcloud] 1441786615: hls_aac format not found
   WARNING: [soundcloud] 1441786615: http_aac format not found
   WARNING: [soundcloud] 1441786615: hls_mp3 format not found
   WARNING: [soundcloud] 1441786615: http_mp3 format not found
   ERROR: [soundcloud] 1441786615: This video is DRM protected
   ERROR: [soundcloud] This video is not available from your location due to geo restriction
   You might want to use a VPN or a proxy server (with --proxy) to workaround.
   ERROR: [soundcloud] This video is not available from your location due to geo restriction
   You might want to use a VPN or a proxy server (with --proxy) to workaround.
   Download: 0 new, 323 already in archive, 6 failed
   Failed tracks:
    - Guitar Heroes (809649217)
    - Adulto funcional (1441786615)
    - Hardtek / Tribecore / Hardfloor / Pumpcore (95153249)
    - Tekno Collection (949270006)
    - RaggaTekk (631025820)
    - psy (661517838)
   ```

3. usb:setup
    1. add multi usb drive support
    2. look into VentoyPlugson
        1. add ventoy theme install (e.g. dark theme, light theme) native theme is really bad
        2. investigate other ventoy plugins that could be interesting
        3. maybe make the usb:setup install and open ventoy plugson UI
    3. add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt
    4. Maybe add persistent windows live install option next to the debian one?

4. maybe move .cache dir into downloads dir

5. playlists:sync
   configurable warning / filter for minimum quality threshold

6. on cli command execution occasionally (e.g. daily or weekly) check and inform about available updates of this project

7. Testing
    1. Analyze test code coverage
    2. Add missing tests — remaining gaps after the `ProcessRunner` seam landed:
        - TODO: `getMountedPartitions` reads `/proc/1/mounts` directly — needs its own seam before the
          mounted-source/mounted-target warning paths can be unit-tested.
        - TODO: `hasVentoyPartition` fallback-true branch (lsblk empty + `/dev/…2` node exists) is untestable
          without creating device nodes; would need a `file_exists` seam.

8. Add torrent support to the download functionality of playlists:sync and usb:setup ("magnet:" and "urn:btmh:" links).
   Line syntax is already reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips
   these lines.
