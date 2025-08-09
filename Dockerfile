# VMForge Controller image — an ENGINYRING project
FROM php:8.3-fpm
RUN apt-get update && apt-get install -y libzip-dev libpng-dev && docker-php-ext-install pdo pdo_mysql
WORKDIR /var/www/vmforge
COPY . .
