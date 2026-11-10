FROM debian:bookworm-slim
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends proftpd-core proftpd-mod-crypto \
    && rm -rf /var/lib/apt/lists/* \
    && useradd -m -d /home/ftpuser -s /bin/bash ftpuser \
    && echo 'ftpuser:ftppass' | chpasswd
EXPOSE 21 990 2124
CMD ["proftpd", "--nodaemon", "-c", "/etc/proftpd/test.conf"]
