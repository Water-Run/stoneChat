<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/langs/en.php
 *
 * English (en) language table. Returns an associative array of
 * translation keys => UTF-8 strings. This is the canonical /
 * fallback language, so every key defined in the language spec is
 * present here, even if the matching zh-CN copy is shorter.
 * Consumed by Server/i18n.php sc_t(). PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */
return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => 'A locally hosted, multi-provider LLM web chat.',

    // --- login ---
    'login.title'            => 'Sign in',
    'login.password'         => 'Password',
    'login.submit'           => 'Sign in',
    'login.error'            => 'Incorrect password. Please try again.',
    'login.locked'           => 'Too many failed attempts. Please wait and try again later.',
    'login.locked'           => 'Too many failed attempts. Please wait and try again later.',

    // --- chat (action buttons / labels) ---
    'chat.send'              => 'Send',
    'chat.stop'              => 'Stop',
    'chat.regenerate'        => 'Regenerate',
    'chat.delete'            => 'Delete',
    'chat.new'               => 'New',
    'chat.newChat'           => 'New chat',
    'chat.deleteChat'        => 'Delete chat',
    'chat.renameChat'        => 'Rename chat',
    'chat.confirmDelete'     => 'Delete this chat? This action cannot be undone.',
    'chat.settings'          => 'Settings',
    'chat.model'             => 'Model',
    'chat.model.label'       => 'Model:',
    'chat.provider'          => 'Provider',
    'chat.tokens.label'      => 'Tokens:',
    'chat.timeout.label'     => 'Timeout (s):',
    'chat.connectCheck'      => 'Check connection',
    'chat.reloadConfig'      => 'Reload configuration',
    'chat.about'             => 'About',
    'chat.empty'             => 'No messages yet. Say something to start the conversation.',

    // --- chat: status & errors ---
    'chat.connected'         => 'Connected',
    'chat.disconnected'      => 'Disconnected',
    'chat.stream.warning'    => 'Connection interrupted. Streaming has been stopped.',
    'chat.error.network'     => 'Network error. Please check your connection.',
    'chat.error.timeout'     => 'Request timed out. Please try again.',
    'chat.error.unauthorized'=> 'Unauthorized. Please sign in again.',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => 'Type a message...',
    'chat.countdown.waiting' => 'Waiting for a reply...',
    'chat.countdown.seconds' => 's',

    // --- new chat dialog ---
    'newchat.title'          => 'New chat',
    'newchat.testAll'        => 'Test all providers',
    'newchat.create'         => 'Create',
    'newchat.cancel'         => 'Cancel',

    // --- about dialog ---
    'about.protocol'         => 'Protocol',
    'about.author'           => 'Author',
    'about.brief'            => 'About',
    'about.github'           => 'GitHub repository',
    'about.close'            => 'Close',

    // --- history ---
    'history.title'          => 'History',
    'history.empty'          => 'No history yet.',
    'history.delete'         => 'Delete entry',
    'history.new'            => 'New chat',
    'history.lastUsed'       => 'Last used',

    // --- common (shared UI buttons) ---
    'common.cancel'          => 'Cancel',
    'common.confirm'         => 'Confirm',
    'common.save'            => 'Save',
    'common.close'           => 'Close',
    'common.yes'             => 'Yes',
    'common.no'              => 'No',

    // --- top-level error namespace (subtask spec) ---
    'error.network'          => 'Network error. Please check your connection.',
    'error.config'           => 'Configuration file is invalid. Please contact the administrator.',
    'error.auth'             => 'Authentication failed. Please sign in again.',
);