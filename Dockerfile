FROM php:8.2-apache

# Download the helper script for easy extension installation
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

# Extension installation:
# - pdo_mysql, mysqli: for database
# - gd, imagick: for image processing
# - intl: for localization (needed for Symfony/Nette/Laravel)
# - zip, bcmath, exif: for file uploads and calculations
RUN install-php-extensions \
    pdo \
    pdo_mysql \
    mysqli \
    imagick \
    gd \
    intl \
    zip \
    bcmath \
    exif \
    opcache \
    mbstring \
    fileinfo \
    curl

# Allow mod_rewrite for pretty URL
RUN a2enmod rewrite

# Set-up work directory
WORKDIR /var/www/html