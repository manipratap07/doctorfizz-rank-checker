# DoctorFizz Rank Checker - SerpApi-only install

This build uses **SerpApi only** for all ranking searches.

## Upload location

Upload the contents of this folder to:

`public_html/keyword-rank-checker/`

The final structure must contain `index.html`, `api/`, `app/`, `admin/`, and `assets/` directly inside that folder.

## Already configured

`app/config.php` is included and already contains the SerpApi key supplied for this build, plus generated `app_key` and `ip_pepper` values.

The SerpApi key never needs to be placed in HTML or JavaScript. PHP reads it server-side.

## Hosting requirements

- PHP 8.1+
- cURL PHP extension
- PDO SQLite PHP extension
- OpenSSL PHP extension
- `app/data/` writable by PHP

Recommended permissions:
- `app/config.php`: 600
- `app/data/`: 700
- files: 644
- folders: 755

## Test after upload

Open:

`https://itzfizz.com/keyword-rank-checker/api/index.php?a=health`

All checks should be true.

Then open:

`https://itzfizz.com/keyword-rank-checker/api/index.php?a=session`

It should return JSON with `"ok": true`.

Finally open:

`https://itzfizz.com/keyword-rank-checker/`

and run a keyword check.

## How searches work

The server calls:

`https://serpapi.com/search.json`

with `engine=google`, keyword, country, language, result offset and the private SerpApi key.

The app reads `organic_results`, matches the requested domain by hostname, and reports the organic position.

## Important

Do not move `app/config.php` to the public root. Keep `app/.htaccess` in place so the config and SQLite data cannot be requested directly.
