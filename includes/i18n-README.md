# Writing a multilingual plugin for Qlopy

The core does not translate anything and does not care which plugin does. It defines one small contract (`includes/i18n.php`). A
multilingual plugin, the official qp-multilang or one you write, tells the core about itself with one call. The core, the SEO module and every
other plugin then ask the core (`qp_current_language()`, `qp_languages()`, ...) and never a particular plugin, so any multilingual plugin works
with every other plugin and the SEO module without either side knowing about the other.

## 1. Register

```php
qp_i18n_register('my-multilang', [
    'languages'    => fn(): array   => [...],   // required
    'current'      => fn(): ?string => 'fr',    // required: language code of this request
    'default'      => fn(): ?string => 'en',
    'url'          => fn(string $type, int $id, string $lang): ?string => 'https://...',
    'urls_batch'   => fn(string $type, array $ids): array => [id => [lang => url]],   // optional, much faster for sitemaps
    'home_url'     => fn(string $lang): ?string => 'https://...',
    'translations' => fn(string $type, int $id): array => [lang => id],               // optional
]);
```

`$type` is `'post'` (any post type) or `'term'`. `languages` may return a list of codes, a map `code => name`, or rows:
`['code' => 'fr', 'locale' => 'fr_FR', 'name' => 'French', 'native' => 'Français', 'rtl' => false, 'default' => false, 'hreflang' => 'fr']`.
Only `languages` and `current` are required. Anything missing only switches off the feature that needs it. A provider function that throws is
logged and treated as "no answer"; it can never break a page.

If several multilingual plugins register, the site owner's choice (option `qp_i18n_provider`, a slug) wins, then the filter
`qp_i18n_provider`, then the first one registered.

## 2. Answer content per language (filters)

The core applies these where it reads titles, content and meta. Each runs under the neutral name and under the older `ml_*` name:

| Neutral name | Older name | Arguments |
|---|---|---|
| `qp_translate_title` | `ml_the_title` | `$title, $post_id, $lang` |
| `qp_translate_content` | `ml_the_content` | `$content, $post_id, $lang` |
| `qp_translate_meta` | `ml_get_meta` | `$value, $object_id, $meta_key, $lang` (post, term and user meta) |

## 3. What you get for free

Once registered, with the SEO module on:

* `<link rel="alternate" hreflang="...">` for every language of a post, term and the front page, plus `x-default` (the default language's address).
* The canonical address of a page is that page in the language being shown, from your `url`.
* `og:locale` from the language's `locale`.
* The sitemap lists every language version as its own `<url>`, each with all the alternates.

Your own code can still adjust any of it: filters `qp_seo_alternates`, `qp_seo_locale`, `qp_seo_sitemap_alternates`, `qp_seo_meta`.
Addresses you give are rebased onto the configured site address by the SEO module, so building them on another host cannot leak it.

## 4. Things a multilingual plugin must still do itself

* Route requests to the right language (path prefix, sub-domain, `?lang=`, cookie) and make `current` answer it.
* If the language is kept in a cookie rather than in the address, register it with the page cache: `qp_page_cache_vary_cookie('my_lang')`,
  otherwise one visitor's language can be served to another. With the language in the address nothing is needed.
* Store and edit translations (the core only asks for them through the filters above).

`content/plugins/qp-i18n-example/plugin.php` is a complete working provider in about 60 lines: copy it. The core's test
(`admin/dev/i18n-test.php`) runs the SEO module against it.

## 5. Two ways to put a language in an address

The contract does not care how a plugin chooses the language, only what addresses it can give:

* **Cookie or header based (qp-multilang today).** The language comes from a cookie, `?lang=xx` or the browser's Accept-Language header, and a
  post keeps one address. The only addresses that always show one language are `<post address>?lang=xx`, so that is what `url` returns, and the
  default language has none (what a visitor with no cookie and no header gets). Tell the page cache what varies the page with `'varies'` (see
  the top of `includes/i18n.php`), or one visitor's language can be served to another.
* **Address based (a path prefix, a sub-domain, or a different address per translation).** `url`, `urls_batch` and `home_url` return those
  addresses and nothing else is needed: the address is already part of the page cache key.

A plugin can support both and switch between them in its own settings: only the three address functions change. Offer an address only for a
language in which the item has text of its own; the same text under another address is duplicate content.

## 6. qp-multilang

`content/plugins/qp-multilang/includes/ml-core-contract.php` registers the plugin with this contract (one guarded `require_once` in
`plugin.php`; nothing the plugin did before is changed, and on a core without the contract the file does nothing). It supplies the language list,
current and default language from its own functions, `?lang=xx` addresses for post types it translates and only for languages in which the post
has its own title or text (one query for a whole sitemap chunk), and the page cache variation (cookie `qp_lang`, the languages of the
Accept-Language header). `admin/dev/multilang-test.php` runs the real plugin against it.