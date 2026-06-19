# USB Setup — Ventoy + Persistent Debian

Installs Ventoy (MBR partition table, FAT32 data partition), optionally copies a Debian live ISO, and configures Ventoy
persistence for it.

## Requirements

All tools — `dosfstools`, `e2fsprogs`, `util-linux`, and the latest Ventoy release — are installed automatically via
`.ddev/web-build/Dockerfile` when you run `ddev start`.

| Tool               | Package                                   | Purpose                      |
|--------------------|-------------------------------------------|------------------------------|
| `mkfs.fat`         | dosfstools                                | Format partition 1 as FAT32  |
| `mkfs.ext4`        | e2fsprogs                                 | Format persistence image     |
| `fallocate`        | util-linux                                | Allocate persistence file    |
| `mount` / `umount` | util-linux                                | Loop-mount persistence image |
| `lsblk`            | util-linux                                | List block devices           |
| `Ventoy2Disk.sh`   | ventoy (auto-downloaded to `/opt/ventoy`) | Install Ventoy bootloader    |

## Usage

```
ddev exec sudo bin/console usb:setup [options]
```

### Options

| Flag                      | Short | Default                           | Description                                              |
|---------------------------|-------|-----------------------------------|----------------------------------------------------------|
| `--device /dev/sdX`       |       | prompted                          | Target USB block device                                  |
| `--debian-iso /path.iso`  |       | prompted (download or local path) | Debian live ISO to copy onto the stick                   |
| `--persistence-size 2048` |       | prompted (default 2048)           | Persistence image size in MiB                            |
| `--ventoy-bin /path`      |       | auto                              | Path to `Ventoy2Disk.sh` (auto-detected)                 |
| `--yes`                   | `-y`  | —                                 | Skip confirmation prompts                                |
| `--no-interaction`        | `-n`  | —                                 | Non-interactive: all args required via flags, no prompts |
| `--help`                  | `-h`  | —                                 | Show full option reference                               |

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

All required args must be provided via flags. Confirmation prompts are skipped automatically:

```bash
ddev exec sudo bin/console usb:setup \
  --device /dev/sdX \
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

## Partition layout after setup

```
/dev/sdX       — MBR partition table
  /dev/sdX1    — FAT32 "VENTOY"  (data: ISOs, persistence.dat, ventoy/)
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
