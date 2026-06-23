/* -------------------------------------------------------------------------
 * stoneChat / Pages/js/app.js
 *
 * Application bootstrap and UI glue for the post-login chat page.
 * IE6-compatible (ES3 / ES5 only): no fetch / Promise / arrow / let
 * / const; no Array.forEach / .map; XHR via SC.Api (api.js); DOM
 * events wired with attachEvent / addEventListener fallback; no
 * localStorage (state lives in window.SC.App module variables).
 *
 * Public namespace: window.SC.App (alias: window.scApp)
 *
 * Methods
 *   bootstrap()              page-load entry point (auto-called)
 *   renderHistoryList(chats) sidebar list
 *   renderTopMenu(provider)  top status row
 *   showNewChatDialog()      modal: pick a provider to start a new chat
 *   showAboutDialog()        modal: environment / author / GitHub
 *   openConfigFile()         degraded hint (browser cannot open local)
 *   reloadConfig()           ask server to re-read CONF.ini, then reload
 *   deleteChat(chatId)       confirm then SC.Api.deleteChat
 *   renameChat(id, name)     SC.Api.renameChat
 *   connectCheck(providerId) SC.Api.connectCheck (defaults to current)
 *   logout()                 stop stream, async logout, then redirect
 *
 * Expected DOM IDs (provided by chat.htm)
 *   sc-logo, sc-lang-switcher, sc-new-chat-btn, sc-reload-config-btn,
 *   sc-open-config-btn, sc-logout-btn, sc-history-list, sc-top-menu,
 *   sc-modal-mask, sc-modal-about, sc-modal-new-chat, sc-about-body,
 *   sc-newchat-list, sc-modal-close
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Module state (kept small; never used for sensitive data).
    // -------------------------------------------------------------------------
    var currentProvider = null;   // currently selected provider row
    var currentChats = [];        // last loaded history list
    var currentConfig = null;     // last loaded /api/config payload
    var currentUsername = '';     // display-only name from auth/config
    var historySearchText = '';   // lower-case sidebar filter
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

    function tr(key, fallback) {
        if (SC.I18n && typeof SC.I18n.t === 'function') {
            var value = SC.I18n.t(key);
            if (value && value !== key) { return value; }
        }
        return fallback;
    }

    function errText(resp) {
        if (resp && resp.error) { return resp.error; }
        return tr('error.unknown', 'unknown');
    }

    function formatHistoryTime(value) {
        if (!value) { return ''; }
        var s = String(value);
        var m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?/.exec(s);
        if (!m) { return s; }
        var d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]),
                         Number(m[4]), Number(m[5]), m[6] ? Number(m[6]) : 0);
        if (!d || isNaN(d.getTime())) { return s; }
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-'
             + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':'
             + pad(d.getMinutes());
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
        currentChats = chats || [];
        if (!chats || chats.length === 0) {
            list.innerHTML = '<li class="history-empty muted">'
                + escHtml(tr('sidebar.noHistory', 'No history yet.'))
                + '</li>';
            return;
        }
        var html = '';
        var shown = 0;
        for (var i = 0; i < chats.length; i++) {
            var c = chats[i];
            var id = c.id || '';
            var title = c.title || tr('chat.untitled', 'Untitled');
            var meta = formatHistoryTime(c.updated || c.created || '');
            var msgCount = c.message_count || 0;
            var hay = (title + ' ' + meta + ' ' + id).toLowerCase();
            if (historySearchText !== ''
                && hay.indexOf(historySearchText) === -1) {
                continue;
            }
            shown++;
            html += '<li data-id="' + escHtml(id) + '">'
                  +   '<a href="javascript:void(0)" '
                  +     'onclick="SC.App.loadChat(\'' + escJs(id) + '\')">'
                  +     '<span class="history-title">' + escHtml(title) + '</span>'
                  +     '<span class="history-meta">'
                  +       escHtml(meta)
                  +       (msgCount ? ' &middot; ' + msgCount + ' msg' : '')
                  +     '</span>'
                  +   '</a>'
                  +   '<a class="history-rename" href="javascript:void(0)" '
                  +     'onclick="SC.App.renameChatPrompt(\'' + escJs(id) + '\', \'' + escHtml(escJs(title)) + '\')">'
                  +     '[r]</a>'
                  +   '<a class="history-delete" href="javascript:void(0)" '
                  +     'onclick="SC.App.deleteChat(\'' + escJs(id) + '\')">'
                  +     '[x]</a>'
                  + '</li>';
        }
        if (shown === 0) {
            html = '<li class="history-empty muted">'
                 + escHtml(tr('sidebar.noMatch', 'No match.'))
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
            menu.innerHTML = '<span class="muted">'
                + escHtml(tr('chat.noModelSelected', 'No model selected.'))
                + '</span>';
            return;
        }
        var name = provider.display_name || provider.label || provider.id || '';
        var model = provider.model || '';
        var tokens = provider.max_tokens || 1024;
        var timeout = provider.timeout || 60;
        var html = '';
        html += '<div class="top-model-row">'
              +   '<span class="top-label">' + escHtml(tr('chat.model', 'Model')) + ':</span>'
              +   '<span class="model-name">' + escHtml(name)
              +     (model ? ' (' + escHtml(model) + ')' : '')
              +   '</span>'
              +   '<a class="btn btn-connect" href="javascript:void(0)" '
              +     'onclick="SC.App.connectCheck()">'
              +     escHtml(tr('chat.check', 'Check')) + '</a>'
              +   '<span class="user-mark" id="sc-user-mark">'
              +      escHtml(tr('chat.user', 'User') + ': '
              +      (currentUsername || '-'))
              +   '</span>'
              + '</div>';
        html += '<div class="top-meta-row">'
              +   '<span class="top-label">' + escHtml(tr('chat.tokens', 'Tokens')) + ':</span>'
              +   '<span class="tokens">' + escHtml(String(tokens)) + '</span>'
              +   '<span class="top-label top-label-short">' + escHtml(tr('chat.timeout', 'Timeout')) + ':</span>'
              +   '<span class="timeout">' + escHtml(String(timeout)) + 's</span>'
              +   '<span class="badge-row">';
        if (!provider.stream) {
            html += '<span class="status-badge status-off">'
                  + '&#128274; non-stream</span>';
        }
        html +=   '</span></div>';
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

    function renderUserMark(authResp) {
        var mark = $id('sc-user-mark');
        if (!mark) { return; }
        var username = '';
        if (authResp && authResp.ok && authResp.data) {
            username = authResp.data.username || '';
        }
        if (username === '' && currentConfig) {
            username = currentConfig.username || '';
        }
        currentUsername = username;
        if (username === '') {
            setText(mark, tr('chat.user', 'User') + ': -');
        } else {
            setText(mark, tr('chat.user', 'User') + ': ' + username);
        }
    }

    function yesNo(flag) {
        return flag ? 'Yes' : 'No';
    }

    function detectModernBrowser() {
        return !!(window.XMLHttpRequest
            && document.addEventListener
            && document.querySelector
            && window.JSON);
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
        var runtime = (currentConfig && currentConfig.runtime)
                    ? currentConfig.runtime : {};
        body.innerHTML =
            '<p class="about-logo"><img src="../Assets/logo_icon.png" alt="stoneChat" width="48" height="48" />'
          + '<strong>' + escHtml(title) + '</strong></p>'
          + '<p>"a caveman peeking at modern technology"</p>'
          + '<p>Modern Windows: '
          +   escHtml(yesNo(!!runtime.modern_windows)) + '</p>'
          + '<p>Modern Browser: '
          +   escHtml(yesNo(detectModernBrowser())) + '</p>'
          + '<p>Time Zone: '
          +   escHtml(runtime.timezone || '-') + '</p>'
          + '<p>PHP: ' + escHtml(runtime.php_version || '-')
          + ' / OS: ' + escHtml(runtime.os || '-') + '</p>'
          + '<p>Author: WaterRun.</p>'
          + '<p>GitHub: '
          +   '<a href="https://github.com/WaterRun/stoneChat" '
          +     'target="_blank">github.com/WaterRun/stoneChat</a></p>';
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
                tr('chat.loadModelsFailed', 'Failed to load models') + ': '
                + errText(resp));
            showModal('sc-modal-new-chat');
            return;
        }
        var providers = (resp.data && resp.data.providers)
                      ? resp.data.providers : [];
        if (providers.length === 0) {
            renderNewChatError(list, tr('chat.noModels', 'No models configured.'));
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
            html +=   '<div class="newchat-meta">' + escHtml(tr('chat.model', 'Model')) + ': '
                  +      escHtml(p.model || '-')
                  +   ' &middot; ' + escHtml(tr('chat.type', 'Type')) + ': '
                  +      escHtml(p.type || '-')
                  +   '</div>';
            html +=   '<div class="newchat-meta">' + escHtml(tr('chat.api', 'API')) + ': '
                  +      escHtml(p.api_base || '-')
                  +   '</div>';
            html +=   renderNewChatBadge(p);
            html +=   '<div class="newchat-actions">'
                  +     '<a class="btn" href="javascript:void(0)" '
                  +       'onclick="SC.App.connectCheck(\'' + escJs(pid) + '\')">'
                  +       escHtml(tr('chat.test', 'Test')) + '</a>'
                  +     '<a class="btn" href="javascript:void(0)" '
                  +       'onclick="SC.App.pickProvider(\'' + escJs(pid) + '\')">'
                  +       escHtml(tr('chat.use', 'Use')) + '</a>'
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
            alert(tr('chat.createFailed', 'Create chat failed') + ': '
                + errText(resp));
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

        loadChat(newId);
    }

    // -------------------------------------------------------------------------
    // openConfigFile: open the server-side editor. Permission is enforced
    // by editor.php so IE cannot block a valid Admin with a stale
    // cached config.php response.
    // -------------------------------------------------------------------------
    function openConfigFile() {
        window.open('editor.php?open_native=1', '_blank',
                    'width=800,height=600');
    }

    // -------------------------------------------------------------------------
    // reloadConfig: ask the server to re-read CONF.ini, then full-reload the
    // page so every selector / menu / history list is rebuilt from scratch.
    // -------------------------------------------------------------------------
    function reloadConfig() {
        var resp = SC.Api.reloadConfig();
        if (!resp || !resp.ok) {
            alert(tr('chat.reloadFailed', 'Reload failed') + ': '
                + errText(resp));
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
        if (!confirm(tr('chat.deleteConfirm',
                        'Delete this conversation?'))) {
            return;
        }
        var isActive = false;
        if (typeof SC.Chat !== 'undefined' && SC.Chat && typeof SC.Chat.getActiveChatId === 'function') {
            isActive = (SC.Chat.getActiveChatId() === chatId);
        }
        var resp = SC.Api.deleteChat(chatId);
        if (!resp || !resp.ok) {
            alert(tr('chat.deleteFailed', 'Delete failed') + ': '
                + errText(resp));
            return;
        }
        var h = SC.Api.getHistory();
        var rows = (h && h.ok && h.data && h.data.conversations)
                 ? h.data.conversations : [];
        currentChats = rows;
        renderHistoryList(rows);

        if (isActive) {
            if (rows.length > 0) {
                loadChat(rows[0].id);
            } else {
                if (typeof SC.Chat !== 'undefined' && SC.Chat && typeof SC.Chat.clearMessages === 'function') {
                    try { SC.Chat.clearMessages(); } catch (e) { /* ignore */ }
                }
                if (typeof SC.Chat !== 'undefined' && SC.Chat && typeof SC.Chat.setActiveChatId === 'function') {
                    try { SC.Chat.setActiveChatId(''); } catch (e) { /* ignore */ }
                }
            }
        } else {
            var activeId = '';
            if (typeof SC.Chat !== 'undefined' && SC.Chat && typeof SC.Chat.getActiveChatId === 'function') {
                activeId = SC.Chat.getActiveChatId();
            }
            if (activeId) {
                var list = $id('sc-history-list');
                if (list) {
                    var items = list.getElementsByTagName('li');
                    for (var i = 0; i < items.length; i++) {
                        var item = items[i];
                        if (item.getAttribute('data-id') === activeId) {
                            item.className = 'history-active';
                        }
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // renameChat: thin wrapper over SC.Api.renameChat.
    // -------------------------------------------------------------------------
    function renameChat(chatId, newName) {
        if (!chatId) { return false; }
        var resp = SC.Api.renameChat(chatId, newName || '');
        if (!resp || !resp.ok) {
            alert(tr('chat.renameFailed', 'Rename failed') + ': '
                + errText(resp));
            return false;
        }
        return true;
    }

    function renameChatPrompt(chatId, oldName) {
        if (!chatId) { return; }
        var promptText = tr('chat.renameChat', 'Rename chat');
        var name = window.prompt ? window.prompt(promptText, oldName || '') : '';
        if (name === null || name === undefined) { return; }
        name = String(name).replace(/^\s+|\s+$/g, '');
        if (name === '') { return; }
        if (renameChat(chatId, name)) {
            var h = SC.Api.getHistory();
            var rows = (h && h.ok && h.data && h.data.conversations)
                     ? h.data.conversations : [];
            renderHistoryList(rows);
            loadChat(chatId);
        }
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
            alert(tr('chat.noModelSelected', 'No model selected.'));
            return;
        }
        var resp = SC.Api.connectCheck(pid);
        if (resp && resp.ok) {
            alert(tr('chat.connectionOk', 'Connection OK') + ' (' + pid + ').');
        } else {
            alert(tr('chat.connectionFailed', 'Connection failed') + ' (' + pid + '): '
                + errText(resp));
        }
    }

    // -------------------------------------------------------------------------
    // logout: clear the server-side session, then redirect to the login
    // page. The redirect runs unconditionally - if the API call failed
    // (e.g. cookie already gone) the login page will reject anyway.
    // -------------------------------------------------------------------------
    function logout() {
        if (typeof SC.Chat !== 'undefined' && SC.Chat
            && typeof SC.Chat.stop === 'function') {
            try { SC.Chat.stop(); } catch (e1) { /* ignore */ }
        }
        try {
            if (SC.Api && typeof SC.Api.logoutAsync === 'function') {
                SC.Api.logoutAsync(function () {});
            }
        } catch (e2) { /* ignore */ }
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

        // Highlight active chat in the sidebar.
        var list = $id('sc-history-list');
        if (list) {
            var items = list.getElementsByTagName('li');
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                if (item.getAttribute('data-id') === chatId) {
                    item.className = 'history-active';
                } else {
                    item.className = '';
                }
            }
        }

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

        // Load messages from server and render them.
        if (window.SC && window.SC.Api && typeof window.SC.Api.getChat === 'function') {
            try {
                var resp = window.SC.Api.getChat(chatId);
                if (resp && resp.ok && resp.data && resp.data.messages) {
                    var messages = resp.data.messages;
                    for (var j = 0; j < messages.length; j++) {
                        var msg = messages[j];
                        if (typeof SC.Chat.renderMessage === 'function') {
                            SC.Chat.renderMessage(msg.role, msg.text, null);
                        }
                    }
                    // Select model in top menu if provider_id is in meta
                    if (resp.data.meta && resp.data.meta.provider_id) {
                        var providersResp = window.SC.Api.getProviders();
                        if (providersResp && providersResp.ok && providersResp.data && providersResp.data.providers) {
                            var providers = providersResp.data.providers;
                            for (var k = 0; k < providers.length; k++) {
                                if (providers[k].id === resp.data.meta.provider_id) {
                                    currentProvider = providers[k];
                                    renderTopMenu(currentProvider);
                                    break;
                                }
                            }
                        }
                    }
                }
            } catch (err) { /* ignore */ }
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
        bindEvent($id('sc-about-btn'),         'click', showAboutDialog);
        bindEvent($id('sc-logout-btn'),        'click', logout);
        bindEvent($id('sc-modal-mask'),        'click', hideModal);
        bindEvent($id('sc-history-search'),    'keyup', function () {
            var node = $id('sc-history-search');
            historySearchText = node ? String(node.value || '').toLowerCase() : '';
            renderHistoryList(currentChats);
        });
        bindEvent($id('sc-history-search'),    'change', function () {
            var node = $id('sc-history-search');
            historySearchText = node ? String(node.value || '').toLowerCase() : '';
            renderHistoryList(currentChats);
        });
        var search = $id('sc-history-search');
        if (search) {
            search.setAttribute('title',
                                tr('sidebar.search', 'Search conversations'));
            search.setAttribute('placeholder',
                                tr('sidebar.search', 'Search conversations'));
        }

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

        var authResp = null;
        if (typeof SC.Api.checkAuth === 'function') {
            authResp = SC.Api.checkAuth();
            if (!authResp || !authResp.ok) {
                window.location.href = 'index.htm';
                return;
            }
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
        if (histResp && histResp.ok === false && histResp.error && (histResp.error.indexOf('401') >= 0 || histResp.error.indexOf('auth_required') >= 0)) {
            window.location.href = 'index.htm';
            return;
        }
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
        renderUserMark(authResp);
        renderTopMenu(currentProvider);

        // 6.5. Initialize Chat UI handlers (uses SC.Chat from chat.js).
        if (typeof SC.Chat !== 'undefined' && SC.Chat && typeof SC.Chat.init === 'function') {
            var providers = (provResp && provResp.ok && provResp.data && provResp.data.providers)
                          ? provResp.data.providers : [];
            SC.Chat.init(currentConfig, providers);
        }

        // 7. Wire persistent UI chrome (logo / sidebar / toolbar / modals).
        bindGlobalEvents();

        // 8. Load the first chat on bootstrap if present.
        if (currentChats && currentChats.length > 0) {
            loadChat(currentChats[0].id);
        }
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
        renameChatPrompt:   renameChatPrompt,
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
