/* -------------------------------------------------------------------------
 * stoneChat / Pages/js/i18n.js
 *
 * Client-side internationalization. IE6-compatible (ES3 only):
 * var/function, no JSON, no Array methods. Cookie-based persistence
 * (no localStorage).
 *
 * Public API (window.SC.I18n, alias window.scI18n):
 *   init(supportedLangs, defaultLang)   bootstrap, reads sc_lang cookie
 *   load(lang)                          returns translation table
 *   apply()                             walks DOM, replaces text
 *   setLang(lang)                       persists to cookie + reloads page
 *   getLang()                           current language code
 *   t(key)                              lookup with English fallback
 *   getLangSwitcherHTML()               8 language buttons
 * ------------------------------------------------------------------------- */
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
    'app.title': 'stoneChat',
    'login.title': 'Login',
    'login.password': 'Password',
    'login.submit': 'Sign in',
    'login.error': 'Invalid password',
    'login.locked': 'Too many failed attempts. Please wait and try again later.',
    'chat.send': 'Send',
    'chat.stop': 'Stop',
    'chat.regenerate': 'Regenerate',
    'chat.countdown': 'Auto-retry in {n}s...',
    'chat.connectCheck': 'Check connection',
    'chat.reloadConfig': 'Reload config',
    'chat.editConfig': 'Edit config',
    'chat.logout': 'Logout',
    'chat.about': 'About',
    'chat.newChat': 'New chat',
    'chat.deleteChat': 'Delete chat',
    'chat.renameChat': 'Rename chat',
    'sidebar.history': 'History',
    'common.close': 'Close',
    'error.network': 'Network error',
    'error.timeout': 'Request timeout',
    'error.unauthorized': 'Unauthorized',
    'role.user': 'You',
    'role.assistant': 'AI',
    'role.system': 'System'
  };

  /* ----- Bundled translations (front-end fallback, no backend injection) ----- */
  var BUNDLED = {
    "zh-CN": {
        "app.title": "stoneChat",
        "login.title": "登录",
        "login.password": "密码",
        "login.submit": "登录",
        "login.error": "密码错误",
        "login.locked": "登录尝试次数过多，请稍后再试。",
        "chat.send": "发送",
        "chat.stop": "停止",
        "chat.regenerate": "重新生成",
        "chat.countdown": "{n} 秒后自动重试...",
        "chat.connectCheck": "检查连接",
        "chat.reloadConfig": "重新加载配置",
        "chat.editConfig": "编辑配置",
        "chat.logout": "退出",
        "chat.about": "关于",
        "chat.newChat": "新建对话",
        "chat.deleteChat": "删除对话",
        "chat.renameChat": "重命名对话",
        "sidebar.history": "历史记录",
        "common.close": "关闭",
        "error.network": "网络错误",
        "error.timeout": "请求超时",
        "error.unauthorized": "未授权",
        "role.user": "你",
        "role.assistant": "AI",
        "role.system": "系统"
    },
    "zh-TW": {
        "app.title": "stoneChat",
        "login.title": "登入",
        "login.password": "密碼",
        "login.submit": "登入",
        "login.error": "密碼錯誤",
        "login.locked": "登入嘗試次數過多，請稍後再試。",
        "chat.send": "發送",
        "chat.stop": "停止",
        "chat.regenerate": "重新生成",
        "chat.countdown": "{n} 秒後自動重試...",
        "chat.connectCheck": "檢查連接",
        "chat.reloadConfig": "重新載入配置",
        "chat.editConfig": "編輯配置",
        "chat.logout": "登出",
        "chat.about": "關於",
        "chat.newChat": "新建對話",
        "chat.deleteChat": "刪除對話",
        "chat.renameChat": "重命名對話",
        "sidebar.history": "歷史記錄",
        "common.close": "關閉",
        "error.network": "網絡錯誤",
        "error.timeout": "請求超時",
        "error.unauthorized": "未授權",
        "role.user": "你",
        "role.assistant": "AI",
        "role.system": "系統"
    },
    "en": {
        "app.title": "stoneChat",
        "login.title": "Login",
        "login.password": "Password",
        "login.submit": "Sign in",
        "login.error": "Invalid password",
        "login.locked": "Too many failed attempts. Please wait and try again later.",
        "chat.send": "Send",
        "chat.stop": "Stop",
        "chat.regenerate": "Regenerate",
        "chat.countdown": "Auto-retry in {n}s...",
        "chat.connectCheck": "Check connection",
        "chat.reloadConfig": "Reload config",
        "chat.editConfig": "Edit config",
        "chat.logout": "Logout",
        "chat.about": "About",
        "chat.newChat": "New chat",
        "chat.deleteChat": "Delete chat",
        "chat.renameChat": "Rename chat",
        "sidebar.history": "History",
        "common.close": "Close",
        "error.network": "Network error",
        "error.timeout": "Request timeout",
        "error.unauthorized": "Unauthorized",
        "role.user": "You",
        "role.assistant": "AI",
        "role.system": "System"
    },
    "ja": {
        "app.title": "stoneChat",
        "login.title": "ログイン",
        "login.password": "パスワード",
        "login.submit": "サインイン",
        "login.error": "パスワードが無効です",
        "login.locked": "ログイン失敗が多すぎます。しばらくお待ちください。",
        "chat.send": "送信",
        "chat.stop": "停止",
        "chat.regenerate": "再生成",
        "chat.countdown": "{n}秒後に自動再試行...",
        "chat.connectCheck": "接続を確認",
        "chat.reloadConfig": "設定を再読込",
        "chat.editConfig": "設定を編集",
        "chat.logout": "ログアウト",
        "chat.about": "情報",
        "chat.newChat": "新規チャット",
        "chat.deleteChat": "チャットを削除",
        "chat.renameChat": "チャット名を変更",
        "sidebar.history": "履歴",
        "common.close": "閉じる",
        "error.network": "ネットワークエラー",
        "error.timeout": "リクエストタイムアウト",
        "error.unauthorized": "認証エラー",
        "role.user": "あなた",
        "role.assistant": "AI",
        "role.system": "システム"
    },
    "ko": {
        "app.title": "stoneChat",
        "login.title": "로그인",
        "login.password": "비밀번호",
        "login.submit": "로그인",
        "login.error": "잘못된 비밀번호",
        "login.locked": "로그인 실패횟수가 너무 많습니다. 잠시 후 다시 시도해주세요.",
        "chat.send": "보내기",
        "chat.stop": "정지",
        "chat.regenerate": "재생성",
        "chat.countdown": "{n}초 후 자동 재시도...",
        "chat.connectCheck": "연결 확인",
        "chat.reloadConfig": "설정 다시 불러오기",
        "chat.editConfig": "설정 편집",
        "chat.logout": "로그아웃",
        "chat.about": "정보",
        "chat.newChat": "새 대화",
        "chat.deleteChat": "대화 삭제",
        "chat.renameChat": "대화 이름 변경",
        "sidebar.history": "기록",
        "common.close": "닫기",
        "error.network": "네트워크 오류",
        "error.timeout": "요청 시간 초과",
        "error.unauthorized": "인증 실패",
        "role.user": "나",
        "role.assistant": "AI",
        "role.system": "시스템"
    },
    "ru": {
        "app.title": "stoneChat",
        "login.title": "Вход",
        "login.password": "Пароль",
        "login.submit": "Войти",
        "login.error": "Неверный пароль",
        "login.locked": "Слишком много неудачных попыток. Повторите попытку позже.",
        "chat.send": "Отправить",
        "chat.stop": "Остановить",
        "chat.regenerate": "Перегенерировать",
        "chat.countdown": "Автоповтор через {n}с...",
        "chat.connectCheck": "Проверить соединение",
        "chat.reloadConfig": "Перезагрузить конфиг",
        "chat.editConfig": "Редактировать конфиг",
        "chat.logout": "Выйти",
        "chat.about": "О программе",
        "chat.newChat": "Новый чат",
        "chat.deleteChat": "Удалить чат",
        "chat.renameChat": "Переименовать чат",
        "sidebar.history": "История",
        "common.close": "Закрыть",
        "error.network": "Сетевая ошибка",
        "error.timeout": "Время истекло",
        "error.unauthorized": "Не авторизован",
        "role.user": "Вы",
        "role.assistant": "ИИ",
        "role.system": "Система"
    },
    "fr": {
        "app.title": "stoneChat",
        "login.title": "Connexion",
        "login.password": "Mot de passe",
        "login.submit": "Se connecter",
        "login.error": "Mot de passe invalide",
        "login.locked": "Trop de tentatives échouées. Réessayez plus tard.",
        "chat.send": "Envoyer",
        "chat.stop": "Arrêter",
        "chat.regenerate": "Régénérer",
        "chat.countdown": "Nouvelle tentative dans {n}s...",
        "chat.connectCheck": "Vérifier la connexion",
        "chat.reloadConfig": "Recharger la config",
        "chat.editConfig": "Modifier la config",
        "chat.logout": "Déconnexion",
        "chat.about": "À propos",
        "chat.newChat": "Nouvelle discussion",
        "chat.deleteChat": "Supprimer la discussion",
        "chat.renameChat": "Renommer la discussion",
        "sidebar.history": "Historique",
        "common.close": "Fermer",
        "error.network": "Erreur réseau",
        "error.timeout": "Délai dépassé",
        "error.unauthorized": "Non autorisé",
        "role.user": "Vous",
        "role.assistant": "IA",
        "role.system": "Système"
    },
    "de": {
        "app.title": "stoneChat",
        "login.title": "Anmelden",
        "login.password": "Passwort",
        "login.submit": "Anmelden",
        "login.error": "Ungültiges Passwort",
        "login.locked": "Zu viele Fehlversuche. Bitte später erneut versuchen.",
        "chat.send": "Senden",
        "chat.stop": "Stoppen",
        "chat.regenerate": "Neu generieren",
        "chat.countdown": "Automatischer Neustart in {n}s...",
        "chat.connectCheck": "Verbindung prüfen",
        "chat.reloadConfig": "Konfiguration laden",
        "chat.editConfig": "Konfiguration bearbeiten",
        "chat.logout": "Abmelden",
        "chat.about": "Über",
        "chat.newChat": "Neuer Chat",
        "chat.deleteChat": "Chat löschen",
        "chat.renameChat": "Chat umbenennen",
        "sidebar.history": "Verlauf",
        "common.close": "Schließen",
        "error.network": "Netzwerkfehler",
        "error.timeout": "Zeitüberschreitung",
        "error.unauthorized": "Nicht autorisiert",
        "role.user": "Sie",
        "role.assistant": "KI",
        "role.system": "System"
    }
};

  var LANG_LABELS = {
    'zh-CN': '\u7b80', 'zh-TW': '\u7e41', 'en': 'En', 'ja': '\u65e5',
    'ko': '\ud55c', 'ru': 'Ru', 'fr': 'Fr', 'de': 'De'
  };

  /* ----- Module state ----- */
  var supportedLangs = ['en'];
  var defaultLang = 'en';
  var currentLang = 'en';
  var table = {};
  var tableCache = {};

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

  /* ----- Sync XHR fetch of /Server/api/lang.php?lang=<code> (returns entries object) ----- */
  function fetchRemote(lang) {
    var xhr = createXHR();
    if (!xhr) return null;
    try {
      xhr.open('GET', '/Server/api/lang.php?lang=' + lang, false);
      xhr.send(null);
      if (xhr.status === 200 && xhr.responseText) {
        var data = null;
        // Prefer native JSON.parse (IE8+ / modern browsers). Fall back to
        // eval for IE6/IE7 - safe because the response is generated by
        // our own /Server/api/lang.php and never carries user content.
        if (typeof JSON !== 'undefined' && typeof JSON.parse === 'function') {
          data = JSON.parse(xhr.responseText);
        } else {
          try { data = eval('(' + xhr.responseText + ')'); }
          catch (e2) { data = null; }
        }
        if (data && typeof data === 'object' && data.ok
            && data.entries && typeof data.entries === 'object') {
          return data.entries;
        }
      }
    } catch (e) { /* network or parse failure - fall back to bundled/en */ }
    return null;
  }

  /* ----- Public: load(lang) - returns translation table ----- */
  function load(lang) {
    if (tableCache[lang]) return tableCache[lang];
    var remote = fetchRemote(lang);
    if (remote) {
      tableCache[lang] = mergeFallback(remote);
      return tableCache[lang];
    }
    if (BUNDLED[lang]) {
      tableCache[lang] = mergeFallback(BUNDLED[lang]);
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
      var active = (lang === currentLang) ? ' class="lang-active"' : '';
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
