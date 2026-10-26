<?php
if (!defined('QLOPY_INIT')) { http_response_code(403); exit; }
// includes/transfer-users.php
// The "users" entity of the import and export engine (core upgrade plan C8) and the helpers that find an existing account by email, phone or both.
// Loaded by includes/transfer.php, so it costs nothing until the engine is used.
//
// What an import will and will not do:
//   - It creates accounts with NO usable password (a random one is set and never shown). A customer sets a password with "forgot password".
//   - Only a role that has no capabilities at all can be given (the built-in "subscriber", or a shop's customer role). A privileged role in the
//     file is refused and reported; there is no setting that allows it.
//   - An account that already exists is never changed except to FILL a gap: a missing name or phone, or an email that was only a placeholder.
//     The username, the role and the password are never touched.
//   - An account with only a phone number gets a placeholder email (name@noemail.invalid), because the users table needs a unique email. It is
//     marked, never shown as an email, and replaced when the real one arrives.
//   - Export never contains a password, a hash or a token.

const QP_TRANSFER_PLACEHOLDER_DOMAIN = 'noemail.invalid';

/** The part of a phone number used to compare two of them: digits only, the last 10 when there are at least 10, '' when it is too short to trust. */
function qp_transfer_phone_key($phone): string {
    $d = preg_replace('/\D+/', '', (string)$phone);
    if ($d === '') return '';
    if (strpos($d, '00') === 0) $d = substr($d, 2);
    if (strlen($d) < 7) return '';
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

/** Every account's phone key => list of user ids, read once per request from the places a phone is kept (the import's own key, the core field, a shop's saved details). */
function &qp_transfer_user_phone_store(): array {
    static $map = null;
    if ($map === null) {
        $map = [];
        $st = db()->query('SELECT user_id, meta_key, meta_value FROM ' . table_name('user_meta') . " WHERE meta_key IN ('phone_key','billing_phone','qcommerce_addresses')");
        foreach ($st as $r) {
            $v = function_exists('maybe_unserialize') ? maybe_unserialize($r['meta_value']) : $r['meta_value'];     // user meta is stored the way the core's update_user_meta wrote it
            if ($r['meta_key'] === 'qcommerce_addresses') $phone = is_array($v) ? (string)($v['customer']['phone'] ?? '') : '';
            else $phone = is_scalar($v) ? (string)$v : '';
            $k = $r['meta_key'] === 'phone_key' ? $phone : qp_transfer_phone_key($phone);
            if ($k !== '') $map[$k][(int)$r['user_id']] = true;
        }
        foreach ($map as $k => $ids) $map[$k] = array_keys($ids);
    }
    return $map;
}

/** Tell the map about a phone number that was just saved, so a later row of the same run finds the account. */
function qp_transfer_user_phone_add(int $userId, string $phone): void {
    $k = qp_transfer_phone_key($phone);
    if ($k === '') return;
    $map = &qp_transfer_user_phone_store();
    if (!in_array($userId, $map[$k] ?? [], true)) $map[$k][] = $userId;
}
/**
 * Find the account a row stands for. $match: email | phone | either | both.
 *   either: an account with this email or this phone (when they point at two different accounts that is a conflict)
 *   both:   the email and the phone must agree on one account; one without the other is a conflict (a row with only one of them is matched on it)
 * Returns [user id or null, conflict message or null].
 */
function qp_transfer_user_find(string $email, string $phone, string $match = 'either'): array {
    $email = strtolower(trim($email));
    $key = qp_transfer_phone_key($phone);
    $byEmail = null;
    if ($email !== '') {
        $st = db()->prepare('SELECT id FROM ' . table_name('users') . ' WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $id = $st->fetchColumn();
        $byEmail = $id ? (int)$id : null;
    }
    $byPhone = null; $ambiguous = false;
    if ($key !== '') {
        $map = qp_transfer_user_phone_store();
        $ids = $map[$key] ?? [];
        if (count($ids) === 1) $byPhone = (int)$ids[0];
        elseif (count($ids) > 1) $ambiguous = true;
    }
    $both = $email !== '' && $key !== '';
    if ($match === 'email') return [$byEmail, null];
    if ($match === 'phone') return $ambiguous ? [null, 'More than one account has this phone number'] : [$byPhone, null];
    if ($match === 'both' && $both) {
        if ($byEmail !== null && $byPhone !== null) return $byEmail === $byPhone ? [$byEmail, null] : [null, 'The email and the phone number belong to two different accounts'];
        if ($ambiguous) return [null, 'More than one account has this phone number'];
        if ($byEmail !== null || $byPhone !== null) return [null, 'Only the ' . ($byEmail !== null ? 'email' : 'phone number') . ' matches an account; the other does not'];
        return [null, null];
    }
    // either (and "both" for a row that has only one of the two)
    if ($byEmail !== null && $byPhone !== null && $byEmail !== $byPhone) return [null, 'The email and the phone number belong to two different accounts'];
    if ($byEmail === null && $ambiguous) return [null, 'More than one account has this phone number'];
    return [$byEmail ?? $byPhone, null];
}

function qp_transfer_user_is_placeholder(string $email): bool {
    return substr(strtolower($email), -strlen('@' . QP_TRANSFER_PLACEHOLDER_DOMAIN)) === '@' . QP_TRANSFER_PLACEHOLDER_DOMAIN;
}

/** Roles that may be given by an import: registered roles that hold no capability at all. */
function qp_transfer_safe_roles(): array {
    $out = [];
    foreach ((array)($GLOBALS['qlopy_roles'] ?? []) as $role => $caps) if (!$caps) $out[] = (string)$role;
    return $out ?: ['subscriber'];
}

function qp_transfer_entity_users(): array {
    $safe = qp_transfer_safe_roles();
    return [
        'label' => 'Users and customers (no passwords)',
        'capability' => 'manage_users',
        'fields' => [
            'ref' => ['label' => 'Original id (optional)', 'type' => 'string', 'max' => 100],
            'username' => ['label' => 'Username (made from the email or phone when empty)', 'type' => 'string', 'max' => 50],
            'email' => ['label' => 'Email', 'type' => 'string', 'max' => 100],
            'phone' => ['label' => 'Phone', 'type' => 'string', 'max' => 50],
            'first_name' => ['label' => 'First name', 'type' => 'string', 'max' => 100],
            'last_name' => ['label' => 'Last name', 'type' => 'string', 'max' => 100],
            'display_name' => ['label' => 'Display name', 'type' => 'string', 'max' => 100],
            'role' => ['label' => 'Role (only a role without capabilities: ' . implode(', ', $safe) . ')', 'type' => 'string', 'max' => 50],
            'address' => ['label' => 'Street address', 'type' => 'string', 'max' => 191],
            'city' => ['label' => 'City', 'type' => 'string', 'max' => 100],
            'postcode' => ['label' => 'Postcode', 'type' => 'string', 'max' => 32],
            'country' => ['label' => 'Country (two letters)', 'type' => 'string', 'max' => 2],
        ],
        'options' => [
            'match' => [
                'label' => 'Recognise an account that already exists by',
                'default' => 'either',
                'choices' => ['email' => 'Email', 'phone' => 'Phone number', 'either' => 'Email or phone number', 'both' => 'Email and phone number together'],
                'help' => '"Email or phone number": either one finds the account (two different accounts is refused). "Email and phone number together": a row that has both must match one account on both; a row with only one of them is matched on it. Phone numbers are compared by their last 10 digits, so +880 1711 111111 and 01711-111111 are the same.',
            ],
        ],
        'key' => function (array $row): string {
            foreach (['ref', 'email'] as $f) if (($row[$f] ?? '') !== '') return strtolower((string)$row[$f]);
            $k = qp_transfer_phone_key($row['phone'] ?? '');
            return $k !== '' ? 'phone:' . $k : (string)($row['username'] ?? '');
        },
        'find' => function (array $row, array $info = []) {
            $match = (string)($info['options']['match'] ?? 'either');
            [$id, $conflict] = qp_transfer_user_find((string)($row['email'] ?? ''), (string)($row['phone'] ?? ''), $match);
            return $conflict !== null ? ['reject' => $conflict] : $id;
        },
        'import_row' => function (array $row, array $ctx) use ($safe) {
            $email = strtolower(trim((string)($row['email'] ?? '')));
            $phone = trim((string)($row['phone'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) return ['result' => 'rejected', 'reason' => 'The email is not valid'];
            if ($email === '' && qp_transfer_phone_key($phone) === '') return ['result' => 'rejected', 'reason' => 'A user needs an email or a phone number (at least 7 digits)'];
            $role = strtolower(trim((string)($row['role'] ?? ''))) ?: (in_array('customer', $safe, true) ? 'customer' : 'subscriber');
            if (!in_array($role, $safe, true)) return ['result' => 'rejected', 'reason' => 'The role "' . $role . '" is not allowed in an import (only a role without capabilities: ' . implode(', ', $safe) . ')'];
            $pdo = db(); $users = table_name('users');
            $local = $ctx['local_id'];

            if ($local !== null) {
                // an account that already exists: only gaps are filled
                $changed = false;
                $u = $pdo->prepare("SELECT email FROM {$users} WHERE id = ?");
                $u->execute([$local]);
                $currentEmail = (string)$u->fetchColumn();
                if ($ctx['dry']) return ['result' => 'updated', 'id' => (int)$local];
                if ($email !== '' && qp_transfer_user_is_placeholder($currentEmail)) {
                    $taken = $pdo->prepare("SELECT 1 FROM {$users} WHERE email = ? AND id <> ? LIMIT 1");
                    $taken->execute([$email, $local]);
                    if (!$taken->fetchColumn()) { $pdo->prepare("UPDATE {$users} SET email = ? WHERE id = ?")->execute([$email, $local]); delete_user_meta($local, 'email_placeholder'); $changed = true; }
                }
                foreach (['first_name', 'last_name', 'display_name'] as $f) {
                    if (!empty($row[$f]) && (string)get_user_meta($local, $f) === '') { update_user_meta($local, $f, (string)$row[$f]); $changed = true; }
                }
                if ($phone !== '' && qp_transfer_phone_key($phone) !== '' && (string)get_user_meta($local, 'phone_key') === '' && (string)get_user_meta($local, 'billing_phone') === '') {
                    update_user_meta($local, 'billing_phone', $phone); update_user_meta($local, 'phone_key', qp_transfer_phone_key($phone)); qp_transfer_user_phone_add((int)$local, $phone); $changed = true;
                }
                if (function_exists('do_action')) do_action('qp_transfer_user_saved', (int)$local, $row, $ctx, false);
                return ['result' => $changed ? 'updated' : 'same', 'id' => (int)$local];
            }

            // a new account
            if ($ctx['dry']) {
                $k = qp_transfer_phone_key($phone);
                $again = ($email !== '' && $ctx['seen']('email', $email)) | ($k !== '' && $ctx['seen']('phone', $k));   // both are asked, so both are remembered
                return ['result' => $again ? 'updated' : 'created', 'id' => 0];
            }
            $slug = function (string $t): string { return trim(strtolower((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $t)), '-._'); };
            $base = $slug((string)($row['username'] ?? ''));
            if ($base === '' && $email !== '') $base = $slug(explode('@', $email)[0]);
            if ($base === '' && qp_transfer_phone_key($phone) !== '') $base = 'u' . qp_transfer_phone_key($phone);
            if ($base === '') $base = $slug(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))) ?: 'user';
            $base = substr($base, 0, 40); $login = $base; $n = 2;
            $chk = $pdo->prepare("SELECT 1 FROM {$users} WHERE username = ? LIMIT 1");
            while (true) { $chk->execute([$login]); if (!$chk->fetchColumn()) break; $login = $base . '-' . $n++; }
            $placeholder = $email === '';
            if ($placeholder) $email = $login . '@' . QP_TRANSFER_PLACEHOLDER_DOMAIN;
            $id = qp_insert_user(['user_login' => $login, 'user_email' => $email, 'role' => $role]);     // no password given: a random one nobody knows
            if (is_object($id)) return ['result' => 'rejected', 'reason' => method_exists($id, 'get_error_messages') ? (implode(' ', (array)$id->get_error_messages()) ?: 'The account could not be created') : 'The account could not be created'];
            $id = (int)$id;
            foreach (['first_name', 'last_name', 'display_name'] as $f) if (!empty($row[$f])) update_user_meta($id, $f, (string)$row[$f]);
            if ($phone !== '') { update_user_meta($id, 'billing_phone', $phone); if (qp_transfer_phone_key($phone) !== '') { update_user_meta($id, 'phone_key', qp_transfer_phone_key($phone)); qp_transfer_user_phone_add($id, $phone); } }
            if ($placeholder) update_user_meta($id, 'email_placeholder', '1');
            update_user_meta($id, 'imported_from', (string)$ctx['source']);
            if (function_exists('do_action')) do_action('qp_transfer_user_saved', $id, $row, $ctx, true);
            return ['result' => 'created', 'id' => $id, 'reason' => $placeholder ? 'no email: a placeholder email was made; the customer has no way to reset a password by email until a real one is added' : ''];
        },
        'delete' => function (int $id) {
            if (function_exists('do_action')) do_action('qp_transfer_user_deleted', $id);
            return function_exists('qp_delete_user') ? qp_delete_user($id) : false;
        },
        'export_columns' => ['id', 'username', 'email', 'phone', 'first_name', 'last_name', 'role', 'registered'],
        'export_filters' => ['role' => ['label' => 'Role', 'options' => array_combine($safe, $safe)]],
        'export_read' => function ($cursor, int $limit, array $filters) {
            $users = table_name('users');
            $st = db()->prepare("SELECT id, username, email, created_at FROM {$users} WHERE id > ? ORDER BY id ASC LIMIT " . (int)$limit);
            $st->execute([(int)$cursor]);
            $rows = [];
            $all = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all as $u) {
                $id = (int)$u['id'];
                $role = (string)get_user_meta($id, 'role');
                if (!empty($filters['role']) && $role !== $filters['role']) continue;
                $rows[] = [
                    'id' => $id, 'username' => $u['username'], 'email' => qp_transfer_user_is_placeholder((string)$u['email']) ? '' : $u['email'],
                    'phone' => (string)get_user_meta($id, 'billing_phone'), 'first_name' => (string)get_user_meta($id, 'first_name'), 'last_name' => (string)get_user_meta($id, 'last_name'),
                    'role' => $role, 'registered' => $u['created_at'],
                ];
            }
            return ['rows' => $rows, 'next' => $all ? (string)end($all)['id'] : (string)$cursor, 'done' => count($all) < $limit];
        },
    ];
}
