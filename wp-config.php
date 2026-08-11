<?php
/**
 * Konfiguracja WordPressa dla wdrożeń kontenerowych (Dokploy + Traefik).
 *
 * Cała konfiguracja pochodzi ze zmiennych środowiskowych, dzięki czemu ten sam
 * obraz obsługuje zarówno domenę w katalogu głównym (https://example.com), jak
 * i domenę ze ścieżką (https://dev.rys.network/przechowuj).
 *
 * Najważniejsze zmienne:
 *   WP_DOMAIN   - domena serwowana przez Traefika, np. dev.rys.network
 *   WP_PATH     - ścieżka domeny w Dokploy, np. /przechowuj (puste = katalog główny)
 *   WP_SCHEME   - https (domyślnie) lub http
 *   DB_NAME / DB_PASSWORD / WORDPRESS_DB_* - dostęp do bazy danych
 *
 * @package WordPress
 */

if ( ! function_exists( 'wpdok_env' ) ) {
	/**
	 * Zwraca pierwszą niepustą zmienną środowiskową z podanej listy nazw.
	 *
	 * @param string|string[] $names   Nazwa zmiennej lub lista nazw (kolejność = priorytet).
	 * @param mixed           $default Wartość zwracana, gdy żadna zmienna nie jest ustawiona.
	 * @return mixed
	 */
	function wpdok_env( $names, $default = null ) {
		foreach ( (array) $names as $name ) {
			$value = getenv( $name );

			if ( false === $value && isset( $_ENV[ $name ] ) ) {
				$value = $_ENV[ $name ];
			}

			if ( false !== $value && null !== $value && '' !== $value ) {
				return $value;
			}
		}

		return $default;
	}

	/**
	 * Interpretuje zmienną środowiskową jako wartość logiczną.
	 *
	 * @param string|string[] $names   Nazwa zmiennej lub lista nazw.
	 * @param bool            $default Wartość domyślna.
	 * @return bool
	 */
	function wpdok_bool( $names, $default = false ) {
		$value = wpdok_env( $names );

		if ( null === $value ) {
			return $default;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Normalizuje ścieżkę domeny do postaci '' albo '/podkatalog'.
	 *
	 * @param string $path Ścieżka z konfiguracji.
	 * @return string
	 */
	function wpdok_normalize_path( $path ) {
		$path = trim( (string) $path );
		$path = '/' . trim( $path, "/ \t\n\r\0\x0B" );

		return ( '/' === $path ) ? '' : $path;
	}
}

/* --------------------------------------------------------------------------
 * Baza danych
 * -------------------------------------------------------------------------- */

define( 'DB_NAME', wpdok_env( array( 'WORDPRESS_DB_NAME', 'DB_NAME' ), 'wordpress' ) );
define( 'DB_USER', wpdok_env( array( 'WORDPRESS_DB_USER', 'DB_USER' ), 'root' ) );
define( 'DB_PASSWORD', wpdok_env( array( 'WORDPRESS_DB_PASSWORD', 'DB_PASSWORD' ), '' ) );
define( 'DB_HOST', wpdok_env( array( 'WORDPRESS_DB_HOST', 'DB_HOST' ), 'wp_db' ) );
define( 'DB_CHARSET', wpdok_env( array( 'WORDPRESS_DB_CHARSET', 'DB_CHARSET' ), 'utf8mb4' ) );
define( 'DB_COLLATE', wpdok_env( array( 'WORDPRESS_DB_COLLATE', 'DB_COLLATE' ), '' ) );

$table_prefix = wpdok_env( array( 'WORDPRESS_TABLE_PREFIX', 'TABLE_PREFIX' ), 'wp_' );

/* --------------------------------------------------------------------------
 * Klucze i sole
 *
 * Ustaw je jawnie w zmiennych środowiskowych (https://api.wordpress.org/secret-key/1.1/salt/).
 * Jeżeli ich nie podasz, są wyprowadzane deterministycznie z WP_SALT_SECRET
 * (lub hasła bazy), żeby sesje przetrwały restart kontenera.
 * -------------------------------------------------------------------------- */

$wpdok_salt_secret = wpdok_env( 'WP_SALT_SECRET', DB_PASSWORD . '|' . DB_NAME );

foreach (
	array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	) as $wpdok_salt_name
) {
	$wpdok_salt_value = wpdok_env( array( 'WORDPRESS_' . $wpdok_salt_name, $wpdok_salt_name ) );

	if ( null === $wpdok_salt_value ) {
		$wpdok_salt_value = hash( 'sha256', $wpdok_salt_name . '|' . $wpdok_salt_secret );
	}

	define( $wpdok_salt_name, $wpdok_salt_value );
}

unset( $wpdok_salt_name, $wpdok_salt_value, $wpdok_salt_secret );

/* --------------------------------------------------------------------------
 * Proxy (Traefik) - wykrywanie HTTPS i oryginalnego hosta
 * -------------------------------------------------------------------------- */

if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
	$wpdok_forwarded_proto = strtolower( trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_PROTO'] )[0] ) );

	if ( 'https' === $wpdok_forwarded_proto ) {
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = 443;
	}

	unset( $wpdok_forwarded_proto );
} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) {
	$_SERVER['HTTPS'] = 'on';
}

if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_HOST'] )[0] );
}

/* --------------------------------------------------------------------------
 * Adresy witryny - obsługa domen ze ścieżką (/przechowuj)
 * -------------------------------------------------------------------------- */

$wpdok_path = wpdok_normalize_path( wpdok_env( array( 'WP_PATH', 'WP_BASE_PATH' ), '' ) );

$wpdok_scheme = strtolower( (string) wpdok_env( 'WP_SCHEME', '' ) );

if ( ! in_array( $wpdok_scheme, array( 'http', 'https' ), true ) ) {
	$wpdok_scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( $_SERVER['HTTPS'] ) ) ? 'https' : 'http';
}

$wpdok_domain = wpdok_env( 'WP_DOMAIN', '' );

if ( ! $wpdok_domain && ! empty( $_SERVER['HTTP_HOST'] ) ) {
	$wpdok_domain = $_SERVER['HTTP_HOST'];
}

// Adres bazowy witryny. Pliki WordPressa i strona główna leżą pod tym samym
// adresem, bo Apache mapuje ścieżkę domeny na katalog z instalacją.
if ( $wpdok_domain ) {
	define( 'WP_HOME', $wpdok_scheme . '://' . $wpdok_domain . $wpdok_path );
	define( 'WP_SITEURL', WP_HOME );
}

// Ciasteczka muszą być ograniczone do ścieżki instalacji, inaczej logowanie
// i panel administracyjny gubią sesję przy kilku instalacjach na jednej domenie.
define( 'COOKIEPATH', $wpdok_path . '/' );
define( 'SITECOOKIEPATH', $wpdok_path . '/' );
define( 'ADMIN_COOKIE_PATH', $wpdok_path . '/wp-admin' );
define( 'PLUGINS_COOKIE_PATH', $wpdok_path . '/wp-content/plugins' );
define( 'COOKIE_DOMAIN', false );

// Gdy w Dokploy włączone jest "Strip Path", Traefik obcina prefiks ścieżki i do
// kontenera trafia URI bez /przechowuj. WordPress porównuje REQUEST_URI z
// adresem witryny, więc prefiks przywracamy tutaj. Przy wyłączonym "Strip Path"
// prefiks już jest na miejscu i ten blok nie robi nic.
if ( '' !== $wpdok_path && 'cli' !== PHP_SAPI && ! empty( $_SERVER['REQUEST_URI'] ) ) {
	$wpdok_uri = $_SERVER['REQUEST_URI'];

	if ( 0 !== strpos( $wpdok_uri, $wpdok_path . '/' ) && $wpdok_uri !== $wpdok_path ) {
		$_SERVER['REQUEST_URI'] = $wpdok_path . ( '/' === substr( $wpdok_uri, 0, 1 ) ? $wpdok_uri : '/' . $wpdok_uri );

		foreach ( array( 'SCRIPT_NAME', 'PHP_SELF' ) as $wpdok_server_key ) {
			if ( ! empty( $_SERVER[ $wpdok_server_key ] )
				&& 0 !== strpos( $_SERVER[ $wpdok_server_key ], $wpdok_path . '/' )
			) {
				$_SERVER[ $wpdok_server_key ] = $wpdok_path . $_SERVER[ $wpdok_server_key ];
			}
		}

		unset( $wpdok_server_key );
	}

	unset( $wpdok_uri );
}

/* --------------------------------------------------------------------------
 * Zachowanie WordPressa
 * -------------------------------------------------------------------------- */

define( 'WP_MEMORY_LIMIT', wpdok_env( 'WP_MEMORY_LIMIT', '256M' ) );
define( 'WP_MAX_MEMORY_LIMIT', wpdok_env( 'WP_MAX_MEMORY_LIMIT', '512M' ) );

define( 'DISALLOW_FILE_EDIT', wpdok_bool( 'DISALLOW_FILE_EDIT', true ) );

// Pliki jądra pochodzą z repozytorium/obrazu, więc aktualizacje jądra przez
// panel są wyłączone. Wtyczki i motywy nadal można instalować normalnie.
define( 'WP_AUTO_UPDATE_CORE', false );

define( 'FS_METHOD', wpdok_env( 'FS_METHOD', 'direct' ) );

define( 'DISABLE_WP_CRON', wpdok_bool( 'DISABLE_WP_CRON', false ) );

define( 'WP_ENVIRONMENT_TYPE', wpdok_env( 'WP_ENVIRONMENT_TYPE', 'production' ) );

// Wymuszenie HTTPS w panelu administracyjnym, gdy witryna działa po https.
define( 'FORCE_SSL_ADMIN', wpdok_bool( 'FORCE_SSL_ADMIN', 'https' === $wpdok_scheme ) );

/* --------------------------------------------------------------------------
 * Debugowanie
 * -------------------------------------------------------------------------- */

define( 'WP_DEBUG', wpdok_bool( array( 'WORDPRESS_DEBUG', 'WP_DEBUG' ), true ) );
define( 'WP_DEBUG_LOG', wpdok_bool( 'WP_DEBUG_LOG', true ) );
define( 'WP_DEBUG_DISPLAY', wpdok_bool( 'WP_DEBUG_DISPLAY', true ) );
define( 'SCRIPT_DEBUG', wpdok_bool( 'SCRIPT_DEBUG', false ) );

if ( ! WP_DEBUG_DISPLAY ) {
	@ini_set( 'display_errors', '0' );
}

/* --------------------------------------------------------------------------
 * Dodatkowe stałe przekazane przez środowisko
 *
 * Zgodność z oficjalnym obrazem `wordpress`: pozwala dopisać własne define()
 * bez przebudowy obrazu. Treść pochodzi wyłącznie z konfiguracji wdrożenia.
 * -------------------------------------------------------------------------- */

$wpdok_config_extra = wpdok_env( 'WORDPRESS_CONFIG_EXTRA' );

if ( $wpdok_config_extra ) {
	eval( $wpdok_config_extra ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}

unset( $wpdok_config_extra, $wpdok_path, $wpdok_scheme, $wpdok_domain );

/* --------------------------------------------------------------------------
 * Koniec konfiguracji.
 * -------------------------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
