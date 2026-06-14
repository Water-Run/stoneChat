<?php
/**
 * stoneChat Server history module.
 *
 * Manages chat conversations stored on disk under HISTORY/<chat_id>/
 * with one file per message plus a meta.txt sidecar.
 *
 * Directory layout (B方案 -- per-conversation subdirectory):
 *
 *   HISTORY/<chat_id>/
 *     meta.txt           # key=value lines: chat_id, created_at, updated_at,
 *                        # provider_id, model, name
 *     system.txt         # optional system prompt (only if present)
 *     user-NNN.txt       # first user message (NNN = 001, 002, ...)
 *     assistant-NNN.txt  # paired assistant reply
 *
 * Public functions (PHP 5.2, sc_-prefixed, include-guarded):
 *
 *   Path helpers
 *     sc_history_dir()                    absolute HISTORY/ root
 *     sc_history_root($cfg)               absolute HISTORY/ root (alias)
 *     sc_history_chat_dir($chat_id)       absolute path to a chat dir
 *     sc_history_validate_id($id)         true if id matches safe pattern
 *
 *   Meta
 *     sc_history_load_meta($chat_id)      read meta.txt as array
 *     sc_history_save_meta($chat_id,$a)   write meta.txt from array
 *
 *   Messages
 *     sc_history_append_message($id,$r,$c)   append user-/assistant-NNN.txt
 *     sc_history_save_message($id,$r,$t,$c)  alias of append_message
 *     sc_history_load_messages($chat_id)    load all messages in order
 *     sc_history_count_messages($chat_id)   count of message files in dir
 *
 *   Listing
 *     sc_history_list($cfg)                   all conversations, newest first
 *     sc_history_load_filtered($cfg,$n,$s,$d) subset by count/size/age
 *
 *   Mutations
 *     sc_history_create($provider_id,$model)  new conversation, returns id
 *     sc_history_new($cfg)                    alias using first provider
 *     sc_history_rename($chat_id,$new_name)   update meta name field
 *     sc_history_delete_to_recycle($chat_id)  move dir to Recycle Bin
 *     sc_history_delete($chat_id)             alias
 *     sc_history_set_system($chat_id,$text)   write system.txt
 *
 *   Composite
 *     sc_history_load($chat_id)               array('meta','system','messages')
 *
 * Path traversal protection: chat_id must match /^[A-Za-z0-9_-]{1,64}$/
 * and the resolved path must remain inside the HISTORY root.
 *
 * Compatible with PHP 5.2.
 */

if (!function_exists('sc_history_validate_id')) {
    /**
     * Check whether a chat id consists of safe characters only.
     *
     * The id must be 1-64 chars of letters, digits, underscore, or hyphen.
     * This rules out "..", "/", "\", NUL, and anything that could escape
     * the HISTORY/ sandbox.
     *
     * @param string $chat_id Candidate chat id.
     * @return bool true if safe.
     */
    function sc_history_validate_id($chat_id) {
        if (!is_string($chat_id) || $chat_id === '') {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $chat_id);
    }
}

if (!function_exists('sc_history_dir')) {
    /**
     * Resolve the absolute path to the HISTORY/ root directory.
     *
     * Honors [ui] history_dir from CONF.ini if set; otherwise defaults
     * to <project_root>/HISTORY/. Creates the directory if missing.
     *
     * @return string Absolute path, or '' on failure.
     */
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

if (!function_exists('sc_history_root')) {
    /**
     * Alias of sc_history_dir() that takes an explicit config bag.
     *
     * The JSON API contract calls this sc_history_root($cfg); behavior
     * matches sc_history_dir() but the config is supplied by the caller
     * instead of re-read from CONF.ini.
     *
     * @param array $cfg Parsed config from sc_load_config().
     * @return string Absolute path, or '' on failure.
     */
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

if (!function_exists('sc_history_chat_dir')) {
    /**
     * Resolve the absolute path to a single chat's subdirectory.
     *
     * Rejects unsafe ids (path traversal). Does NOT create the directory;
     * callers that need it (create / append) mkdir themselves.
     *
     * @param string $chat_id Chat id.
     * @return string Absolute path, or '' if id is unsafe or root missing.
     */
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

if (!function_exists('sc_history_atomic_write')) {
    /**
     * Write $content to $path atomically (tmp file + rename).
     *
     * Uses a temp file in the same directory so rename() stays on the
     * same filesystem; on Windows the target is unlinked first because
     * rename-over-existing is not always reliable for files.
     *
     * @param string $path    Absolute target path.
     * @param string $content Bytes to write.
     * @return bool true on success.
     */
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

if (!function_exists('sc_history_parse_meta')) {
    /**
     * Parse a meta.txt string into an associative array.
     *
     * Format: "key=value" per line, '#' or ';' starts a comment, blanks
     * ignored. Values are kept as strings.
     *
     * @param string $text Raw meta.txt contents.
     * @return array key=>value pairs; empty on empty input.
     */
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

if (!function_exists('sc_history_serialize_meta')) {
    /**
     * Serialize an associative array into meta.txt format.
     *
     * Strips CR/LF from values so a single key can't poison the file.
     *
     * @param array $meta key=>value pairs.
     * @return string "key=value\n" text ready to write.
     */
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

if (!function_exists('sc_history_load_meta')) {
    /**
     * Read a chat's meta.txt as an associative array.
     *
     * @param string $chat_id Chat id.
     * @return array Associative array; empty array if missing or unsafe id.
     */
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

if (!function_exists('sc_history_save_meta')) {
    /**
     * Write a chat's meta.txt from an associative array.
     *
     * @param string $chat_id Chat id.
     * @param array  $meta    key=>value pairs.
     * @return bool true on success.
     */
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

if (!function_exists('sc_history_message_filename')) {
    /**
     * Compose a zero-padded message filename for a role and index.
     *
     * @param string $role  "user" or "assistant".
     * @param int    $index 1-based message index.
     * @return string e.g. "user-001.txt"; '' on invalid input.
     */
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

if (!function_exists('sc_history_parse_message_filename')) {
    /**
     * Parse a filename into array('role'=>..,'index'=>..) or empty array.
     *
     * Only user-NNN.txt and assistant-NNN.txt with NNN in 1..999 match.
     *
     * @param string $name Filename (no directory).
     * @return array non-empty on match.
     */
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

if (!function_exists('sc_history_next_index')) {
    /**
     * Compute the next 1-based index for a given role in a chat dir.
     *
     * Returns max(existing)+1 for the role, or 1 if none exist.
     *
     * @param string $dir  Absolute chat dir.
     * @param string $role "user" or "assistant".
     * @return int Next index (>= 1).
     */
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

if (!function_exists('sc_history_append_message')) {
    /**
     * Append a new message to a chat conversation.
     *
     * The file name is auto-numbered. Touches meta.txt's updated_at so
     * listings stay fresh.
     *
     * @param string $chat_id Chat id.
     * @param string $role    "user" or "assistant".
     * @param string $content Message text.
     * @return int New message index (>= 1), or 0 on failure.
     */
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
        // Refresh updated_at so listings reflect the new turn.
        $meta = sc_history_load_meta($chat_id);
        $meta['updated_at'] = date('Y-m-d H:i:s');
        sc_history_save_meta($chat_id, $meta);
        return $idx;
    }
}

if (!function_exists('sc_history_save_message')) {
    /**
     * Alias of sc_history_append_message() matching the JSON contract.
     *
     * The $cfg argument is unused and kept for signature compatibility.
     *
     * @param string $id   Chat id.
     * @param string $role "user" or "assistant".
     * @param string $text Message text.
     * @param array  $cfg  Unused.
     * @return int New message index.
     */
    function sc_history_save_message($id, $role, $text, $cfg) {
        return sc_history_append_message($id, $role, $text);
    }
}

if (!function_exists('sc_history_set_system')) {
    /**
     * Write the system prompt for a chat (creates system.txt).
     *
     * Pass an empty string to remove the system prompt.
     *
     * @param string $chat_id Chat id.
     * @param string $text    System prompt text.
     * @return bool true on success.
     */
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

if (!function_exists('sc_history_load_messages')) {
    /**
     * Load all messages of a chat in user/assistant turn order.
     *
     * Returns array of array('role'=>..,'text'=>..). The order is
     * user-001, assistant-001, user-002, assistant-002, ...; if one role
     * is missing for an index, only the present role is emitted.
     *
     * @param string $chat_id Chat id.
     * @return array List of messages; empty on missing/unknown chat.
     */
    function sc_history_load_messages($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '' || !is_dir($dir)) {
            return array();
        }
        $pairs = array();   // idx => array('user'=>text, 'assistant'=>text)
        $exist = array();   // idx => array('user'=>bool, 'assistant'=>bool)
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
                $out[] = array('role' => 'assistant', 'text' => $pair['assistant']);
            }
        }
        return $out;
    }
}

if (!function_exists('sc_history_count_messages')) {
    /**
     * Count message files (user-NNN.txt + assistant-NNN.txt) in a chat.
     *
     * @param string $chat_id Chat id.
     * @return int Number of message files; 0 on missing/unknown chat.
     */
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

if (!function_exists('sc_history_dir_size_bytes')) {
    /**
     * Recursively compute total bytes used by a chat directory.
     *
     * @param string $chat_id Chat id.
     * @return int Total bytes; 0 on missing/unknown chat.
     */
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

if (!function_exists('sc_history_sort_by_updated_desc')) {
    /**
     * usort() callback: sort conversations by updated_at descending.
     *
     * Entries without an updated_at timestamp sort to the end.
     *
     * @param array $a First conversation row.
     * @param array $b Second conversation row.
     * @return int Comparison result.
     */
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

if (!function_exists('sc_history_list')) {
    /**
     * List all conversations under HISTORY/, newest first.
     *
     * Each row: array('id','title','updated','created','provider_id',
     *                 'model','message_count').
     *
     * @param array $cfg Parsed config (used to locate the HISTORY root).
     * @return array List of conversation rows; empty when none exist.
     */
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
                'title'         => isset($meta['name'])        ? (string)$meta['name']        : '',
                'updated'       => isset($meta['updated_at'])  ? (string)$meta['updated_at']  : '',
                'created'       => isset($meta['created_at'])  ? (string)$meta['created_at']  : '',
                'provider_id'   => isset($meta['provider_id']) ? (string)$meta['provider_id'] : '',
                'model'         => isset($meta['model'])       ? (string)$meta['model']       : '',
                'message_count' => sc_history_count_messages($name),
            );
        }
        @closedir($dh);
        usort($items, 'sc_history_sort_by_updated_desc');
        return $items;
    }
}

if (!function_exists('sc_history_load_filtered')) {
    /**
     * List conversations filtered by count / size / age.
     *
     * Any limit <= 0 disables that filter. Filters are AND-combined.
     *   count_limit     -- max number of rows to return (newest first).
     *   size_limit_mb   -- exclude chats whose directory exceeds this.
     *   days_limit      -- exclude chats whose updated_at is older than this.
     *
     * @param array $cfg            Parsed config.
     * @param int   $count_limit    Max rows to return (0 = unlimited).
     * @param int   $size_limit_mb  Max directory size in MB (0 = unlimited).
     * @param int   $days_limit     Max age in days (0 = unlimited).
     * @return array Filtered conversation rows.
     */
    function sc_history_load_filtered($cfg, $count_limit, $size_limit_mb, $days_limit) {
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

if (!function_exists('sc_history_rmdir_recursive')) {
    /**
     * Recursively delete a directory and its contents.
     *
     * @param string $dir Absolute directory path.
     * @return bool true on success.
     */
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

if (!function_exists('sc_history_recycle_via_com')) {
    /**
     * Move a path to the Windows Recycle Bin via Shell.Application COM.
     *
     * Uses NameSpace(10) (ssfBITBUCKET) + MoveHere with FOF_ALLOWUNDO (64)
     * so the move is undoable, which is what populates the Recycle Bin.
     * Returns false on non-Windows or when COM is unavailable.
     *
     * @param string $path Absolute path to the file or directory.
     * @return bool true if the path is gone after the call.
     */
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
            $bin = $shell->NameSpace(10); // 10 = ssfBITBUCKET
            if (!$bin) {
                return false;
            }
            // 64 = FOF_ALLOWUNDO -- required so the shell routes the move
            // through the Recycle Bin instead of performing a plain delete.
            $bin->MoveHere($path, 64);
            // MoveHere is asynchronous on some shells; poll briefly.
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

if (!function_exists('sc_history_delete_to_recycle')) {
    /**
     * Delete a chat conversation, sending it to the Recycle Bin on Windows.
     *
     * Tries Shell.Application COM first; on failure (or non-Windows),
     * falls back to a permanent recursive delete.
     *
     * @param string $chat_id Chat id.
     * @return bool true if the chat is gone after the call.
     */
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

if (!function_exists('sc_history_delete')) {
    /**
     * Alias of sc_history_delete_to_recycle() matching the JSON contract.
     *
     * @param string $chat_id Chat id.
     * @param array  $cfg     Unused (kept for signature compatibility).
     * @return bool true on success.
     */
    function sc_history_delete($chat_id, $cfg) {
        return sc_history_delete_to_recycle($chat_id);
    }
}

if (!function_exists('sc_history_rename')) {
    /**
     * Rename a chat conversation (display name only -- id stays the same).
     *
     * Writes the new name into meta.txt and refreshes updated_at.
     *
     * @param string $chat_id  Chat id.
     * @param string $new_name New display name (CR/LF stripped).
     * @return bool true on success.
     */
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

if (!function_exists('sc_history_create')) {
    /**
     * Create a brand-new chat conversation and return its id.
     *
     * The id is generated as YYYYMMDDHHMMSS-<4-hex>; collisions are
     * retried a few times before giving up.
     *
     * @param string $provider_id Provider slug (e.g. "openai").
     * @param string $model       Model id (e.g. "gpt-3.5-turbo").
     * @return string New chat id, or '' on failure.
     */
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

if (!function_exists('sc_history_new')) {
    /**
     * Alias of sc_history_create() using the first configured provider.
     *
     * Looks up CONF.ini via sc_load_providers(); falls back to an empty
     * provider/model when no providers are configured.
     *
     * @param array $cfg Parsed config (unused; kept for signature compat).
     * @return string New chat id, or '' on failure.
     */
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

if (!function_exists('sc_history_load')) {
    /**
     * Load a full conversation: meta + system + ordered messages.
     *
     * @param string $chat_id Chat id.
     * @return array array('meta'=>array, 'system'=>string, 'messages'=>array)
     *               -- empty placeholders on unknown chat.
     */
    function sc_history_load($chat_id) {
        $dir = sc_history_chat_dir($chat_id);
        if ($dir === '') {
            return array('meta' => array(), 'system' => '', 'messages' => array());
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
