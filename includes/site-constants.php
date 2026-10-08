<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/site-constants.php
// Site-specific constants for updater and other global values.
// This file is intended to be edited by site owners and should not be
// overwritten by the updater. Keep sensitive values out of VCS if needed.

// Core/version information (bump with releases)
if (!defined('QLOPY_VERSION')) {
    define('QLOPY_VERSION', '1.3.0');
}

if (!defined('QLOPY_CORE_UPDATE_URL')) {
    define('QLOPY_CORE_UPDATE_URL', 'https://updates.qlopy.com/core/qlopy.updater.json');
}

// Add any other site-level constants here (for example override paths,
// feature flags, etc.). Prefer this file for values the updater should not
// modify during automated update operations.
