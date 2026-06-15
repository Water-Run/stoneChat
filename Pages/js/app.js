/**
 * stoneChat Pages/js/app.js
 *
 * Application bootstrap and UI glue for the post-login chat page.
 *
 * IE6-compatible (ES3 / ES5 only):
 *   - no fetch, no Promise, no arrow functions, no let/const
 *   - no Array.prototype.forEach / .map (use for-loops)
 *   - XHR-based API access via SC.Api (api.js)
 *   - DOM events wired with attachEvent / addEventListener fallback
 *   - No localStorage (state lives in window.SC.App module variables)
 *
 * Public namespace: window.SC.App (alias: window.scApp)
 *
 * Methods
 * -------
 *   bootstrap()              page-load entry point (auto-called)
 *   renderHistoryList(chats) sidebar list
 *   renderTopMenu(provider)  top status row
 *   showNewChatDialog()      modal: pick a provider to start a new chat
 *   showAboutDialog()        modal: protocol / author / GitHub
 *   openConfigFile()         degraded hint (browser cannot open local file)
 *   reloadConfig()           ask server to re-read CONF.ini, then reload
 *   deleteChat(chatId)       confirm then SC.Api.deleteChat
 *   renameChat(id, name)     SC.Api.renameChat
 *   connectCheck(providerId) SC.Api.connectCheck (defaults to current)
 *   logout()                 SC.Api.logout, then redirect to login page
 *
 * Expected DOM IDs (provided by chat.htm)
 * ---------------------------------------
 *   sc-logo                 header brand image / link -> opens About
 *   sc-lang-switcher        container for language buttons
 *   sc-new-chat-btn         "New chat" sidebar button
 *   sc-reload-config-btn    "Reload config" toolbar button
 *   sc-open-config-btn      "Open CONF.ini" button (degraded: hint only)
 *   sc-logout-btn           "Logout" button
 *   sc-history-list         <ul> sidebar list container
 *   sc-top-menu             top status row container
 *   sc-modal-mask           full-viewport overlay
 *   sc-modal-about          About dialog panel
 *   sc-modal-new-chat       New-chat dialog panel
 *   sc-about-body           body <div> inside About dialog
 *   sc-newchat-list         <ul> provider list inside New-chat dialog
 *   sc-modal-close          any close button inside a dialog
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Module state (kept small; never used for sensitive data).
    // -------------------------------------------------------------------------
    var currentProvider = null;   // currently selected provider row
    var currentChats = [];        // last loaded history list
    var currentConfig = null;     // last loaded /api/config payload
    var loginUrl = 'index.htm';   // where to redirect on logout

    var SC = window.SC || {};
    if (typeof SC !== 'object' || SC === null) { SC = {}; }

    // -------------------------------------------------------------------------
    // Tiny DOM helpers (IE6-safe).
    // -------------------------------------------------------------------------
    function $id(id) {
        return document.getElementById(id);
    }

    // Set text in an IE6-friendly way: textContent if present (IE8+),
    // innerText otherwise. Setting innerHTML with user-controlled data is
    // dangerous; always go through setText when the value is not pre-vetted.
    function setText(node, text) {
        if (!node) { return; }
        var safe = (text === null || text === undefined) ? '' : String(text);
        if (typeof node.textContent === 'string') {
            node.textContent = safe;
        } else if (typeof node.innerText === 'string') {
            node.innerText = safe;
        }
    }

    // HTML-escape for safe insertion into innerHTML strings.
    function escHtml(s) {
        if (s === null || s === undefined) { return ''; }
        s = String(s);
        return s.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
    }

    // Escape a string for use inside a single-quoted JS literal in an
    // inline event handler attribute. Closes backslash and quote only;
    // the surrounding HTML context is still escaped via escHtml above.
    function escJs(s) {
        if (s === null || s === undefined) { return ''; }
        s = String(s);
        return s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
    }

    // -------------------------------------------------------------------------
    // Cross-browser event binding. Prefer addEventListener (W3C), fall back
    // to attachEvent (IE<9), then to element.onevent = fn (legacy).
    // -------------------------------------------------------------------------
    function bindEvent(node, evt, handler) {
        if (!node || typeof handler !== 'function') { return; }
        if (typeof node.addEventListener === 'function') {
            node.addEventListener(evt, handler, false);
        } else if (typeof node.attachEvent === 'function') {
            node.attachEvent('on' + evt, handler);
        } else {
            node['on' + evt] = handler;
        }
    }

    // -------------------------------------------------------------------------
    // Modal helpers. There is one mask and any number of dialogs; showing a
    // dialog reveals the mask and the panel, hiding clears both. Clicking
    // the mask or any element with class "sc-modal-close" hides everything.
    // -------------------------------------------------------------------------
    function showModal(dialogId) {
        var mask = $id('sc-modal-mask');
        var dlg = $id(dialogId);
        if (mask) { mask.className = 'modal-mask modal-show'; }
        if (dlg)  { dlg.className = 'modal-dialog modal-show'; }
    }

    function hideModal() {
        var mask = $id('sc-modal-mask');
        if (mask) { mask.className = 'modal-mask'; }
        var ids = ['sc-modal-about', 'sc-modal-new-chat'];
        for (var i = 0; i < ids.length; i++) {
            var d = $id(ids[i]);
            if (d) { d.className = 'modal-dialog'; }
        }
    }

    // -------------------------------------------------------------------------
    // Render the history list into the sidebar.
    // Each row links to SC.App.loadChat(id) and exposes a delete button.
    // -------------------------------------------------------------------------
    function renderHistoryList(chats) {
        var list = $id('sc-history-list');
        if (!list) { return; }
        if (!chats || chats.length === 0) {
            list.innerHTML = '<li class="history-empty muted">No history yet.</li>';
            return;
        }
        var html = '';
        for (var i = 0; i < chats.length; i++) {
            var c = chats[i];
            var id = c.id || '';
            var title = c.title || 'Untitled';
            var meta = c.updated || c.created || '';
            var msgCount = c.message_count || 0;
            html += '<li data-id="' + escHtml(id) + '">'
                  +   '<a href="javascript:void(0)" '
                  +     'onclick="SC.App.loadChat(\'' + escJs(id) + '\')">'
                  +     '<span class="history-title">' + escHtml(title) + '</span>'
                  +     '<span class="history-meta">'
                  +       escHtml(meta)
                  +       (msgCount ? ' &middot; ' + msgCount + ' msg' : '')
                  +     '</span>'
                  +   '</a>'
                  +   '<a class="history-delete" href="javascript:void(0)" '
                  +     'onclick="SC.App.deleteChat(\'' + escJs(id) + '\')">'
                  +     '[x]</a>'
                  + '</li>';
        }
        list.innerHTML = html;
    }

    // -------------------------------------------------------------------------
    // Render the top status menu: model name, check button, tokens,
    // timeout, and the stream / non-stream badge.
    //
    // Stream badge policy: when stream=true the row stays empty (no
    // badge at all); when stream=false we render a red
    // "&#128274; non-stream" badge so the operator immediately notices
    // the request will block until completion.
    // -------------------------------------------------------------------------
    function renderTopMenu(provider) {
        var menu = $id('sc-top-menu');
        if (!menu) { return; }
        if (!provider) {
            menu.innerHTML = '<span class="muted">No provider selected.</span>';
            return;
        }
        var name = provider.display_name || provider.label || provider.id || '';
        var model = provider.model || '';
        var tokens = provider.max_tokens || 1024;
        var timeout = provider.timeout || 60;
        var html = '';
        html += '<div class="row">'
              +   '<label>Model:</label>'
              +   '<span class="model-name">' + escHtml(name)
              +     (model ? ' (' + escHtml(model) + ')' : '')
              +   '</span>'
              +   '<a class="btn btn-connect" href="javascript:void(0)" '
              +     'onclick="SC.App.connectCheck()">Check</a>'
              + '</div>';
        html += '<div class="row">'
              +   '<label>Tokens:</label>'
              +   '<span class="tokens">' + escHtml(String(tokens)) + '</span>'
              +   '<label>Timeout:</label>'
              +   '<span class="timeout">' + escHtml(String(timeout)) + 's</span>'
              + '</div>';
        html += '<div class="row badge-row">';
        if (provider.stream) {
            // stream=true -> deliberately no badge per the visual spec.
            html += '';
        } else {
            html += '<span class="status-badge status-off">'
                  + '&#128274; non-stream</span>';
        }
        html += '</div>';
        menu.innerHTML = html;
    }

    // -------------------------------------------------------------------------
    // Render the language switcher (delegates to SC.I18n.getLangSwitcherHTML).
    // -------------------------------------------------------------------------
    function renderLangSwitcher() {
        var holder = $id('sc-lang-switcher');
        if (!holder) { return; }
        if (SC.I18n && typeof SC.I18n.getLangSwitcherHTML === 'function') {
            holder.innerHTML = SC.I18n.getLangSwitcherHTML();
        }
    }

    // -------------------------------------------------------------------------
    // About dialog. Pulls title from the loaded config when available;
    // everything else is hard-coded (the values are constants, not user
    // input, so plain innerHTML is safe here).
    // -------------------------------------------------------------------------
    function showAboutDialog() {
        var body = $id('sc-about-body');
        if (!body) { return; }
        var title = (currentConfig && currentConfig.title) ? currentConfig.title : 'stoneChat';
        body.innerHTML =
            '<p><strong>' + escHtml(title) + '</strong></p>'
          + '<p>Protocol: stoneChat v1 (HTTP+JSON, file-based HISTORY/).</p>'
          + '<p>Author: stoneChat project.</p>'
          + '<p>A retro-styled multi-provider LLM chat client for LAN deployment.</p>'
          + '<p>GitHub: '
          +   '<a href="https://github.com/waterrun/stoneChat" '
          +     'target="_blank">github.com/waterrun/stoneChat</a></p>';
        showModal('sc-modal-about');
    }

    // -------------------------------------------------------------------------
    // "New chat" dialog. Loads providers via SC.Api.getProviders and
    // renders one row per provider with a per-row "Test" and "Use" button.
    // "Test" pings the provider without creating a chat; "Use" creates a
    // fresh conversation via SC.Api.createChat and dispatches to chat.js.
    // -------------------------------------------------------------------------
    function showNewChatDialog() {
        var list = $id('sc-newchat-list');
        if (!list) { return; }
        var resp = SC.Api.getProviders();
        if (!resp || !resp.ok) {
            renderNewChatError(list,
                'Failed to load providers: '
                + ((resp && resp.error) ? resp.error : 'unknown'));
            showModal('sc-modal-new-chat');
            return;
        }
        var providers = (resp.data && resp.data.providers)
                      ? resp.data.providers : [];
        if (providers.length === 0) {
            renderNewChatError(list, 'No providers configured.');
            showModal('sc-modal-new-chat');
            return;
        }
        list.innerHTML = renderNewChatListHTML(providers);
        showModal('sc-modal-new-chat');
    }

    // Render a single error / empty-state row inside the new-chat list.
    function renderNewChatError(list, message) {
        list.innerHTML = '<li class="muted">' + escHtml(message) + '</li>';
    }

    // Build the HTML for the provider rows shown inside the new-chat
    // dialog. Returns a string; the caller assigns it to innerHTML.
    function renderNewChatListHTML(providers) {
        var html = '';
        for (var i = 0; i < providers.length; i++) {
            var p = providers[i];
            var pid = p.id || '';
            var name = p.display_name || p.label || pid;
            html += '<li class="newchat-item" data-id="' + escHtml(pid) + '">';
            html +=   '<div class="newchat-name"><strong>'
                  +      escHtml(name)
                  +   '</strong></div>';
            html +=   '<div class="newchat-meta">model: '
                  +      escHtml(p.model || '-')
                  +   ' &middot; type: '
                  +      escHtml(p.type || '-')
                  +   '</div>';
            html +=   '<div class="newchat-meta">api: '
                  +      escHtml(p.api_base || '-')
                  +   '</div>';
            html +=   renderNewChatBadge(p);
            html +=   '<div class="newchat-actions">'
                  +     '<a class="btn" href="javascript:void(0)" '
                  +       'onclick="SC.App.connectCheck(\'' + escJs(pid) + '\')">'
                  +       'Test</a>'
                  +     '<a class="btn" href="javascript:void(0)" '
                  +       'onclick="SC.App.pickProvider(\'' + escJs(pid) + '\')">'
                  +       'Use</a>'
                  +   '</div>';
            html += '</li>';
        }
        return html;
    }

    // Stream badge for one provider row. stream=true -> empty (no badge),
    // stream=false -> red "non-stream" badge with a lock glyph.
    function renderNewChatBadge(provider) {
        if (provider.stream) {
            return '<div class="newchat-badges"></div>';
        }
        return '<div class="newchat-badges">'
             + '<span class="status-badge status-off">'
             + '&#128274; non-stream</span></div>';
    }

    // -------------------------------------------------------------------------
    // Create a new chat for the chosen provider. Then refresh the
    // sidebar (the new conversation will be the most-recent entry),
    // tell chat.js to switch its active id, and clear the message pane
    // so the empty conversation is reflected immediately. Falls back to
    // a page reload if chat.js is not yet on the page.
    // -------------------------------------------------------------------------
    function pickProvider(providerId) {
        var resp = SC.Api.createChat(providerId);
        if (!resp || !resp.ok || !resp.data || !resp.data.id) {
            alert('Create chat failed: '
                + ((resp && resp.error) ? resp.error : 'unknown'));
            return;
        }
        var newId = resp.data.id;
        hideModal();

        // Refresh sidebar list so the brand-new conversation appears.
        var h = SC.Api.getHistory();
        var rows = (h && h.ok && h.data && h.data.conversations)
                 ? h.data.conversations : [];
        currentChats = rows;
        renderHistoryList(rows);

        // Hand the new id to chat.js (best-effort - chat.js may not be
        // loaded yet on a freshly-served page).
        if (typeof SC.Chat !== 'undefined'
            && SC.Chat
            && typeof SC.Chat.setActiveChatId === 'function') {
            try { SC.Chat.setActiveChatId(newId); } catch (e) { /* ignore */ }
        }
        if (typeof SC.Chat !== 'undefined'
            && SC.Chat
            && typeof SC.Chat.clearMessages === 'function') {
            try { SC.Chat.clearMessages(); } catch (e) { /* ignore */ }
        }
    }

    // -------------------------------------------------------------------------
    // openConfigFile: a browser running on a client machine cannot open a
    // server-side file directly. We surface the location of CONF.ini on
    // the server host and remind the user that INSTALL.bat / a text editor
    // is the right tool for editing it. Then they can press "Reload config"
    // to pick up the changes.
    // -------------------------------------------------------------------------
    function openConfigFile() {
        alert(
            'CONF.ini lives on the server host, next to RUN.bat.\n\n'
          + 'A browser page cannot open a local file from the server.\n'
          + 'Edit CONF.ini with Notepad (or INSTALL.bat reopens it),\n'
          + 'then click "Reload config" to apply the changes.'
        );
    }

    // -------------------------------------------------------------------------
    // reloadConfig: ask the server to re-read CONF.ini, then full-reload the
    // page so every selector / menu / history list is rebuilt from scratch.
    // -------------------------------------------------------------------------
    function reloadConfig() {
        var resp = SC.Api.reloadConfig();
        if (!resp || !resp.ok) {
            alert('Reload failed: '
                + ((resp && resp.error) ? resp.error : 'unknown'));
            return;
        }
        if (typeof location !== 'undefined' && location.reload) {
            location.reload();
        }
    }

    // -------------------------------------------------------------------------
    // deleteChat: confirm, then call SC.Api.deleteChat, then refresh the
    // sidebar list so the removed conversation disappears.
    // -------------------------------------------------------------------------
    function deleteChat(chatId) {
        if (!chatId) { return; }
        if (!confirm('Delete this conversation? '
                   + 'On Windows it will be moved to the Recycle Bin.')) {
            return;
        }
        var resp = SC.Api.deleteChat(chatId);
        if (!resp || !resp.ok) {
            alert('Delete failed: '
                + ((resp && resp.error) ? resp.error : 'unknown'));
            return;
        }
        var h = SC.Api.getHistory();
        var rows = (h && h.ok && h.data && h.data.conversations)
                 ? h.data.conversations : [];
        currentChats = rows;
        renderHistoryList(rows);
    }

    // -------------------------------------------------------------------------
    // renameChat: thin wrapper over SC.Api.renameChat.
    // -------------------------------------------------------------------------
    function renameChat(chatId, newName) {
        if (!chatId) { return false; }
        var resp = SC.Api.renameChat(chatId, newName || '');
        if (!resp || !resp.ok) {
            alert('Rename failed: '
                + ((resp && resp.error) ? resp.error : 'unknown'));
            return false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // connectCheck: ping a provider. When providerId is omitted, the
    // currently selected provider is used (top-menu button).
    // -------------------------------------------------------------------------
    function connectCheck(providerId) {
        var pid = providerId
                || (currentProvider && currentProvider.id)
                || '';
        if (!pid) {
            alert('No provider selected.');
            return;
        }
        var resp = SC.Api.connectCheck(pid);
        if (resp && resp.ok) {
            alert('Connection OK (' + pid + ').');
        } else {
            alert('Connection failed (' + pid + '): '
                + ((resp && resp.error) ? resp.error : 'unknown'));
        }
    }

    // -------------------------------------------------------------------------
    // logout: clear the server-side session, then redirect to the login
    // page. The redirect runs unconditionally - if the API call failed
    // (e.g. cookie already gone) the login page will reject anyway.
    // -------------------------------------------------------------------------
    function logout() {
        try { SC.Api.logout(); } catch (e) { /* ignore */ }
        if (typeof location !== 'undefined') {
            location.href = loginUrl;
        }
    }

    // -------------------------------------------------------------------------
    // loadChat: switch the active conversation id in chat.js and clear the
    // message pane so the UI reflects the click immediately. A future
    // revision will replace the clear with a real load-from-server call;
    // today this is enough because the operator's next user message
    // re-anchors the visible history through the send loop.
    // -------------------------------------------------------------------------
    function loadChat(chatId) {
        if (!chatId) { return; }
        if (typeof SC.Chat !== 'undefined'
            && SC.Chat
            && typeof SC.Chat.setActiveChatId === 'function') {
            try { SC.Chat.setActiveChatId(chatId); } catch (e) { /* ignore */ }
        }
        if (typeof SC.Chat !== 'undefined'
            && SC.Chat
            && typeof SC.Chat.clearMessages === 'function') {
            try { SC.Chat.clearMessages(); } catch (e) { /* ignore */ }
        }
    }

    // -------------------------------------------------------------------------
    // Wire the persistent UI chrome (logo, sidebar buttons, toolbar
    // buttons, modal close handlers). Called once from bootstrap().
    // -------------------------------------------------------------------------
    function bindGlobalEvents() {
        bindEvent($id('sc-logo'),              'click', showAboutDialog);
        bindEvent($id('sc-new-chat-btn'),      'click', showNewChatDialog);
        bindEvent($id('sc-reload-config-btn'), 'click', reloadConfig);
        bindEvent($id('sc-open-config-btn'),   'click', openConfigFile);
        bindEvent($id('sc-logout-btn'),        'click', logout);
        bindEvent($id('sc-modal-mask'),        'click', hideModal);

        // Any button carrying class "sc-modal-close" hides the open dialog.
        var nodes = document.getElementsByTagName('button');
        for (var i = 0; i < nodes.length; i++) {
            var b = nodes[i];
            if (b.className
                && b.className.indexOf('sc-modal-close') >= 0) {
                bindEvent(b, 'click', hideModal);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Page-load entry point. Steps run in order so a partial failure
    // leaves the page in a sensible state (e.g. if providers fail to load
    // we still render the empty sidebar and the language switcher).
    // -------------------------------------------------------------------------
    function bootstrap() {
        if (typeof SC.Api === 'undefined' || !SC.Api) {
            // api.js failed to load - we have no way to talk to the
            // server, so the rest of bootstrap is meaningless.
            return;
        }

        // 1. Public config (title, supported languages, auth flag, ...).
        var cfgResp = SC.Api.getConfig();
        if (cfgResp && cfgResp.ok && cfgResp.data) {
            currentConfig = cfgResp.data;
        }

        // 2. Providers list (used by the top menu and the new-chat dialog).
        var provResp = SC.Api.getProviders();
        if (provResp && provResp.ok
            && provResp.data && provResp.data.providers
            && provResp.data.providers.length > 0) {
            currentProvider = provResp.data.providers[0];
        }

        // 3. History list (sidebar).
        var histResp = SC.Api.getHistory();
        if (histResp && histResp.ok
            && histResp.data && histResp.data.conversations) {
            currentChats = histResp.data.conversations;
        }

        // 4. Render the sidebar history list.
        renderHistoryList(currentChats);

        // 5. Render the top status menu.
        renderTopMenu(currentProvider);

        // 6. Render the language switcher (uses SC.I18n from i18n.js).
        renderLangSwitcher();

        // 7. Wire persistent UI chrome (logo / sidebar / toolbar / modals).
        bindGlobalEvents();
    }

    // -------------------------------------------------------------------------
    // Export.
    // -------------------------------------------------------------------------
    SC.App = {
        bootstrap:          bootstrap,
        renderHistoryList:  renderHistoryList,
        renderTopMenu:      renderTopMenu,
        renderLangSwitcher: renderLangSwitcher,
        showNewChatDialog:  showNewChatDialog,
        showAboutDialog:    showAboutDialog,
        openConfigFile:     openConfigFile,
        reloadConfig:       reloadConfig,
        deleteChat:         deleteChat,
        renameChat:         renameChat,
        connectCheck:       connectCheck,
        logout:             logout,
        loadChat:           loadChat,
        pickProvider:       pickProvider,
        hideModal:          hideModal
    };

    window.SC = SC;
    // Lowercase alias kept for any in-tree callers already using it.
    window.scApp = SC.App;

    // Auto-bootstrap: include this script via defer or as the last tag in
    // <body> so the elements referenced above already exist.
    if (typeof document !== 'undefined'
        && document.getElementsByTagName
        && document.body) {
        bootstrap();
    }
}());
