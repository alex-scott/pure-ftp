FROM debian:bookworm-slim
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends pure-ftpd \
    && rm -rf /var/lib/apt/lists/* \
    && useradd -m -d /home/ftpuser -s /bin/bash ftpuser \
    && echo 'ftpuser:ftppass' | chpasswd
EXPOSE 21
# -P announces an unreachable passive address, to test that the client ignores it.
CMD ["pure-ftpd", "-l", "unix", "-E", "-A", "-j", "-p", "30040:30049", "-P", "10.255.255.1", "-c", "20", "-C", "20"]
