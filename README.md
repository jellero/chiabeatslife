# Chiabeatslife — Slim 4

Ricostruzione del frontend pubblico di `chiabeatslife.jacopoellero.it` su Slim 4, prendendo come riferimento l'architettura usata in `jellero/laucoexperience` senza trascinarne CMS, database o funzioni specifiche.

## Contratto di migrazione

- WordPress non è richiesto dal nuovo frontend;
- Slim 4 gestisce tutte le pagine tramite un unico front controller;
- le URL pubbliche esistenti vengono mantenute, incluso lo slash finale usato dal sito attuale;
- CSS e JavaScript del sito live vengono copiati **byte-per-byte** negli stessi path pubblici (`/wp-content/...`, `/wp-includes/...`);
- immagini, font, PDF e altri asset referenziati dalle pagine vengono archiviati negli stessi path;
- la shell HTML, il routing e il rendering sono nuovi;
- sitemap e health check sono serviti direttamente dall'applicazione Slim.

## Architettura

```text
public/index.php                 front controller unico
bootstrap/app.php                bootstrap e autoload Composer
config/routes.php                inventario dichiarativo delle URL pubbliche
src/Http/ApplicationFactory.php  wiring Slim, route, sitemap, 404
src/Http/PageAction.php          rendering degli snapshot pagina
resources/views/layout.php       nuova shell HTML condivisa
storage/pages/                   contenuto renderizzato delle pagine importate
storage/site-map.json            inventario machine-readable delle pagine
storage/migration-report.json    manifest asset e dipendenze WordPress residue
public/wp-content/               CSS/JS/media originali del sito
public/wp-includes/              asset WordPress richiesti dal frontend

tools/import_wordpress.py        crawler/migratore idempotente
```

## Import del sito live

Il crawler parte dalla home, legge le sitemap WordPress disponibili e segue i link interni. Per ogni pagina:

1. conserva titolo, attributi HTML/body e contenuto pubblico;
2. rimuove solo metadati di runtime WordPress non utili al nuovo frontend;
3. individua CSS, JavaScript, immagini, font e altri asset;
4. salva gli asset same-origin nel loro path originale, senza riscrivere CSS o JavaScript;
5. genera `config/routes.php` e gli snapshot JSON;
6. produce `storage/migration-report.json` con checksum SHA-256, asset mancanti ed eventuali endpoint WordPress ancora referenziati.

Esecuzione manuale:

```bash
python -m pip install requests beautifulsoup4
python tools/import_wordpress.py \
  --base-url https://chiabeatslife.jacopoellero.it/ \
  --output .
```

Lo stesso processo è definito in `.github/workflows/migrate-site.yml`.

## Installazione e verifica

Richiede PHP 8.3 o 8.4.

```bash
composer install --no-dev --optimize-autoloader
composer check
```

Health check:

```text
GET /api/v1/health
```

## Deploy

Configurazione consigliata: document root puntato a `public/`. Per hosting condivisi che puntano alla root del repository è presente anche `.htaccess`, che protegge codice/configurazione e inoltra le richieste a `public/index.php`.

Prima del cut-over va controllato `storage/migration-report.json`: se il vecchio frontend contiene form o chiamate AJAX verso WordPress, il report le segnala esplicitamente così da sostituire l'endpoint prima di spegnere WordPress.
