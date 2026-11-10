FROM debian:bookworm-slim
RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends vsftpd \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/run/vsftpd/empty \
    && useradd -m -d /home/ftpuser -s /bin/bash ftpuser \
    && echo 'ftpuser:ftppass' | chpasswd
EXPOSE 21
CMD ["vsftpd", "/etc/vsftpd/test.conf"]
