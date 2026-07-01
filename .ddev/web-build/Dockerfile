RUN apt-get update && apt-get install -y --no-install-recommends \
    bsdmainutils \
    curl \
    dosfstools \
    e2fsprogs \
    exfatprogs \
    ffmpeg \
    parted \
    psmisc \
    python3-pip \
    util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && ln -sf "$(command -v mkfs.exfat)" /usr/local/bin/mkexfatfs \
    && ln -sf "$(command -v fsck.exfat)" /usr/local/bin/exfatfsck

RUN set -e \
    && VENTOY_VER=$(curl -fsSL https://api.github.com/repos/ventoy/Ventoy/releases/latest \
        | grep '"tag_name"' | sed 's/.*"v\([^"]*\)".*/\1/') \
    && curl -fsSL "https://github.com/ventoy/Ventoy/releases/download/v${VENTOY_VER}/ventoy-${VENTOY_VER}-linux.tar.gz" \
        | tar -xz -C /opt \
    && mv /opt/ventoy-${VENTOY_VER} /opt/ventoy \
    && chmod +x /opt/ventoy/Ventoy2Disk.sh

RUN curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
    && chmod a+rx /usr/local/bin/yt-dlp

RUN pip3 install --break-system-packages curl-cffi
