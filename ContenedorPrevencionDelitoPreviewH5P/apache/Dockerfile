FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl pdo pdo_pgsql zip bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
COPY php-uploads.ini /usr/local/etc/php/conf.d/99-uploads.ini
COPY init.sh /usr/local/bin/app-init.sh
RUN chmod +x /usr/local/bin/app-init.sh

WORKDIR /var/www/html

EXPOSE 80

CMD ["/usr/local/bin/app-init.sh"]
