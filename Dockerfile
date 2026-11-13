FROM php:8.2-apache

# Install system dependencies required for PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) mysqli pdo_mysql gd zip

# Enable Apache modules required for URL rewriting and reverse proxy headers
RUN a2enmod rewrite headers

# Copy application source code into container webroot
WORKDIR /var/www/html
COPY . /var/www/html/

# Copy startup script and make executable
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Ensure uploads directory exists with writable permissions
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/uploads

# Expose HTTP port (Render uses dynamic $PORT, standard fallback 80 or 10000)
EXPOSE 80 10000

# Start Apache via startup script
CMD ["/usr/local/bin/start.sh"]

