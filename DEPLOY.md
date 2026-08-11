# WordPress na Dokploy — również pod domeną ze ścieżką (`/przechowuj`)

Repozytorium jest gotowe do wdrożenia jako **Compose** w Dokploy. Kod WordPressa
pochodzi z tej gałęzi, konfiguracja w całości ze zmiennych środowiskowych.

## Co rozwiązuje ta konfiguracja

Domena ze ścieżką (`https://dev.rys.network/przechowuj`) psuje standardową
instalację WordPressa w kontenerze na trzy sposoby — tu każdy jest obsłużony:

| Problem | Rozwiązanie |
| --- | --- |
| Apache nie zna ścieżki `/przechowuj`, więc każdy adres poza stroną główną kończy się 404 | `docker/entrypoint.sh` tworzy `Alias /przechowuj → /var/www/html`, czyli pełnoprawną instalację w podkatalogu |
| Bezpośrednie odnośniki (permalinki) nie działają, bo `.htaccess` ma `RewriteBase /` | `.htaccess` jest generowany przy starcie z `RewriteBase` odpowiadającym `WP_PATH` |
| Logowanie „gubi” sesję, panel przekierowuje w kółko | `wp-config.php` ustawia `COOKIEPATH`, `SITECOOKIEPATH`, `ADMIN_COOKIE_PATH` na ścieżkę instalacji |

Dodatkowo:

- `WP_HOME` / `WP_SITEURL` budowane z `WP_DOMAIN` + `WP_PATH` — bez wpisywania
  adresu na sztywno w pliku,
- wykrywanie HTTPS z nagłówka `X-Forwarded-Proto` (Traefik kończy TLS),
- prawdziwe IP klienta (`mod_remoteip`) zamiast adresu kontenera Traefika,
- własna domena wskazana na kontener Traefika w `/etc/hosts`, żeby REST API,
  WP-Cron i Stan zdrowia witryny nie zależały od hairpin NAT
  (typowy `cURL error 28`, zob. [Dokploy/templates#128](https://github.com/Dokploy/templates/issues/128)),
- obsługa włączonego w Dokploy „Strip Path” (`WP_STRIP_PREFIX=true`) — prefiks
  ścieżki jest wtedy odtwarzany na poziomie PHP.

## Wdrożenie krok po kroku

1. **Dokploy → Create → Compose**, źródło: to repozytorium, gałąź `main`,
   Compose Path: `docker-compose.yml`.
2. **Environment** — wklej zmienne z `.env.example` i uzupełnij:

   ```env
   WP_DOMAIN=dev.rys.network
   WP_PATH=/przechowuj
   WP_STRIP_PREFIX=false
   DB_NAME=wordpress
   DB_PASSWORD=<długie losowe hasło>
   WP_SALT_SECRET=<długi losowy ciąg>
   ```

3. **Domains → Add Domain**:
   - Host: `dev.rys.network`
   - Path: `/przechowuj`
   - **Strip Path: wyłączone**
   - Service Name: `wordpress`, Container Port: `80`
   - Certificate: Let's Encrypt
4. **Deploy**, a następnie wejdź na
   `https://dev.rys.network/przechowuj/wp-admin/install.php`.

`WP_PATH` musi być identyczne ze ścieżką domeny w Dokploy. Jeżeli witryna ma
działać w katalogu głównym domeny, zostaw `WP_PATH` puste.

### Jeżeli jednak włączysz „Strip Path”

Ustaw `WP_STRIP_PREFIX=true` (`WP_PATH` zostaw wypełnione). Traefik obcina wtedy
prefiks, alias Apache nie jest tworzony, `.htaccess` dostaje `RewriteBase /`,
a `wp-config.php` doszywa prefiks do `REQUEST_URI`. W tym trybie `.htaccess`
jest ustawiany tylko do odczytu — reguły, które WordPress zapisałby po zmianie
bezpośrednich odnośników, zawierałyby prefiks niewidoczny dla Apache i zwracały
404 (panel pokaże wtedy komunikat o ręcznej aktualizacji pliku; można go
zignorować, plik jest już poprawny).

Wariant z wyłączonym „Strip Path” jest prostszy i pewniejszy — WordPress dostaje
wtedy dokładnie te adresy, które sam generuje.

## Kilka witryn na jednej domenie

Każda instalacja to osobny projekt Compose z własnym `WP_PATH`
(`/przechowuj`, `/sklep`, …) i własną bazą. Ciasteczka są ograniczone do
ścieżki, więc instalacje nie wylogowują się nawzajem.

## Co jest utrwalane

Kod (jądro, pliki z repozytorium) pochodzi z obrazu i jest odtwarzany przy
każdym wdrożeniu — aktualizacje jądra robisz przez `git`, dlatego
`WP_AUTO_UPDATE_CORE` jest wyłączone. W wolumenach zostają:
`wp-content/uploads`, `plugins`, `themes`, `languages`, `upgrade` oraz dane MySQL.

## Zmienne środowiskowe

| Zmienna | Domyślnie | Opis |
| --- | --- | --- |
| `WP_DOMAIN` | host żądania | Domena witryny, bez schematu i ścieżki |
| `WP_PATH` | *(puste)* | Ścieżka domeny, np. `/przechowuj` |
| `WP_STRIP_PREFIX` | `false` | `true`, gdy w Dokploy włączony „Strip Path” |
| `WP_SCHEME` | `https` | Schemat adresu witryny |
| `DB_NAME`, `DB_PASSWORD` | — | Baza danych (użytkownik: `root`) |
| `WP_SALT_SECRET` | hasło bazy | Baza do wyliczenia kluczy i soli |
| `WORDPRESS_AUTH_KEY`, … | wyliczane | Pełny zestaw kluczy i soli, jeśli wolisz podać wprost |
| `WORDPRESS_TABLE_PREFIX` | `wp_` | Prefiks tabel |
| `WORDPRESS_DEBUG` | `0` | `1` włącza `WP_DEBUG` |
| `WP_MEMORY_LIMIT` | `256M` | Limit pamięci WordPressa |
| `DISALLOW_FILE_EDIT` | `true` | Edytor plików w panelu |
| `DISABLE_WP_CRON` | `false` | Wyłączenie WP-Cron (np. na rzecz crona systemowego) |
| `FORCE_SSL_ADMIN` | `true` dla https | Wymuszenie HTTPS w panelu |
| `WORDPRESS_CONFIG_EXTRA` | *(puste)* | Dodatkowe stałe PHP |

## Uruchomienie lokalne

```bash
cp .env.example .env          # ustaw WP_DOMAIN=localhost, WP_PATH=/przechowuj, WP_SCHEME=http
docker network create dokploy-network 2>/dev/null || true
docker compose up --build
```

Lokalnie kontener nie jest wystawiony na port hosta (ruch kieruje Traefik).
Do testów bez Traefika dodaj w `docker-compose.yml` mapowanie
`ports: ["8080:80"]` w usłudze `wordpress`.
