FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
        curl gnupg2 lsb-release ca-certificates php-cli \
 && curl -fsSLO https://repo.percona.com/apt/percona-release_latest.generic_all.deb \
 && dpkg -i percona-release_latest.generic_all.deb \
 && percona-release enable pxb-80 release \
 && apt-get update \
 && apt-get install -y percona-xtrabackup-80 \
 && ARCH=$(dpkg --print-architecture) \
 && curl -fsSL -o /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-${ARCH}" \
 && chmod +x /usr/local/bin/supercronic \
 && rm -rf /var/lib/apt/lists/* percona-release_latest.generic_all.deb

# CLI داکر (برای stop/start کردن کانتینر db)
COPY --from=docker:27-cli /usr/local/bin/docker /usr/local/bin/docker

WORKDIR /app