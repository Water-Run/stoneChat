/**
 * stoneChat client-side internationalization.
 * IE6-compatible (ES3 only): var/function, no JSON, no Array methods.
 * Cookie-based persistence (no localStorage).
 *
 * Public API (window.SC.I18n, alias window.scI18n):
 *   init(supportedLangs, defaultLang)   - bootstrap, reads 'sc_lang' cookie
 *   load(lang)                          - returns translation table
 *   apply()                             - walks DOM, replaces [data-i18n=key] text
 *   setLang(lang)                       - persists to cookie + reloads page
 *   getLang()                           - current language code
 *   t(key)                              - lookup with English fallback
 *   getLangSwitcherHTML()               - 8 language buttons
 */
(function () {
  var SC = window.SC || {};

  /* ----- Cookie helpers (no localStorage) ----- */
  function getCookie(name) {
    var parts = document.cookie.split(';');
    for (var i = 0; i < parts.length; i++) {
      var c = parts[i];
      while (c.charAt(0) === ' ') c = c.substring(1);
      if (c.indexOf(name + '=') === 0) {
        return decodeURIComponent(c.substring(name.length + 1));
      }
    }
    return null;
  }

  function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + (days * 86400000));
    document.cookie = name + '=' + encodeURIComponent(value) +
      ';expires=' + d.toUTCString() + ';path=/';
  }

  /* ----- XHR factory (IE6 ActiveX fallback) ----- */
  function createXHR() {
    if (typeof XMLHttpRequest !== 'undefined') {
      return new XMLHttpRequest();
    }
    try { return new ActiveXObject('Msxml2.XMLHTTP'); }
    catch (e1) {
      try { return new ActiveXObject('Microsoft.XMLHTTP'); }
      catch (e2) { return null; }
    }
  }

  /* ----- English fallback (always available) ----- */
  var FALLBACK_EN = {
    'login.title': 'Login',
    'login.password': 'Password',
    'login.submit': 'Sign in',
    'login.error': 'Invalid password',
    'chat.send': 'Send',
    'chat.countdown': 'Auto-retry in {n}s...',
    'chat.connectCheck': 'Check connection',
    'chat.reloadConfig': 'Reload config',
    'chat.about': 'About',
    'chat.newChat': 'New chat',
    'chat.deleteChat': 'Delete chat',
    'chat.renameChat': 'Rename chat',
    'error.network': 'Network error',
    'error.timeout': 'Request timeout',
    'error.unauthorized': 'Unauthorized'
  };

  /* ----- Bundled translations (front-end fallback, no backend injection) ----- */
  var BUNDLED = {
    'zh-CN': {
      'login.title': '\u767b\u5f55',
      'login.password': '\u5bc6\u7801',
      'login.submit': '\u767b\u5f55',
      'login.error': '\u5bc6\u7801\u9519\u8bef',
      'chat.send': '\u53d1\u9001',
      'chat.countdown': '{n} \u79d2\u540e\u81ea\u52a8\u91cd\u8bd5...',
      'chat.connectCheck': '\u68c0\u67e5\u8fde\u63a5',
      'chat.reloadConfig': '\u91cd\u65b0\u52a0\u8f7d\u914d\u7f6e',
      'chat.about': '\u5173\u4e8e',
      'chat.newChat': '\u65b0\u5efa\u5bf9\u8bdd',
      'chat.deleteChat': '\u5220\u9664\u5bf9\u8bdd',
      'chat.renameChat': '\u91cd\u547d\u540d\u5bf9\u8bdd',
      'error.network': '\u7f51\u7edc\u9519\u8bef',
      'error.timeout': '\u8bf7\u6c42\u8d85\u65f6',
      'error.unauthorized': '\u672a\u6388\u6743'
    },
    'zh-TW': {
      'login.title': '\u767b\u5165',
      'login.password': '\u5bc6\u78bc',
      'login.submit': '\u767b\u5165',
      'login.error': '\u5bc6\u78bc\u932f\u8aa4',
      'chat.send': '\u50b3\u9001',
      'chat.countdown': '{n} \u79d2\u5f8c\u81ea\u52d5\u91cd\u8a66...',
      'chat.connectCheck': '\u6aa2\u67e5\u9023\u7dda',
      'chat.reloadConfig': '\u91cd\u65b0\u8f09\u5165\u8a2d\u5b9a',
      'chat.about': '\u95dc\u65bc',
      'chat.newChat': '\u65b0\u589e\u5c0d\u8a71',
      'chat.deleteChat': '\u522a\u9664\u5c0d\u8a71',
      'chat.renameChat': '\u91cd\u65b0\u547d\u540d\u5c0d\u8a71',
      'error.network': '\u7db2\u8def\u932f\u8aa4',
      'error.timeout': '\u8acb\u6c42\u903e\u6642',
      'error.unauthorized': '\u672a\u6388\u6b0a'
    },
    'ja': {
      'login.title': '\u30ed\u30b0\u30a4\u30f3',
      'login.password': '\u30d1\u30b9\u30ef\u30fc\u30c9',
      'login.submit': '\u30b5\u30a4\u30f3\u30a4\u30f3',
      'login.error': '\u30d1\u30b9\u30ef\u30fc\u30c9\u304c\u7121\u52b9\u3067\u3059',
      'chat.send': '\u9001\u4fe1',
      'chat.countdown': '{n}\u79d2\u5f8c\u306b\u81ea\u52d5\u518d\u8a66\u884c...',
      'chat.connectCheck': '\u63a5\u7d9a\u3092\u78ba\u8a8d',
      'chat.reloadConfig': '\u8a2d\u5b9a\u3092\u518d\u8aad\u8f09',
      'chat.about': '\u60c5\u5831',
      'chat.newChat': '\u65b0\u898f\u30c1\u30e3\u30c3\u30c8',
      'chat.deleteChat': '\u30c1\u30e3\u30c3\u30c8\u3092\u524a\u9664',
      'chat.renameChat': '\u30c1\u30e3\u30c3\u30c8\u540d\u3092\u5909\u66f4',
      'error.network': '\u30cd\u30c3\u30c8\u30ef\u30fc\u30af\u30a8\u30e9\u30fc',
      'error.timeout': '\u30ea\u30af\u30a8\u30b9\u30c8\u30bf\u30a4\u30e0\u30a2\u30a6\u30c8',
      'error.unauthorized': '\u8a8d\u8a3c\u30a8\u30e9\u30fc'
    },
    'ko': {
      'login.title': '\ub85c\uae00\uc778',
      'login.password': '\ube44\ubc00\ubc88\ud638',
      'login.submit': '\ub85c\uae00\uc778',
      'login.error': '\uc798\ubabb\ub41c \ube44\ubc00\ubc88\ud638',
      'chat.send': '\ubcf4\ub0b4\uae30',
      'chat.countdown': '{n}\ucd08 \ud6c4 \uc790\ub3d9 \uc7ac\uc2dc\ub3c4...',
      'chat.connectCheck': '\uc5f0\uacb0 \ud655\uc778',
      'chat.reloadConfig': '\uc124\uc815 \ub2e4\uc2dc \ubd88\ub7ec\uc624\uae30',
      'chat.about': '\uc815\ubcf4',
      'chat.newChat': '\uc0c8 \ub300\ud654',
      'chat.deleteChat': '\ub300\ud654 \uc0ad\uc81c',
      'chat.renameChat': '\ub300\ud654 \uc774\ub984 \ubcc0\uacbd',
      'error.network': '\ub124\ud2b8\uc6cc\ud06c \uc624\ub958',
      'error.timeout': '\uc694\uccad \uc2dc\uac04 \ucd08\uacfc',
      'error.unauthorized': '\uc778\uc99d \uc2e4\ud328'
    },
    'ru': {
      'login.title': '\u0412\u0445\u043e\u0434',
      'login.password': '\u041f\u0430\u0440\u043e\u043b\u044c',
      'login.submit': '\u0412\u043e\u0439\u0442\u0438',
      'login.error': '\u041d\u0435\u0432\u0435\u0440\u043d\u044b\u0439 \u043f\u0430\u0440\u043e\u043b\u044c',
      'chat.send': '\u041e\u0442\u043f\u0440\u0430\u0432\u0438\u0442\u044c',
      'chat.countdown': '\u0410\u0432\u0442\u043e\u043f\u043e\u0432\u0442\u043e\u0440 \u0447\u0435\u0440\u0435\u0437 {n}\u0441...',
      'chat.connectCheck': '\u041f\u0440\u043e\u0432\u0435\u0440\u0438\u0442\u044c \u0441\u043e\u0435\u0434\u0438\u043d\u0435\u043d\u0438\u0435',
      'chat.reloadConfig': '\u041f\u0435\u0440\u0435\u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u043a\u043e\u043d\u0444\u0438\u0433',
      'chat.about': '\u041e \u043f\u0440\u043e\u0433\u0440\u0430\u043c\u043c\u0435',
      'chat.newChat': '\u041d\u043e\u0432\u044b\u0439 \u0447\u0430\u0442',
      'chat.deleteChat': '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u0447\u0430\u0442',
      'chat.renameChat': '\u041f\u0435\u0440\u0435\u0438\u043c\u0435\u043d\u043e\u0432\u0430\u0442\u044c \u0447\u0430\u0442',
      'error.network': '\u0421\u0435\u0442\u0435\u0432\u0430\u044f \u043e\u0448\u0438\u0431\u043a\u0430',
      'error.timeout': '\u0412\u0440\u0435\u043c\u044f \u0438\u0441\u0442\u0435\u043a\u043b\u043e',
      'error.unauthorized': '\u041d\u0435 \u0430\u0432\u0442\u043e\u0440\u0438\u0437\u043e\u0432\u0430\u043d'
    },
    'fr': {
      'login.title': 'Connexion',
      'login.password': 'Mot de passe',
      'login.submit': 'Se connecter',
      'login.error': 'Mot de passe invalide',
      'chat.send': 'Envoyer',
      'chat.countdown': 'Nouvelle tentative dans {n}s...',
      'chat.connectCheck': 'V\u00e9rifier la connexion',
      'chat.reloadConfig': 'Recharger la config',
      'chat.about': '\u00c0 propos',
      'chat.newChat': 'Nouvelle discussion',
      'chat.deleteChat': 'Supprimer la discussion',
      'chat.renameChat': 'Renommer la discussion',
      'error.network': 'Erreur r\u00e9seau',
      'error.timeout': 'D\u00e9lai d\u00e9pass\u00e9',
      'error.unauthorized': 'Non autoris\u00e9'
    },
    'de': {
      'login.title': 'Anmelden',
      'login.password': 'Passwort',
      'login.submit': 'Anmelden',
      'login.error': 'Ung\u00fcltiges Passwort',
      'chat.send': 'Senden',
      'chat.countdown': 'Automatischer Neustart in {n}s...',
      'chat.connectCheck': 'Verbindung pr\u00fcfen',
      'chat.reloadConfig': 'Konfig neu laden',
      'chat.about': '\u00dcber',
      'chat.newChat': 'Neuer Chat',
      'chat.deleteChat': 'Chat l\u00f6schen',
      'chat.renameChat': 'Chat umbenennen',
      'error.network': 'Netzwerkfehler',
      'error.timeout': 'Zeit\u00fcberschreitung',
      'error.unauthorized': 'Nicht autorisiert'
    }
  };

  /* ----- Language switcher button labels ----- */
  var LANG_LABELS = {
    'zh-CN': '\u7b80', 'zh-TW': '\u7e41', 'en': 'En', 'ja': '\u65e5',
    'ko': '\ud55c', 'ru': 'Ru', 'fr': 'Fr', 'de': 'De'
  };

  /* ----- Module state ----- */
  var supportedLangs = ['en'];
  var defaultLang = 'en';
  var currentLang = 'en';
  var table = {};
  var tableCache = { 'en': FALLBACK_EN };

  /* ----- Merge FALLBACK_EN into target table (so missing keys still work) ----- */
  function mergeFallback(src) {
    var out = {};
    for (var k in FALLBACK_EN) {
      if (Object.prototype.hasOwnProperty.call(FALLBACK_EN, k)) {
        out[k] = FALLBACK_EN[k];
      }
    }
    if (src) {
      for (var k2 in src) {
        if (Object.prototype.hasOwnProperty.call(src, k2)) {
          out[k2] = src[k2];
        }
      }
    }
    return out;
  }

  /* ----- Pick a valid language from cookie or return null ----- */
  function pickFromCookie(supported) {
    var cookie = getCookie('sc_lang');
    if (!cookie) return null;
    for (var i = 0; i < supported.length; i++) {
      if (supported[i] === cookie) return cookie;
    }
    return null;
  }

  /* ----- Sync XHR fetch of /Server/langs/<lang>.js (sets window.scLang_<code>) ----- */
  function fetchRemote(lang) {
    var xhr = createXHR();
    if (!xhr) return null;
    try {
      xhr.open('GET', '/Server/langs/' + lang + '.js', false);
      xhr.send(null);
      if (xhr.status === 200 && xhr.responseText) {
        var globalKey = 'scLang_' + lang.replace(/-/g, '_');
        window[globalKey] = null;
        eval(xhr.responseText);
        return window[globalKey] || null;
      }
    } catch (e) { /* network or parse failure - fall back to bundled/en */ }
    return null;
  }

  /* ----- Public: load(lang) - returns translation table ----- */
  function load(lang) {
    if (tableCache[lang]) return tableCache[lang];
    if (BUNDLED[lang]) {
      tableCache[lang] = mergeFallback(BUNDLED[lang]);
      return tableCache[lang];
    }
    var remote = fetchRemote(lang);
    if (remote) {
      tableCache[lang] = mergeFallback(remote);
      return tableCache[lang];
    }
    tableCache[lang] = FALLBACK_EN;
    return tableCache[lang];
  }

  /* ----- Public: init(supportedLangs, defaultLang) ----- */
  function init(supported, def) {
    supportedLangs = (supported && supported.length) ? supported : ['en'];
    defaultLang = def || 'en';
    currentLang = pickFromCookie(supportedLangs) || defaultLang;
    table = load(currentLang);
  }

  /* ----- Public: t(key) - lookup with English fallback ----- */
  function t(key) {
    if (table[key]) return table[key];
    if (FALLBACK_EN[key]) return FALLBACK_EN[key];
    return key;
  }

  /* ----- Public: setLang(lang) - persists to cookie and reloads page ----- */
  function setLang(lang) {
    setCookie('sc_lang', lang, 365);
    if (typeof location !== 'undefined' && location.reload) {
      location.reload();
    }
  }

  /* ----- Public: getLang() ----- */
  function getLang() { return currentLang; }

  /* ----- Helper: set text in IE6-friendly way (innerText fallback) ----- */
  function setNodeText(node, text) {
    if (typeof node.textContent === 'string') {
      node.textContent = text;
    } else {
      node.innerText = text;
    }
  }

  /* ----- Public: apply() - walk DOM, replace text of [data-i18n=key] elements ----- */
  function apply() {
    if (typeof document === 'undefined' || !document.getElementsByTagName) return;
    var nodes = document.getElementsByTagName('*');
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      var key = node.getAttribute ? node.getAttribute('data-i18n') : null;
      if (key) setNodeText(node, t(key));
    }
  }

  /* ----- Public: getLangSwitcherHTML() - 8 language buttons ----- */
  function getLangSwitcherHTML() {
    var html = '<div class="sc-lang-switcher">';
    for (var i = 0; i < supportedLangs.length; i++) {
      var lang = supportedLangs[i];
      var label = LANG_LABELS[lang] || lang;
      var active = (lang === currentLang) ? ' class="sc-active"' : '';
      html += '<a href="javascript:window.SC.I18n.setLang(\'' + lang +
        '\')"' + active + '>' + label + '</a> ';
    }
    html += '</div>';
    return html;
  }

  /* ----- Expose ----- */
  SC.I18n = {
    init: init,
    load: load,
    apply: apply,
    setLang: setLang,
    getLang: getLang,
    t: t,
    getLangSwitcherHTML: getLangSwitcherHTML
  };

  window.SC = SC;
  window.scI18n = SC.I18n;  /* alias per task-spec window.scI18n */
})();
