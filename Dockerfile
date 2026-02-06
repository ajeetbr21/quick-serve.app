# Use the official PHP image with Apache
FROM php:8.2-apache

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application source
COPY . /var/www/html/

# Update port configuration to listen on environment variable $PORT (Cloud Run requirement)
# Cloud Run injects PORT (default 8080), Apache defaults to 80.
# We replace 80 with ${PORT} in ports.conf and 000-default.conf
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Configure custom php.ini settings if needed (upload limits)
RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 10M" >> /usr/local/etc/php/conf.d/uploads.ini

# Permission handling for www-data user
RUN chown -R www-data:www-data /var/www/html

# Expose the port (Docker documentation only, Cloud Run ignores this but good practice)
EXPOSE 8080

# Start Apache in foreground
CMD ["apache2-foreground"]
