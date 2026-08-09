# Dev-only image — production is plain shared hosting (INV-003).
# Mirrors cyon.ch-class hosting: Apache + mod_php + .htaccess support.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install pdo_mysql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Shared hosting allows .htaccess overrides — enable the same here.
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Conservative limits similar to shared hosting defaults.
COPY php.ini /usr/local/etc/php/conf.d/familycastel-dev.ini
