# Development image for SSVS. Matches the production server: AlmaLinux 10 with PHP from AppStream.
# Both base images are pinned by digest so a moved tag can't change a build. Dependabot reads FROM lines and updates the digests, so the comment above each one repeats the tag for people reading the file.
# composer:2
FROM composer:2@sha256:0049ce90b432795bf413a738b073963ada1a689e1a8708104684baafe94586ec AS composer

# almalinux:10 (this is the last stage, so it's the app image)
FROM almalinux:10@sha256:ccf217024b3d5e268cc208d1edb384a1edee6359370cf6ea1ecbc42254e57375

RUN dnf -y install \
        php-cli php-common php-mbstring php-mysqlnd php-pdo php-xml \
        php-intl php-bcmath php-process php-opcache \
        unzip git \
    && dnf clean all

# Development and testing only: Laravel Dusk (the browser tests) needs the zip extension. The production server doesn't install Dusk, so it doesn't need this.
RUN dnf -y install php-pecl-zip \
    && dnf clean all

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/app/start.sh /usr/local/bin/ssvs-start

CMD ["sh", "/usr/local/bin/ssvs-start"]
