1. Extend USB setup (similar to already implemented Debian download):
    1. Fix Ventoy failing with "Some tools can not run on current system. Please check log.txt for details. ./tool/
       ventoy_lib.sh: line 63: mkexfatfs: command not found" — the Dockerfile installs `exfatprogs` (provides
       `mkfs.exfat`), but Ventoy's script looks for the older `exfat-utils` binary name `mkexfatfs` specifically.
       Either install `exfat-utils` alongside/instead of `exfatprogs`, or symlink `mkfs.exfat` to `mkexfatfs` in
       `.ddev/web-build/Dockerfile`.
    2. Add torrent support to the download functionality ("magnet:" and "urn:btmh:" links). Line syntax is already
       reserved for this in `config/usb-downloads.txt`; `usb:setup` currently recognizes but skips these lines.
    3. Maybe add persistent windows live install option additionally to debian too?

2. Add [spotdl](https://github.com/spotdl/spotify-downloader) support to
   `/home/stefanr/PhpstormProjects/yt-dlp/src/Command/SoundCloudDownloadCommand.php`.

3. Fix potential export issue: "Exporting fails for certain track formats (FLAC/ALAC/WAV/AIFF outside 44.1/48/96 kHz,
   streaming tracks, AVI/MPG/M4V/MOV, MP4 with video, DRUM CAPTURE files)."
   → Check affected tracks convert/replace as needed before exporting.

4. Check if still occurs (maybe was fixed already): fix functionality for soundcloud likes to work like "normal"
   soundcloud playlists.
   Currently has side effect to download every liked playlist completely instead of only liked titles.
