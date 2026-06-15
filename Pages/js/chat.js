/**
 * stoneChat Pages/js/chat.js
 *
 * Chat interaction layer for the stoneChat web client.
 *
 * IE6 / Win-XP compatible (ES3 only):
 *   - var / function declarations only (no let, no const, no arrow funcs)
 *   - no fetch / Promise / localStorage / JSON.parse assumptions
 *   - XMLHttpRequest with ActiveX fallback (delegated to SC.Api)
 *   - textContent / createElement for all user-controlled content
 *     (no innerHTML injection -> XSS protection)
 *
 * Public namespaces:
 *   window.SC.Chat   primary namespace (per the user-facing spec)
 *   window.scChat    legacy alias kept for in-tree callers
 *
 * Method shape:
 *   init(config, providers)         - bootstrap, wire up UI controls
 *   renderMessage(role, content, ts) - append a message bubble
 *   sendMessage(chatId, text)       - send to provider, render user + AI
 *   stop()                          - abort the in-flight XHR
 *   regenerate()                    - resend the last user message
 *   startCountdown()                - begin the "waiting" timer
 *   stopCountdown()                 - halt and reset the timer
 *   updateCharCount(text, max)      - update the "字数: N / M" display
 *   handleEnterKey(e, callback)     - Ctrl+Enter shortcut handler
 *   scrollToBottom()                - auto-scroll messages container
 *   clearMessages()                 - empty the messages container
 *   deleteConversation(chatId)      - drop a conversation (recycle bin)
 *   setActiveChatId(chatId)         - remember which chat is active
 *
 * The user-facing spec mandates a synchronous send via SC.Api.sendMessage.
 * When the backend grows an SSE streaming endpoint, the renderMessage +
 * startCountdown pair is the integration point - the assistant bubble is
 * already created as a normal DOM node, so chunked appendage is a 2-line
 * follow-up: streamChunk(text) -> node.lastChild.textContent += text.
 */
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
        isStreaming: false      // true while a request is in flight
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

    /* ------------------------------------------------------------------
     * DOM lookup helpers - prefer id, fall back to first class match.
     * ------------------------------------------------------------------ */

    function sc_findMessagesContainer() {
        return document.getElementById('chat-messages')
            || document.getElementById('messages')
            || (document.querySelector
                ? document.querySelector('.chat-messages')
                : null);
    }

    function sc_findCountdownNode() {
        return document.getElementById('countdown')
            || (document.querySelector
                ? document.querySelector('.input-area .countdown')
                : null);
    }

    function sc_findCharCountNode() {
        return document.getElementById('char-count')
            || (document.querySelector
                ? document.querySelector('.char-count')
                : null);
    }

    function sc_findInput() {
        return document.getElementById('chat-input')
            || document.getElementsByTagName('textarea').item(0)
            || null;
    }

    function sc_findButton(id, className) {
        var byId = document.getElementById(id);
        if (byId) { return byId; }
        if (document.querySelector) {
            return document.querySelector('.' + className);
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
     * Public: renderMessage(role, content, timestamp)
     * Append a new message bubble to the messages container. Returns the
     * wrapper DOM node so callers (or a future SSE stream) can update it.
     * ------------------------------------------------------------------ */

    function sc_renderMessage(role, content, timestamp) {
        var container = sc_findMessagesContainer();
        if (!container) { return null; }
        var safeRole = (role === 'user' || role === 'assistant'
                        || role === 'system') ? role : 'assistant';
        var labels = (state.config && state.config.labels) || SC_ROLE_LABELS;
        var roleText = labels[safeRole] || SC_ROLE_LABELS_EN[safeRole]
            || safeRole;
        var ts = timestamp ? sc_formatTimestamp(timestamp)
                 : sc_formatTimestamp(new Date());
        var wrapper = sc_makeEl('div', 'msg ' + safeRole + '-message');
        wrapper.appendChild(sc_makeEl('span', 'msg-role',
            roleText + ' @ ' + ts));
        wrapper.appendChild(sc_makeEl('div', 'msg-body', content));
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
        sc_setText(node, '\u5b57\u6570: ' + len + ' / ' + limit);
        // "字数: N / M" (Chinese "character count"); ASCII-safe fallback:
        if (node.textContent === '' && typeof node.innerText !== 'undefined') {
            sc_setText(node, 'Chars: ' + len + ' / ' + limit);
        }
    }

    /* ------------------------------------------------------------------
     * Countdown text rendering and control. "⏱ 等待: 12秒"
     * ------------------------------------------------------------------ */

    function sc_renderCountdown(seconds) {
        var node = sc_findCountdownNode();
        if (!node) { return; }
        sc_setText(node, '\u23f1 \u7b49\u5f85: ' + seconds + '\u79d2');
        // "⏱ 等待: N 秒" (timer / waiting / seconds); the IE6 font may not
        // render U+23F1, so we also expose an ASCII variant below.
        if (node.textContent === '' && typeof node.innerText !== 'undefined') {
            sc_setText(node, 'Wait: ' + seconds + 's');
        }
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

    /* ------------------------------------------------------------------
     * Input + button enable/disable during in-flight requests.
     * ------------------------------------------------------------------ */

    function sc_setStreaming(enabled) {
        state.isStreaming = !!enabled;
        var input = sc_findInput();
        var sendBtn = sc_findButton('send-button', 'send-button');
        var stopBtn = sc_findButton('stop-button', 'stop-button');
        var regenBtn = sc_findButton('regenerate-button', 'regenerate-button');
        if (input) { input.disabled = state.isStreaming; }
        if (sendBtn) { sendBtn.disabled = state.isStreaming; }
        if (regenBtn) { regenBtn.disabled = state.isStreaming; }
        if (stopBtn) { stopBtn.disabled = !state.isStreaming; }
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
     * Extract the assistant reply string from a SC.Api.sendMessage
     * envelope. Pure function: same input -> same output, no DOM touch.
     * ------------------------------------------------------------------ */

    function sc_extractReply(data) {
        var reply = '';
        if (data && typeof data === 'object') {
            reply = data.reply || data.message || data.content || data.assistant || '';
        } else if (typeof data === 'string') {
            reply = data;
        }
        return reply || '[empty response]';
    }

    /* ------------------------------------------------------------------
     * Public: sendMessage(chatId, text)
     * - validates input
     * - renders the user bubble immediately
     * - disables input / starts countdown
     * - calls SC.Api.sendMessage
     * - on success: renders assistant reply, resets input
     * - on failure: renders an [Error] assistant bubble for visibility
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

        var xhr = window.SC.Api.sendMessageStream(chatId, text,
            function (chunk) {
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
                        errMsg = 'Connection interrupted. Streaming has been stopped.';
                        if (window.SC && window.SC.I18n && typeof window.SC.I18n.t === 'function') {
                            errMsg = window.SC.I18n.t('chat.stream.warning') || errMsg;
                        }
                    } else {
                        errMsg = '[Error] ' + errMsg;
                    }
                    if (typeof bodyNode.textContent === 'string') {
                        bodyNode.textContent += ' ' + errMsg;
                    } else {
                        bodyNode.innerText += ' ' + errMsg;
                    }
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

        var xhr = window.SC.Api.regenerateChatStream(state.lastChatId,
            function (chunk) {
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
                    // Done
                } else {
                    var errMsg = (result && result.error) || 'unknown_error';
                    if (errMsg === 'abort' || (xhr && xhr.status === 0)) {
                        errMsg = 'Connection interrupted. Streaming has been stopped.';
                        if (window.SC && window.SC.I18n && typeof window.SC.I18n.t === 'function') {
                            errMsg = window.SC.I18n.t('chat.stream.warning') || errMsg;
                        }
                    } else {
                        errMsg = '[Error] ' + errMsg;
                    }
                    if (typeof bodyNode.textContent === 'string') {
                        bodyNode.textContent += ' ' + errMsg;
                    } else {
                        bodyNode.innerText += ' ' + errMsg;
                    }
                }
            }
        );

        state.activeXhr = xhr;
        return { ok: true };
    }

    /* ------------------------------------------------------------------
     * Public: handleEnterKey(e, callback)
     * Triggers `callback` on Ctrl+Enter (or Cmd+Enter on a Mac keyboard).
     * Returns true if the event was consumed.
     * ------------------------------------------------------------------ */

    function sc_handleEnterKey(e, callback) {
        e = e || window.event;
        if (!e) { return false; }
        var ctrl = e.ctrlKey === true || e.metaKey === true;
        var key = e.keyCode || e.which || 0;
        if (ctrl && key === 13) {
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
