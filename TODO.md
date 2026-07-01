1. Testing
    1. Add missing tests — broaden coverage beyond the current pure-helper unit tests.
        - DONE: ffmpeg arg building (`buildFfmpegArgs`), `mapInfoJsonToTags`, `relativeFromParts`, and `UsbSetupCommand`
          pure logic (`parseChecksum`, `classifyDownloadLine`, `shouldRejectAsHtml`, `parseLastContentType`).
        - TODO: process-dependent paths (`runCmd`, `execute`, `probeSampleRate`, download/convert/mount flow) still need
          an injectable `ProcessRunner` seam before they can be unit-tested; consider extracting one.
    2. add github action to automatically run tests on every push

2. usb:setup
    1. add total available free space check before uploading software to usb (shows warning)
    2. only ask for Software downloads file (download_sources) on the first run / when its not configured
    3. add ventoy theme install (dark theme)
    4. add support for automatic archive extraction of downloads e.g. by marking urls in config/usb-downloads.txt
    5. Maybe add persistent windows live install option additionally too?
    6. Add torrent support to the download functionality ("magnet:" and "urn:btmh:" links). Line syntax is already
       reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips these lines.

3. soundcloud:download
    1. Add [spotdl](https://github.com/spotdl/spotify-downloader) support to
       `/home/stefanr/PhpstormProjects/yt-dlp/src/Command/SoundCloudDownloadCommand.php`.

4. on every cli command execution first check and inform about available updates of this project
