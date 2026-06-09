# ── Dockerfile pour le projet Mailing Platform (PHP + Apache) ─────────

FROM php:8.3-apache

# ── Métadonnées ──────────────────────────────────────────────────────
LABEL maintainer="Mailing Platform"
LABEL description="Image PHP 8.3 + Apache pour la plateforme de mailing"

# ── Dépendances système ──────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# ── Extensions PHP ───────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    mysqli \
    pdo \
    pdo_mysql \
    zip \
    opcache

# ── Activer mod_rewrite pour Apache ──────────────────────────────────
RUN a2enmod rewrite

# ── Configuration Apache : pointer vers /var/www/html/public si besoin
# Si votre point d'entrée est /var/www/html/index.php, commentez la ligne ci-dessous.
# Si vous avez un dossier public/, décommentez-la :
# RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf

# ── Permissions ─────────────────────────────────────────────────────
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ── Copier le code source (optionnel si vous utilisez volumes) ───────
# Quand on monte un volume en développement, le COPY est écrasé.
# En production, décommentez la ligne suivante :
# COPY . /var/www/html/

# ── Port exposé ──────────────────────────────────────────────────────
EXPOSE 80

# ── Démarrage ────────────────────────────────────────────────────────
CMD ["apache2-foreground"]
