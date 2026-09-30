FROM wordpress:php8.3-apache

# phpredis for the object cache
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# pdftotext (poppler-utils, GPL-2.0) extracts the text of uploaded PDFs for site search.
# Upstream version pinned; the Debian revision may move for security fixes.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends 'poppler-utils=25.03.0-5*'; \
    rm -rf /var/lib/apt/lists/*

# Compression is configured in apache-tmc.conf (Brotli preferred, gzip fallback); Debian's default
# deflate.conf would otherwise run gzip first for every client that accepts both.
RUN a2enmod remoteip headers expires brotli \
    && echo '# Compression: see /etc/apache2/conf-enabled/zz-tmc.conf' > /etc/apache2/mods-available/deflate.conf

COPY php.ini /usr/local/etc/php/conf.d/zz-tmc.ini
COPY apache-tmc.conf /etc/apache2/conf-enabled/zz-tmc.conf
# Multisite (subdomain) rewrite rules; copied into /var/www/html on first start
COPY htaccess /usr/src/wordpress/.htaccess
