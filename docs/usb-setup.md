# USB Setup — Ventoy + Persistent Debian

Installs Ventoy (MBR partition table, FAT32 data partition), optionally copies a Debian live ISO, and configures Ventoy
persistence for it. Can also duplicate an already-set-up stick onto a new one (any size/brand, as long as the used
payload fits) via `--source-device`.

## Requirements

All tools — `dosfstools`, `e2fsprogs`, `util-linux`, and the latest Ventoy release — are installed automatically via
`.ddev/web-build/Dockerfile` when you run `ddev start`.

| Tool                       | Package                                   | Purpose                                      |
|----------------------------|-------------------------------------------|----------------------------------------------|
| `mkfs.fat`                 | dosfstools                                | Format partition 1 as FAT32                  |
| `mkfs.ext4`                | e2fsprogs                                 | Format persistence image                     |
| `mkfs.exfat` / `mkexfatfs` | exfatprogs                                | exFAT formatting (Ventoy install / reformat) |
| `fallocate`                | util-linux                                | Allocate persistence file                    |
| `mount` / `umount`         | util-linux                                | Loop-mount persistence image                 |
| `lsblk`                    | util-linux                                | List block devices                           |
| `rsync`                    | rsync                                     | Mirror payload when duplicating a stick      |
| `Ventoy2Disk.sh`           | ventoy (auto-downloaded to `/opt/ventoy`) | Install Ventoy bootloader                    |

> Ventoy's `ventoy_lib.sh` invokes the legacy `exfat-utils` binary name `mkexfatfs`, which modern `exfatprogs`
> replaces with `mkfs.exfat`. `.ddev/web-build/Dockerfile` symlinks `mkfs.exfat` → `mkexfatfs` (and `fsck.exfat` →
> `exfatfsck`) so Ventoy install no longer fails with `mkexfatfs: command not found`.

## Usage

```
ddev exec sudo bin/console usb:setup [options]
```

### Parameters

Resolution: CLI option → env var → prompt → default. **Env vars behave like their CLI option — they suppress the
prompt.** The config keys are deliberately *not* part of value resolution: they only pre-fill the prompt defaults
(the device/ISO/persistence questions fire every run on purpose), and prompt answers are written back to
`config/usb-setup.json`. Only `cache_dir` resolves through the config file too (it is never prompted). usb:setup
reads the same `.env` file as playlists:sync (`DOTENV_PATH` override supported). See the shared
[Configuration & parameter resolution](../README.md#configuration--parameter-resolution) section in the README.

| Parameter          | CLI option           | Env var                | Config key                             | Prompted when                                                                     | Default                            |
|--------------------|----------------------|------------------------|----------------------------------------|-----------------------------------------------------------------------------------|------------------------------------|
| Target device      | `--device`           | `USB_DEVICE`           | `device` (prompt default only)         | every interactive run (device list choice)                                        | —                                  |
| Mode (update/redo) | `--update`           | `USB_UPDATE`           | —                                      | every interactive run unless CLI/env value                                        | auto (update when Ventoy detected) |
| Ventoy install     | `--install-ventoy`   | `USB_INSTALL_VENTOY`   | `install_ventoy` (prompt default only) | every interactive run unless CLI/env value                                        | install/update                     |
| Payload source     | `--source-device`    | `USB_SOURCE_DEVICE`    | `payload_source`, `source_device`      | every interactive run unless `--source-device`                                    | configuration                      |
| Debian ISO         | `--debian-iso`       | `USB_DEBIAN_ISO`       | `iso_source`, `iso_path`               | every interactive run unless duplicating or CLI/env value                         | download                           |
| ISO variant        | `--iso-variant`      | `USB_ISO_VARIANT`      | `iso_variant` (prompt default only)    | with the ISO prompt; a CLI/env value implies "download" and skips the ISO prompts | `standard`                         |
| Persistence size   | `--persistence-size` | `USB_PERSISTENCE_SIZE` | `persistence_mib`                      | every interactive run when an ISO is used and no CLI/env value                    | `2048` MiB                         |
| Ventoy binary      | `--ventoy-bin`       | `VENTOY_BIN`           | —                                      | never (auto-detected)                                                             | auto                               |
| Downloads file     | `--downloads-file`   | `USB_DOWNLOADS_FILE`   | `download_sources`                     | every interactive run unless CLI/env value or duplicate mode                      | `config/usb-downloads.txt`         |
| Cache dir          | `--cache-dir`        | `USB_CACHE_DIR`        | `cache_dir`                            | never                                                                             | `downloads/.cache`                 |
| Skip confirmations | `--yes` / `-y`       | `USB_YES`              | —                                      | —                                                                                 | off                                |
| Device name check  | —                    | —                      | `device_name`, `source_device_name`    | never (recorded automatically each interactive run)                               | —                                  |

`--install-ventoy` takes `yes`/`no`; `USB_UPDATE`, `USB_INSTALL_VENTOY` and `USB_YES` accept `1/0`, `true/false`,
`yes/no`. `--iso-variant` values: `standard`, `gnome`, `kde`, `cinnamon`, `lxde`, `lxqt`, `mate`, `xfce` — setting
it downloads that variant without prompting, also in non-interactive mode. `--yes`/`-y`/`USB_YES` skips confirmation
prompts (wipe/update/continue-anyway); `-n`/`--no-interaction` skips **all** prompts and implies `-y` — the device
is then required (via `--device` or `USB_DEVICE`). `-h`/`--help` shows the full option reference.

## Commands

### Interactive run (default)

Lists detected block devices, then prompts for any missing values. For the Debian ISO it asks whether to **download** (
fetches the latest from `cdimage.debian.org` and lets you pick a variant) or use a **local path**:

```bash
ddev exec sudo bin/console usb:setup
```

Pre-fill some or all values — skips prompts for provided args:

```bash
ddev exec sudo bin/console usb:setup --device /dev/sdX
```

### Non-interactive run

All required values must be provided via flags or env vars. Confirmation prompts are skipped automatically:

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  -n
```

Full non-interactive setup including an ISO download (the `USB_*` env vars work the same as their flags):

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --iso-variant kde \
  --persistence-size 4090 \
  -n
```

### Install Ventoy only (no ISO, no persistence)

```bash
ddev exec sudo bin/console usb:setup --device /dev/sdX
```

This gives you a Ventoy USB with MBR partition table and FAT32 on partition 1. Drop any ISO files onto the stick to boot
them.

### Full setup — Ventoy + Debian ISO + persistence

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --debian-iso /path/to/debian-live-12.9.0-amd64-standard.iso \
  --persistence-size 4000
```

### Skip confirmation prompts (scripted / automated)

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --debian-iso /path/to/debian-live.iso \
  --persistence-size 2048 \
  -y -n
```

### Duplicate an existing stick

Sets up `/dev/sdX` like the already-configured stick `/dev/sdY`: Ventoy is properly installed on the target (a plain
file copy cannot make it bootable), then the source's whole data partition — ISO, `/ventoy/ventoy.json`,
`persistence.dat` **including its user data**, `/software/` — is mirrored with rsync. The source is only ever mounted
read-only. A free-space preflight aborts before anything is wiped if the source's used payload doesn't fit the
target, and files over the FAT32 ~4 GiB limit are listed and skipped:

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --source-device /dev/sdY
```

Re-sync a previously duplicated stick after the source changed — an rsync dry run previews what would be added,
changed, and deleted on the target and asks for confirmation before deletions (`-y` skips that confirmation too):

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --source-device /dev/sdY \
  --update
```

Explicit `--debian-iso` / `--downloads-file` still apply **additively** on top of the mirrored payload;
`--persistence-size` is ignored when a `persistence.dat` was carried over from the source (recreating it would
destroy the duplicated data). Without `--source-device`, the interactive run asks whether the payload should come
from the configuration or be duplicated from an existing stick.

### Provision default software onto the stick

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --downloads-file config/usb-downloads.txt
```

### Custom Ventoy binary location

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
  --ventoy-bin /opt/ventoy/Ventoy2Disk.sh
```

Or via environment variable:

```bash
ddev exec env VENTOY_BIN=/opt/ventoy/Ventoy2Disk.sh \
  sudo bin/console usb:setup --device /dev/sdX
```

### Software downloads file

`--downloads-file` (default: `config/usb-downloads.txt`) points at a plain-text list of software to copy onto the
stick's `/software/` folder — by default Rekordbox, Traktor DJ2, the T-Racks 8x8 Matrix Digital Processor Editor, and
trial installers for Traktor Pro, Ableton Live, and Resolume Arena (edit the file to fill in the vendor URLs you
want). One link per line; blank lines and `#` comments are ignored:

- `https://...` / `http://...` — downloaded now (via `curl`) and copied onto the stick.
- `magnet:...` / `urn:btmh:...` — recognized but **not implemented yet**; torrent support is planned (see
  [../TODO.md](../TODO.md)), so these lines are currently logged and skipped.
- A local file path — copied onto the stick as-is, no download involved.
- A local directory path — every file directly inside it (non-recursive) is copied onto the stick.
- A local directory path ending in `/**` — scanned recursively (all subfolders too), preserving each file's
  subfolder path under `/software/` on the stick, so same-named files in different subfolders don't collide.

Relative local paths resolve against the current working directory (the project root when run via `bin/console`).

Unlike the Debian ISO download, there is no published checksum for these files, so downloads are cached by
filename/size only — **not** integrity-verified. At the interactive prompt, type `-` to skip software provisioning
entirely for that run.

Some vendors don't offer a stable, unauthenticated direct-download URL at all (the actual installer link is only
generated after account login, form submission, or an active browser session/cookies) — `config/usb-downloads.txt`
documents which of the default entries this affects. `usb:setup` rejects any response with a `text/*` `Content-Type`
(the signature of a login/session-gated HTML page) with a warning instead of copying it onto the stick, but this
only catches that one failure mode — always sanity-check a new URL yourself (`curl -I <url>`, expect a binary
content-type) before adding it.

For software with no working direct-download URL (account-gated vendor pages, e.g. Traktor Pro via Native Access),
download the installer manually in a browser and drop it into `config/usb-manual-downloads/` — the default
`config/usb-downloads.txt` already lists that folder as a local directory entry, so `usb:setup` picks up and copies
anything placed there automatically, no manual mounting needed.

`config/usb-downloads.txt` itself is checked into the repo (it contains no personal data). Interactive answers for
`usb:setup` more broadly (device, persistence size, ISO source, ...) are saved to `config/usb-setup.json`, which is
gitignored since it can contain machine-specific values — `config/usb-setup.json.example` is the checked-in starting
point; copy it to `config/usb-setup.json` if you want pre-filled prompt defaults from a previous run.

### List available block devices

```bash
lsblk -o NAME,SIZE,TYPE,TRAN,VENDOR,MODEL,MOUNTPOINT
```

### Show full help

```bash
ddev exec bin/console usb:setup --help
```

## What it does — step by step

1. **Installs Ventoy** — runs `Ventoy2Disk.sh -I /dev/sdX` (MBR mode, no `-g` flag).
2. **Reformats partition 1 as FAT32** — Ventoy defaults to exFAT; this step overrides it with
   `mkfs.fat -F 32 -n VENTOY`.
3. **Copies the Debian ISO** — plain `cp` into the FAT32 partition root.
4. **Creates `persistence.dat`** — allocates a file on the FAT32 partition, formats it as ext4 with label `persistence`,
   and writes `/persistence.conf` (`/ union`) inside so Debian live-boot enables persistence automatically.
5. **Writes `/ventoy/ventoy.json`** — links the ISO to the persistence backend via Ventoy's persistence plugin.
6. **Copies queued software installers** — downloads each `http(s)` URL from the downloads file (cached, but not
   checksum-verified) and copies them into `/software/` on the FAT32 partition.

With `--source-device`, steps 3–6 are replaced by an rsync mirror of the source stick's data partition (steps 1–2
still run so the target is actually bootable); explicitly passed ISO/software options are applied additively
afterwards. The mirror excludes OS artifacts (`System Volume Information`, `.Trash*`, `FOUND.NNN`) and uses
`--modify-window=1` so FAT's 2-second timestamps don't force full re-copies on `--update`.

## Partition layout after setup

```
/dev/sdX       — MBR partition table
  /dev/sdX1    — FAT32 "VENTOY"  (data: ISOs, persistence.dat, ventoy/, software/)
  /dev/sdX2    — Ventoy system partition (do not touch)
```

## ventoy.json written to the stick

```json
{
  "persistence": [
    {
      "image": "/debian-live-12.iso",
      "backend": "/persistence.dat"
    }
  ]
}
```

## FAT32 limitations

FAT32 limits individual files to **4 GiB − 1 byte**. This affects:

- ISO files larger than ~4 GiB will fail to copy.
- Persistence images must be kept under ~4090 MiB (the script caps this automatically).
- The same cap applies to each software installer copied into `/software/`.

If you need larger files, reformat partition 1 manually as exFAT after running the script:

```bash
sudo umount /dev/sdX1
sudo mkfs.exfat -n VENTOY /dev/sdX1
```

## Ventoy auto-detection order

The command checks these locations in order:

1. `--ventoy-bin` flag
2. `VENTOY_BIN` environment variable
3. `/opt/ventoy/Ventoy2Disk.sh`
4. `/usr/share/ventoy/Ventoy2Disk.sh`
5. `/usr/lib/ventoy/Ventoy2Disk.sh`
6. `ventoy` on `$PATH`

## Booting

1. Plug in the USB stick and reboot.
2. Select the USB device in your BIOS/UEFI boot menu.
3. Ventoy's menu appears — select the Debian live ISO.
4. Debian boots with persistence active (changes survive reboots).
