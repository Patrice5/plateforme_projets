FROM php:8.2-apache

# Installer les extensions nécessaires
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Copier le code du projet dans le conteneur
COPY . /var/www/html/

# Donner les bons droits
RUN chown -R www-data:www-data /var/www/html

