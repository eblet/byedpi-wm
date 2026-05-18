FROM alpine:3.19

ARG TARGETARCH
ARG TARGETVARIANT

RUN apk add --no-cache \
    nginx \
    php82 \
    php82-fpm \
    php82-curl \
    php82-json \
    php82-mbstring \
    php82-posix \
    php82-session \
    php82-tokenizer \
    supervisor \
    bash \
    procps \
    curl \
    ca-certificates \
    && ln -sf /usr/bin/php82 /usr/bin/php \
    && rm -rf /var/cache/apk/*

RUN mkdir -p /var/log/supervisor \
    /var/log/byedpi \
    /run/nginx \
    /app/data \
    /app/logs

COPY docker/config/nginx.conf /etc/nginx/nginx.conf
COPY docker/config/default.conf /etc/nginx/http.d/default.conf
COPY docker/config/php-fpm.conf /etc/php82/php-fpm.conf
COPY docker/config/www.conf /etc/php82/php-fpm.d/www.conf
COPY docker/config/supervisord.conf /etc/supervisord.conf

COPY . /app/
WORKDIR /app

COPY docker/scripts/copy-binary.sh /tmp/copy-binary.sh
RUN chmod +x /tmp/copy-binary.sh && /tmp/copy-binary.sh

COPY docker/scripts/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN chown -R nginx:nginx /app \
    && chmod 755 /app/byedpi/ciadpi* \
    && chmod 666 /app/config.json \
    && chmod 666 /app/local.pac

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:80/ || exit 1

EXPOSE 80 20001-20028

VOLUME ["/app/data", "/app/logs"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
