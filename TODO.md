1. Add [spotdl](https://github.com/spotdl/spotify-downloader) support to
   `/home/stefanr/PhpstormProjects/yt-dlp/src/Command/SoundCloudDownloadCommand.php`.

2. Fix potential export issue: "Exporting fails for certain track formats (FLAC/ALAC/WAV/AIFF outside 44.1/48/96 kHz,
   streaming tracks, AVI/MPG/M4V/MOV, MP4 with video, DRUM CAPTURE files)."
   → Check affected tracks convert/replace as needed before exporting.

3. Check if still occurs (maybe was fixed already): fix functionality for soundcloud likes to work like "normal"
   soundcloud playlists.
   Currently has side effect to download every liked playlist completely instead of only liked titles.
