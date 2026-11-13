#!/bin/bash
set -e

# Render assigns a dynamic port via the PORT environment variable (default: 80 or 10000)
PORT="${PORT:-80}"
echo "[Render Startup] Starting Apache on port ${PORT}..."

# 1. Configure Apache to listen on Render's dynamic PORT
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# 2. Reverse Proxy & Port 10000 Fixes:
# Prevent Apache from appending internal container port (:10000) to redirects
cat << 'EOF' > /etc/apache2/conf-available/render-reverse-proxy.conf
ServerName localhost
UseCanonicalName Off
UseCanonicalPhysicalPort Off

<Directory /var/www/html>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF

a2enconf render-reverse-proxy > /dev/null 2>&1

# 3. Ensure AllowOverride All in main apache2.conf so .htaccess takes full effect
sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# 4. Ensure uploads directory is writable
mkdir -p /var/www/html/uploads
chown -R www-data:www-data /var/www/html/uploads
chmod -R 777 /var/www/html/uploads

# 5. Start Apache in foreground
echo "[Render Startup] Apache configured successfully. Launching apache2-foreground..."
exec apache2-foreground
