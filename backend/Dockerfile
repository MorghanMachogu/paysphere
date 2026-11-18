FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Allow .htaccess in the document root
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY . .
RUN rm -f .env && mkdir -p logs && chown -R www-data:www-data logs

EXPOSE 80
