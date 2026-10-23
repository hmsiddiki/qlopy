# Database migrations run by the updater

The updater (`includes/updater.php`, `qlopy_do_update_action`) runs the file named by `migration_script` in the release manifest,
after it has taken the database backup and copied the new files. The file is included in an isolated scope with `QLOPY_INIT` and
`QLOPY_UPDATER_RUNNING` defined, and returns `true` (success) or `false` (the update is rolled back).

## Release manifest line for the C-series (core upgrade plan)

```json
"migration_script": "includes/db-migrations/c1d-post-meta-index.php"
```

- `c1d-post-meta-index.php` adds the index `post_meta(meta_key, post_id)` with `ALGORITHM=INPLACE, LOCK=NONE` only. It skips (and logs why) on
  MyISAM, old servers, tables over the row limit, or when the index exists. It always returns `true`: a skipped index never fails an update,
  because every query works the same without it. Running it twice is safe.
- Nothing else in the C-series needs a migration. Tables for scheduled tasks (C2) and the SEO redirects and 404 log (C3) are created the first
  time they are used by an administrator action, never during a visitor request. Widgets (C6) and the API (C4) store their data in
  existing tables (`site_options`, `user_meta`) and the `uploads` folder. The FULLTEXT search index stays opt-in on General Settings.

The manifest itself is built by whatever packages the release (it is not in this repository), so this line has to be added there.
