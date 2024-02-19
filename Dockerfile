# Use the official PHP image with version 8.2
FROM php:8.2-fpm

# Set working directory
WORKDIR /var/www/html

# Install necessary extensions and Composer
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install necessary PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Copy composer files and install dependencies
COPY composer.json composer.lock /var/www/html/
RUN composer install --no-scripts --no-autoloader

# Copy the application code
COPY . /var/www/html

# Expose port 9000 to communicate with Nginx
EXPOSE 9000

# Nginx configuration
# COPY participacao/nginx.conf /etc/nginx/conf.d/default.conf

# # Copy the PHP-FPM configuration file
# COPY participacao/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Start PHP-FPM
CMD ["php-fpm"]
