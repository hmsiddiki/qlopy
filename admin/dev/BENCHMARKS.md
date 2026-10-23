# Qlopy server-time benchmarks

Produced by `admin/dev/bench.php` with the debug-only profiler (`includes/profiler.php`). Server time is PHP time from the start of
the request to shutdown (network and TLS are out of it: one kept-alive connection, warmup requests discarded).

Run (needs `'profiler' => true` in `config.php` on a dev machine; never on a live site):

    MSYS_NO_PATHCONV=1 php admin/dev/bench.php --base=https://aloelo.dev/orcasy --resolve=127.0.0.1 --label=after \
      --baseline=admin/dev/bench-baseline.json \
      --urls="home=/,page=/sample-page,post=/post/welcome-to-your-site,shop=/shop/,product=/product/sample-widget,cart=/cart/,not_found=/qp-nope,admin_login=/admin/login.php"

`--baseline` makes the script exit 1 when a median is worse by more than 15% and 2 ms (the build budget). `MSYS_NO_PATHCONV=1` is
only needed in Git Bash, which otherwise rewrites `name=/path` arguments.

## Baseline c1a-baseline (2026-10-22, owner's Windows desktop, PHP 8.2 NTS, nginx, MariaDB; qCommerce + Stripe + SSLCommerz + Any Hostname active, Shofy theme)

20 requests per URL after 4 warmup.

| page | status | median ms | p95 ms | min ms | SQL (n / ms) | files | peak MB |
| --- | --- | --- | --- | --- | --- | --- | --- |
| home (shop front page) | 200 | 102.16 | 113.54 | 91.16 | 154 / 17.19 | 104 | 8 |
| page | 200 | 55.49 | 105.47 | 42.42 | 69 / 8.17 | 84 | 6 |
| post | 200 | 50.24 | 79.54 | 44.98 | 76 / 9.59 | 85 | 6 |
| shop | 200 | 102.77 | 119.31 | 94.27 | 144 / 17.34 | 109 | 8 |
| product | 200 | 54.25 | 126.47 | 52.54 | 92 / 10.56 | 106 | 8 |
| cart | 200 | 52.11 | 69.29 | 48.07 | 70 / 8.86 | 89 | 6 |
| not_found | 404 | 57.33 | 67.25 | 46.26 | 66 / 8.34 | 83 | 6 |
| admin_login | 200 | 12.16 | 29.49 | 11.87 | 1 / 0.23 | 12 | 4 |

Median ms per phase (`after_last_mark` is the template and output after routing; `sql` overlaps the phases):

| page | core_bootstrap | theme_resolve | theme_functions | plugins_load | init_action | route_parse | qp_init_action | after_last_mark | sql |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| page | 27.24 | 0.21 | 0.48 | 10.63 | 2.85 | 0.37 | 4.96 | 8.29 | 8.17 |
| post | 17.75 | 0.2 | 0.52 | 11.62 | 2.93 | 0.35 | 5.63 | 10.67 | 9.59 |
| product | 17.5 | 0.2 | 0.52 | 11.35 | 2.86 | 0.4 | 5.31 | 17.27 | 10.56 |
| shop | 19.42 | 0.21 | 0.74 | 14.52 | 3.12 | 0.41 | | 62.83 | 17.34 |

(The baseline JSON is `admin/dev/bench-baseline.json`. The first `core_bootstrap` of a run can be higher while opcache warms.)

## What the first measurements show (log mode, query shapes)

- Core bootstrap (config, `auth.php`, includes) is the largest cost every page pays: about 17 ms of about 50.
- SQL is only about 8 ms of a plain page, but 54 of its 69 queries (117 of 144 on the shop) are single `site_options` reads, and
  one plugin option (`qcommerce_settings`) is read 30 times on a plain page and 93 times on the shop. Each read is also PHP and
  PDO call overhead, not only database time.
- Every page view runs 3 `UPDATE qp_site_options SET ... WHERE option_name = 'menus_data'` statements. Cause: the theme calls
  `register_menu_location()` on each request, which saves the whole menu option each time, even when nothing changed.
  `menus_save_all()` also claims to refresh the read cache but refreshes a separate static, so `menus_data` is read 4 times.
- `update_option_meta()` runs a `COUNT(*)` and then an `UPDATE` even when the value is identical.

## c1b-options-and-menus (2026-10-22): per-request option map, menu write fix, Windows image-path fix

Compared with `bench-c1a-baseline.json` (the "before"); `bench-baseline.json` is now this run and is the budget for later steps.

| page | before ms | after ms | change | SQL before / after |
| --- | --- | --- | --- | --- |
| home | 102.16 | 63.08 | -38.3% | 154 / 18 |
| page | 55.49 | 38.89 | -29.9% | 69 / 6 |
| post | 50.24 | 41.03 | -18.3% | 76 / 11 |
| shop | 102.77 | 56.20 | -45.3% | 144 / 18 |
| product | 54.25 | 52.65 | -2.9% | 92 / 11 |
| cart | 52.11 | 44.24 | -15.1% | 70 / 3 |
| not_found | 57.33 | 37.10 | -35.3% | 66 / 4 |
| admin_login | 12.16 | 13.02 | +7.1% (noise; the map is bypassed in the admin) | 1 / 1 |

Part of the shop and product gain is the image fix (below), which stopped a thumbnail being regenerated on every view.

Where the time is now (plain page, median): loading `auth.php` and the session token about 12 ms, the rest of the core includes about 4 ms,
plugins about 10 ms, `qp_init` about 4 ms, template and output about 5 ms, SQL about 1 ms. Loading `auth.php` is the next target.

Also found: on Windows a derived image size was regenerated on every request (431 copies of one thumbnail in `uploads/2026/10`),
because `qp_get_attachment_image_src()` tested for an absolute path with a broken regex (`'#^[A-Za-z]:\#'`, whose backslash escapes
the closing delimiter). Fixed; Linux paths start with `/` and never hit it. The 430 stray copies are still on disk.

## C1d step 1: product search with `LIKE '%word%'` (2026-10-22)

Dev DB (MariaDB 10.11, InnoDB, no search index), 50,000 seeded products (`php admin/dev/search-seed.php seed 50000`;
each has a 4-word title and 60 words of content). Query as built by `includes/query.php`: `title LIKE ? OR content LIKE ?`,
published only, newest 20. Median of 5 runs, database time only.

| Rows | Search | Median |
|---|---|---|
| 16 (before seeding) | `trail` | 0.1 ms |
| 50,016 | `trail` (many matches) | 84 ms |
| 50,016 | `zzzz` (no match, full scan) | 124 ms |

Read: cost grows linearly with the number of posts, and a miss is the slowest case. That is the number the FULLTEXT option has to beat.
Remove the seed rows with `php admin/dev/search-seed.php remove`.

## C1d step 2: FULLTEXT on `posts(title, content)` (2026-10-22)

Same 50,016-row data set. Index built with `qp_schema_add_posts_fulltext_index()` (`ALGORITHM=INPLACE, LOCK=SHARED`: reads
continue, writes wait): build took 2.1 s. Query: `MATCH(title, content) AGAINST('word*' IN BOOLEAN MODE)`, published, newest 20.

| Search | LIKE (step 1) | FULLTEXT |
|---|---|---|
| `zzzz` (no match) | 124 ms | 0.2 ms |
| `trail` (matches ~90% of rows) | 84 ms | 155 ms |

Read: FULLTEXT wins by a wide margin when the word is rare or absent, which is the normal case for a real catalogue. It loses when a
word is in almost every row, because every match is then sorted by date. The seed data uses only 25 words, so `trail` is an
extreme case that real product text does not produce. Step 3 should cap work on very common words (fall back to `LIKE`, or rank by
relevance and limit) and the benchmark should be repeated with a realistic word spread before the option is recommended.

## C1d step 5: realistic word spread, decision on a common-word cap (2026-10-22)

50,000 products, words drawn from a 5,000-word vocabulary with natural-language (Zipf) frequencies
(`php admin/dev/search-seed.php seed 50000 zipf`, then `php admin/dev/search-seed.php bench <words>`). Database time, median of 5,
published products, newest 20.

| Word rank | Posts matching | LIKE | FULLTEXT |
|---|---|---|---|
| 1 | 49,964 (100%) | 68 ms | 200 ms |
| 2 | 48,596 (97%) | 89 ms | 189 ms |
| 10 | 25,094 (50%) | 135 ms | 95 ms |
| 50 | 6,460 (13%) | 156 ms | 31 ms |
| 100 | 3,305 (7%) | 164 ms | 21 ms |
| 500 | 687 | 163 ms | 12 ms |
| 1,000 | 343 | 167 ms | 11 ms |
| 3,000 | 101 | 166 ms | 10 ms |
| 5,000 | 57 | 167 ms | 9 ms |
| not in catalogue | 0 | 164 ms | 9 ms |

Read: FULLTEXT is faster for any word found in less than about half of the posts, up to 18 times faster, and flat at about 9 ms
while LIKE stays at 150 to 170 ms. It is slower only for a word in nearly every post (about 120 ms worse), which is a search that
cannot narrow anything down.

**Decision: no common-word cap.** A pre-check was measured and rejected: counting matches (`LIMIT 5001`) already costs 27 to 73 ms for
common words, which would add more than it saves. Sorting by `id` instead of date did not help either (191 ms). The worst case is
bounded and rare, and the setting is off by default and one click to switch back.

## C1g step 1: where the first 12 ms go (2026-10-22)

Finer profiler marks inside `auth.php` (`auth_db_connect`, `auth_head_includes`, `auth_file_parsed`). Plain page, median of 30:

| Phase | ms |
|---|---|
| start of request to `db.php` connected (includes compiling `auth.php`) | 9.7 |
| `menus.php`, `rewrite.php`, `ajax-hooks.php` | 2.7 |
| rest of `auth.php` definitions | 0.5 |
| **session token check (`auth_init_from_token`)** | **0.4** |
| `qpmeta.php`, `post-types.php`, `assets.php` and other core includes | 4.0 |

Findings:
1. **The session token is not the cost** (0.4 ms). The earlier plan guess was wrong.
2. **The cost is PHP compiling the core on every request: this dev server has OPcache switched off** (`;zend_extension=opcache` in
   `E:\Wnmp\php\php.ini`). A request loads about 85 files; the core files alone are about 600 KB (`auth.php` 287 KB, `qpmeta.php` 118 KB).
   Tokenizing them takes 8 ms; PDO connect to MariaDB takes 1.7 ms.
3. Loading the same core bootstrap from the command line: 19 ms without OPcache, 6.5 ms with it (file cache, warm): about 12.5 ms saved.

Conclusion: most of the 12 ms is a server setting, not a core bug. Real hosts normally have OPcache on, so the numbers on this machine
overstate the core's cost. Shipped: a read-only "PHP OPcache: On/Off" line on General Settings that says so. Not changed: `php.ini`.

## C1g step 2: OPcache switched on in the dev PHP (2026-10-22)

`E:\Wnmp\php\php.ini`: `zend_extension=opcache`, `opcache.enable=1` (timestamp validation left on, so edits show within 2 s); php-cgi
restarted by the owner. Same method, 30 requests per URL, run twice (second run saved as `bench-c1g-opcache.json`).

| page | before (c1b) ms | OPcache on ms | SQL |
|---|---|---|---|
| home | 63.1 | 22 to 23 | 18 |
| page | 38.9 | 7 to 11 | 6 |
| post | 41.0 | 8 to 12 | 11 |
| shop | 56.2 | 22 to 25 | 18 |
| product | 52.7 | 20 | 11 |
| cart | 44.2 | 8 to 21 | 3 |
| not_found | 37.1 | 12 | 4 |
| admin_login | 13.0 | 0.8 | 1 |

Peak memory per request fell from 6 to 8 MB to 2 MB. Bootstrap phases (`auth_db_connect` to `core_bootstrap`) went from about 20 ms to
about 1 ms. A few requests per run still take 20 to 30 ms (p95); these are not the core and look like desktop noise or a cold
opcode cache entry after a file change, so budgets should compare medians over more requests before this becomes the new baseline.
**What is left per plain page (about 7 to 11 ms):** plugins_load 1.0, qp_init_action 2.5, template and output 2.4 to 3, SQL 0.9.
The core bootstrap no longer needs cutting.

## C2 step 3: built-in scheduler on page loads (2026-10-22)

Idle cost (nothing due): one small file read per front-end page, about 47 microseconds. Benchmark minimums are unchanged (page 7.2 ms,
before 6.9 to 7.4 ms; one more file loaded: `qp-cron-due.php`); medians swing 8 to 13 ms between runs on this desktop, so the 15%
budget check is not reliable for this step and the file-read figure above is the number to trust.
When work is due the visitor's page is not delayed on PHP-FPM (`fastcgi_finish_request`) by design. This dev server is php-cgi,
one process, with a plugin that flushes output early, so the page cannot be released: the slice runs in the page's own process and a
3-second task held the visitor 3.2 s (measured). The slice there is limited to 2 s and small counts; slow tasks are the owner's
cue to use a real crontab or the external ping.

## C1e step 5: page cache (2026-10-22)

Round trip to the local server over one kept-alive HTTPS connection (curl, 150 requests after 10 warmup; includes nginx and TLS record
handling, not the handshake). Cache off means PHP runs every time (OPcache on).

| page | cache off median / p95 | cache on (HIT) median / p95 | faster |
|---|---|---|---|
| /sample-page | 16.7 / 32.4 ms | 1.0 / 1.2 ms | 17x |
| /shop/ | 26.6 / 38.3 ms | 1.0 / 1.2 ms | 27x |
| / (shop front page) | 26.1 / 39.1 ms | 1.4 / 1.6 ms | 19x |

A HIT does not load `auth.php`, the theme, plugins or the database. With the cache off the only cost is one `is_file()` per request;
the C1a benchmark minimums are unchanged (plain page 7.5 ms).

Correctness matrix: `php admin/dev/page-cache-test.php` (23 checks, restores everything it touches): ordinary page stored, HIT identical, no
cookie on a HIT, HEAD not served, 20 simultaneous cold requests, cart / preview cookie / preview parameter / password-post cookie / search
bypass, logged-in visitor never served or stored and the anonymous copy untouched, registered language cookie gives separate copies,
stored files hold no nonce, cookie or session token, scheduled publish by the crontab runner purges, the archive shows the new post at
once, price change shows after the save hook (and a raw meta write with no hook leaves the old price: the one known gap), theme switch
purges both ways, 304, 404 never from cache.

## C1f: front-end loading (2026-10-22)

Counted from the HTML each theme prints for `/sample-page` (what a browser has to fetch), local files measured on disk, uncompressed:

| theme | stylesheets | scripts | third-party requests | local asset weight |
|---|---|---|---|---|
| default 1.0 (before) | 2 | 4 | 3 (Bootstrap CSS and JS from stackpath.bootstrapcdn.com, jQuery from code.jquery.com), all in or blocking the page | 2 KB (plus about 330 KB fetched from other sites) |
| default 1.1 (after) | 2 | 4 (5 on pages that can show comments) | 0 | about 330 KB, same origin, cacheable for a year |
| shofy (active here) | 11 | 17 | 0 | about 2.1 MB over 28 files |

Default 1.1: no render-blocking request to another site, no dependency on a CDN that can disappear (StackPath's has been retired), and
`comment-reply.js` only where comments can appear. It also stopped rewriting the rewrite cache (a database query and a file write)
on every request. Network timing is not measured here: this machine has no network latency to a CDN, so the saving is the removed
DNS, TLS and download of three third-party files per first visit, which cannot be shown on localhost.
Not changed: Shofy's 17 scripts (an installed theme is not touched; which script a page needs is the theme author's call).

## C4 API and C1g final (2026-10-22)

Server time (profiler total), 100 requests each over one kept-alive connection, OPcache on. File: `bench-c4-api.json`; page reference: `bench-c1g-final.json`.

| request | median ms | min ms | SQL | files |
|---|---|---|---|---|
| home page | 17.8 | 15.3 | 18 | 111 |
| GET /api/qlopy/v1/ | 2.4 | 2.0 | 1 | 69 |
| GET /api/qlopy/v1/posts?per_page=10 | 3.2 | 2.5 | 4 | 69 |
| GET /api/qlopy/v1/posts/2 | 2.9 | 2.2 | 3 | 69 |

Medians on this machine move by up to 10 ms between identical runs; read the minimum, or use `--n=300`. Registering the core routes costs about 1.25 ms
uncached in a CLI run, so no compiled route table was built (see the plan, C4-1).
