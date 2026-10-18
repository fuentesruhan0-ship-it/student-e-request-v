FROM php:8.2-fpm

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    nginx \
    libpng-dev libjpeg-dev libwebp-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Create required directories and set permissions
RUN mkdir -p /var/www/html/uploads /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/var

# Configure Nginx
RUN echo 'server {\n\
    listen 80 default_server;\n\
    root /var/www/html;\n\
    index index.php index.html;\n\
    \n\
    location / {\n\
        try_files $uri $uri/ /index.php?$query_string;\n\
    }\n\
    \n\
    location ~ \.php$ {\n\
        fastcgi_pass 127.0.0.1:9000;\n\
        fastcgi_index index.php;\n\
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n\
        include fastcgi_params;\n\
    }\n\
    \n\
    location ~ /\.ht {\n\
        deny all;\n\
    }\n\
}' > /etc/nginx/sites-available/default

# Create startup script with proper error handling
RUN echo '#!/bin/bash\n\
set -e\n\
echo "Starting PHP-FPM..."\n\
php-fpm -D\n\
echo "PHP-FPM started successfully"\n\
echo "Starting Nginx..."\n\
nginx -t\n\
exec nginx -g "daemon off;"' > /start.sh && chmod +x /start.sh

# Expose port
EXPOSE 80

# Start services
CMD ["/start.sh"]
