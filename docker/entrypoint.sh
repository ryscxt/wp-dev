#!/usr/bin/env bash
#
# Punkt wejścia kontenera WordPressa dla Dokploy.
#
# Zadania:
#   1. Zmapowanie ścieżki domeny (np. /przechowuj) na katalog instalacji.
#   2. Wygenerowanie .htaccess z poprawnym RewriteBase dla tej ścieżki.
#   3. Przygotowanie katalogów wp-content i uprawnień.
#   4. Wskazanie własnej domeny na kontener Traefika (pętla zwrotna: REST API,
#      WP-Cron, Stan zdrowia witryny), zamiast polegać na hairpin NAT.
#   5. Oczekiwanie na bazę danych.

set -euo pipefail

WP_ROOT="${WP_ROOT:-/var/www/html}"

log() {
	echo "[wp-entrypoint] $*"
}

# --- 1. Normalizacja ścieżki -------------------------------------------------
# '' | '/' -> ''      '/przechowuj/' | 'przechowuj' -> '/przechowuj'
wp_path="${WP_PATH:-}"
wp_path="${wp_path#/}"
wp_path="${wp_path%/}"
if [ -n "$wp_path" ]; then
	wp_path="/$wp_path"
fi

wp_strip_prefix="$(echo "${WP_STRIP_PREFIX:-false}" | tr '[:upper:]' '[:lower:]')"

# --- 2. Konfiguracja Apache dla ścieżki --------------------------------------
subpath_conf="/etc/apache2/conf-enabled/zz-wp-subpath.conf"
rm -f "$subpath_conf"

# Ścieżka, którą widzi Apache. Przy włączonym "Strip Path" Traefik obcina
# prefiks, więc do serwera trafiają adresy bez niego.
apache_path="$wp_path"

if [ -n "$wp_path" ]; then
	case "$wp_strip_prefix" in
		1 | true | yes | on)
			# Prefiks odtwarza wp-config.php na poziomie PHP.
			apache_path=""
			log "WP_PATH=$wp_path (Strip Path włączony w Dokploy - alias Apache pominięty)"
			;;
		*)
			log "WP_PATH=$wp_path (alias Apache: $wp_path -> $WP_ROOT)"
			cat > "$subpath_conf" <<-APACHE_CONF
				# Wygenerowane przez docker/entrypoint.sh - nie edytuj ręcznie.
				Alias "$wp_path" "$WP_ROOT"
			APACHE_CONF
			;;
	esac
else
	log "WP_PATH nie ustawione - witryna serwowana z katalogu głównego domeny"
fi

# --- 3. .htaccess z RewriteBase pasującym do ścieżki -------------------------
# Katalog z kodem pochodzi z obrazu i jest ulotny, więc plik odtwarzamy przy
# każdym starcie. Ręczne zmiany są zachowywane, jeżeli usuniesz znacznik.
htaccess="$WP_ROOT/.htaccess"

if [ ! -f "$htaccess" ] || grep -q "wp-entrypoint generated" "$htaccess" 2>/dev/null; then
	cat > "$htaccess" <<-HTACCESS
		# wp-entrypoint generated - plik odtwarzany przy starcie kontenera.
		# BEGIN WordPress
		<IfModule mod_rewrite.c>
		RewriteEngine On
		RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
		RewriteBase $apache_path/
		RewriteRule ^index\\.php\$ - [L]
		RewriteCond %{REQUEST_FILENAME} !-f
		RewriteCond %{REQUEST_FILENAME} !-d
		RewriteRule . $apache_path/index.php [L]
		</IfModule>
		# END WordPress
	HTACCESS

	if [ "$apache_path" != "$wp_path" ]; then
		# W trybie "Strip Path" reguły zapisane przez WordPressa po zmianie
		# bezpośrednich odnośników zawierałyby prefiks, którego Apache tu nie
		# widzi - plik zostaje tylko do odczytu, żeby nie rozłożyć witryny.
		chown root:root "$htaccess"
		chmod 0444 "$htaccess"
	else
		chown www-data:www-data "$htaccess"
		chmod 0644 "$htaccess"
	fi
fi

# --- 4. Katalogi zapisywalne przez WordPressa --------------------------------
for dir in \
	"$WP_ROOT/wp-content/uploads" \
	"$WP_ROOT/wp-content/upgrade" \
	"$WP_ROOT/wp-content/upgrade-temp-backup" \
	"$WP_ROOT/wp-content/languages" \
	"$WP_ROOT/wp-content/plugins" \
	"$WP_ROOT/wp-content/themes"; do
	mkdir -p "$dir"
	# Wolumeny nazwane startują jako root - właściciela ustawiamy tylko dla
	# samego katalogu, żeby nie przechodzić po tysiącach plików przy każdym starcie.
	chown www-data:www-data "$dir"
done

# --- 5. Własna domena -> kontener Traefika -----------------------------------
# Żądania zwrotne (REST API, WP-Cron, Stan zdrowia witryny) idą na własną domenę.
# Bez tego trafiają na publiczne IP serwera i zależą od hairpin NAT, co na wielu
# hostach kończy się timeoutem (cURL error 28).
# Zob. https://github.com/Dokploy/templates/issues/128
if [ -n "${WP_DOMAIN:-}" ]; then
	(
		for _ in $(seq 1 60); do
			traefik_ip="$(getent hosts dokploy-traefik | awk '{print $1; exit}')"
			if [ -n "$traefik_ip" ]; then
				if ! grep -q "[[:space:]]${WP_DOMAIN}\$" /etc/hosts; then
					echo "$traefik_ip $WP_DOMAIN" >> /etc/hosts
					log "dodano do /etc/hosts: $traefik_ip $WP_DOMAIN"
				fi
				break
			fi
			sleep 2
		done
	) &
fi

# --- 6. Oczekiwanie na bazę danych -------------------------------------------
db_host="${WORDPRESS_DB_HOST:-${DB_HOST:-wp_db}}"
db_user="${WORDPRESS_DB_USER:-${DB_USER:-root}}"
db_pass="${WORDPRESS_DB_PASSWORD:-${DB_PASSWORD:-}}"

if [ -n "$db_host" ]; then
	log "oczekiwanie na bazę danych ($db_host)..."
	for attempt in $(seq 1 60); do
		if php -r '
			$host = $argv[1];
			$port = 3306;
			if (strpos($host, ":") !== false) {
				list($host, $port) = explode(":", $host, 2);
			}
			mysqli_report(MYSQLI_REPORT_OFF);
			$link = @mysqli_connect($host, $argv[2], $argv[3], "", (int) $port);
			// Błąd uwierzytelnienia też oznacza, że serwer już odpowiada.
			exit(($link || in_array(mysqli_connect_errno(), [1044, 1045, 1049], true)) ? 0 : 1);
		' "$db_host" "$db_user" "$db_pass" 2>/dev/null; then
			log "baza danych odpowiada"
			break
		fi

		if [ "$attempt" -eq 60 ]; then
			log "baza danych nie odpowiedziała w oczekiwanym czasie - startuję mimo to"
		fi
		sleep 2
	done
fi

exec "$@"
