<?php
// Settings > SEO (core upgrade plan C3, step 1): titles, descriptions, canonical, robots, Open Graph and Twitter tags.
require_once __DIR__ . '/admin_head.php';
require_once __DIR__ . '/../includes/seo.php';
require_once __DIR__ . '/../includes/seo-schema.php';      // the advanced tier's code is needed here even while it is switched off
require_once __DIR__ . '/../includes/seo-redirects.php';
$page_title = 'SEO';

if (!current_user_can('manage_options')) {
    http_response_code(403);
    require_once __DIR__ . '/inc/header.php';
    require_once __DIR__ . '/inc/navbar.php';
    echo '<div class="container"><h1>Permission Denied</h1><p>You do not have permission to access this admin page.</p></div>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

$stored = get_option_meta('qp_seo_settings');
$s = array_merge(qp_seo_defaults(), is_array($stored) ? $stored : []);

$tab = in_array($_GET['tab'] ?? 'settings', ['settings', 'redirects', '404'], true) ? ($_GET['tab'] ?? 'settings') : 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seo_action'])) {
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($_POST['seo_nonce'] ?? '', 'seo_settings')) { qp_set_admin_notice('Invalid request (bad nonce)'); header('Location: seo.php'); exit; }
    qp_seo_sitemap_dir();
    $act = (string)$_POST['seo_action'];
    if ($act === 'add_redirect') {
        $r = qp_seo_redirect_add((string)($_POST['source'] ?? ''), (string)($_POST['target'] ?? ''), (int)($_POST['code'] ?? 301), (string)($_POST['match_type'] ?? 'exact'), ['keep_query' => !empty($_POST['keep_query']), 'note' => (string)($_POST['note'] ?? '')]);
        qp_set_admin_notice($r['ok'] ? 'Redirect saved.' : 'Redirect not saved: ' . $r['error']);
    } elseif ($act === 'toggle_redirect') { qp_seo_redirect_set_enabled((int)$_POST['id'], !empty($_POST['enable'])); qp_set_admin_notice('Redirect updated.'); }
    elseif ($act === 'delete_redirect') { qp_seo_redirect_delete((int)$_POST['id']); qp_set_admin_notice('Redirect deleted.'); }
    elseif ($act === 'clear_404') { qp_seo_404_clear(); qp_set_admin_notice('The 404 log was cleared.'); }
    header('Location: seo.php?tab=' . ($act === 'clear_404' ? '404' : 'redirects')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_seo'])) {
    if (!function_exists('qp_admin_verify_nonce') || !qp_admin_verify_nonce($_POST['seo_nonce'] ?? '', 'seo_settings')) {
        qp_set_admin_notice('Invalid request (bad nonce)');
        header('Location: seo.php'); exit;
    }
    $txt = function ($k, $max = 200) { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); };
    foreach (['title_home', 'title_singular', 'title_term', 'title_archive', 'title_search', 'title_404', 'title_paged'] as $k) $s[$k] = $txt($k);
    $s['description'] = $txt('description', 300);
    $s['og_image'] = $txt('og_image', 500);
    $s['twitter_card'] = in_array($_POST['twitter_card'] ?? '', ['summary', 'summary_large_image'], true) ? $_POST['twitter_card'] : 'summary_large_image';
    $tw = $txt('twitter_site', 60); $s['twitter_site'] = $tw === '' ? '' : '@' . ltrim($tw, '@');
    foreach (['noindex_search', 'noindex_404', 'noindex_terms_paged', 'respect_theme', 'schema'] as $k) $s[$k] = !empty($_POST[$k]);
    $s['org_type'] = ($_POST['org_type'] ?? '') === 'Person' ? 'Person' : 'Organization';
    $s['org_name'] = $txt('org_name', 120);
    $s['org_logo'] = $txt('org_logo', 500);
    $s['org_same_as'] = mb_substr(trim((string)($_POST['org_same_as'] ?? '')), 0, 2000);
    $s['language'] = preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $txt('language', 20)) ? $txt('language', 20) : 'en-US';
    $s['breadcrumb_home'] = $txt('breadcrumb_home', 60) ?: 'Home';
    $s['sitemap'] = !empty($_POST['sitemap']);
    $s['sitemap_images'] = !empty($_POST['sitemap_images']);
    $s['sitemap_terms'] = !empty($_POST['sitemap_terms']);
    $allTypes = function_exists('get_post_types') ? array_keys(get_post_types()) : ['post', 'page'];
    $s['sitemap_post_types'] = array_values(array_intersect(array_unique(array_merge(['post', 'page'], $allTypes)), array_map('strval', (array)($_POST['sitemap_post_types'] ?? []))));
    $s['robots_block_all'] = !empty($_POST['robots_block_all']);
    $s['robots_extra'] = mb_substr(trim((string)($_POST['robots_extra'] ?? '')), 0, 2000);
    update_option_meta('qp_seo_settings', $s);
    if ($s['sitemap']) { require_once __DIR__ . '/../includes/schema.php'; qp_schema_add_posts_type_status_index(); }
    if (function_exists('qp_seo_sitemap_purge')) qp_seo_sitemap_purge();
    update_option_meta('qp_seo_enabled', !empty($_POST['qp_seo_enabled']));
    qp_seo_sitemap_dir();   // the folder's existence is what makes every entry point load the purge and slug listeners
    qp_seo_redirects_install();
    if (!empty($_POST['qp_seo_enabled']) && function_exists('qp_schedule_single_event') && function_exists('qp_next_scheduled') && qp_next_scheduled('qp_seo_404_prune') === null) qp_schedule_single_event(time() + 3600, 'qp_seo_404_prune', [], 86400);
    // The page cache must not keep pages built with the old tags.
    if (function_exists('qp_page_cache_purge_all')) qp_page_cache_purge_all();
    qp_set_admin_notice('SEO settings saved.');
    header('Location: seo.php'); exit;
}

$notice = function_exists('qp_get_admin_notice') ? qp_get_admin_notice() : null;
$enabled = qp_seo_enabled();
$theme_tags = qp_seo_theme_tags();
$seo_nonce = function_exists('qp_admin_create_nonce') ? qp_admin_create_nonce('seo_settings') : '';
require_once __DIR__ . '/inc/header.php';
$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$names = ['canonical' => 'canonical link', 'description' => 'meta description', 'robots' => 'robots meta', 'og' => 'Open Graph tags', 'twitter' => 'Twitter card tags', 'jsonld' => 'structured data (JSON-LD)'];
$printed = array_keys(array_filter($theme_tags));
?>
<div class="container mt-4">
  <h2>SEO</h2>
  <?php if ($notice): ?><div class="alert alert-info"><?= $h($notice) ?></div><?php endif; ?>
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link<?= $tab === 'settings' ? ' active' : '' ?>" href="seo.php">Settings</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === 'redirects' ? ' active' : '' ?>" href="seo.php?tab=redirects">Redirects</a></li>
    <li class="nav-item"><a class="nav-link<?= $tab === '404' ? ' active' : '' ?>" href="seo.php?tab=404">404 log</a></li>
  </ul>
  <?php if ($tab === 'redirects'): ?>
    <?php $rules = qp_seo_redirect_list(); $prefill = (string)($_GET['source'] ?? ''); ?>
    <p class="small text-muted">Send visitors and search engines from an old address to a new one. Addresses are relative to the site (<code>/old-page</code>), case does not matter, a trailing slash does not matter. A rule goes one step only: point it at the final address. Rules are added automatically when you change a post's address.</p>
    <form method="post" class="mb-4">
      <input type="hidden" name="seo_nonce" value="<?= $h($seo_nonce) ?>"><input type="hidden" name="seo_action" value="add_redirect">
      <div class="form-row">
        <div class="form-group col-md-3"><label for="source">Old address</label><input class="form-control" type="text" id="source" name="source" value="<?= $h($prefill) ?>" placeholder="/old-page" required></div>
        <div class="form-group col-md-2"><label for="match_type">Match</label><select class="form-control" id="match_type" name="match_type"><option value="exact">exactly</option><option value="prefix">everything under it</option><option value="regex">pattern (regex)</option></select></div>
        <div class="form-group col-md-4"><label for="target">New address</label><input class="form-control" type="text" id="target" name="target" placeholder="/new-page or https://example.com/page"></div>
        <div class="form-group col-md-3"><label for="code">Type</label><select class="form-control" id="code" name="code"><option value="301">301 moved for good</option><option value="302">302 moved for now</option><option value="307">307 temporary (keeps method)</option><option value="308">308 permanent (keeps method)</option><option value="410">410 gone (no new address)</option></select></div>
      </div>
      <div class="form-row"><div class="form-group col-md-6"><input class="form-control" type="text" name="note" placeholder="Note (optional)"></div>
        <div class="col-md-4 pt-2"><label class="small"><input type="checkbox" name="keep_query" value="1" checked> keep the query string</label></div>
        <div class="col-md-2"><button class="btn btn-primary" type="submit">Add redirect</button></div></div>
    </form>
    <table class="table table-sm"><thead><tr><th>Old address</th><th>Match</th><th>New address</th><th>Type</th><th>Hits</th><th>Note</th><th></th></tr></thead><tbody>
    <?php foreach ($rules as $r): ?>
      <tr<?= $r['enabled'] ? '' : ' class="text-muted"' ?>><td><code><?= $h($r['source']) ?></code></td><td><?= $h($r['match_type']) ?></td><td><?= $r['code'] == 410 ? '<em>gone</em>' : $h($r['target']) ?></td><td><?= (int)$r['code'] ?></td><td><?= (int)$r['hits'] ?></td><td class="small"><?= $h($r['note']) ?><?= $r['auto'] ? ' <span class="badge badge-secondary">automatic</span>' : '' ?></td>
        <td class="text-nowrap"><form method="post" class="d-inline"><input type="hidden" name="seo_nonce" value="<?= $h($seo_nonce) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="seo_action" value="toggle_redirect"><input type="hidden" name="enable" value="<?= $r['enabled'] ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-secondary"><?= $r['enabled'] ? 'Turn off' : 'Turn on' ?></button></form>
          <form method="post" class="d-inline"><input type="hidden" name="seo_nonce" value="<?= $h($seo_nonce) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="seo_action" value="delete_redirect"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr>
    <?php endforeach; if (!$rules): ?><tr><td colspan="7" class="text-muted">No redirects yet.</td></tr><?php endif; ?>
    </tbody></table>
  <?php elseif ($tab === '404'): ?>
    <?php $log = qp_seo_404_top(200); ?>
    <p class="small text-muted">Addresses on this site that visitors or search engines asked for and did not find, most requested first. At most 1000 are kept and entries not seen for 30 days are removed. Turn a popular one into a redirect.</p>
    <table class="table table-sm"><thead><tr><th>Address</th><th>Times</th><th>Last seen</th><th>Came from</th><th></th></tr></thead><tbody>
    <?php foreach ($log as $l): ?>
      <tr><td><code><?= $h($l['path']) ?></code></td><td><?= (int)$l['hits'] ?></td><td class="small"><?= $h(format_site_datetime((int)$l['last_seen'])) ?></td><td class="small"><?= $h(mb_substr($l['referrer'], 0, 60)) ?></td><td><a class="btn btn-sm btn-outline-primary" href="seo.php?tab=redirects&amp;source=<?= $h(rawurlencode($l['path'])) ?>">Redirect</a></td></tr>
    <?php endforeach; if (!$log): ?><tr><td colspan="5" class="text-muted">Nothing logged. (Only requests made while the SEO module is on are counted.)</td></tr><?php endif; ?>
    </tbody></table>
    <form method="post"><input type="hidden" name="seo_nonce" value="<?= $h($seo_nonce) ?>"><input type="hidden" name="seo_action" value="clear_404"><button class="btn btn-outline-danger btn-sm">Clear the log</button></form>
  <?php endif; ?>
  <?php if ($tab === 'settings'):
    $seo_host = strtolower((string)parse_url(qp_seo_site_url(), PHP_URL_HOST));
    $looks_dev = $seo_host === 'localhost' || filter_var($seo_host, FILTER_VALIDATE_IP) !== false
        || (bool)preg_match('/(\.(local|localhost|test|dev|internal|lan)|^(dev|staging|stage|test|demo|preview)\.)/', $seo_host);
    if ($looks_dev && empty($s['robots_block_all'])): ?>
  <div class="alert alert-warning">This site's address (<code><?= $h($seo_host) ?></code>) looks like a development or staging site, and search engines are <strong>not</strong> discouraged. If it is reachable from the internet it can be indexed. Tick &ldquo;Discourage search engines from indexing this site&rdquo; below, and untick it when you go live.</div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="seo_nonce" value="<?= $h($seo_nonce) ?>">

    <div class="form-group">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qp_seo_enabled" name="qp_seo_enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
        <label class="form-check-label" for="qp_seo_enabled"><strong>Advanced SEO</strong> (off by default)</label>
      </div>
      <small class="form-text text-muted">Basic SEO is always on: description, canonical link, robots rules, Open Graph and Twitter tags, <code>sitemap.xml</code> and <code>robots.txt</code>. Advanced SEO adds structured data (JSON-LD), the redirect manager and 404 log, language alternates and the SEO box in the editor. Every address uses the site address from Settings, never the host a visitor arrived on.</small>
    </div>

    <div class="form-group">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="respect_theme" name="respect_theme" value="1"<?= $s['respect_theme'] ? ' checked' : '' ?>>
        <label class="form-check-label" for="respect_theme">Do not repeat tags the active theme already prints</label>
      </div>
      <small class="form-text text-muted">
        Found in the active theme: <strong><?= $printed ? $h(implode(', ', array_map(function ($k) use ($names) { return $names[$k]; }, $printed))) : 'nothing' ?></strong>.
        <?= $printed ? 'With this box ticked those tags are left to the theme, so nothing appears twice. Untick it to let this module print them (and remove them from the theme).' : '' ?>
      </small>
    </div>

    <h5 class="mt-4">Page titles</h5>
    <p class="small text-muted">Placeholders: <code>%title%</code> the page or post title, <code>%site%</code> the site name, <code>%tagline%</code> the tagline, <code>%term%</code> category or tag name, <code>%query%</code> the search words, <code>%page%</code> the page number. A title set on one post overrides its template. Themes show the result by printing <code>qp_seo_title()</code> in their <code>&lt;title&gt;</code>.</p>
    <?php foreach (['title_home' => 'Home page', 'title_singular' => 'Posts and pages', 'title_term' => 'Category and tag pages', 'title_archive' => 'Post type archives', 'title_search' => 'Search results', 'title_404' => 'Not found page', 'title_paged' => 'Added to page 2 and later'] as $k => $label): ?>
      <div class="form-group row">
        <label class="col-sm-3 col-form-label" for="<?= $k ?>"><?= $h($label) ?></label>
        <div class="col-sm-9"><input class="form-control" type="text" id="<?= $k ?>" name="<?= $k ?>" value="<?= $h($s[$k]) ?>"></div>
      </div>
    <?php endforeach; ?>

    <h5 class="mt-4">Defaults</h5>
    <div class="form-group">
      <label for="description">Site description (used when a page has no text of its own)</label>
      <textarea class="form-control" id="description" name="description" rows="2" maxlength="300"><?= $h($s['description']) ?></textarea>
    </div>
    <div class="form-group">
      <label for="og_image">Default sharing image (address, or a media id)</label>
      <input class="form-control" type="text" id="og_image" name="og_image" value="<?= $h($s['og_image']) ?>">
      <small class="form-text text-muted">Used for social cards when a post has no image of its own. A post's own sharing image, then its featured image, then the first image in its text are tried first.</small>
    </div>
    <div class="form-row">
      <div class="form-group col-md-4">
        <label for="twitter_card">Twitter card</label>
        <select class="form-control" id="twitter_card" name="twitter_card">
          <option value="summary_large_image"<?= $s['twitter_card'] === 'summary_large_image' ? ' selected' : '' ?>>Large image</option>
          <option value="summary"<?= $s['twitter_card'] === 'summary' ? ' selected' : '' ?>>Small image</option>
        </select>
      </div>
      <div class="form-group col-md-4">
        <label for="twitter_site">Twitter account</label>
        <input class="form-control" type="text" id="twitter_site" name="twitter_site" value="<?= $h($s['twitter_site']) ?>" placeholder="@yoursite">
      </div>
    </div>

    <h5 class="mt-4">Structured data and breadcrumbs</h5>
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" id="schema" name="schema" value="1"<?= $s['schema'] ? ' checked' : '' ?>>
      <label class="form-check-label" for="schema">Print structured data (JSON-LD) that tells search engines what each page is: the site, who publishes it, the page, the article and its breadcrumb trail</label>
    </div>
    <div class="form-row">
      <div class="form-group col-md-3"><label for="org_type">The site is published by</label>
        <select class="form-control" id="org_type" name="org_type"><option value="Organization"<?= $s['org_type'] === 'Organization' ? ' selected' : '' ?>>An organization</option><option value="Person"<?= $s['org_type'] === 'Person' ? ' selected' : '' ?>>A person</option></select></div>
      <div class="form-group col-md-5"><label for="org_name">Name (empty: the site name)</label><input class="form-control" type="text" id="org_name" name="org_name" value="<?= $h($s['org_name']) ?>"></div>
      <div class="form-group col-md-4"><label for="language">Language code</label><input class="form-control" type="text" id="language" name="language" value="<?= $h($s['language']) ?>" placeholder="en-US"></div>
    </div>
    <div class="form-group"><label for="org_logo">Logo (address, or a media id)</label><input class="form-control" type="text" id="org_logo" name="org_logo" value="<?= $h($s['org_logo']) ?>"></div>
    <div class="form-group"><label for="org_same_as">Other profiles of the publisher (one address per line)</label><textarea class="form-control" id="org_same_as" name="org_same_as" rows="3"><?= $h($s['org_same_as']) ?></textarea></div>
    <div class="form-group"><label for="breadcrumb_home">First breadcrumb</label><input class="form-control" type="text" id="breadcrumb_home" name="breadcrumb_home" value="<?= $h($s['breadcrumb_home']) ?>" style="max-width:240px"><small class="form-text text-muted">Themes show the trail with <code>qp_seo_breadcrumbs_html()</code>.</small></div>

    <h5 class="mt-4">Sitemap and robots.txt</h5>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="sitemap" name="sitemap" value="1"<?= $s['sitemap'] ? ' checked' : '' ?>><label class="form-check-label" for="sitemap">Publish a sitemap at <code><?= $h(qp_seo_site_url('/sitemap.xml')) ?></code> (pages are built once and kept; they are rebuilt after content changes)</label></div>
    <div class="form-group mt-2"><span class="small">Content types in the sitemap:</span>
      <?php $allT = function_exists('get_post_types') ? array_keys(get_post_types()) : []; foreach (array_values(array_unique(array_merge(['post', 'page'], $allT))) as $t): ?>
        <label class="mr-3 small"><input type="checkbox" name="sitemap_post_types[]" value="<?= $h($t) ?>"<?= in_array($t, $s['sitemap_post_types'], true) ? ' checked' : '' ?>> <?= $h($t) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="sitemap_images" name="sitemap_images" value="1"<?= $s['sitemap_images'] ? ' checked' : '' ?>><label class="form-check-label" for="sitemap_images">List up to 5 images per page (more work when the sitemap is built)</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="sitemap_terms" name="sitemap_terms" value="1"<?= $s['sitemap_terms'] ? ' checked' : '' ?>><label class="form-check-label" for="sitemap_terms">Include category and tag pages</label></div>
    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="robots_block_all" name="robots_block_all" value="1"<?= $s['robots_block_all'] ? ' checked' : '' ?>><label class="form-check-label" for="robots_block_all"><strong>Discourage search engines from indexing this site</strong> (adds noindex to every page, blocks everything in <code>robots.txt</code> and turns the sitemap off; for a site under construction)</label></div>
    <div class="form-group mt-2"><label for="robots_extra">Extra lines for <code>robots.txt</code></label><textarea class="form-control" id="robots_extra" name="robots_extra" rows="3"><?= $h($s['robots_extra']) ?></textarea><small class="form-text text-muted"><code><?= $h(qp_seo_site_url('/robots.txt')) ?></code> is built from these settings. If a real <code>robots.txt</code> file exists in the site folder, the web server serves that file and this is ignored.</small></div>

    <h5 class="mt-4">Search engines</h5>
    <?php foreach (['noindex_search' => 'Ask search engines not to index search result pages', 'noindex_404' => 'Ask search engines not to index the not found page', 'noindex_terms_paged' => 'Ask search engines not to index category and tag pages after page 1'] as $k => $label): ?>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="<?= $k ?>" name="<?= $k ?>" value="1"<?= $s[$k] ? ' checked' : '' ?>>
        <label class="form-check-label" for="<?= $k ?>"><?= $h($label) ?></label>
      </div>
    <?php endforeach; ?>
    <p class="small text-muted mt-2">Posts that are private, password protected or not published are always marked noindex. A post can also be marked noindex or nofollow on its own (post setting <code>_seo_robots</code>).</p>

    <button class="btn btn-primary mt-3" type="submit" name="save_seo" value="1">Save SEO settings</button>
  </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/footer.php'; exit; ?>
