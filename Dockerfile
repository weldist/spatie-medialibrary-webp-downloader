ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

# System dependencies for image processing
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libmagickwand-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install GD with WebP + JPEG + PNG + FreeType support, exif (required by spatie/image),
# and intl (required by Illuminate\Support\Number::fileSize used in the convert command summary)
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install gd exif intl

# Imagick (PECL) — used by tests that exercise the imagick driver path
RUN pecl install imagick \
    && docker-php-ext-enable imagick

# Install Composer
COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

# Avoid git "dubious ownership" warning when /app is mounted as a volume
RUN git config --global --add safe.directory /app

WORKDIR /app
