<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/history.php
 *
 * Manage chat conversations stored on disk under HISTORY/<chat_id>/
 * with one file per message plus a meta.txt sidecar.
 *
 * Directory layout (per-conversation subdirectory):
 *
 *   HISTORY/<chat_id>/
 *     meta.txt           key=value: chat_id, created_at, updated_at,
 *                        provider_id, model, name
 *     system.txt         optional system prompt (only when present)
 *     user-NNN.txt       first user message (NNN = 001, 002, ...)
 *     assistant-NNN.txt  paired assistant reply
 *
 * Public helpers (PHP 5.2, sc_-prefixed, include-guarded):
 *   sc_history_validate_id($id)         true iff id matches safe pattern
 *   sc_history_dir()                    absolute HISTORY/ root
 *   sc_history_root($cfg)               absolute HISTORY/ root (alias)
 *   sc_history_chat_dir($chat_id)       absolute path to a chat dir
 *   sc_history_atomic_write($p, $c)     tmp + rename
 *   sc_history_parse_meta($text)        raw text -> assoc array
 *   sc_history_serialize_meta($meta)    assoc -> raw text
 *   sc_history_load_meta($chat_id)      read meta.txt as array
 *   sc_history_save_meta($id, $meta)    write meta.txt from array
 *   sc_history_message_filename($r, $i) compose "user-NNN.txt" name
 *   sc_history_parse_message_filename() name -> (role, index) or empty
 *   sc_history_next_index($dir, $role)  next 1-based index for a role
 *   sc_history_append_message(...)      append user/assistant file
 *   sc_history_save_message(...)        alias of append_message
 *   sc_history_load_messages($id)       all messages in turn order
 *   sc_history_count_messages($id)      count of message files
 *   sc_history_dir_size_bytes($id)      total bytes used
 *   sc_history_sort_by_updated_desc()   usort callback
 *   sc_history_list($cfg)               all conversations, newest first
 *   sc_history_load_filtered($cfg,...)  subset by count/size/age
 *   sc_history_rmdir_recursive($dir)    recursive delete
 *   sc_history_recycle_via_com($path)   send to Recycle Bin (Win COM)
 *   sc_history_delete_to_recycle($id)   delete via recycle or rmdir
 *   sc_history_delete($id, $cfg)        alias matching JSON contract
 *   sc_history_rename($id, $new_name)   update meta name field
 *   sc_history_create($prov, $model)    new conversation, returns id
 *   sc_history_new($cfg)                alias using first provider
 *   sc_history_set_system($id, $text)   write system.txt
 *   sc_history_load($chat_id)           array('meta','system','messages')
 *
 * Path traversal protection: chat_id must match
 *   /^[A-Za-z0-9_-]{1,64}$/
 * and the resolved path must remain inside the HISTORY root.
 *
 * PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */

/* sc_history_validate_id($chat_id)
 *   True iff the id is 1-64 chars of [A-Za-z0-9_-]. Rules out "..",
 *   "/", "\", NUL -- anything that could escape the HISTORY/ sandbox. */
if (!function_exists('sc_history_validate_id')) {
    function sc_history_validate_id($chat_id) {
        if (!is_string($chat_id) || $chat_id === '') {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $chat_id);
    }
}

/* sc_history_dir()
 *   Absolute path to the HISTORY/ root; honors [ui] history_dir
 *   from CONF.ini when set. Creates the directory if missing. */
if (!function_exists('sc_history_dir')) {
    function sc_history_dir() {
        $project_root = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..';
        $default_root = $project_root . DIRECTORY_SEPARATOR . 'HISTORY';
        $ini_path = $project_root . DIRECTORY_SEPARATOR . 'CONF.ini';
        $root = $default_root;
        $cfg = array();
        if (is_file($ini_path) && is_readable($ini_path)) {
            if (function_exists('sc_load_config')) {
                $cfg = sc_load_config($ini_path);
            } else {
                $parsed = @parse_ini_file($ini_path, true);
                if (is_array($parsed)) {
                    $cfg = $parsed;
                }
            }
            if (is_array($cfg) && isset($cfg['ui']['history_dir'])
                && is_string($cfg['ui']['history_dir'])
                && $cfg['ui']['history_dir'] !== '') {
                $custom = $cfg['ui']['history_dir'];
                if (function_exists('sc_resolve_path')) {
                    $resolved = sc_resolve_path($custom, $project_root);
                    if (is_string($resolved) && $resolved !== '') {
                        $root = $resolved;
                    }
                }
            }
        }
        if (!is_dir($root)) {
            if (!@mkdir($root, 0777, true)) {
                return '';
            }
        }
        return $root;
    }
}

/* sc_history_root($cfg)
 *   Alias of sc_history_dir() that takes an explicit config bag. */
if (!function_exists('sc_history_root')) {
    function sc_history_root($cfg) {
        $root = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
              . DIRECTORY_SEPARATOR . 'HISTORY';
        if (is_array($cfg) && isset($cfg['ui']['history_dir'])
            && is_string($cfg['ui']['history_dir'])
            && $cfg['ui']['history_dir'] !== '') {
            $base = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..';
            if (function_exists('sc_resolve_path')) {
                $resolved = sc_resolve_path($cfg['ui']['history_dir'], $base);
                if (is_string($resolved) && $resolved !== '') {
                    $root = $resolved;
                }
            }
        }
        if (!is_dir($root)) {
            if (!@mkdir($root, 0777, true)) {
                return '';
            }
        }
        return $root;
    }
}

/* sc_history_chat_dir($chat_id)
 *   Absolute path to a chat's subdirectory. Rejects unsafe ids
 *   (path traversal). Does NOT create the directory; callers that
 *   need it (create / append) mkdir themselves. */
if (!function_exists('sc_history_chat_dir')) {
    function sc_history_chat_dir($chat_id) {
        if (!sc_history_validate_id($chat_id)) {
            return '';
        }
        $root = sc_history_dir();
        if ($root === '') {
            return '';
        }
        return $root . DIRECTORY_SEPARATOR . $chat_id;
    }
}

/* sc_history_atomic_write($path, $content)
 *   Write $content to $path via a tmp file in the same directory,
 *   then rename. On Windows the target is unlinked first because
 *   rename-over-existing is not always reliable for files. */
if (!function_exists('sc_history_atomic_write')) {
    function sc_history_atomic_write($path, $content) {
        if (!is_string($path) || $path === '') {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true)) {
                return false;
            }
        }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.tmp_' . uniqid('', true);
        $bytes = @file_put_contents($tmp, $content);
        if ($bytes === false) {
            return false;
        }
        if (is_file($path)) {
            @unlink($path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}

/* sc_history_parse_meta($text)
 *   "key=value" per line; '#' or ';' starts a comment; blanks
 *   ignored. Values stay as strings. */
if (!function_exists('sc_history_parse_meta')) {
    function sc_history_parse_meta($text) {
        $out = array();
        if (!is_string($text) || $text === '') {
            return $out;
        }
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if (!is_array($lines)) {
            return $out;
        }
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#' || $trim[0] === ';') {
                continue;
            }
            $eq = strpos($trim, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($trim, 0, $eq));
            $v = trim(substr($trim, $eq + 1));
            if ($k !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}

/* sc_history_serialize_meta($meta)
 *   Serialise an assoc array into meta.txt format. Strips CR/LF
 *   from values so a single key cannot poison the file. */
if (!function_exists('sc_history_serialize_meta')) {
    function sc_history_serialize_meta($meta) {
        if (!is_array($meta) || empty($meta)) {
            return '';
        }
        $lines = array();
        foreach ($meta as $k => $v) {
            $k = (string)$k;
            if ($k === '') {
                continue;
            }
            $v = (string)$v;
            $v = str_replace(array("\r", "\n"), ' ', $v);
            $lines[] = $k . '=' . $v;
        }
        return implode("\n", $lines) . "\n";
    }
}

/* sc_history_load_meta($chat_id)
 *   Read a chat's meta.txt as an associative array. */
if (!function_exists('sc_history_load_meta')) {
    function sc_history_load_meta($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '') {
            return array();
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'meta.txt';
        if (!is_file($file) || !is_readable($file)) {
            return array();
        }
        $text = @file_get_contents($file);
        if (!is_string($text)) {
            return array();
        }
        return sc_history_parse_meta($text);
    }
}

/* sc_history_save_meta($chat_id, $meta)
 *   Write a chat's meta.txt from an associative array. */
if (!function_exists('sc_history_save_meta')) {
    function sc_history_save_meta($chat_id, $meta) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '') {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return false;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'meta.txt';
        return sc_history_atomic_write($file, sc_history_serialize_meta($meta));
    }
}

/* sc_history_message_filename($role, $index)
 *   Compose a zero-padded message filename (e.g. "user-001.txt"). */
if (!function_exists('sc_history_message_filename')) {
    function sc_history_message_filename($role, $index) {
        if ($role !== 'user' && $role !== 'assistant') {
            return '';
        }
        $idx = (int)$index;
        if ($idx < 1 || $idx > 999) {
            return '';
        }
        return $role . '-' . sprintf('%03d', $idx) . '.txt';
    }
}

/* sc_history_parse_message_filename($name)
 *   Parse a filename into array('role'=>..,'index'=>..) or empty.
 *   Only user-NNN.txt / assistant-NNN.txt with NNN in 1..999 match. */
if (!function_exists('sc_history_parse_message_filename')) {
    function sc_history_parse_message_filename($name) {
        if (!is_string($name) || $name === '') {
            return array();
        }
        if (!preg_match('/^(user|assistant)-(\d{3})\.txt$/', $name, $m)) {
            return array();
        }
        $idx = (int)$m[2];
        if ($idx < 1 || $idx > 999) {
            return array();
        }
        return array('role' => $m[1], 'index' => $idx);
    }
}

/* sc_history_next_index($dir, $role)
 *   Next 1-based index for a role in a chat dir: max(existing)+1
 *   for that role, or 1 if none exist. */
if (!function_exists('sc_history_next_index')) {
    function sc_history_next_index($dir, $role) {
        if (!is_dir($dir)) {
            return 1;
        }
        $dh = @opendir($dir);
        if ($dh === false) {
            return 1;
        }
        $max = 0;
        while (($name = @readdir($dh)) !== false) {
            $parsed = sc_history_parse_message_filename($name);
            if (empty($parsed)) {
                continue;
            }
            if ($parsed['role'] !== $role) {
                continue;
            }
            if ($parsed['index'] > $max) {
                $max = $parsed['index'];
            }
        }
        @closedir($dh);
        return $max + 1;
    }
}

/* sc_history_append_message($chat_id, $role, $content)
 *   Append a new message to a chat. File name is auto-numbered.
 *   Touches meta.txt's updated_at so listings stay fresh. */
if (!function_exists('sc_history_append_message')) {
    function sc_history_append_message($chat_id, $role, $content) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '') {
            return 0;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            return 0;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return 0;
        }
        $idx = sc_history_next_index($dir, $role);
        $fname = sc_history_message_filename($role, $idx);
        if ($fname === '') {
            return 0;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $fname;
        $bytes = @file_put_contents($path, (string)$content);
        if ($bytes === false) {
            return 0;
        }
        /* refresh updated_at so listings reflect the new turn. */
        $meta = sc_history_load_meta($chat_id);
        $meta['updated_at'] = date('Y-m-d H:i:s');
        sc_history_save_meta($chat_id, $meta);
        return $idx;
    }
}

/* sc_history_save_message($id, $role, $text, $cfg)
 *   Alias of sc_history_append_message() matching the JSON contract.
 *   $cfg is kept for signature compatibility and is ignored. */
if (!function_exists('sc_history_save_message')) {
    function sc_history_save_message($id, $role, $text, $cfg) {
        return sc_history_append_message($id, $role, $text);
    }
}

/* sc_history_set_system($chat_id, $text)
 *   Write the system prompt for a chat (creates system.txt).
 *   Pass '' to remove the system prompt. */
if (!function_exists('sc_history_set_system')) {
    function sc_history_set_system($chat_id, $text) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '') {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            return false;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'system.txt';
        if ((string)$text === '') {
            if (is_file($file)) {
                return @unlink($file);
            }
            return true;
        }
        return sc_history_atomic_write($file, (string)$text);
    }
}

/* sc_history_load_messages($chat_id)
 *   Load all messages of a chat in user/assistant turn order:
 *   user-001, assistant-001, user-002, assistant-002, ...; if one
 *   role is missing for an index, only the present role is emitted. */
if (!function_exists('sc_history_load_messages')) {
    function sc_history_load_messages($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return array();
        }
        $pairs = array();   /* idx => array('user'=>text, 'assistant'=>text) */
        $exist = array();   /* idx => array('user'=>bool, 'assistant'=>bool) */
        $dh = @opendir($dir);
        if ($dh === false) {
            return array();
        }
        while (($name = @readdir($dh)) !== false) {
            $parsed = sc_history_parse_message_filename($name);
            if (empty($parsed)) {
                continue;
            }
            $idx = $parsed['index'];
            $role = $parsed['role'];
            if (!isset($pairs[$idx])) {
                $pairs[$idx] = array('user' => '', 'assistant' => '');
                $exist[$idx] = array('user' => false, 'assistant' => false);
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            $text = @file_get_contents($path);
            $pairs[$idx][$role] = ($text === false) ? '' : (string)$text;
            $exist[$idx][$role] = true;
        }
        @closedir($dh);
        ksort($pairs, SORT_NUMERIC);
        $out = array();
        foreach ($pairs as $idx => $pair) {
            if (!empty($exist[$idx]['user'])) {
                $out[] = array('role' => 'user', 'text' => $pair['user']);
            }
            if (!empty($exist[$idx]['assistant'])) {
                $out[] = array('role' => 'assistant',
                               'text' => $pair['assistant']);
            }
        }
        return $out;
    }
}

/* sc_history_count_messages($chat_id)
 *   Count message files (user-NNN.txt + assistant-NNN.txt) in chat. */
if (!function_exists('sc_history_count_messages')) {
    function sc_history_count_messages($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $dh = @opendir($dir);
        if ($dh === false) {
            return 0;
        }
        while (($name = @readdir($dh)) !== false) {
            if (!empty(sc_history_parse_message_filename($name))) {
                $count++;
            }
        }
        @closedir($dh);
        return $count;
    }
}

/* sc_history_dir_size_bytes($chat_id)
 *   Recursively compute total bytes used by a chat directory. */
if (!function_exists('sc_history_dir_size_bytes')) {
    function sc_history_dir_size_bytes($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $dh = @opendir($dir);
        if ($dh === false) {
            return 0;
        }
        while (($name = @readdir($dh)) !== false) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $sz = @filesize($path);
                if ($sz !== false) {
                    $total += (int)$sz;
                }
            }
        }
        @closedir($dh);
        return $total;
    }
}

/* sc_history_sort_by_updated_desc($a, $b)
 *   usort callback: sort conversations by updated_at descending.
 *   Entries without an updated_at timestamp sort to the end. */
if (!function_exists('sc_history_sort_by_updated_desc')) {
    function sc_history_sort_by_updated_desc($a, $b) {
        $ua = isset($a['updated']) ? (string)$a['updated'] : '';
        $ub = isset($b['updated']) ? (string)$b['updated'] : '';
        if ($ua === '' && $ub === '') {
            return 0;
        }
        if ($ua === '') {
            return 1;
        }
        if ($ub === '') {
            return -1;
        }
        if ($ua === $ub) {
            return 0;
        }
        return ($ua < $ub) ? 1 : -1;
    }
}

/* sc_history_list($cfg)
 *   List all conversations under HISTORY/, newest first.
 *
 *   Each row: array('id','title','updated','created','provider_id',
 *                   'model','message_count'). */
if (!function_exists('sc_history_list')) {
    function sc_history_list($cfg) {
        $root = sc_history_root($cfg);
        if ($root === '' || !is_dir($root)) {
            return array();
        }
        $dh = @opendir($root);
        if ($dh === false) {
            return array();
        }
        $items = array();
        while (($name = @readdir($dh)) !== false) {
            if (!sc_history_validate_id($name)) {
                continue;
            }
            $dir = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $meta = sc_history_load_meta($name);
            $items[] = array(
                'id'            => $name,
                'title'         => isset($meta['name'])
                                  ? (string)$meta['name'] : '',
                'updated'       => isset($meta['updated_at'])
                                  ? (string)$meta['updated_at'] : '',
                'created'       => isset($meta['created_at'])
                                  ? (string)$meta['created_at'] : '',
                'provider_id'   => isset($meta['provider_id'])
                                  ? (string)$meta['provider_id'] : '',
                'model'         => isset($meta['model'])
                                  ? (string)$meta['model'] : '',
                'message_count' => sc_history_count_messages($name),
            );
        }
        @closedir($dh);
        usort($items, 'sc_history_sort_by_updated_desc');
        return $items;
    }
}

/* sc_history_load_filtered($cfg, $count_limit, $size_limit_mb, $days_limit)
 *   List conversations filtered by count / size / age. Any limit
 *   <= 0 disables that filter. Filters are AND-combined. */
if (!function_exists('sc_history_load_filtered')) {
    function sc_history_load_filtered($cfg, $count_limit, $size_limit_mb,
                                      $days_limit) {
        $items = sc_history_list($cfg);
        if (empty($items)) {
            return array();
        }
        $now_ts = time();
        $day_sec = 86400;
        $filtered = array();
        $count = 0;
        $max_count = (int)$count_limit;
        $max_bytes = ((int)$size_limit_mb) * 1024 * 1024;
        $max_age_sec = ((int)$days_limit) * $day_sec;
        foreach ($items as $item) {
            if ($max_count > 0 && $count >= $max_count) {
                break;
            }
            if ($max_age_sec > 0 && !empty($item['updated'])) {
                $ts = strtotime($item['updated']);
                if ($ts !== false && ($now_ts - $ts) > $max_age_sec) {
                    continue;
                }
            }
            if ($max_bytes > 0) {
                $sz = sc_history_dir_size_bytes($item['id']);
                if ($sz > $max_bytes) {
                    continue;
                }
            }
            $filtered[] = $item;
            $count++;
        }
        return $filtered;
    }
}

/* sc_history_rmdir_recursive($dir)
 *   Recursively delete a directory and its contents. */
if (!function_exists('sc_history_rmdir_recursive')) {
    function sc_history_rmdir_recursive($dir) {
        if (!is_dir($dir)) {
            return true;
        }
        $dh = @opendir($dir);
        if ($dh === false) {
            return false;
        }
        while (($name = @readdir($dh)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                if (!sc_history_rmdir_recursive($path)) {
                    @closedir($dh);
                    return false;
                }
            } else {
                if (!@unlink($path)) {
                    @closedir($dh);
                    return false;
                }
            }
        }
        @closedir($dh);
        return @rmdir($dir);
    }
}

/* sc_history_recycle_via_com($path)
 *   Move a path to the Windows Recycle Bin via Shell.Application COM.
 *   Uses NameSpace(10) (ssfBITBUCKET) + MoveHere with FOF_ALLOWUNDO
 *   (64) so the move is undoable. Returns false on non-Windows or
 *   when COM is unavailable. */
if (!function_exists('sc_history_recycle_via_com')) {
    function sc_history_recycle_via_com($path) {
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            return false;
        }
        if (!class_exists('COM')) {
            return false;
        }
        try {
            $shell = new COM('Shell.Application');
            if (!$shell) {
                return false;
            }
            $bin = $shell->NameSpace(10); /* 10 = ssfBITBUCKET */
            if (!$bin) {
                return false;
            }
            /* 64 = FOF_ALLOWUNDO -- required so the shell routes the
             * move through the Recycle Bin instead of a plain delete. */
            $bin->MoveHere($path, 64);
            /* MoveHere is asynchronous on some shells; poll briefly. */
            for ($i = 0; $i < 20; $i++) {
                if (!is_dir($path) && !is_file($path)) {
                    return true;
                }
                usleep(100000);
            }
            return (!is_dir($path) && !is_file($path));
        } catch (Exception $e) {
            return false;
        }
    }
}

/* sc_history_delete_to_recycle($chat_id)
 *   Delete a chat, sending it to the Recycle Bin on Windows.
 *   Falls back to a permanent recursive delete on failure. */
if (!function_exists('sc_history_delete_to_recycle')) {
    function sc_history_delete_to_recycle($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return false;
        }
        if (sc_history_recycle_via_com($dir)) {
            return true;
        }
        return sc_history_rmdir_recursive($dir);
    }
}

/* sc_history_delete($chat_id, $cfg)
 *   Alias of sc_history_delete_to_recycle(). */
if (!function_exists('sc_history_delete')) {
    function sc_history_delete($chat_id, $cfg) {
        return sc_history_delete_to_recycle($chat_id);
    }
}

/* sc_history_rename($chat_id, $new_name)
 *   Update the display name in meta.txt (the id stays the same).
 *   CR/LF in $new_name are stripped. */
if (!function_exists('sc_history_rename')) {
    function sc_history_rename($chat_id, $new_name) {
        $meta = sc_history_load_meta($chat_id);
        if (empty($meta)) {
            return false;
        }
        $safe = str_replace(array("\r", "\n"), ' ', (string)$new_name);
        $meta['name'] = $safe;
        $meta['updated_at'] = date('Y-m-d H:i:s');
        return sc_history_save_meta($chat_id, $meta);
    }
}

/* sc_history_create($provider_id, $model)
 *   New chat conversation; id is YYYYMMDDHHMMSS-<4-hex>, with a
 *   few collision retries. */
if (!function_exists('sc_history_create')) {
    function sc_history_create($provider_id, $model) {
        $root = sc_history_dir();
        if ($root === '') {
            return '';
        }
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $rand = sprintf('%04x', mt_rand(0, 0xffff));
            $id = date('YmdHis') . '-' . $rand;
            if (!sc_history_validate_id($id)) {
                continue;
            }
            $dir = $root . DIRECTORY_SEPARATOR . $id;
            if (is_dir($dir)) {
                usleep(10000);
                continue;
            }
            if (!@mkdir($dir, 0777, true)) {
                return '';
            }
            $now = date('Y-m-d H:i:s');
            $meta = array(
                'chat_id'     => $id,
                'created_at'  => $now,
                'updated_at'  => $now,
                'provider_id' => (string)$provider_id,
                'model'       => (string)$model,
                'name'        => '',
            );
            sc_history_save_meta($id, $meta);
            return $id;
        }
        return '';
    }
}

/* sc_history_new($cfg)
 *   Alias of sc_history_create() using the first configured provider. */
if (!function_exists('sc_history_new')) {
    function sc_history_new($cfg) {
        $provider = '';
        $model = '';
        if (is_array($cfg) && isset($cfg['llm']['model'])) {
            $model = (string)$cfg['llm']['model'];
        }
        if (function_exists('sc_load_providers')) {
            $ini = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..'
                 . DIRECTORY_SEPARATOR . 'CONF.ini';
            $providers = sc_load_providers($ini);
            if (!empty($providers)) {
                $first = $providers[0];
                if (isset($first['id'])) {
                    $provider = (string)$first['id'];
                }
                if ($model === '' && isset($first['model'])) {
                    $model = (string)$first['model'];
                }
            }
        }
        return sc_history_create($provider, $model);
    }
}

/* sc_history_load($chat_id)
 *   array('meta' => assoc, 'system' => string, 'messages' => list). */
if (!function_exists('sc_history_load')) {
    function sc_history_load($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '') {
            return array('meta' => array(), 'system' => '',
                         'messages' => array());
        }
        $meta = sc_history_load_meta($chat_id);
        $system = '';
        $system_path = $dir . DIRECTORY_SEPARATOR . 'system.txt';
        if (is_file($system_path) && is_readable($system_path)) {
            $text = @file_get_contents($system_path);
            if (is_string($text)) {
                $system = $text;
            }
        }
        $messages = sc_history_load_messages($chat_id);
        return array(
            'meta'     => $meta,
            'system'   => $system,
            'messages' => $messages,
        );
    }
}
