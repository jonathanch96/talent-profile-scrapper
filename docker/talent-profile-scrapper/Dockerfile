# Use the official PHP 8.3 FPM image
FROM php:8.3-fpm

# Arguments from docker-compose
ARG user
ARG uid

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    vim \
    && docker-php-ext-install \
        bcmath \
        pdo_pgsql \
        pgsql \
        zip \
        pcntl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm \
    && docker-php-ext-install -j$(nproc) gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install and enable Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create a new user to match host UID
RUN useradd -G www-data,root -u $uid -d /home/$user $user \
    && mkdir -p /home/$user/.composer \
    && chown -R $user:$user /home/$user

# Increase PHP upload limits
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i "s/upload_max_filesize = 2M/upload_max_filesize = 300M/" "$PHP_INI_DIR/php.ini" \
    && sed -i "s/post_max_size = 8M/post_max_size = 300M/" "$PHP_INI_DIR/php.ini"

RUN mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R $user:$user /var/www/html/storage /var/www/html/bootstrap/cache

RUN chown -R $user:$user /var/www/html/storage /var/www/html/bootstrap/cache

RUN chmod -R 775 vendor storage bootstrap/cache

# Switch to non-root user
USER $user
