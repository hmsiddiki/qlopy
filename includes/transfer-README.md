# Import and export engine (core upgrade plan C8)

Settings > **Import / Export** moves content in and out of the site as CSV, JSON or JSON-lines files. The core knows nothing about
shops, orders or WooCommerce: a plugin says what can be moved (an **entity**) and, if the rows do not come from a file, where they come
from (a **source**). The engine brings the rest: a resumable run with progress, a dry run, an ID map (old id to new id), an undo, problem
reports and a safe image downloader.

Files: `includes/transfer.php` (the engine), `includes/http-fetch.php` (safe downloads), `admin/transfer.php` (the screen),
`admin/dev/transfer-test.php` (99 checks; `php admin/dev/transfer-test.php`).

## What it costs

A site that never opens the screen pays nothing. `transfer.php` is loaded only by the admin screen, by code that calls it, and while a run
is waiting (option `qp_transfer_active` is 1), so the scheduled task that carries the run on has its handler. Two tables (`transfer_runs`,
`transfer_map`) are created the first time an administrator uses it, never during a visitor request.

## Register an entity

```php
add_filter('qp_transfer_entities', function ($entities) {
    $entities['my_things'] = [
        'label'  => 'My things',
        'fields' => [
            'name'  => ['label' => 'Name',  'type' => 'string',  'required' => true, 'max' => 200],
            'price' => ['label' => 'Price', 'type' => 'decimal'],
            'photo' => ['label' => 'Photo (web address)', 'type' => 'url'],
        ],
        'key'  => 'name',                                   // what makes a row "the same row" on a repeat run (optional)
        'find' => function (array $row): ?int { ... },      // an existing local id the ID map does not know (optional)
        'import_row' => function (array $row, array $ctx): array {
            // $row: your field names, cleaned and typed. $ctx: dry, local_id, mode, run_id, source, plan, user, media.
            if ($ctx['dry']) return ['result' => 'created', 'id' => 0];
            $id = my_save($row, $ctx['local_id']);
            return ['result' => $ctx['local_id'] ? 'updated' : 'created', 'id' => $id];
        },
        'delete' => function (int $id): bool { ... },       // needed for "undo this run" (what the run created is deleted)
        'export_read' => function ($cursor, int $limit, array $filters): array {
            return ['rows' => [...], 'next' => $lastCursor, 'done' => $isLast];   // rows keyed by field name
        },
        'export_columns' => ['id', 'name', 'price'],        // optional; default is the fields
        'export_filters' => ['status' => ['label' => 'Status', 'options' => ['a' => 'A', 'b' => 'B']]],   // optional, shown on the Export tab
        'capability' => 'manage_options',                   // who may use it (default)
    ];
    return $entities;
});
```

- **Field types:** `string` (default, `max` 500, optional `choices`), `text`, `int`, `decimal` (kept as text so no precision is lost; reads
  `1,234.56`, `1.234,56`, `12,5`, `৳ 1,000`), `bool` (yes/no/1/0/true/false/on/off), `date` (read with `strtotime`, stored UTC),
  `url` (http and https only), `list` (split on `|` and `,`). A value that does not clearly mean one thing is **rejected with a reason**, never guessed.
- **Results of `import_row`:** `created`, `updated`, `same`, `skipped` or `rejected` (+ `reason`). Throwing counts as rejected. Return `id`
  (the local id) for anything written so the ID map and undo work.
- **Every row runs inside a database transaction** that a dry run rolls back, so a dry run saves nothing even if an entity forgets. Files
  are different: `$ctx['dry']` tells you not to download or write them (`$ctx['media']` already honours it).
- **Money:** keep it as the decimal text the engine gives you and convert to your minor units yourself; refuse what you cannot read.

## Register a source

File sources (`csv`, `json`, `ndjson`) are built in. Another source (an API, a database dump) is:

```php
add_filter('qp_transfer_sources', function ($sources) {
    $sources['woo_api'] = [
        'label' => 'WooCommerce store',
        'read'  => function ($cursor, int $limit, array $plan): array {
            return ['rows' => [[$rowAssoc, $cursorAfterThisRow], ...], 'done' => $isLast];
        },
    ];
    return $sources;
});
```

Then start a run from your own screen with `qp_transfer_run_create([...])` (below) and send the user to `Settings > Import / Export > Runs`
to watch it. Secrets such as API keys belong in the plan only for as long as the run needs them: do not store them in the plan snapshot.

## Runs from code

```php
[$id, $error] = qp_transfer_run_create([
    'entity' => 'posts', 'direction' => 'import', 'source' => 'csv', 'dry' => true, 'user' => get_current_user_id(),
    'plan' => ['file' => $nameInPrivateFolder, 'format' => 'csv', 'delimiter' => ',', 'columns' => [...], 'encoding' => 'UTF-8',
               'map' => ['title' => 'Title', 'slug' => 'Slug'],      // entity field => source column
               'fixed' => ['post_type' => 'post'],                    // entity field => constant
               'mode' => 'create_update',                             // or create_only, update_only
               'source_id' => 'my-old-site',                          // what the ID map calls the system the rows came from
               'limit' => 0, 'force' => false],
]);
$state = qp_transfer_step($id, 15);        // one batch inside 15 seconds: ['state' => running|done|busy|failed|..., 'run' => ...]
qp_transfer_run_undo($id);                 // then qp_transfer_step() until state is 'undone'
qp_transfer_map_local('my-old-site', 'my_things', 'OLD-KEY');   // the local id an old key became (to point one entity at another)
```

An export plan is `['format' => 'csv|json|ndjson', 'filters' => [...], 'limit' => 0]`; the finished run's `output` is a file in the private
folder, downloaded through the screen (a signed link; files are removed after a day).

## How a run goes on

* **Resumable.** A run keeps a cursor (a byte offset for CSV and JSON lines, a row number for JSON). Stopping and continuing, or a
  crash, never repeats work: a row already saved is recognised through the ID map and reported as `same`.
* **One at a time.** A lock lets one worker take a batch; the admin page and the queue task can run together without clashing.
* **Built on the C2 queue, no second scheduler.** The task `qp_transfer_step` (heavy) takes the next waiting run for one batch and queues
  itself again. Heavy tasks do not run from the visitor-triggered worker: they run from a real crontab, the external ping or Settings > Cron
  "Run". Without one, the open admin page drives the same batches over AJAX with a progress bar and the run waits while the page is closed.
* **Undo** deletes what a run **created** (rows and the images it downloaded). Rows a run only updated are not changed back: an update keeps
  no copy of the old values. Say so to your users.

## Safe downloads (`includes/http-fetch.php`)

```php
[$ok, $info] = qp_http_download($url, $destFile, ['max_bytes' => 10 * 1048576]);   // $info: path, bytes, content_type, final_url, status
[$ok, $body, $info] = qp_http_get($url, ['headers' => ['Accept: application/json'], 'basic_auth' => 'user:key']);
[$ok, $message, $ip, $parts] = qp_http_url_check($url, ['schemes' => ['https']]);
```

Only the schemes you allow (default https), every address the host resolves to must be public (private, loopback, link-local, carrier-grade
and reserved ranges are refused, IPv4-mapped IPv6 included), the connection is pinned to the checked address, no credentials in the address,
redirects are followed by hand (3 by default) with every hop checked again and custom headers dropped when a redirect leaves the host, a
size cap is enforced while writing, and a partial file is always removed. `['allow_private' => true]` lifts the address rule for a local
development source and nothing else. The updater's `qlopy_download_file()` is for trusted, signed packages and is unchanged.

## Images

`qp_transfer_fetch_media($url, $args)` downloads an image and stores it through `qp_handle_upload`, so the database keeps the path
**relative to the configured uploads folder** (`2026/10/photo.jpg`), every image size is generated, and the full address is built when it is
printed. The same address is downloaded once (the ID map remembers it). `$args`: `dry`, `run_id` (so an undo removes it), `user`, `max_bytes`,
`schemes`, `allow_private`, `name`. In an entity, call `$ctx['media']($url)`.

## Safety rules

* The screen needs `manage_options`; each entity can ask for more (`capability`). Export files are random-named, in a private folder under
  uploads (`transfer-private`, with an index file and a deny rule), served only through the signed link, removed after a day.
* A CSV cell that starts with `=`, `+`, `-` or `@` is neutralised in exports (spreadsheet formula injection).
* Exporting personal data is the entity's responsibility: register such an entity with a capability that fits, and say what it contains.
* The engine never creates user accounts or passwords.


## More for entity authors

* **Key as code.** `'key'` may be a closure (or an array callable) that works the key out from the cleaned row, for example "the original id,
  else the SKU, else the name". The key is what the ID map remembers; rows with an empty key are always created.
* **`json` fields.** A field of type `json` takes an object or list as it is (JSON and JSON-lines files) or as JSON text (a CSV cell).
* **Notes on a saved row.** `import_row` may return a `reason` with `created` or `updated` too: it is listed as "saved, with a note".
* **Dry runs and duplicates inside a file.** A dry run rolls every row back, so a later row cannot see an earlier one. The engine remembers
  the keys earlier rows of the dry run would have written (a second row with the same key is reported as an update), and
  `$ctx['seen']('namespace', 'value')` lets an entity do the same for its own uniqueness rules (it returns true when an earlier row of this dry
  run already used that value; in a real run it is always false because the real rows are there to see).
* **Terms.** `qp_transfer_term_path($taxonomy, 'A > B > C', $dry)` finds or creates nested terms and returns `[leaf id, ids created]`;
  `qp_transfer_set_terms($postId, $taxonomy, $ids)` replaces a post's terms in that taxonomy; `qp_transfer_parse_paths($cell)` reads
  `A > B | C` or a JSON list. The core registers a `terms` entity (name, taxonomy, slug, parent) too.
* **Order of work.** Import what others point at first (categories, products), then what points at them (orders). Rows are remembered under
  the run's *source id*, so a line in an order finds a product through `qp_transfer_map_local($sourceId, 'my_product_entity', $oldId)`.

## Users (core entity `users`, `includes/transfer-users.php`)

Users and customers can be imported and exported (Settings > Import / Export, for administrators who manage users). What it will and will not do:

* **No passwords.** An imported account gets a random password nobody knows; the customer uses "forgot password". Export never contains a password or hash.
* **Only harmless roles.** Only a role that has no capabilities at all (the built-in `subscriber`, or a shop's customer role) can be given. A privileged role in the file is refused and reported; no setting allows it.
* **Recognising an account that already exists** is a choice on the screen (`options.match`): **Email**, **Phone number**, **Email or phone number** (either finds the account; two different accounts is refused) or **Email and phone number together** (a row that has both must match one account on both; a row with only one of them is matched on it). Phone numbers are compared by their last 10 digits (`qp_transfer_phone_key`), so `+880 1711 111111` and `01711-111111` are the same. The phone is looked for in the import's own key, the core field `billing_phone` and a shop's saved checkout details.
* **An existing account is only ever filled in:** a missing name or phone, or an email that was a placeholder. Its username, role and password are never touched.
* **A customer with only a phone number** gets a placeholder email (`name@noemail.invalid`, marked, never exported or shown as an email) because the users table needs a unique email. Such a customer has no way to reset a password by email until a real one is added (a later row that matches on the phone and brings an email replaces it).
* **Undo** deletes the accounts the run created (never the ones it only filled in).
* Plugins get two actions: `qp_transfer_user_saved($userId, $row, $ctx, $created)` and `qp_transfer_user_deleted($userId)` (qCommerce uses them to prefill a new customer's checkout details and to turn orders back into guest orders when an import is undone).
* Helpers: `qp_transfer_user_find($email, $phone, $match)` returns `[user id or null, conflict message or null]`; `qp_transfer_phone_key($phone)`.

## Entity options and refusing in `find`

An entity can ask for choices on the mapping screen: `'options' => ['match' => ['label' => ..., 'choices' => ['email' => 'Email', ...], 'default' => 'email', 'help' => ...]]`. Only a declared answer is kept; `$ctx['options']` has them. Its `find($row, $info)` receives `['options' => ..., 'plan' => ...]` and may return `['reject' => 'why']` to refuse a row (for example when two accounts match).

## More for source authors

* A source row may carry `'_skip' => 'reason'` (and `'_key'`): the engine counts it as skipped and lists it without calling the entity (for example a product type that cannot be imported).
* A source may have `'finish' => callable($run)`: called when a run ends (done, failed, cancelled or deleted), so it can forget what it only needed for the run (a key, a session). It is called for dry runs too; a source that is followed by a real run should not forget on a dry one.
* `qp_http_get()` and `qp_http_download()` return the answer's headers in `$info['headers']` (names in lower case), so a source can read paging totals such as `x-wp-total`.
* A worked example is the WooCommerce migration plugin (`content/plugins/qcommerce-woo-migrate`).