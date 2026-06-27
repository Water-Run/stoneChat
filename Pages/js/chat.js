/* -------------------------------------------------------------------------
 * stoneChat / Pages/js/chat.js
 *
 * Chat interaction layer for the stoneChat web client.
 *
 * IE6 / Win-XP compatible (ES3 only): var / function declarations
 * only; no fetch / Promise / localStorage / JSON.parse assumptions;
 * XMLHttpRequest with ActiveX fallback (delegated to SC.Api);
 * textContent / createElement for all user-controlled content (no
 * innerHTML injection -> XSS protection).
 *
 * Public namespaces:
 *   window.SC.Chat   primary namespace (per the user-facing spec)
 *   window.scChat    legacy alias kept for in-tree callers
 *
 * Methods:
 *   init(config, providers)         bootstrap, wire up UI controls
 *   renderMessage(role, content, ts) append a message bubble
 *   sendMessage(chatId, text)       send to provider, render user + AI
 *   stop()                          abort the in-flight XHR
 *   regenerate()                    resend the last user message
 *   startCountdown()                begin the "waiting" timer
 *   stopCountdown()                 halt and reset the timer
 *   updateCharCount(text, max)      update the "字数: N / M" display
 *   handleEnterKey(e, callback)     Enter/Shift+Enter send shortcut
 *   scrollToBottom()                auto-scroll messages container
 *   clearMessages()                 empty the messages container
 *   deleteConversation(chatId)      delete a conversation
 *   setActiveChatId(chatId)         remember which chat is active
 *
 * The user-facing spec mandates a synchronous send via SC.Api.sendMessage.
 * When the backend grows an SSE streaming endpoint, the renderMessage +
 * startCountdown pair is the integration point -- the assistant bubble
 * is already created as a normal DOM node, so chunked appendage is a
 * 2-line follow-up: streamChunk(text) -> node.lastChild.textContent
 * += text.
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Configuration constants
     * ------------------------------------------------------------------ */

    // Soft maximum for the user input. Mirrors the documented limit used
    // by most LLM providers (32k tokens ~ 32k chars for our purposes).
    var SC_DEFAULT_MAX_CHARS = 32768;

    // One tick per second keeps the countdown text live without thrashing
    // the (very old) IE6 layout engine.
    var SC_COUNTDOWN_INTERVAL_MS = 1000;

    // Human-readable role labels. Kept short to fit the 2001-era UI.
    var SC_ROLE_LABELS = {
        user: '\u4f60',        // "you" (Chinese, kept ASCII-safe here)
        assistant: 'AI',
        system: 'System'
    };
    // Fallback if the above is not desired:
    var SC_ROLE_LABELS_EN = { user: 'You', assistant: 'AI', system: 'System' };

    /* ------------------------------------------------------------------
     * Module state (single object so callers can't pollute internals)
     * ------------------------------------------------------------------ */

    var state = {
        activeXhr: null,        // last XHR we own (for stop())
        lastUserMessage: null,  // most recent user text (for regenerate)
        lastChatId: null,       // conversation id used by the last send
        lastAssistantNode: null,// last assistant bubble (for streaming)
        countdownTimer: null,   // window.setInterval handle
        countdownSeconds: 0,    // elapsed seconds (UI label)
        config: null,           // SC.Chat.init(config) value
        providers: null,        // SC.Chat.init(providers) value
        isStreaming: false,     // true while a request is in flight
        isNaming: false         // true while title generation is in flight
    };

    /* ------------------------------------------------------------------
     * Cross-browser event attach (IE6 attachEvent fallback)
     * ------------------------------------------------------------------ */

    function sc_attachEvent(elem, type, handler) {
        if (!elem) { return; }
        if (typeof elem.addEventListener === 'function') {
            elem.addEventListener(type, handler, false);
        } else if (typeof elem.attachEvent === 'function') {
            // IE6-8 path
            elem.attachEvent('on' + type, handler);
        } else {
            // Last-ditch: assign the property directly.
            elem['on' + type] = handler;
        }
    }

    /* ------------------------------------------------------------------
     * Safe DOM factory: create element, set className, set text via
     * textContent (never innerHTML) so user data can't inject markup.
     * ------------------------------------------------------------------ */

    function sc_makeEl(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== null && text !== undefined) {
            if (typeof node.textContent === 'string') {
                node.textContent = text;
            } else {
                node.innerText = text;
            }
        }
        return node;
    }

    function sc_setText(node, value) {
        if (!node) { return; }
        if (typeof node.textContent === 'string') {
            node.textContent = value;
        } else {
            node.innerText = value;
        }
    }

    function sc_tr(key, fallback) {
        if (window.SC && window.SC.I18n
            && typeof window.SC.I18n.t === 'function') {
            var text = window.SC.I18n.t(key);
            if (text && text !== key) { return text; }
        }
        return fallback;
    }

    function sc_fmt(text, a, b) {
        var out = String(text || '');
        out = out.replace('{n}', String(a));
        out = out.replace('{m}', String(b));
        return out;
    }

    /* ------------------------------------------------------------------
     * DOM lookup helpers - prefer id, fall back to first class match.
     * ------------------------------------------------------------------ */

    function sc_findMessagesContainer() {
        return document.getElementById('chat-messages')
            || document.getElementById('messages')
            || sc_findByClass('chat-messages');
    }

    function sc_findCountdownNode() {
        return document.getElementById('countdown')
            || sc_findByClass('countdown');
    }

    function sc_findCharCountNode() {
        return document.getElementById('char-count')
            || sc_findByClass('char-count');
    }

    function sc_findSendHintNode() {
        return document.getElementById('send-hint')
            || sc_findByClass('send-hint');
    }

    function sc_findInput() {
        return document.getElementById('chat-input')
            || document.getElementsByTagName('textarea').item(0)
            || null;
    }

    function sc_findButton(id, className) {
        var byId = document.getElementById(id);
        if (byId) { return byId; }
        return sc_findByClass(className);
    }

    function sc_findByClass(className) {
        if (!document.getElementsByTagName) { return null; }
        var nodes = document.getElementsByTagName('*');
        var wanted = ' ' + className + ' ';
        for (var i = 0; nodes && i < nodes.length; i++) {
            var cls = nodes[i].className || '';
            if ((' ' + cls + ' ').indexOf(wanted) !== -1) {
                return nodes[i];
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * Date formatting: "YYYY-MM-DD HH:MM" in the user's local time.
     * The spec demands this exact shape; pad manually because IE6 has no
     * String.prototype.padStart.
     * ------------------------------------------------------------------ */

    function sc_formatTimestamp(d) {
        var date = d instanceof Date ? d : new Date();
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-'
            + pad(date.getDate()) + ' ' + pad(date.getHours()) + ':'
            + pad(date.getMinutes());
    }

    /* ------------------------------------------------------------------
     * Small, safe Markdown subset.
     *
     * The parser is deliberately old-fashioned: no innerHTML, no regex
     * replace callbacks, no dependencies. It creates DOM nodes directly
     * so raw HTML in model output remains text.
     * ------------------------------------------------------------------ */

    function sc_emptyNode(node) {
        if (!node) { return; }
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
        if (node.children && node.children.length) {
            while (node.children.length) {
                node.removeChild(node.children[0]);
            }
        }
        if (typeof node.textContent === 'string') {
            node.textContent = '';
        } else if (typeof node.innerText === 'string') {
            node.innerText = '';
        }
    }

    function sc_appendText(parent, text) {
        if (!parent || text === '') { return; }
        parent.appendChild(document.createTextNode(String(text)));
    }

    function sc_safeLink(url) {
        if (!url) { return ''; }
        var u = String(url);
        var l = u.toLowerCase();
        if (l.indexOf('http://') === 0 || l.indexOf('https://') === 0
            || l.indexOf('mailto:') === 0) {
            return u;
        }
        return '';
    }

    function sc_appendInline(parent, text) {
        var s = String(text || '');
        var i = 0;
        while (i < s.length) {
            if (s.charAt(i) === '`') {
                var ce = s.indexOf('`', i + 1);
                if (ce > i + 1) {
                    var code = sc_makeEl('code', 'md-code', null);
                    sc_appendText(code, s.substring(i + 1, ce));
                    parent.appendChild(code);
                    i = ce + 1;
                    continue;
                }
            }
            if (s.substr(i, 2) === '**') {
                var se = s.indexOf('**', i + 2);
                if (se > i + 2) {
                    var strong = sc_makeEl('strong', '', null);
                    sc_appendInline(strong, s.substring(i + 2, se));
                    parent.appendChild(strong);
                    i = se + 2;
                    continue;
                }
            }
            if (s.charAt(i) === '*'
                && s.substr(i, 2) !== '**') {
                var ee = s.indexOf('*', i + 1);
                if (ee > i + 1 && s.charAt(i + 1) !== ' ') {
                    var em = sc_makeEl('em', '', null);
                    sc_appendInline(em, s.substring(i + 1, ee));
                    parent.appendChild(em);
                    i = ee + 1;
                    continue;
                }
            }
            if (s.charAt(i) === '[') {
                var lb = s.indexOf('](', i + 1);
                if (lb !== -1) {
                    var rb = s.indexOf(')', lb + 2);
                    if (rb !== -1) {
                        var label = s.substring(i + 1, lb);
                        var rawUrl = s.substring(lb + 2, rb);
                        var url = sc_safeLink(rawUrl);
                        if (url !== '') {
                            var a = sc_makeEl('a', '', null);
                            a.setAttribute('href', url);
                            a.setAttribute('target', '_blank');
                            sc_appendInline(a, label);
                            parent.appendChild(a);
                        } else {
                            sc_appendText(parent, s.substring(i, rb + 1));
                        }
                        i = rb + 1;
                        continue;
                    }
                }
            }
            var next = s.length;
            var marks = ['`', '**', '*', '['];
            for (var m = 0; m < marks.length; m++) {
                var p = s.indexOf(marks[m], i + 1);
                if (p !== -1 && p < next) { next = p; }
            }
            sc_appendText(parent, s.substring(i, next));
            i = next;
        }
    }

    function sc_listInfo(line) {
        var m = /^(\s*)([-*])\s+(.+)$/.exec(line);
        if (m) {
            return { tag: 'ul', text: m[3] };
        }
        m = /^(\s*)[0-9]+\.\s+(.+)$/.exec(line);
        if (m) {
            return { tag: 'ol', text: m[2] };
        }
        return null;
    }

    function sc_appendParagraph(parent, lines) {
        if (!lines || lines.length === 0) { return; }
        var p = sc_makeEl('p', 'md-p', null);
        sc_appendInline(p, lines.join('\n'));
        parent.appendChild(p);
    }

    function sc_appendCodeBlock(parent, lines) {
        var pre = sc_makeEl('pre', 'md-pre', null);
        var code = sc_makeEl('code', 'md-codeblock', null);
        sc_appendText(code, lines.join('\n'));
        pre.appendChild(code);
        parent.appendChild(pre);
    }

    function sc_appendThinkBlock(parent, lines) {
        if (!lines || lines.length === 0) { return; }
        var box = sc_makeEl('div', 'md-think', null);
        var title = sc_makeEl('div', 'md-think-title', 'think');
        var body = sc_makeEl('div', 'md-think-body', null);
        sc_appendInline(body, lines.join('\n'));
        box.appendChild(title);
        box.appendChild(body);
        parent.appendChild(box);
    }

    function sc_renderMarkdown(parent, content) {
        sc_emptyNode(parent);
        var text = String(content || '');
        text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        var lines = text.split('\n');
        var para = [];
        var inCode = false;
        var inThink = false;
        var codeLines = [];
        var thinkLines = [];
        var i;

        for (i = 0; i < lines.length; i++) {
            var line = lines[i];
            var tag = line.replace(/^\s+|\s+$/g, '').toLowerCase();
            if (line.indexOf('```') === 0) {
                if (inCode) {
                    sc_appendCodeBlock(parent, codeLines);
                    codeLines = [];
                    inCode = false;
                } else {
                    sc_appendParagraph(parent, para);
                    para = [];
                    inCode = true;
                }
                continue;
            }
            if (inCode) {
                codeLines.push(line);
                continue;
            }
            if (tag === '<think>') {
                sc_appendParagraph(parent, para);
                para = [];
                inThink = true;
                thinkLines = [];
                continue;
            }
            if (tag === '</think>') {
                sc_appendThinkBlock(parent, thinkLines);
                thinkLines = [];
                inThink = false;
                continue;
            }
            if (inThink) {
                thinkLines.push(line);
                continue;
            }
            if (/^\s*$/.test(line)) {
                sc_appendParagraph(parent, para);
                para = [];
                continue;
            }
            var heading = /^(#{1,3})\s+(.+)$/.exec(line);
            if (heading) {
                sc_appendParagraph(parent, para);
                para = [];
                var h = sc_makeEl('h' + heading[1].length, 'md-heading', null);
                sc_appendInline(h, heading[2]);
                parent.appendChild(h);
                continue;
            }
            var li = sc_listInfo(line);
            if (li) {
                sc_appendParagraph(parent, para);
                para = [];
                var list = sc_makeEl(li.tag, 'md-list', null);
                while (i < lines.length) {
                    li = sc_listInfo(lines[i]);
                    if (!li || li.tag !== list.tagName.toLowerCase()) {
                        i--;
                        break;
                    }
                    var item = sc_makeEl('li', '', null);
                    sc_appendInline(item, li.text);
                    list.appendChild(item);
                    i++;
                }
                parent.appendChild(list);
                continue;
            }
            para.push(line);
        }
        if (inCode) {
            sc_appendCodeBlock(parent, codeLines);
        }
        if (inThink) {
            sc_appendThinkBlock(parent, thinkLines);
        }
        sc_appendParagraph(parent, para);
    }

    /* ------------------------------------------------------------------
     * Public: renderMessage(role, content, timestamp)
     * Append a new message bubble to the messages container. Returns the
     * wrapper DOM node so callers (or a future SSE stream) can update it.
     * ------------------------------------------------------------------ */

    function sc_renderMessage(role, content, timestamp) {
        var container = sc_findMessagesContainer();
        if (!container) { return null; }
        var safeRole = (role === 'user' || role === 'assistant'
                        || role === 'system') ? role : 'assistant';
        var fallbackLabels = (state.config && state.config.labels) || SC_ROLE_LABELS;
        var roleFallback = fallbackLabels[safeRole] || SC_ROLE_LABELS_EN[safeRole] || safeRole;
        var roleText = window.SC.I18n.t('role.' + safeRole);
        if (roleText === 'role.' + safeRole) { roleText = roleFallback; }
        var ts = timestamp ? sc_formatTimestamp(timestamp)
                 : sc_formatTimestamp(new Date());
        var wrapper = sc_makeEl('div', 'msg ' + safeRole + '-message');
        wrapper.appendChild(sc_makeEl('span', 'msg-role',
            roleText + ' @ ' + ts));
        var body = sc_makeEl('div', 'msg-body', null);
        sc_renderMarkdown(body, content);
        wrapper.appendChild(body);
        container.appendChild(wrapper);
        sc_scrollToBottom();
        if (safeRole === 'assistant') {
            state.lastAssistantNode = wrapper;
        }
        return wrapper;
    }

    /* ------------------------------------------------------------------
     * Public: clearMessages() - empty the messages container.
     * Uses a removeChild loop because IE6 lacks Element.remove() and
     * setting innerHTML='' is forbidden by our XSS policy.
     * ------------------------------------------------------------------ */

    function sc_clearMessages() {
        var container = sc_findMessagesContainer();
        if (!container) { return; }
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
        state.lastAssistantNode = null;
    }

    /* ------------------------------------------------------------------
     * Public: scrollToBottom() - bring the latest message into view.
     * ------------------------------------------------------------------ */

    function sc_scrollToBottom() {
        var container = sc_findMessagesContainer();
        if (!container) { return; }
        container.scrollTop = container.scrollHeight;
    }

    /* ------------------------------------------------------------------
     * Public: updateCharCount(text, max) - refresh the live char counter.
     * Shape: "字数: 0 / 32768"
     * ------------------------------------------------------------------ */

    function sc_updateCharCount(text, max) {
        var node = sc_findCharCountNode();
        if (!node) { return; }
        var limit = (typeof max === 'number' && max > 0) ? max
            : SC_DEFAULT_MAX_CHARS;
        var len = (text === null || text === undefined) ? 0
            : String(text).length;
        sc_setText(node, sc_fmt(sc_tr('chat.charCount',
                                      '\u5b57\u6570: {n} / {m}'),
                                len, limit));
    }

    /* ------------------------------------------------------------------
     * Countdown text rendering and control. "⏱ 等待: 12秒"
     * ------------------------------------------------------------------ */

    function sc_renderCountdown(seconds) {
        var node = sc_findCountdownNode();
        if (!node) { return; }
        sc_setText(node, sc_fmt(sc_tr('chat.wait',
                                      '\u7b49\u5f85: {n}\u79d2'),
                                seconds, ''));
    }

    function sc_startCountdown() {
        sc_stopCountdown();
        state.countdownSeconds = 0;
        sc_renderCountdown(0);
        state.countdownTimer = window.setInterval(function () {
            state.countdownSeconds += 1;
            sc_renderCountdown(state.countdownSeconds);
        }, SC_COUNTDOWN_INTERVAL_MS);
    }

    function sc_stopCountdown() {
        if (state.countdownTimer !== null) {
            window.clearInterval(state.countdownTimer);
            state.countdownTimer = null;
        }
        state.countdownSeconds = 0;
        sc_renderCountdown(0);
    }

    function sc_renderSendHint() {
        var node = sc_findSendHintNode();
        if (!node) { return; }
        var mode = 'enter';
        if (state.config && state.config.send_shortcut === 'shift_enter') {
            mode = 'shift_enter';
        }
        if (mode === 'shift_enter') {
            sc_setText(node, sc_tr('chat.sendHintShift',
                                   'Shift+Enter\u53d1\u9001 / Enter\u6362\u884c'));
        } else {
            sc_setText(node, sc_tr('chat.sendHintEnter',
                                   'Enter\u53d1\u9001 / Shift+Enter\u6362\u884c'));
        }
    }

    /* ------------------------------------------------------------------
     * Input + button enable/disable during in-flight requests.
     * ------------------------------------------------------------------ */

    function sc_setStreaming(enabled) {
        state.isStreaming = !!enabled;
        var input = sc_findInput();
        var sendBtn = sc_findButton('send-button', 'send-button');
        var stopBtn = sc_findButton('stop-button', 'stop-button');
        var regenBtn = sc_findButton('regenerate-button', 'regenerate-button');
        var nameBtn = sc_findButton('name-button', 'name-button');
        if (input) { input.disabled = state.isStreaming; }
        if (sendBtn) { sendBtn.disabled = state.isStreaming; }
        if (regenBtn) { regenBtn.disabled = state.isStreaming; }
        if (nameBtn) { nameBtn.disabled = state.isStreaming || state.isNaming; }
        if (stopBtn) { stopBtn.disabled = !state.isStreaming; }
    }

    function sc_renderError(bodyNode, message) {
        if (!bodyNode) { return; }
        var box = sc_makeEl('div', 'msg-error', '');
        sc_setText(box, '[' + sc_tr('chat.errorLabel', 'Error') + '] '
                   + String(message || 'unknown_error'));
        bodyNode.appendChild(box);
        sc_scrollToBottom();
    }

    function sc_refreshHistoryActive(chatId) {
        if (!window.SC || !window.SC.Api || !window.SC.App
            || typeof window.SC.Api.getHistory !== 'function'
            || typeof window.SC.App.renderHistoryList !== 'function') {
            return;
        }
        try {
            var h = window.SC.Api.getHistory();
            var rows = (h && h.ok && h.data && h.data.conversations)
                     ? h.data.conversations : [];
            window.SC.App.renderHistoryList(rows);
            var list = document.getElementById('sc-history-list');
            if (list && chatId) {
                var items = list.getElementsByTagName('li');
                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    if (item.getAttribute('data-id') === chatId) {
                        item.className = 'history-active';
                    }
                }
            }
        } catch (err) { /* ignore */ }
    }

    function sc_setNameButtonBusy(flag) {
        var btn = sc_findButton('name-button', 'name-button');
        if (!btn) { return; }
        btn.disabled = !!flag || state.isStreaming;
        if (flag) {
            sc_setText(btn, sc_tr('chat.generateTitleBusy',
                                  '[Generating...]'));
        } else {
            sc_setText(btn, sc_tr('chat.generateTitle', '[Title]'));
        }
    }

    function sc_setNameStatus(key, fallback) {
        var node = sc_findSendHintNode();
        if (!node) { return; }
        sc_setText(node, sc_tr(key, fallback));
    }

    function sc_generateTitle() {
        var chatId = state.lastChatId || '';
        if (!chatId) {
            sc_setNameStatus('chat.noActiveChat', 'No active chat.');
            return { ok: false, error: 'no_active_chat' };
        }
        if (state.isStreaming || state.isNaming) {
            return { ok: false, error: 'busy' };
        }
        if (!window.SC || !window.SC.Api
            || typeof window.SC.Api.nameChatAsync !== 'function') {
            sc_setNameStatus('chat.generateTitleFailed',
                             'Generate title failed');
            return { ok: false, error: 'api_unavailable' };
        }
        state.isNaming = true;
        sc_setNameButtonBusy(true);
        sc_setNameStatus('chat.generateTitleBusy', '[Generating...]');
        window.SC.Api.nameChatAsync(chatId, state.lastUserMessage || '',
            function (r) {
                state.isNaming = false;
                sc_setNameButtonBusy(false);
                if (r && r.ok && r.data && r.data.chat_name) {
                    sc_refreshHistoryActive(chatId);
                    sc_setNameStatus('chat.generateTitleDone',
                                     'Title updated');
                } else {
                    sc_setNameStatus('chat.generateTitleFailed',
                                     'Generate title failed');
                }
            });
        return { ok: true };
    }

    /* ------------------------------------------------------------------
     * Reset the user input area after a successful send.
     * ------------------------------------------------------------------ */

    function sc_resetInput() {
        var input = sc_findInput();
        if (!input) { return; }
        input.value = '';
        sc_updateCharCount('', SC_DEFAULT_MAX_CHARS);
    }

    /* ------------------------------------------------------------------
     * Public: sendMessage(chatId, text)
     *   - validates input
     *   - renders the user bubble immediately
     *   - disables input / starts countdown
     *   - calls SC.Api.sendMessageStream (streaming)
     *   - on completion: stops countdown, re-enables input
     *   - on error: appends an "[Error] <reason>" tag to the assistant
     *     bubble so the failure is visible in the conversation log
     * ------------------------------------------------------------------ */

    function sc_sendMessage(chatId, text) {
        if (!chatId) { return { ok: false, error: 'missing_chat_id' }; }
        if (text === null || text === undefined || text === '') {
            return { ok: false, error: 'empty_message' };
        }
        if (state.isStreaming) { return { ok: false, error: 'busy' }; }
        if (!window.SC || !window.SC.Api
            || typeof window.SC.Api.sendMessageStream !== 'function') {
            return { ok: false, error: 'api_unavailable' };
        }

        state.lastChatId = chatId;
        state.lastUserMessage = text;
        state.lastAssistantNode = null;

        sc_renderMessage('user', text, new Date());
        sc_setStreaming(true);
        sc_startCountdown();
        sc_resetInput();

        var assistantNode = sc_renderMessage('assistant', '', new Date());
        var bodyNode = assistantNode.getElementsByTagName('div')[0]
                    || assistantNode.lastChild;
        var assistantText = '';

        var xhr = window.SC.Api.sendMessageStream(chatId, text,
            function (chunk) {
                assistantText += chunk;
                if (typeof bodyNode.textContent === 'string') {
                    bodyNode.textContent += chunk;
                } else {
                    bodyNode.innerText += chunk;
                }
                sc_scrollToBottom();
            },
            function (result) {
                sc_stopCountdown();
                sc_setStreaming(false);
                state.activeXhr = null;

                if (result && result.ok === true) {
                    sc_renderMarkdown(bodyNode, assistantText);
                    if (result.data && result.data.chat_name && window.SC && window.SC.App && typeof window.SC.App.renderHistoryList === 'function') {
                        try {
                            var h = window.SC.Api.getHistory();
                            var rows = (h && h.ok && h.data && h.data.conversations)
                                     ? h.data.conversations : [];
                            window.SC.App.renderHistoryList(rows);
                            var list = document.getElementById('sc-history-list');
                            if (list && state.lastChatId) {
                                var items = list.getElementsByTagName('li');
                                for (var i = 0; i < items.length; i++) {
                                    var item = items[i];
                                    if (item.getAttribute('data-id') === state.lastChatId) {
                                        item.className = 'history-active';
                                    }
                                }
                            }
                        } catch (err) { /* ignore */ }
                    }
                } else {
                    var errMsg = (result && result.error) || 'unknown_error';
                    if (errMsg === 'abort' || (xhr && xhr.status === 0)) {
                        errMsg = sc_tr('chat.streamWarning',
                                       'Connection interrupted. Streaming has been stopped.');
                    }
                    sc_renderError(bodyNode, errMsg);
                }
            }
        );

        state.activeXhr = xhr;
        return { ok: true };
    }

    /* ------------------------------------------------------------------
     * Public: stop() - abort the in-flight XHR (if any) and re-enable UI.
     * ------------------------------------------------------------------ */

    function sc_stop() {
        if (state.activeXhr && typeof state.activeXhr.abort === 'function') {
            try { state.activeXhr.abort(); } catch (e) { /* ignore */ }
        }
        state.activeXhr = null;
        sc_stopCountdown();
        sc_setStreaming(false);
    }

    /* ------------------------------------------------------------------
     * Public: regenerate() - resend the most recent user message.
     * ------------------------------------------------------------------ */

    function sc_regenerate() {
        if (!state.lastChatId) {
            return { ok: false, error: 'no_last_chat' };
        }
        if (state.isStreaming) { return { ok: false, error: 'busy' }; }
        if (!window.SC || !window.SC.Api
            || typeof window.SC.Api.regenerateChatStream !== 'function') {
            return { ok: false, error: 'api_unavailable' };
        }

        var container = sc_findMessagesContainer();
        if (container) {
            var child = container.lastChild;
            while (child) {
                if (child.nodeType === 1 && child.className && child.className.indexOf('assistant-message') !== -1) {
                    container.removeChild(child);
                    break;
                }
                child = child.previousSibling;
            }
        }
        state.lastAssistantNode = null;

        sc_setStreaming(true);
        sc_startCountdown();

        var assistantNode = sc_renderMessage('assistant', '', new Date());
        var bodyNode = assistantNode.getElementsByTagName('div')[0]
                    || assistantNode.lastChild;
        var assistantText = '';

        var xhr = window.SC.Api.regenerateChatStream(state.lastChatId,
            function (chunk) {
                assistantText += chunk;
                if (typeof bodyNode.textContent === 'string') {
                    bodyNode.textContent += chunk;
                } else {
                    bodyNode.innerText += chunk;
                }
                sc_scrollToBottom();
            },
            function (result) {
                sc_stopCountdown();
                sc_setStreaming(false);
                state.activeXhr = null;

                if (result && result.ok === true) {
                    sc_renderMarkdown(bodyNode, assistantText);
                } else {
                    var errMsg = (result && result.error) || 'unknown_error';
                    if (errMsg === 'abort' || (xhr && xhr.status === 0)) {
                        errMsg = sc_tr('chat.streamWarning',
                                       'Connection interrupted. Streaming has been stopped.');
                    }
                    sc_renderError(bodyNode, errMsg);
                }
            }
        );

        state.activeXhr = xhr;
        return { ok: true };
    }

    /* ------------------------------------------------------------------
     * Public: handleEnterKey(e, callback)
     * Default: Enter sends, Shift+Enter inserts a newline.
     * If the current user has send_shortcut = shift_enter, the two
     * are swapped.
     * Returns true if the event was consumed.
     * ------------------------------------------------------------------ */

    function sc_handleEnterKey(e, callback) {
        e = e || window.event;
        if (!e) { return false; }
        var key = e.keyCode || e.which || 0;
        if (key !== 13) { return false; }
        var ctrl = e.ctrlKey === true || e.metaKey === true;
        var mode = 'enter';
        if (state.config && state.config.send_shortcut === 'shift_enter') {
            mode = 'shift_enter';
        }
        var shouldSend = ctrl || (mode === 'shift_enter'
                         ? e.shiftKey === true : e.shiftKey !== true);
        if (shouldSend) {
            if (typeof callback === 'function') {
                try { callback(); } catch (err) { /* swallow */ }
            }
            if (typeof e.preventDefault === 'function') {
                e.preventDefault();
            } else {
                e.returnValue = false;
            }
            return true;
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * Public: setActiveChatId(chatId) - remember which conversation the
     * UI buttons should target. The caller should call this whenever the
     * user switches conversations in the sidebar.
     * ------------------------------------------------------------------ */

    function sc_setActiveChatId(chatId) {
        state.lastChatId = chatId;
    }

    function sc_getActiveChatId() {
        return state.lastChatId;
    }

    /* ------------------------------------------------------------------
     * Wire up the four primary control buttons (input, send, stop,
     * regenerate). Kept under 50 lines by extracting delegation.
     * ------------------------------------------------------------------ */

    function sc_bindButtons() {
        var input = sc_findInput();
        var sendBtn = sc_findButton('send-button', 'send-button');
        var stopBtn = sc_findButton('stop-button', 'stop-button');
        var regenBtn = sc_findButton('regenerate-button', 'regenerate-button');
        var nameBtn = sc_findButton('name-button', 'name-button');

        if (input) {
            sc_attachEvent(input, 'keydown', function (e) {
                sc_handleEnterKey(e, function () {
                    sc_sendMessage(state.lastChatId || '', input.value);
                });
            });
            sc_attachEvent(input, 'keyup', function () {
                sc_updateCharCount(input.value, SC_DEFAULT_MAX_CHARS);
            });
        }
        if (sendBtn) {
            sc_attachEvent(sendBtn, 'click', function () {
                if (input) {
                    sc_sendMessage(state.lastChatId || '', input.value);
                }
            });
        }
        if (stopBtn) {
            sc_attachEvent(stopBtn, 'click', sc_stop);
            stopBtn.disabled = true;
        }
        if (regenBtn) {
            sc_attachEvent(regenBtn, 'click', sc_regenerate);
        }
        if (nameBtn) {
            sc_attachEvent(nameBtn, 'click', sc_generateTitle);
            sc_setNameButtonBusy(false);
        }
        return input;
    }

    /* ------------------------------------------------------------------
     * Document-level click delegation for per-message actions:
     //   <a class="msg-regenerate">...</a>
     //   <a class="msg-delete" data-chat-id="...">...</a>
     // IE6 has no addEventListener on document for click, so we wrap
     // document.onclick and chain any prior handler.
     * ------------------------------------------------------------------ */

    function sc_bindDocumentDelegation() {
        var prevDocClick = document.onclick;
        document.onclick = function (e) {
            e = e || window.event;
            var target = e.target || e.srcElement;
            if (!target) { return; }
            var cls = target.className || '';
            if (cls.indexOf && cls.indexOf('msg-regenerate') !== -1) {
                sc_regenerate();
            } else if (cls.indexOf && cls.indexOf('msg-delete') !== -1) {
                var id = target.getAttribute
                    ? target.getAttribute('data-chat-id') : null;
                sc_deleteConversation(id);
            }
            if (typeof prevDocClick === 'function') {
                try { prevDocClick(e); } catch (err) { /* ignore */ }
            }
        };
    }

    /* ------------------------------------------------------------------
     * Top-level init for the DOM layer. Splits the work into the two
     * binders above to keep each helper under 50 lines.
     * ------------------------------------------------------------------ */

    function sc_bindControls() {
        var input = sc_bindButtons();
        sc_bindDocumentDelegation();
        // Initialise the visible counters.
        sc_updateCharCount(input ? input.value : '', SC_DEFAULT_MAX_CHARS);
        sc_renderCountdown(0);
        sc_renderSendHint();
    }

    /* ------------------------------------------------------------------
     * Public: init(config, providers)
     * Stores config + provider list, then wires the DOM controls.
     * ------------------------------------------------------------------ */

    function sc_init(config, providers) {
        state.config = config || {};
        state.providers = providers || [];
        sc_bindControls();
    }

    /* ------------------------------------------------------------------
     * Public: deleteConversation(chatId)
     * Falls back to the most recently-used chat id if not given.
     * ------------------------------------------------------------------ */

    function sc_deleteConversation(chatId) {
        var id = chatId || state.lastChatId;
        if (!id) { return { ok: false, error: 'missing_id' }; }
        if (!window.SC || !window.SC.Api
            || typeof window.SC.Api.deleteChat !== 'function') {
            return { ok: false, error: 'api_unavailable' };
        }
        var res;
        try {
            res = window.SC.Api.deleteChat(id);
        } catch (e) {
            res = { ok: false, error: 'exception:' + (e.message || e) };
        }
        if (res && res.ok === true) {
            sc_clearMessages();
        }
        return res;
    }

    /* ------------------------------------------------------------------
     * Public surface
     * ------------------------------------------------------------------ */

    if (typeof window.SC === 'undefined' || window.SC === null
        || typeof window.SC !== 'object') {
        window.SC = {};
    }

    var SC_Chat = {
        init: sc_init,
        renderMessage: sc_renderMessage,
        sendMessage: sc_sendMessage,
        stop: sc_stop,
        regenerate: sc_regenerate,
        generateTitle: sc_generateTitle,
        startCountdown: sc_startCountdown,
        stopCountdown: sc_stopCountdown,
        updateCharCount: sc_updateCharCount,
        handleEnterKey: sc_handleEnterKey,
        scrollToBottom: sc_scrollToBottom,
        clearMessages: sc_clearMessages,
        deleteConversation: sc_deleteConversation,
        setActiveChatId: sc_setActiveChatId,
        getActiveChatId: sc_getActiveChatId
    };

    window.SC.Chat = SC_Chat;
    window.scChat = SC_Chat;   // legacy alias per JSON acceptance criterion
})();
