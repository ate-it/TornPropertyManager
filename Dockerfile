FROM php:8.2-apache

# Install libcurl dev headers, then enable curl PHP extension and Apache mod_rewrite
RUN apt-get update && apt-get install -y libcurl4-openssl-dev \
 && docker-php-ext-install curl \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Point document root at /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/apache2.conf \
      /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -ri -e 's/AllowOverride None/AllowOverride All/g' \
      /etc/apache2/apache2.conf \
      /etc/apache2/conf-available/*.conf

COPY . /var/www/html/

# Ensure cache dir exists and is writable by Apache
RUN mkdir -p /var/www/html/cache \
 && chown -R www-data:www-data /var/www/html/cache

EXPOSE 80
