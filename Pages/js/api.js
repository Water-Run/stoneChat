/* -------------------------------------------------------------------------
 * stoneChat / Pages/js/api.js
 *
 * IE6-compatible synchronous XMLHttpRequest wrappers for the
 * /Server/api/* endpoints. Pure vanilla JavaScript: no fetch, no
 * Promise, no jQuery, no localStorage, no arrow / let / const / no
 * template literals.
 *
 * Public namespaces
 *   SC.Api          (primary, per the user-facing spec)
 *   window.scApi    (alias for in-tree lowercase callers)
 *   window.SC_Api   (alias for legacy / hand-written callers)
 *
 * Method shape
 *   Every method returns a single envelope synchronously:
 *     { ok: Boolean, data: any, error: String }
 *   ok    - true on HTTP 2xx AND server-reported success; false
 *           otherwise.
 *   data  - server payload. For endpoints that reply with
 *           {ok, data, error}, the inner data is surfaced verbatim;
 *           for endpoints that reply with a plain body (e.g.
 *           /api/config.php), the entire body is placed in data.
 *   error - empty string on success; otherwise a short code such
 *           as 'timeout', 'network_error', 'http_404', 'invalid_json',
 *           or the server's own error string.
 *
 * Cookies: same-origin XHR forwards cookies automatically. The
 * session cookie set by /Server/api/auth.php is therefore sent
 * without needing withCredentials (undefined in IE6 anyway).
 *
 * Timeout: 30 seconds, via window.setTimeout + xhr.abort() because
 * xhr.timeout is only available from IE8 onward.
 *
 * JSON: IE6/IE7 have no native JSON. We use JSON.parse /
 * JSON.stringify when available and fall back to a minimal in-house
 * implementation otherwise.
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * Configuration constants
     * ------------------------------------------------------------------ */

    // Base URL for all API endpoints.
    // Matches the project convention established by Pages/js/i18n.js, which
    // also uses absolute paths under /Server/...
    var SC_API_BASE = '/Server/api/';

    // Default per-request timeout, in milliseconds.
    var SC_TIMEOUT_MS = 30000;

    /* ------------------------------------------------------------------
     * Minimal JSON polyfill (IE6 / IE7)
     *
     * Used only when the host browser lacks a native JSON object. Both
     * functions are intentionally conservative: parse only handles the
     * subset the server emits (objects, arrays, strings, numbers, booleans,
     * null). stringify emits compact JSON with only ASCII-safe escapes.
     * ------------------------------------------------------------------ */

    function sc_isArray(value) {
        // IE6 lacks Array.isArray. Use constructor comparison, which is
        // safe for our purposes because we only stringify plain objects
        // produced by the server.
        return value !== null && typeof value === 'object'
            && typeof value.length === 'number'
            && typeof value.splice === 'function';
    }

    function sc_jsonEscapeString(str) {
        var out = '';
        for (var i = 0; i < str.length; i++) {
            var c = str.charAt(i);
            if (c === '"' || c === '\\') {
                out += '\\' + c;
            } else if (c === '\b') {
                out += '\\b';
            } else if (c === '\f') {
                out += '\\f';
            } else if (c === '\n') {
                out += '\\n';
            } else if (c === '\r') {
                out += '\\r';
            } else if (c === '\t') {
                out += '\\t';
            } else {
                var code = str.charCodeAt(i);
                if (code < 0x20) {
                    var hex = code.toString(16);
                    while (hex.length < 4) { hex = '0' + hex; }
                    out += '\\u' + hex;
                } else {
                    out += c;
                }
            }
        }
        return out;
    }

    function sc_jsonStringify(value) {
        // Prefer the host's native implementation when present.
        if (typeof JSON !== 'undefined' && typeof JSON.stringify === 'function') {
            return JSON.stringify(value);
        }
        if (value === null) { return 'null'; }
        var t = typeof value;
        if (t === 'string') {
            return '"' + sc_jsonEscapeString(value) + '"';
        }
        if (t === 'number') {
            // NaN and Infinity are not valid JSON; emit null as a safe fallback.
            if (!isFinite(value)) { return 'null'; }
            return String(value);
        }
        if (t === 'boolean') { return value ? 'true' : 'false'; }
        if (sc_isArray(value)) {
            var parts = [];
            for (var i = 0; i < value.length; i++) {
                parts.push(value[i] === undefined ? 'null' : sc_jsonStringify(value[i]));
            }
            return '[' + parts.join(',') + ']';
        }
        if (t === 'object') {
            var keys = [];
            for (var k in value) {
                if (Object.prototype.hasOwnProperty.call(value, k)) {
                    keys.push(k);
                }
            }
            var objParts = [];
            for (var j = 0; j < keys.length; j++) {
                objParts.push('"' + sc_jsonEscapeString(keys[j]) + '":'
                              + sc_jsonStringify(value[keys[j]]));
            }
            return '{' + objParts.join(',') + '}';
        }
        return 'null';
    }

    function sc_jsonParse(text) {
        if (typeof JSON !== 'undefined' && typeof JSON.parse === 'function') {
            try {
                return JSON.parse(text);
            } catch (e1) {
                return { __sc_parse_error: e1.message || String(e1) };
            }
        }
        // Last-resort fallback for IE6/IE7. The text comes from our own
        // server, so the eval() risk is bounded; we still wrap it so any
        // unexpected syntax error is reported as invalid_json.
        try {
            // eslint-disable-next-line no-eval
            return eval('(' + text + ')');
        } catch (e) {
            return { __sc_parse_error: e.message || String(e) };
        }
    }

    /* ------------------------------------------------------------------
     * XHR factory with IE6 ActiveX fallback
     * ------------------------------------------------------------------ */

    function sc_createXhr() {
        if (typeof XMLHttpRequest !== 'undefined') {
            try { return new XMLHttpRequest(); }
            catch (e) { /* fall through to ActiveX */ }
        }
        var activeXIds = ['Msxml2.XMLHTTP.6.0', 'Msxml2.XMLHTTP.3.0',
                          'Msxml2.XMLHTTP', 'Microsoft.XMLHTTP'];
        for (var i = 0; i < activeXIds.length; i++) {
            try { return new ActiveXObject(activeXIds[i]); }
            catch (e) { /* try next */ }
        }
        return null;
    }

    /* ------------------------------------------------------------------
     * Low-level synchronous request
     *
     * sc_request(method, endpoint, body)
     *   method   - HTTP verb in upper case: 'GET', 'POST', 'DELETE'.
     *   endpoint - file name relative to SC_API_BASE, e.g. 'auth.php'.
     *   body     - any JSON-serialisable object, or null for GET/DELETE.
     *
     * Always returns {ok, data, error}; never throws.
     * ------------------------------------------------------------------ */

    function sc_request(method, endpoint, body) {
        var xhr = sc_createXhr();
        if (!xhr) {
            return { ok: false, data: null, error: 'xhr_unavailable' };
        }

        var url = SC_API_BASE + endpoint;
        var hasBody = body !== null && body !== undefined;
        var payload = hasBody ? sc_jsonStringify(body) : null;

        try {
            xhr.open(method, url, false); // false = synchronous
        } catch (e) {
            return { ok: false, data: null,
                     error: 'open_failed:' + (e.message || e) };
        }

        try {
            xhr.setRequestHeader('Content-Type', 'application/json; charset=UTF-8');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            // Same-origin requests send cookies by default; we add Accept
            // explicitly so the server can branch on streaming vs JSON.
            xhr.setRequestHeader('Accept', 'application/json');
        } catch (e) {
            // Some old IE builds reject setRequestHeader for certain verbs.
            // Carry on: the defaults are acceptable for our endpoints.
        }

        var timedOut = false;
        var timer = window.setTimeout(function () {
            timedOut = true;
            try { xhr.abort(); } catch (e) { /* ignore */ }
        }, SC_TIMEOUT_MS);

        try {
            xhr.send(payload);
        } catch (e) {
            window.clearTimeout(timer);
            return { ok: false, data: null,
                     error: 'send_failed:' + (e.message || e) };
        }
        window.clearTimeout(timer);

        if (timedOut) {
            return { ok: false, data: null, error: 'timeout' };
        }

        var status = 0;
        try { status = xhr.status; } catch (e) { /* IE throws on no network */ }

        var text = '';
        try { text = xhr.responseText || ''; } catch (e) { /* ignore */ }

        if (status === 0) {
            return { ok: false, data: null, error: 'network_error' };
        }
        if (status < 200 || status >= 300) {
            // Trim the body slice to keep error codes short and to avoid
            // echoing sensitive payloads back through the envelope.
            var snippet = text.length > 200 ? text.substring(0, 200) : text;
            return { ok: false, data: null,
                     error: 'http_' + status + ':' + snippet };
        }

        var parsed = null;
        if (text.length > 0) {
            var result = sc_jsonParse(text);
            if (result && typeof result === 'object'
                && '__sc_parse_error' in result) {
                return { ok: false, data: null,
                         error: 'invalid_json:' + result.__sc_parse_error };
            }
            parsed = result;
        }

        // Normalise to {ok, data, error}. If the server already speaks the
        // envelope, just project the fields. Otherwise treat the body as
        // opaque success data.
        if (parsed !== null && typeof parsed === 'object'
            && 'ok' in parsed) {
            return {
                ok: parsed.ok === true,
                data: 'data' in parsed ? parsed.data : parsed,
                error: 'error' in parsed && parsed.error !== null
                       && parsed.error !== undefined
                       ? String(parsed.error) : ''
            };
        }
        return { ok: true, data: parsed, error: '' };
    }

    function sc_request_async(method, endpoint, body, onChunk, onComplete) {
        var xhr = sc_createXhr();
        if (!xhr) {
            if (typeof onComplete === 'function') {
                onComplete({ ok: false, data: null, error: 'xhr_unavailable' });
            }
            return null;
        }

        var url = SC_API_BASE + endpoint;
        var hasBody = body !== null && body !== undefined;
        var payload = hasBody ? sc_jsonStringify(body) : null;

        try {
            xhr.open(method, url, true); // true = asynchronous
        } catch (e) {
            if (typeof onComplete === 'function') {
                onComplete({ ok: false, data: null, error: 'open_failed:' + (e.message || e) });
            }
            return null;
        }

        try {
            xhr.setRequestHeader('Content-Type', 'application/json; charset=UTF-8');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'text/event-stream, application/json');
        } catch (e) {
            // ignore
        }

        var lastLength = 0;
        var timedOut = false;
        var sseBuffer = '';
        var isEventStream = false;
        var lastEventData = null;
        var streamError = '';
        
        var timer = window.setTimeout(function () {
            timedOut = true;
            try { xhr.abort(); } catch (e) { /* ignore */ }
        }, SC_TIMEOUT_MS);

        xhr.onreadystatechange = function () {
            if (timedOut) { return; }
            
            var state = 0;
            try { state = xhr.readyState; } catch (e) { return; }
            
            if (state === 3 || state === 4) {
                if (!isEventStream) {
                    var ct = '';
                    try { ct = xhr.getResponseHeader('Content-Type') || ''; } catch (e) {}
                    if (ct.indexOf('text/event-stream') !== -1) {
                        isEventStream = true;
                    }
                }
                
                var text = '';
                try { text = xhr.responseText || ''; } catch (e) { /* ignore */ }
                
                if (text.length > lastLength) {
                    var newText = text.substring(lastLength);
                    lastLength = text.length;
                    
                    if (isEventStream) {
                        sseBuffer += newText;
                        while (true) {
                            var pos = sseBuffer.indexOf('\n');
                            if (pos === -1) { break; }
                            var line = sseBuffer.substring(0, pos);
                            sseBuffer = sseBuffer.substring(pos + 1);
                            
                            line = line.replace(/^\s+|\s+$/g, ''); // trim
                            if (line.indexOf('data:') === 0) {
                                var dataVal = line.substring(5);
                                dataVal = dataVal.replace(/^\s+|\s+$/g, ''); // trim
                                if (dataVal === '[DONE]') {
                                    continue;
                                }
                                var parsedData = sc_jsonParse(dataVal);
                                if (parsedData && typeof parsedData === 'object' && !('__sc_parse_error' in parsedData)) {
                                    if (parsedData.error !== null && parsedData.error !== undefined) {
                                        streamError = String(parsedData.error);
                                    }
                                    if (parsedData.done) {
                                        lastEventData = parsedData;
                                    }
                                    var contentChunk = parsedData.content || parsedData.assistant || '';
                                    if (contentChunk !== '' && typeof onChunk === 'function') {
                                        onChunk(contentChunk);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            if (state === 4) {
                window.clearTimeout(timer);
                
                var status = 0;
                try { status = xhr.status; } catch (e) { /* ignore */ }
                
                var finalText = '';
                try { finalText = xhr.responseText || ''; } catch (e) { /* ignore */ }
                
                if (status === 0) {
                    if (typeof onComplete === 'function') {
                        onComplete({ ok: false, data: null, error: 'network_error' });
                    }
                    return;
                }
                
                if (status < 200 || status >= 300) {
                    var snippet = finalText.length > 200 ? finalText.substring(0, 200) : finalText;
                    if (typeof onComplete === 'function') {
                        onComplete({ ok: false, data: null, error: 'http_' + status + ':' + snippet });
                    }
                    return;
                }

                if (streamError !== '') {
                    if (typeof onComplete === 'function') {
                        onComplete({ ok: false, data: null, error: streamError });
                    }
                    return;
                }
                
                var parsed = null;
                var contentType = '';
                try { contentType = xhr.getResponseHeader('Content-Type') || ''; } catch (e) { /* ignore */ }
                
                if (contentType.indexOf('application/json') !== -1 && finalText.length > 0) {
                    var result = sc_jsonParse(finalText);
                    if (result && typeof result === 'object' && '__sc_parse_error' in result) {
                        if (typeof onComplete === 'function') {
                            onComplete({ ok: false, data: null, error: 'invalid_json:' + result.__sc_parse_error });
                        }
                        return;
                    }
                    parsed = result;
                }
                
                if (typeof onComplete === 'function') {
                    if (parsed !== null && typeof parsed === 'object' && 'ok' in parsed) {
                        onComplete({
                            ok: parsed.ok === true,
                            data: 'data' in parsed ? parsed.data : parsed,
                            error: 'error' in parsed && parsed.error !== null && parsed.error !== undefined ? String(parsed.error) : ''
                        });
                    } else {
                        // For event streams, at the end we might get the closing DONE event which contains metadata like chat_name.
                        // We check if we can parse the last sse lines for done metadata.
                        var lastDoneMeta = null;
                        if (isEventStream && sseBuffer.length > 0) {
                            var lines = sseBuffer.split('\n');
                            for (var i = 0; i < lines.length; i++) {
                                var l = lines[i].replace(/^\s+|\s+$/g, '');
                                if (l.indexOf('data:') === 0) {
                                    var dVal = l.substring(5).replace(/^\s+|\s+$/g, '');
                                    var pVal = sc_jsonParse(dVal);
                                    if (pVal && typeof pVal === 'object' && pVal.done) {
                                        lastDoneMeta = pVal;
                                    }
                                }
                            }
                        }
                        onComplete({ ok: true, data: lastEventData || lastDoneMeta || parsed || finalText, error: '' });
                    }
                }
            }
        };

        try {
            xhr.send(payload);
        } catch (e) {
            window.clearTimeout(timer);
            if (typeof onComplete === 'function') {
                onComplete({ ok: false, data: null, error: 'send_failed:' + (e.message || e) });
            }
            return null;
        }

        return xhr;
    }

    /* ------------------------------------------------------------------
     * Public methods on SC.Api
     * ------------------------------------------------------------------ */

    var SC_Api = {
        // GET /Server/api/config.php  - public client config
        getConfig: function () {
            return sc_request('GET', 'config.php', null);
        },

        // GET /Server/api/providers.php  - model list with availability
        getProviders: function () {
            return sc_request('GET', 'providers.php', null);
        },

        // GET /Server/api/history.php  - conversation list
        getHistory: function () {
            return sc_request('GET', 'history.php', null);
        },

        // POST /Server/api/history.php action=new
        //   providerId - model id to bind to the new chat
        createChat: function (providerId) {
            return sc_request('POST', 'history.php',
                              { action: 'new', provider_id: providerId });
        },

        // DELETE /Server/api/history.php?id=<chatId>
        //   chatId - conversation id to remove (Recycle Bin on Windows)
        deleteChat: function (chatId) {
            return sc_request('DELETE', 'history.php?id=' + chatId, null);
        },

        // POST /Server/api/history.php action=rename
        //   chatId - conversation id
        //   newName - desired title
        renameChat: function (chatId, newName) {
            return sc_request('POST', 'history.php',
                              { action: 'rename', id: chatId, title: newName });
        },

        // GET /Server/api/history.php?id=<chatId>  - load one conversation
        getChat: function (chatId) {
            return sc_request('GET', 'history.php?id=' + chatId, null);
        },

        // POST /Server/api/chat.php  - send a user message (non-streaming)
        //   chatId  - conversation id
        //   message - user message text
        sendMessage: function (chatId, message) {
            return sc_request('POST', 'chat.php',
                               { action: 'send', conversation_id: chatId, message: message });
        },

        // POST /Server/api/chat.php action=regenerate
        //   chatId  - conversation id
        regenerateChat: function (chatId) {
            return sc_request('POST', 'chat.php',
                               { action: 'regenerate', chat_id: chatId });
        },

        sendMessageStream: function (chatId, message, onChunk, onComplete) {
            return sc_request_async('POST', 'chat.php',
                               { action: 'send', conversation_id: chatId, message: message },
                               onChunk, onComplete);
        },

        regenerateChatStream: function (chatId, onChunk, onComplete) {
            return sc_request_async('POST', 'chat.php',
                               { action: 'regenerate', chat_id: chatId },
                               onChunk, onComplete);
        },

        // POST /Server/api/chat.php action=test  - probe model reachability
        //   providerId - model id to test
        connectCheck: function (providerId) {
            return sc_request('POST', 'chat.php',
                               { action: 'test', provider_id: providerId });
        },

        // POST /Server/api/auth.php action=login
        //   password - plaintext password (HTTPS / LAN deployment)
        login: function (password) {
            return sc_request('POST', 'auth.php',
                               { action: 'login', password: password });
        },

        // POST /Server/api/auth.php action=logout
        logout: function () {
            return sc_request('POST', 'auth.php', { action: 'logout' });
        },

        // POST /Server/api/auth.php action=logout, asynchronous.
        // Used by the Logout button so an active stream cannot freeze IE.
        logoutAsync: function (onComplete) {
            return sc_request_async('POST', 'auth.php',
                                    { action: 'logout' }, null, onComplete);
        },

        // POST /Server/api/auth.php action=check
        checkAuth: function () {
            return sc_request('POST', 'auth.php', { action: 'check' });
        },

        // POST /Server/api/config.php action=reload
        reloadConfig: function () {
            return sc_request('POST', 'config.php', { action: 'reload' });
        }
    };

    /* ------------------------------------------------------------------
     * Export
     * ------------------------------------------------------------------ */

    // Bootstrap the SC root namespace if the page hasn't already done so
    // (e.g. i18n.js may have set window.SC.I18n first).
    if (typeof window.SC === 'undefined') {
        window.SC = {};
    }
    if (typeof window.SC !== 'object' || window.SC === null) {
        window.SC = {};
    }

    window.SC.Api = SC_Api;
    window.SC_Api = SC_Api;   // auxiliary camel-case alias
    window.scApi = SC_Api;    // legacy alias kept for in-tree callers

    // Expose low-level helpers for advanced callers (e.g. diagnostic pages).
    window.SC.ApiBase = SC_API_BASE;
    window.SC.ApiTimeoutMs = SC_TIMEOUT_MS;
    window.SC.ApiRaw = sc_request;
}());
