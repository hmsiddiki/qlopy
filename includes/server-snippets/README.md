# Server notes for the page cache and the scheduler

Qlopy works without any of this. These snippets make the page cache and the scheduler safer and faster on your web server.
Replace `/orcasy` with the folder Qlopy is installed in (or remove it when Qlopy is at the root of the site).

## 1. Keep cache and scheduler files private

The page cache stores finished public pages under `uploads/page-cache/`, and the scheduler keeps small state files in
`uploads/` (`cron-next.txt`, `cron-state.json`, `cron-lock`). None holds secrets, but nobody needs to download them.

nginx (inside the `server` block):

    location ~ ^/orcasy/uploads/(page-cache/|cache/|cron-) { deny all; return 404; }

Apache (`uploads/.htaccess`; Qlopy also writes one inside `uploads/page-cache/` when the cache is switched on):

    <FilesMatch "^cron-(next\.txt|state\.json|lock)$">
        Require all denied
    </FilesMatch>
    RedirectMatch 404 ^/orcasy/uploads/(page-cache|cache)/

## 2. Scheduled tasks: a real crontab is best

Settings > General > Scheduled Tasks keeps the site working without one (the built-in worker), but a crontab means visitors never
carry any of the work. One line, every minute:

    * * * * * /usr/bin/php /path/to/orcasy/qp-cron.php --key=YOUR_RUNNER_KEY >/dev/null 2>&1

The runner key is on the Cron screen. While the crontab runs, the built-in worker stays quiet by itself (it checks a "last run" stamp
for 15 minutes), so you do not need to switch it off. Hosts with no crontab can enable "External Ping" on the Cron screen and have a
web-cron service send a POST (not a GET: a GET puts the key in your server logs):

    curl -fsS -X POST -d "key=YOUR_RUNNER_KEY" "https://example.com/orcasy/?qp_cron=1"

## 3. PHP-FPM: letting the visitor go before background work

The built-in worker and the page cache write run after the page has been sent. That needs `fastcgi_finish_request()` (PHP-FPM has it;
php-cgi and mod_php do not, and Qlopy then runs a short in-request slice, which keeps the visitor's connection open for up to about 2
seconds, at most once every 30 seconds, only when something is due). The worker also calls the site on 127.0.0.1, so the FPM pool
needs at least two workers (`pm.max_children >= 2`) and the site must answer on its own port from the server itself. The Cron screen
shows "Loopback request: works" or the failure.

## 4. Page cache: what to expect

* Turn it on in Settings > General > Page Cache for Visitors. Check a public page with `curl -sI https://example.com/ | grep -i x-qp-cache`:
  `MISS; stored` the first time, then `HIT; age=N`. `BYPASS; reason` says why a page was not served or stored.
* Plugins that take over the whole page output (for example host-rewriting plugins) cannot be combined with it; the Settings screen says so.
* A plugin that shows prices, stock or anything that changes without an admin save must call `qp_page_cache_purge_all_soon()` or
  `qp_page_cache_purge_url($url)` when it changes, and declare itself with `qp_page_cache_declare_compatible('plugin-slug')`.
* nginx can serve the cache's `Cache-Control: public, max-age=0, must-revalidate` + `ETag` as is. Do not add `proxy_cache` or
  `fastcgi_cache` on top for the same pages: two caches that purge separately are how stale pages happen.

## 5. Long cache lifetimes for static files

Files whose name changes when their content changes can be cached by browsers for a year. In the default theme that is everything in
`content/themes/default/vendor/` (the file name holds a hash of its content). Other theme and plugin files use `?ver=` in the URL, which
changes when the theme or plugin version changes; a month is a safe lifetime for them.

nginx:

    location ~ ^/orcasy/content/themes/[^/]+/vendor/ {
        add_header Cache-Control "public, max-age=31536000, immutable";
        access_log off;
    }
    location ~* ^/orcasy/(content|uploads)/.*\.(css|js|woff2?|ttf|svg|png|jpe?g|gif|webp|avif|ico)$ {
        add_header Cache-Control "public, max-age=2592000";
        access_log off;
    }

Apache (`.htaccess` at the site root, needs mod_headers):

    <FilesMatch "\.(css|js|woff2?|ttf|svg|png|jpe?g|gif|webp|avif|ico)$">
        Header set Cache-Control "public, max-age=2592000"
    </FilesMatch>
    <If "%{REQUEST_URI} =~ m#/content/themes/[^/]+/vendor/#">
        Header set Cache-Control "public, max-age=31536000, immutable"
    </If>

Do not use these lifetimes for files that change under the same name and URL (a logo you replace in place, a user's uploaded file
with a reused name): give them a new name, or leave them out.
