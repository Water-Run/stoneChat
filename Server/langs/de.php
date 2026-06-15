<?php
/**
 * stoneChat Server language file — German (de).
 *
 * Key set matches the canonical en.php (the language spec). Every key defined
 * there is present here so sc_t() never has to fall back to English.
 *
 * Formal address register:
 *   - Button / menu labels are written as Sie-imperatives / infinitives
 *     (Anmelden, Senden, Abbrechen, Verbindung prüfen, Konfiguration neu
 *     laden, Chat löschen ...). In German the formal imperative for the
 *     2nd-person plural 'Sie' is identical to the infinitive, so this is
 *     the natural Sie-form.
 *   - Error / status messages keep a neutral impersonal construction so the
 *     user is addressed politely without sounding like a command.
 *
 * Loaded by Server/i18n.php via include; uses the `return array(...)` style
 * so the loader captures the table through the include return value.
 *
 * UTF-8. Compatible with PHP 5.2 (array() not []; direct UTF-8 bytes; no
 * \uXXXX escapes which would require PHP 5.4+).
 */

return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => 'Ein lokal gehosteter Multi-Provider-LLM-Web-Chat.',

    // --- login ---
    'login.title'            => 'Anmelden',
    'login.password'         => 'Passwort',
    'login.submit'           => 'Anmelden',
    'login.error'            => 'Falsches Passwort. Bitte versuchen Sie es erneut.',
    'login.locked'           => 'Zu viele Fehlversuche. Bitte später erneut versuchen.',
    'login.locked'           => 'Zu viele Fehlversuche. Bitte warten Sie, bevor Sie es erneut versuchen.',

    // --- chat (action buttons / labels) ---
    'chat.send'              => 'Senden',
    'chat.stop'              => 'Stoppen',
    'chat.regenerate'        => 'Neu generieren',
    'chat.delete'            => 'Löschen',
    'chat.new'               => 'Neu',
    'chat.newChat'           => 'Neuer Chat',
    'chat.deleteChat'        => 'Chat löschen',
    'chat.renameChat'        => 'Chat umbenennen',
    'chat.confirmDelete'     => 'Diesen Chat löschen? Diese Aktion kann nicht rückgängig gemacht werden.',
    'chat.settings'          => 'Einstellungen',
    'chat.model'             => 'Modell',
    'chat.model.label'       => 'Modell:',
    'chat.provider'          => 'Anbieter',
    'chat.tokens.label'      => 'Tokens:',
    'chat.timeout.label'     => 'Zeitlimit (s):',
    'chat.connectCheck'      => 'Verbindung prüfen',
    'chat.reloadConfig'      => 'Konfiguration neu laden',
    'chat.about'             => 'Über',
    'chat.empty'             => 'Noch keine Nachrichten. Schreiben Sie etwas, um das Gespräch zu beginnen.',

    // --- chat: status & errors ---
    'chat.connected'         => 'Verbunden',
    'chat.disconnected'      => 'Getrennt',
    'chat.stream.warning'    => 'Verbindung unterbrochen. Das Streaming wurde gestoppt.',
    'chat.error.network'     => 'Netzwerkfehler. Bitte überprüfen Sie Ihre Verbindung.',
    'chat.error.timeout'     => 'Zeitüberschreitung. Bitte versuchen Sie es erneut.',
    'chat.error.unauthorized'=> 'Nicht autorisiert. Bitte melden Sie sich erneut an.',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => 'Nachricht eingeben...',
    'chat.countdown.waiting' => 'Warte auf eine Antwort...',
    'chat.countdown.seconds' => 's',

    // --- new chat dialog ---
    'newchat.title'          => 'Neuer Chat',
    'newchat.testAll'        => 'Alle Anbieter testen',
    'newchat.create'         => 'Erstellen',
    'newchat.cancel'         => 'Abbrechen',

    // --- about dialog ---
    'about.protocol'         => 'Protokoll',
    'about.author'           => 'Autor',
    'about.brief'            => 'Über',
    'about.github'           => 'GitHub-Repository',
    'about.close'            => 'Schließen',

    // --- history ---
    'history.title'          => 'Verlauf',
    'history.empty'          => 'Noch kein Verlauf.',
    'history.delete'         => 'Eintrag löschen',
    'history.new'            => 'Neuer Chat',
    'history.lastUsed'       => 'Zuletzt verwendet',

    // --- common (shared UI buttons) ---
    'common.cancel'          => 'Abbrechen',
    'common.confirm'         => 'Bestätigen',
    'common.save'            => 'Speichern',
    'common.close'           => 'Schließen',
    'common.yes'             => 'Ja',
    'common.no'              => 'Nein',

    // --- top-level error namespace ---
    'error.network'          => 'Netzwerkfehler. Bitte überprüfen Sie Ihre Verbindung.',
    'error.config'           => 'Die Konfigurationsdatei ist ungültig. Bitte wenden Sie sich an den Administrator.',
    'error.auth'             => 'Authentifizierung fehlgeschlagen. Bitte melden Sie sich erneut an.',
);
