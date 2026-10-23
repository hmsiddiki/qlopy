<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/i18n.php
// The multilingual contract of the core. Any multilingual plugin (the official qp-multilang or a third-party one) tells the core about itself
// through ONE registration call; the core, the SEO module and other plugins then ask the core, never a particular plugin.
//
//   qp_i18n_register('my-plugin', [
//       'languages'   => fn(): array   => [...],            // required: the languages this site uses (see below for accepted shapes)
//       'current'     => fn(): ?string => 'fr',             // required: language code of this request
//       'default'     => fn(): ?string => 'en',             // the site's default language
//       'url'         => fn(string $type, int $id, string $lang): ?string => '...',   // address of a post ('post') or term ('term') in a language
//       'urls_batch'  => fn(string $type, array $ids): array => [id => [lang => url]], // optional: the same for many items at once (sitemaps)
//       'home_url'    => fn(string $lang): ?string => '...',                           // the front page in a language
//       'translations'=> fn(string $type, int $id): array => [lang => id],             // optional: which languages an item exists in
//       'varies'      => ['cookies' => ['my_lang'], 'accept_language' => ['en', 'fr']],  // optional: for the page cache, see below
//   ]);
//
// 'languages' may return a list of codes (['en', 'fr']), a map code => name (['en' => 'English']), or a list of rows
// (['code' => 'fr', 'locale' => 'fr_FR', 'name' => 'French', 'native' => 'Français', 'rtl' => false, 'default' => false, 'hreflang' => 'fr']).
// Only 'languages' and 'current' are required; whatever else is missing, the features that need it simply stay quiet (no hreflang
// without addresses, no per-language sitemap entries without them).
//
// Reading (what the core and plugins call): qp_i18n_enabled(), qp_current_language(), qp_default_language(), qp_languages(),
// qp_language_info($code), qp_translation_url($type, $id, $lang), qp_translation_urls($type, $id), qp_translation_urls_batch($type, $ids),
// qp_language_home_urls().
//
// Filters the core applies for content, so a plugin can answer per language. Each is applied under its neutral name (qp_translate_*) and
// under the older ml_* name that qp-multilang already uses, so existing code keeps working:
//   qp_translate_meta    / ml_get_meta    ($value, $object_id, $meta_key, $lang)   post, term and user meta
//   qp_translate_title   / ml_the_title   ($title, $post_id, $lang)
//   qp_translate_content / ml_the_content ($content, $post_id, $lang)
// Choosing between two providers when more than one registers: the option qp_i18n_provider (a slug), or the filter qp_i18n_provider
// ($provider_or_null, $all_providers); by default the first one registered.
//
// Backward compatibility: when no provider has registered but the older qp-multilang functions exist (ml_get_current_lang(),
// ml_get_languages(), ml_get_default_lang()), the read functions use them, so nothing changes for a site that already runs it.

$GLOBALS['qp_i18n_providers'] = $GLOBALS['qp_i18n_providers'] ?? [];

function qp_i18n_register(string $slug, array $provider): void {
    if ($slug === '' || !isset($provider['languages']) || !isset($provider['current']) || !is_callable($provider['languages']) || !is_callable($provider['current'])) return;
    $GLOBALS['qp_i18n_providers'][$slug] = $provider;
    // 'varies' tells the page cache what makes one address show different languages, so one visitor's language is never served to another:
    //   'varies' => ['cookies' => ['qp_lang'], 'accept_language' => ['en', 'fr']]   (the language codes chosen from the browser's header)
    // A provider that puts the language in the address (path, sub-domain, ?lang=) needs no 'varies': the address is already part of the key.
    $v = $provider['varies'] ?? null;
    if (is_array($v)) {
        foreach ((array)($v['cookies'] ?? []) as $c) if (is_string($c) && $c !== '' && function_exists('qp_page_cache_vary_cookie')) qp_page_cache_vary_cookie($c);
        if (!empty($v['accept_language']) && function_exists('qp_page_cache_vary_accept_language')) qp_page_cache_vary_accept_language(array_values(array_map('strval', (array)$v['accept_language'])));
    }
}

/** The active provider (an array of callables) or null. */
function qp_i18n_provider(): ?array {
    $all = $GLOBALS['qp_i18n_providers'];
    if (!$all) return null;
    $chosen = null;
    if (count($all) > 1) {
        $want = function_exists('get_option_meta') ? get_option_meta('qp_i18n_provider') : null;
        if (is_string($want) && isset($all[$want])) $chosen = $all[$want];
    }
    if ($chosen === null) $chosen = reset($all);
    if (function_exists('apply_filters')) { $f = apply_filters('qp_i18n_provider', $chosen, $all); if (is_array($f)) $chosen = $f; }
    return $chosen;
}

function qp_i18n_call(string $key, ...$args) {
    $p = qp_i18n_provider();
    if (!$p || empty($p[$key]) || !is_callable($p[$key])) return null;
    try { return call_user_func_array($p[$key], $args); } catch (Throwable $e) { error_log('qp i18n provider ' . $key . ' failed: ' . $e->getMessage()); return null; }
}

function qp_i18n_enabled(): bool {
    return qp_i18n_provider() !== null || function_exists('ml_get_current_lang');
}

/** The language code of this request, or null on a single-language site. */
function qp_current_language(): ?string {
    if (qp_i18n_provider() !== null) { $c = qp_i18n_call('current'); return is_string($c) && $c !== '' ? $c : null; }
    if (function_exists('ml_get_current_lang')) { $c = ml_get_current_lang(); return is_string($c) && $c !== '' ? $c : null; }
    return null;
}

function qp_default_language(): ?string {
    if (qp_i18n_provider() !== null) { $d = qp_i18n_call('default'); if (is_string($d) && $d !== '') return $d; }
    elseif (function_exists('ml_get_default_lang')) { $d = ml_get_default_lang(); if (is_string($d) && $d !== '') return $d; }
    foreach (qp_languages() as $l) { if (!empty($l['default'])) return $l['code']; }
    return null;
}

/** Languages keyed by code, each ['code','locale','name','native','rtl','default','hreflang']. Empty on a single-language site. */
function qp_languages(): array {
    static $cache = null;
    if ($cache !== null && qp_i18n_provider() === null) return $cache;
    $raw = qp_i18n_provider() !== null ? qp_i18n_call('languages') : (function_exists('ml_get_languages') ? ml_get_languages() : []);
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $k => $row) {
        if (is_string($row) && is_string($k) && !is_numeric($k)) $row = ['code' => $k, 'name' => $row];     // code => name
        elseif (is_string($row)) $row = ['code' => $row];                                                   // list of codes
        elseif (is_array($row) && empty($row['code']) && is_string($k) && !is_numeric($k)) $row['code'] = $k; // code => row
        if (!is_array($row) || empty($row['code']) || !is_string($row['code'])) continue;
        $code = $row['code'];
        if (!preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]{2,8})*$/', $code)) continue;
        $out[$code] = [
            'code' => $code,
            'locale' => (isset($row['locale']) && trim((string)$row['locale']) !== '') ? trim((string)$row['locale']) : str_replace('-', '_', $code),
            'name' => (string)($row['name'] ?? $row['label'] ?? $code),
            'native' => (string)($row['native'] ?? $row['name'] ?? $row['label'] ?? $code),
            'rtl' => !empty($row['rtl']),
            'default' => !empty($row['default']),
            'hreflang' => (string)($row['hreflang'] ?? str_replace('_', '-', $code)),
        ];
    }
    return qp_i18n_provider() !== null ? $out : ($cache = $out);
}

function qp_language_info(string $code): ?array {
    $l = qp_languages();
    return $l[$code] ?? null;
}

/** [lang => id] of the languages an item exists in, when the provider says; otherwise every site language (the same item in each). */
function qp_translations(string $type, int $id): array {
    $t = qp_i18n_call('translations', $type, $id);
    if (is_array($t) && $t) return $t;
    return array_fill_keys(array_keys(qp_languages()), $id);
}

/** Address of an item in one language, or null when the provider cannot say. */
function qp_translation_url(string $type, int $id, string $lang): ?string {
    $u = qp_i18n_call('url', $type, $id, $lang);
    return is_string($u) && $u !== '' ? $u : null;
}

/** [lang => url] for every language the item exists in (only the languages the provider gave an address for). */
function qp_translation_urls(string $type, int $id): array {
    $b = qp_translation_urls_batch($type, [$id]);
    return $b[$id] ?? [];
}

/** [id => [lang => url]] for many items. Uses the provider's urls_batch when it has one, else one 'url' call per item and language. */
function qp_translation_urls_batch(string $type, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids || !qp_i18n_provider()) return [];
    $batch = qp_i18n_call('urls_batch', $type, $ids);
    $out = [];
    if (is_array($batch)) {
        foreach ($batch as $id => $row) if (is_array($row)) foreach ($row as $lang => $u) if (is_string($u) && $u !== '') $out[(int)$id][(string)$lang] = $u;
        if ($out) return $out;
    }
    foreach ($ids as $id) {
        foreach (array_keys(qp_translations($type, $id)) as $lang) {
            $u = qp_translation_url($type, $id, (string)$lang);
            if ($u !== null) $out[$id][$lang] = $u;
        }
    }
    return $out;
}

/** [lang => url] of the front page in each language. */
function qp_language_home_urls(): array {
    $out = [];
    foreach (array_keys(qp_languages()) as $lang) {
        $u = qp_i18n_call('home_url', $lang);
        if (is_string($u) && $u !== '') $out[$lang] = $u;
    }
    return $out;
}

/** Apply a content filter under its older ml_* name and its neutral qp_translate_* name. */
function qp_i18n_filter(string $legacy, string $neutral, $value, ...$args) {
    if (!function_exists('apply_filters')) return $value;
    $value = apply_filters($legacy, $value, ...$args);
    return apply_filters($neutral, $value, ...$args);
}
