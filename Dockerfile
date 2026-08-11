# Obraz WordPressa budowany z tego repozytorium.
#
# Jądro WordPressa pochodzi z repozytorium (nie z obrazu `wordpress`), dzięki
# czemu wersja kodu jest dokładnie taka, jak w gałęzi, którą wdrażasz.
FROM php:8.3-apache

# Rozszerzenia PHP wymagane/zalecane przez WordPressa.
RUN set -eux; \
	apt-get update; \
	apt-get install -y --no-install-recommends \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
		libwebp-dev \
		libzip-dev \
		libicu-dev \
		libonig-dev \
	; \
	docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
	docker-php-ext-install -j"$(nproc)" \
		exif \
		gd \
		intl \
		mysqli \
		opcache \
		zip \
	; \
	rm -rf /var/lib/apt/lists/*

# Moduły Apache: przepisywanie adresów, prawdziwe IP klienta zza Traefika,
# nagłówki i cache statyków.
RUN set -eux; \
	a2enmod rewrite remoteip headers expires

COPY docker/apache-wordpress.conf /etc/apache2/conf-available/z-wordpress.conf
RUN a2enconf z-wordpress

COPY docker/php-wordpress.ini /usr/local/etc/php/conf.d/zz-wordpress.ini

COPY docker/entrypoint.sh /usr/local/bin/wp-entrypoint.sh
RUN chmod +x /usr/local/bin/wp-entrypoint.sh

WORKDIR /var/www/html

# Kod WordPressa z repozytorium.
COPY --chown=www-data:www-data . /var/www/html/

# Pliki wdrożeniowe nie należą do katalogu serwowanego przez Apache.
RUN set -eux; \
	rm -rf \
		/var/www/html/docker \
		/var/www/html/Dockerfile \
		/var/www/html/docker-compose.yml \
		/var/www/html/.dockerignore \
		/var/www/html/.env \
		/var/www/html/.env.example \
		/var/www/html/.git \
		/var/www/html/DEPLOY.md

ENV WP_PATH="" \
	WP_STRIP_PREFIX="false" \
	WP_SCHEME="https"

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
	CMD php -r 'exit(@fsockopen("127.0.0.1", 80, $e, $s, 3) ? 0 : 1);'

ENTRYPOINT ["/usr/local/bin/wp-entrypoint.sh"]
CMD ["apache2-foreground"]
