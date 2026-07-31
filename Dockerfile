# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — dependencies
#
# Kept separate so Composer itself never ships in the final image, and so a
# code-only change doesn't reinstall the vendor tree.
# ---------------------------------------------------------------------------
# Built on the same PHP image as the runtime stage rather than composer:2, so
# the build pulls one base image instead of two — which also keeps it clear of
# Docker Hub's anonymous pull limits on a busy CI runner.
FROM php:8.4-apache AS vendor

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*; \
    curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin --filename=composer

WORKDIR /build

COPY src/composer.json src/composer.lock ./

# --no-scripts: the scripts expect the application tree, which isn't here yet.
# --ignore-platform-reqs: this stage only downloads the versions already pinned
# in composer.lock; intl/zip/mysqli have to exist in the runtime image, which
# they do, not in this throwaway one.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --ignore-platform-reqs

# ---------------------------------------------------------------------------
# Stage 2 — runtime
# ---------------------------------------------------------------------------
FROM php:8.4-apache

# The base image already ships mbstring, openssl (needed to verify release
# signatures) and OPcache, so only these three are missing: intl and zip for the
# app, mysqli for the default database driver.
#
# intl is built on its own: compiling it in parallel races on its subdirectories
# ("mkdir: cannot create directory 'collator/.libs': File exists").
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        unzip \
    ; \
    docker-php-ext-configure intl; \
    docker-php-ext-install intl; \
    docker-php-ext-install -j"$(nproc)" zip mysqli; \
    \
    # Drop the headers but keep the shared libraries the extensions link
    # against: `--auto-remove` would take libicu/libzip with them, and the
    # image would build fine and then fail to load intl.so and zip.so at
    # runtime. apt can't see that dependency, so it must not be asked to guess.
    apt-get purge -y libicu-dev libzip-dev; \
    php -m | grep -qx intl; \
    php -m | grep -qx zip; \
    php -m | grep -qx mysqli; \
    \
    rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# CodeIgniter serves from public/; everything above it must stay unreachable.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

COPY --from=vendor /build/vendor ./vendor

# Only src/ — the application. The repository root holds packaging and
# documentation (Dockerfile, compose.yaml, docs/), which have no business
# inside the image.
COPY src/ .

# The image is the unit of deployment here, so in-place self-update is off:
# it would write to a filesystem that disappears with the container.
ENV CI_ENVIRONMENT=production \
    SELF_UPDATE=false

# writable/ is a volume at runtime; these are the permissions it inherits.
RUN rm -f .env \
 && mkdir -p writable/cache writable/logs writable/session writable/uploads writable/backups writable/tmp \
 && chown -R www-data:www-data writable \
 && chmod -R 775 writable

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Releases served to every connected app live here — losing it loses them all.
VOLUME ["/var/www/html/writable"]

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1/") === false ? 1 : 0);'

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
