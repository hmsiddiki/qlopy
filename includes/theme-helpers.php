<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// Theme helpers: header parsing and preview token management
if (!function_exists('parse_theme_header')) {
    function parse_theme_header($style_file_path) {
        $out = [
            'Theme Name' => '',
            'ThemeURI' => '',
            'Author' => '',
            'AuthorURI' => '',
            'Description' => '',
            'Version' => '',
            'License' => '',
            'LicenseURI' => '',
        ];
        // Determine theme directory from provided style.css path
        $dir = dirname($style_file_path);
        $functions_file = $dir . DIRECTORY_SEPARATOR . 'functions.php';

        // Prefer a header block in functions.php (plugin-style header comments)
        $head = '';
        if (file_exists($functions_file)) {
            $raw = @file_get_contents($functions_file, false, null, 0, 8192);
            if ($raw !== false) {
                // Strip leading <?php if present
                $raw_trim = ltrim($raw);
                if (strpos($raw_trim, '<?php') === 0) {
                    // find first comment block after opening PHP tag
                    $after = substr($raw_trim, 5); // remove <?php
                    if (preg_match('/\/\*([\s\S]*?)\*\//', $after, $m)) {
                        $head = $m[1];
                    } elseif (preg_match('/\/\/[^\n]*\n/', $after, $m2)) {
                        // single-line comments fallback (take first line)
                        $head = $m2[0];
                    } else {
                        // fallback: use the raw trimmed content as-is
                        $head = $after;
                    }
                } else {
                    // No opening PHP tag; treat beginning of file as potential header
                    if (preg_match('/\/\*([\s\S]*?)\*\//', $raw_trim, $m3)) {
                        $head = $m3[1];
                    } else {
                        $head = substr($raw_trim, 0, 8192);
                    }
                }

                    if (!function_exists('get_current_taxonomy')) {
                        /**
                         * Return the current taxonomy if the request resolved to an archive for a taxonomy.
                         * Falls back to $_GET['taxonomy'] or the global qp_query_vars set by parse_request().
                         */
                        function get_current_taxonomy() {
                            $g = $GLOBALS['qp_query_vars'] ?? null;
                            if (is_array($g) && !empty($g['taxonomy'])) return $g['taxonomy'];
                            if (!empty($_GET['taxonomy'])) return trim($_GET['taxonomy']);
                            return null;
                        }
                    }

                    if (!function_exists('get_current_taxonomy_slug')) {
                        /**
                         * Return the current taxonomy term slug for archive pages, or null.
                         * Prefer the global qp_query_vars copy populated by parse_request().
                         */
                        function get_current_taxonomy_slug() {
                            $g = $GLOBALS['qp_query_vars'] ?? null;
                            if (is_array($g) && !empty($g['term'])) return $g['term'];
                            if (!empty($_GET['term'])) return trim($_GET['term']);
                            return null;
                        }
                    }

                        if (!function_exists('is_taxonomy_archive')) {
                            /**
                             * Returns true when the current request is a taxonomy archive.
                             * If $taxonomy is provided, returns true only when the current taxonomy matches it.
                             */
                            function is_taxonomy_archive($taxonomy = null) {
                                $g = $GLOBALS['qp_query_vars'] ?? null;
                                $route = null;
                                $current_tax = null;
                                if (is_array($g)) {
                                    $route = $g['route'] ?? null;
                                    $current_tax = $g['taxonomy'] ?? null;
                                }
                                if ($route === null) {
                                    $route = $_GET['route'] ?? null;
                                }
                                if ($current_tax === null) {
                                    $current_tax = $_GET['taxonomy'] ?? null;
                                }
                                if ($route !== 'archive') return false;
                                if ($taxonomy === null) return !empty($current_tax);
                                return $current_tax === $taxonomy;
                            }
                        }
            }
        }

        // If no header found in functions.php, fall back to style.css parsing
        if ($head === '') {
            if (!file_exists($style_file_path)) return $out;
            $head = @file_get_contents($style_file_path, false, null, 0, 8192);
            if ($head === false) return $out;
        }
        $map = [
            'Theme Name' => '/Theme\s*Name\s*:\s*(.+)/i',
            'ThemeURI'   => '/Theme\s*URI\s*:\s*(.+)/i',
            'Author'     => '/Author\s*:\s*(.+)/i',
            // Require the literal "URI" token for the URI fields so they don't
            // accidentally match the plain Author/License lines. This avoids
            // capturing e.g. 'Author: Your Name' into AuthorURI.
            'AuthorURI'  => '/Author\s*(?:URI)\s*:\s*(.+)/i',
            'Description'=> '/Description\s*:\s*(.+)/i',
            'Version'    => '/Version\s*:\s*(.+)/i',
            'License'    => '/License\s*:\s*(.+)/i',
            'LicenseURI' => '/License\s*(?:URI)\s*:\s*(.+)/i',
        ];
        foreach ($map as $key => $regex) {
            if (preg_match($regex, $head, $m)) {
                // For patterns with optional groups, pick last captured group
                $val = end($m);
                $out[$key] = trim($val);
            }
        }
        return $out;
    }
}

/* Shortcode API moved to `auth.php` so both frontend and admin have access. */

?>