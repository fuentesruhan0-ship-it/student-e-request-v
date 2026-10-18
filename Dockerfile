FROM php:8.2-apache

# Install PHP extensions required by the app
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libwebp-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Create required directories and set permissions
RUN mkdir -p /var/www/html/uploads /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/var \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/var

# Apache configuration: allow .htaccess overrides
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Expose port (Railway uses PORT env var)
EXPOSE 80

# Start Apache directly - Railway will handle port mapping
CMD ["apache2-foreground"]
