# =============================================================================
# Krayin CRM (Sensia) - imagen de producción para Railway
#
# Apache + mod_php sirviendo /var/www/html/public en el $PORT que inyecta
# Railway. Los assets de Vite/admin ya vienen compilados en public/ (están
# versionados), así que no hace falta Node en la imagen.
# =============================================================================
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        ffmpeg \
        libfreetype6-dev \
        libicu-dev \
        libgmp-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
        zlib1g-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-configure intl \
    && docker-php-ext-install bcmath calendar exif gd gmp intl mysqli opcache pdo pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY .configs/apache.conf /etc/apache2/sites-available/000-default.conf
# Apache escucha en ${PORT}; entrypoint.sh lo exporta con default 80
# mod_php exige mpm_prefork; dejar otro MPM activo da "AH00534: More than one MPM loaded"
RUN echo 'Listen ${PORT}' > /etc/apache2/ports.conf \
    && echo 'ServerName localhost' > /etc/apache2/conf-enabled/servername.conf \
    && a2dismod -f mpm_event mpm_worker || true \
    && a2enmod mpm_prefork rewrite headers

WORKDIR /var/www/html

# Dependencias primero para aprovechar la cache de capas
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENV PORT=80
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
