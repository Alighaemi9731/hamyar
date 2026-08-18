### MobiShop — development application image (PHP 8.4 FPM)
#
# The application source is bind-mounted at /var/www/html in dev (see compose.yaml),
# so this image intentionally contains NO application code. A production image is
# built from docker/app/Dockerfile.prod (Phase 11).

FROM php:8.4-fpm-bookworm

ARG UID=1000
ARG GID=1000

# --- system packages -------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        postgresql-client \
        ca-certificates \
        curl \
    && rm -rf /var/lib/apt/lists/*

# --- php extensions --------------------------------------------------------
# mlocati's installer resolves the correct build deps per-extension and strips
# them afterwards, which keeps the image far smaller than hand-rolled docker-php-ext-install.
COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        gd \
        zip \
        bcmath \
        intl \
        exif \
        pcntl \
        sockets \
        opcache

# --- composer --------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- node + a headless browser, for Pest 4 browser tests -------------------
#
# Roadmap 11.1b. Pest's browser plugin drives Playwright, which is a Node program, and
# it spawns it from PHP — so Node has to live wherever PHP does rather than on the host.
#
# This is the reason the image is not as small as it looks like it should be, and the
# cost was taken deliberately: 11.1b exists to replace source-level layout guards with
# rendered ones, and a rendered assertion that can only be run by pushing to CI is one
# nobody iterates on. A test you cannot debug locally is a test that gets deleted the
# first time it goes red for an unclear reason.
#
# `--with-deps` installs the shared libraries Chromium needs on Debian; without them the
# browser is present and fails to launch, which presents as an opaque timeout.
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs

ENV PLAYWRIGHT_BROWSERS_PATH=/opt/playwright

# The apt lists are cleared *after* this rather than with the Node install above:
# `--with-deps` shells out to apt-get itself, and clearing them first leaves it with
# nothing to resolve against — a failure that reads as a Playwright problem.
RUN npx --yes playwright@1.56.0 install --with-deps chromium \
    && chmod -R a+rX /opt/playwright \
    && rm -rf /var/lib/apt/lists/*

# --- php ini ---------------------------------------------------------------
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-mobishop.ini

# --- unprivileged user matching the host, so bind-mounted files stay writable
RUN if getent group "${GID}" >/dev/null; then \
        existing_group="$(getent group "${GID}" | cut -d: -f1)"; \
    else \
        groupadd -g "${GID}" mobishop; \
        existing_group=mobishop; \
    fi; \
    if getent passwd "${UID}" >/dev/null; then \
        usermod -l mobishop -d /home/mobishop -m -g "${existing_group}" "$(getent passwd "${UID}" | cut -d: -f1)"; \
    else \
        useradd -u "${UID}" -g "${existing_group}" -m -s /bin/bash mobishop; \
    fi; \
    mkdir -p /home/mobishop/.composer && chown -R "${UID}:${GID}" /home/mobishop

WORKDIR /var/www/html

USER mobishop

# The bind-mounted repo is owned by the host user, which git inside the container
# treats as "dubious ownership" and refuses to read — Pint uses git to scope changed
# files, so without this every run prints a warning.
RUN git config --global --add safe.directory /var/www/html

CMD ["php-fpm"]
