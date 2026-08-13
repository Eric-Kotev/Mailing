#!/bin/bash
mkdir -p /var/www/html/pages/campagnes/uploads/pieces_jointes
chown -R www-data:www-data /var/www/html/pages/campagnes/uploads
chmod -R 775 /var/www/html/pages/campagnes/uploads
exec "$@"