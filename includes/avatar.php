<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// Avatar helpers: site default avatar, per-user avatar, and get_avatar() API.

if (!function_exists('qp_default_avatar_url')) {
    function qp_default_avatar_url(): string {
        if (function_exists('get_option_meta')) {
            $ds = get_option_meta('discussion_settings') ?? [];
            if (!empty($ds['default_avatar_path'])) {
                $path = $ds['default_avatar_path'];
                // if path begins with /uploads or uploads, make absolute URL
                if (strpos($path, '/uploads') === 0 || strpos($path, 'uploads/') === 0) {
                    if (function_exists('qp_uploads_url_base')) return rtrim(qp_uploads_url_base(), '/') . '/' . ltrim(str_replace('uploads/', '', $path), '/');
                }
            }
        }
        // fallback to uploads/default-avatar.png
        if (function_exists('qp_uploads_url_base')) {
            return rtrim(qp_uploads_url_base(), '/') . '/default-avatar.png';
        }
        // last resort: empty string
        return '';
    }
}

if (!function_exists('qp_user_avatar_url')) {
    function qp_user_avatar_url(int $user_id, int $size = 96): ?string {
        if (function_exists('get_user_meta')) {
            // Prefer attachment id saved in 'avatar_attachment_id'
            $aid = get_user_meta($user_id, 'avatar_attachment_id');
            if (!empty($aid) && function_exists('qp_get_attachment_image_src')) {
                $src = qp_get_attachment_image_src((int)$aid, 'full');
                if ($src) return $src[0];
            }
            // Backwards-compatible: allow per-user avatar via user meta 'avatar' (store '/uploads/...' path)
            $av = get_user_meta($user_id, 'avatar');
            if (!empty($av)) {
                // if relative uploads path, build absolute
                if (strpos($av, '/uploads') === 0 || strpos($av, 'uploads/') === 0) {
                    if (function_exists('qp_uploads_url_base')) return rtrim(qp_uploads_url_base(), '/') . '/' . ltrim(str_replace('uploads/', '', $av), '/');
                }
                return $av;
            }
        }
        // If no per-user avatar found, return the site default avatar URL
        if (function_exists('qp_default_avatar_url')) return qp_default_avatar_url();
        return null;
    }
}

if (!function_exists('get_avatar')) {
    function get_avatar($id_or_email, $size = 96, $default = null, $alt = '', $attrs = []) {
         $user = null;
        $user_id = null;
        if (is_int($id_or_email) || (is_string($id_or_email) && ctype_digit($id_or_email))) {
            $user = function_exists('get_user_by_id') ? get_user_by_id((int)$id_or_email) : null;
        } elseif (is_array($id_or_email) && !empty($id_or_email['comment_author_email'])) {
            $user = function_exists('get_user_by_email') ? get_user_by_email($id_or_email['comment_author_email']) : null;
        } elseif (is_string($id_or_email) && strpos($id_or_email, '@') !== false) {
            $user = function_exists('get_user_by_email') ? get_user_by_email($id_or_email) : null;
        }

         // If we resolved a user, prefer their per-user avatar
        if (!empty($user) && is_array($user)) {
            $uid = (int)($user['raw']['id'] ?? $user['ID'] ?? 0);
            if ($uid > 0) {
                $user_id = $uid;
            }
        }

        // 1. per-user avatar
        if ($user_id !== null) {
            $uav = qp_user_avatar_url($user_id, $size);
            if ($uav) {
                // handle optional attributes (class and others)
                $class = 'avatar';
                $other = '';
                if (is_array($attrs)) {
                    if (!empty($attrs['class'])) {
                        $class .= ' ' . $attrs['class'];
                        unset($attrs['class']);
                    }
                    foreach ($attrs as $k => $v) {
                        $other .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
                    }
                }
                $html = '<img src="' . htmlspecialchars($uav) . '" alt="' . htmlspecialchars($alt) . '" width="' . (int)$size . '" height="' . (int)$size . '" class="' . htmlspecialchars($class) . '"' . $other . '>';
                return function_exists('apply_filters') ? apply_filters('get_avatar', $html, $id_or_email, $size, $default) : $html;
            }
        }

        // 2. No Gravatar lookup — fall through to site default when no per-user avatar

        // 3. site default
        $default_url = $default ?: qp_default_avatar_url();
        // handle optional attributes for default avatar
        $class = 'avatar';
        $other = '';
        if (is_array($attrs)) {
            if (!empty($attrs['class'])) {
                $class .= ' ' . $attrs['class'];
                unset($attrs['class']);
            }
            foreach ($attrs as $k => $v) {
                $other .= ' ' . htmlspecialchars($k) . '="' . htmlspecialchars($v) . '"';
            }
        }
        $html = '<img src="' . htmlspecialchars($default_url) . '" alt="' . htmlspecialchars($alt) . '" width="' . (int)$size . '" height="' . (int)$size . '" class="' . htmlspecialchars($class) . '"' . $other . '>';
        return function_exists('apply_filters') ? apply_filters('get_avatar', $html, $id_or_email, $size, $default) : $html;
    }
}

if (!function_exists('get_avatar_url')) {
    function get_avatar_url($id_or_email, $size = 96, $default = null) {
        // Determine user by id or email using core helpers
        $user = null;
        if (is_int($id_or_email) || (is_string($id_or_email) && ctype_digit($id_or_email))) {
            $user = function_exists('get_user_by_id') ? get_user_by_id((int)$id_or_email) : null;
        } elseif (is_array($id_or_email) && !empty($id_or_email['comment_author_email'])) {
            $user = function_exists('get_user_by_email') ? get_user_by_email($id_or_email['comment_author_email']) : null;
        } elseif (is_string($id_or_email) && strpos($id_or_email, '@') !== false) {
            $user = function_exists('get_user_by_email') ? get_user_by_email($id_or_email) : null;
        }

        // If we resolved a user, prefer their per-user avatar
        if (!empty($user) && is_array($user)) {
            $uid = (int)($user['raw']['id'] ?? $user['ID'] ?? 0);
            if ($uid > 0) {
                $uav = qp_user_avatar_url($uid, $size);
                if (!empty($uav)) return $uav;
            }
        }

        // Fallback: use caller-provided default then system default
        if (!empty($default)) return $default;
        if (function_exists('qp_default_avatar_url')) return qp_default_avatar_url();
        return '';
    }
}

?>