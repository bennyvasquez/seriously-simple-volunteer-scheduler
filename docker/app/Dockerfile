# Development image for SSVS. Matches the production server: AlmaLinux 10 with PHP from AppStream.
FROM almalinux:10

RUN dnf -y install \
        php-cli php-common php-mbstring php-mysqlnd php-pdo php-xml \
        php-intl php-bcmath php-process php-opcache \
        unzip git \
    && dnf clean all

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/app/start.sh /usr/local/bin/ssvs-start

CMD ["sh", "/usr/local/bin/ssvs-start"]
