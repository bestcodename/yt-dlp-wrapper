1. Testing
    1. Add missing tests — broaden coverage beyond the current pure-helper unit tests.
        - TODO: process-dependent paths (`runCmd`, `execute`, `probeSampleRate`, download/convert/mount flow,
          `lsblkInfo`/`hasVentoyPartition`, `isFat32Ventoy`, `installVentoy` outcome verification,
          `validateSourceDevice`/`mirrorDataPartition` mount+rsync flow) still need an injectable `ProcessRunner`
          seam before they can be unit-tested; consider extracting one.
        - TODO: interactive `usb:setup` flow tests via Symfony `CommandTester::setInputs()` once the `ProcessRunner`
          seam exists — regression cases: `device_name` clobber (stale-config merge), device-name mismatch warning,
          mode default follows detected stick state, update mode falls back to full Ventoy install when Ventoy
          missing (`-u` vs `-I` flag selection).
        - TODO: `checkAndRecordDeviceName` decision logic (warn on mismatch / warn on missing record / silent) —
          extract into a pure helper to make it unit-testable without the seam.
    2. add github action to automatically run tests on every push

2. usb:setup
    1. add total available free space check before uploading software to usb (shows warning) — done for the
       duplicate path (`--source-device` preflight fails before wipe); still missing for the configuration path
       (ISO + software downloads)
    2. only ask for Software downloads file (download_sources) on the first run / when its not configured
    3. add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt
    4. Maybe add persistent windows live install option additionally too?
    5. Add torrent support to the download functionality ("magnet:" and "urn:btmh:" links). Line syntax is already
       reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips these lines.
    6. add multi usb drive support
    7. look into VentoyPlugson
        1. add ventoy theme install (dark theme)
    8. NVMe support: partitions are derived as `$device.'1'`/`$device.'2'` everywhere (target and source), which is
       wrong for `/dev/nvme0n1` (`nvme0n1p1`) even though the device regex accepts nvme paths

3. soundcloud:download
    1. Add [spotdl](https://github.com/spotdl/spotify-downloader) support to
       [src/Command/SoundCloudDownloadCommand.php](src/Command/SoundCloudDownloadCommand.php).

4. on every cli command execution first check and inform about available updates of this project
