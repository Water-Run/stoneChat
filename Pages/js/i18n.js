/* -------------------------------------------------------------------------
 * stoneChat / Pages/js/i18n.js
 *
 * Client-side internationalization. IE6-compatible (ES3 only):
 * var/function, no JSON, no Array methods. Language is selected by
 * URL query string; no localStorage and no language cookie.
 *
 * Public API (window.SC.I18n, alias window.scI18n):
 *   init(supportedLangs, defaultLang)   bootstrap, reads ?lang=
 *   load(lang)                          returns translation table
 *   apply()                             walks DOM, replaces text
 *   setLang(lang)                       rewrites ?lang= and reloads page
 *   getLang()                           current language code
 *   t(key)                              lookup with English fallback
 *   getLangSwitcherHTML()               8 language buttons
 * ------------------------------------------------------------------------- */
(function () {
  var SC = window.SC || {};

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
    'login.submitting': 'Signing in...',
    'login.error': 'Invalid password',
    'login.locked': 'Too many failed attempts. Please wait and try again later.',
    'chat.send': 'Send',
    'chat.stop': 'Stop',
    'chat.regenerate': 'Regenerate',
    'chat.generateTitle': 'Generate title',
    'chat.generateTitleBusy': '[Generating...]',
    'chat.generateTitleDone': 'Title updated',
    'chat.generateTitleFailed': 'Generate title failed',
    'chat.noActiveChat': 'No active chat.',
    'chat.countdown': 'Auto-retry in {n}s...',
    'chat.connectCheck': 'Check connection',
    'chat.reloadConfig': 'Reload config',
    'chat.editConfig': 'Edit config',
    'chat.logout': 'Logout',
    'chat.about': 'About',
    'chat.newChat': 'New chat',
    'chat.deleteChat': 'Delete chat',
    'chat.renameChat': 'Rename chat',
    'chat.check': 'Check',
    'chat.user': 'User',
    'chat.model': 'Model',
    'chat.type': 'Type',
    'chat.api': 'API',
    'chat.tokens': 'Tokens',
    'chat.timeout': 'Timeout',
    'chat.test': 'Test',
    'chat.use': 'Use',
    'chat.noModelSelected': 'No model selected.',
    'chat.untitled': 'Untitled',
    'chat.loadModelsFailed': 'Failed to load models',
    'chat.noModels': 'No models configured.',
    'chat.connectionOk': 'Connection OK',
    'chat.connectionFailed': 'Connection failed',
    'chat.deleteConfirm': 'Delete this conversation?',
    'chat.charCount': 'Chars: {n} / {m}',
    'chat.wait': 'Wait: {n}s',
    'chat.sendHintEnter': 'Enter sends / Shift+Enter makes a new line',
    'chat.sendHintShift': 'Shift+Enter sends / Enter makes a new line',
    'chat.errorLabel': 'Error',
    'chat.streamWarning': 'Connection interrupted. Streaming has been stopped.',
    'chat.createFailed': 'Create chat failed',
    'chat.reloadFailed': 'Reload failed',
    'chat.deleteFailed': 'Delete failed',
    'chat.renameFailed': 'Rename failed',
    'chat.configEditorOff': 'CONF.ini editor is disabled by [ui] allow_online_editor.',
    'error.unknown': 'unknown',
    'sidebar.history': 'History',
    'sidebar.search': 'Search conversations',
    'sidebar.noHistory': 'No history yet.',
    'sidebar.noMatch': 'No match.',
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
        "login.submitting": "正在登录...",
        "login.error": "密码错误",
        "login.locked": "登录尝试次数过多，请稍后再试。",
        "chat.send": "发送",
        "chat.stop": "停止",
        "chat.regenerate": "重新生成",
        "chat.generateTitle": "生成标题",
        "chat.generateTitleBusy": "[生成中...]",
        "chat.generateTitleDone": "标题已更新",
        "chat.generateTitleFailed": "生成标题失败",
        "chat.noActiveChat": "没有当前对话。",
        "chat.countdown": "{n} 秒后自动重试...",
        "chat.connectCheck": "检查连接",
        "chat.reloadConfig": "重载配置",
        "chat.editConfig": "编辑配置",
        "chat.logout": "退出",
        "chat.about": "关于",
        "chat.newChat": "新建对话",
        "chat.deleteChat": "删除对话",
        "chat.renameChat": "重命名对话",
        "chat.check": "检查",
        "chat.user": "用户",
        "chat.model": "模型",
        "chat.type": "类型",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "超时",
        "chat.test": "测试",
        "chat.use": "使用",
        "chat.noModelSelected": "未选择模型。",
        "chat.untitled": "未命名",
        "chat.loadModelsFailed": "读取模型失败",
        "chat.noModels": "没有配置模型。",
        "chat.connectionOk": "连接正常",
        "chat.connectionFailed": "连接失败",
        "chat.deleteConfirm": "删除这个对话？",
        "chat.charCount": "字数: {n} / {m}",
        "chat.wait": "等待: {n}秒",
        "chat.sendHintEnter": "Enter发送 / Shift+Enter换行",
        "chat.sendHintShift": "Shift+Enter发送 / Enter换行",
        "chat.errorLabel": "错误",
        "chat.streamWarning": "连接已中断，流式传输已停止。",
        "chat.createFailed": "新建对话失败",
        "chat.reloadFailed": "重新加载失败",
        "chat.deleteFailed": "删除失败",
        "chat.renameFailed": "重命名失败",
        "chat.configEditorOff": "CONF.ini 编辑器被 [ui] allow_online_editor 关闭。",
        "error.unknown": "未知",
        "sidebar.history": "历史记录",
        "sidebar.search": "查找对话",
        "sidebar.noHistory": "没有历史记录。",
        "sidebar.noMatch": "没有符合项。",
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
        "login.submitting": "正在登入...",
        "login.error": "密碼錯誤",
        "login.locked": "登入嘗試次數過多，請稍後再試。",
        "chat.send": "發送",
        "chat.stop": "停止",
        "chat.regenerate": "重新生成",
        "chat.generateTitle": "生成標題",
        "chat.generateTitleBusy": "[生成中...]",
        "chat.generateTitleDone": "標題已更新",
        "chat.generateTitleFailed": "生成標題失敗",
        "chat.noActiveChat": "沒有目前對話。",
        "chat.countdown": "{n} 秒後自動重試...",
        "chat.connectCheck": "檢查連接",
        "chat.reloadConfig": "重載配置",
        "chat.editConfig": "編輯配置",
        "chat.logout": "登出",
        "chat.about": "關於",
        "chat.newChat": "新建對話",
        "chat.deleteChat": "刪除對話",
        "chat.renameChat": "重命名對話",
        "chat.check": "檢查",
        "chat.user": "使用者",
        "chat.model": "模型",
        "chat.type": "類型",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "逾時",
        "chat.test": "測試",
        "chat.use": "使用",
        "chat.noModelSelected": "未選擇模型。",
        "chat.untitled": "未命名",
        "chat.loadModelsFailed": "讀取模型失敗",
        "chat.noModels": "沒有配置模型。",
        "chat.connectionOk": "連接正常",
        "chat.connectionFailed": "連接失敗",
        "chat.deleteConfirm": "刪除此對話？",
        "chat.charCount": "字數: {n} / {m}",
        "chat.wait": "等待: {n}秒",
        "chat.sendHintEnter": "Enter發送 / Shift+Enter換行",
        "chat.sendHintShift": "Shift+Enter發送 / Enter換行",
        "chat.errorLabel": "錯誤",
        "chat.streamWarning": "連接已中斷，流式傳輸已停止。",
        "chat.createFailed": "新建對話失敗",
        "chat.reloadFailed": "重新載入失敗",
        "chat.deleteFailed": "刪除失敗",
        "chat.renameFailed": "重命名失敗",
        "chat.configEditorOff": "CONF.ini 編輯器被 [ui] allow_online_editor 關閉。",
        "error.unknown": "未知",
        "sidebar.history": "歷史記錄",
        "sidebar.search": "查找對話",
        "sidebar.noHistory": "沒有歷史記錄。",
        "sidebar.noMatch": "沒有符合項。",
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
        "login.submitting": "Signing in...",
        "login.error": "Invalid password",
        "login.locked": "Too many failed attempts. Please wait and try again later.",
        "chat.send": "Send",
        "chat.stop": "Stop",
        "chat.regenerate": "Regenerate",
        "chat.generateTitle": "Generate title",
        "chat.generateTitleBusy": "[Generating...]",
        "chat.generateTitleDone": "Title updated",
        "chat.generateTitleFailed": "Generate title failed",
        "chat.noActiveChat": "No active chat.",
        "chat.countdown": "Auto-retry in {n}s...",
        "chat.connectCheck": "Check connection",
        "chat.reloadConfig": "Reload config",
        "chat.editConfig": "Edit config",
        "chat.logout": "Logout",
        "chat.about": "About",
        "chat.newChat": "New chat",
        "chat.deleteChat": "Delete chat",
        "chat.renameChat": "Rename chat",
        "chat.check": "Check",
        "chat.user": "User",
        "chat.model": "Model",
        "chat.type": "Type",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "Timeout",
        "chat.test": "Test",
        "chat.use": "Use",
        "chat.noModelSelected": "No model selected.",
        "chat.untitled": "Untitled",
        "chat.loadModelsFailed": "Failed to load models",
        "chat.noModels": "No models configured.",
        "chat.connectionOk": "Connection OK",
        "chat.connectionFailed": "Connection failed",
        "chat.deleteConfirm": "Delete this conversation?",
        "chat.charCount": "Chars: {n} / {m}",
        "chat.wait": "Wait: {n}s",
        "chat.sendHintEnter": "Enter sends / Shift+Enter makes a new line",
        "chat.sendHintShift": "Shift+Enter sends / Enter makes a new line",
        "chat.errorLabel": "Error",
        "chat.streamWarning": "Connection interrupted. Streaming has been stopped.",
        "chat.createFailed": "Create chat failed",
        "chat.reloadFailed": "Reload failed",
        "chat.deleteFailed": "Delete failed",
        "chat.renameFailed": "Rename failed",
        "chat.configEditorOff": "CONF.ini editor is disabled by [ui] allow_online_editor.",
        "error.unknown": "unknown",
        "sidebar.history": "History",
        "sidebar.search": "Search conversations",
        "sidebar.noHistory": "No history yet.",
        "sidebar.noMatch": "No match.",
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
        "login.submitting": "サインイン中...",
        "login.error": "パスワードが無効です",
        "login.locked": "ログイン失敗が多すぎます。しばらくお待ちください。",
        "chat.send": "送信",
        "chat.stop": "停止",
        "chat.regenerate": "再生成",
        "chat.generateTitle": "タイトル生成",
        "chat.generateTitleBusy": "[生成中...]",
        "chat.generateTitleDone": "タイトルを更新しました",
        "chat.generateTitleFailed": "タイトル生成に失敗しました",
        "chat.noActiveChat": "現在のチャットがありません。",
        "chat.countdown": "{n}秒後に自動再試行...",
        "chat.connectCheck": "接続を確認",
        "chat.reloadConfig": "設定を再読込",
        "chat.editConfig": "設定を編集",
        "chat.logout": "ログアウト",
        "chat.about": "情報",
        "chat.newChat": "新規チャット",
        "chat.deleteChat": "チャットを削除",
        "chat.renameChat": "チャット名を変更",
        "chat.check": "確認",
        "chat.user": "ユーザー",
        "chat.model": "モデル",
        "chat.type": "種類",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "タイムアウト",
        "chat.test": "テスト",
        "chat.use": "使用",
        "chat.noModelSelected": "モデル未選択。",
        "chat.untitled": "無題",
        "chat.loadModelsFailed": "モデル読込失敗",
        "chat.noModels": "モデル未設定。",
        "chat.connectionOk": "接続 OK",
        "chat.connectionFailed": "接続失敗",
        "chat.deleteConfirm": "このチャットを削除しますか？",
        "chat.charCount": "文字数: {n} / {m}",
        "chat.wait": "待機: {n}秒",
        "chat.sendHintEnter": "Enter送信 / Shift+Enter改行",
        "chat.sendHintShift": "Shift+Enter送信 / Enter改行",
        "chat.errorLabel": "エラー",
        "chat.streamWarning": "接続が中断されました。ストリームを停止しました。",
        "chat.createFailed": "チャット作成失敗",
        "chat.reloadFailed": "再読込失敗",
        "chat.deleteFailed": "削除失敗",
        "chat.renameFailed": "名前変更失敗",
        "chat.configEditorOff": "CONF.ini エディタは [ui] allow_online_editor で無効です。",
        "error.unknown": "不明",
        "sidebar.history": "履歴",
        "sidebar.search": "チャット検索",
        "sidebar.noHistory": "履歴はありません。",
        "sidebar.noMatch": "一致なし。",
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
        "login.submitting": "로그인 중...",
        "login.error": "잘못된 비밀번호",
        "login.locked": "로그인 실패횟수가 너무 많습니다. 잠시 후 다시 시도해주세요.",
        "chat.send": "보내기",
        "chat.stop": "정지",
        "chat.regenerate": "재생성",
        "chat.generateTitle": "제목 생성",
        "chat.generateTitleBusy": "[생성 중...]",
        "chat.generateTitleDone": "제목이 갱신되었습니다",
        "chat.generateTitleFailed": "제목 생성 실패",
        "chat.noActiveChat": "현재 대화가 없습니다.",
        "chat.countdown": "{n}초 후 자동 재시도...",
        "chat.connectCheck": "연결 확인",
        "chat.reloadConfig": "설정 다시 불러오기",
        "chat.editConfig": "설정 편집",
        "chat.logout": "로그아웃",
        "chat.about": "정보",
        "chat.newChat": "새 대화",
        "chat.deleteChat": "대화 삭제",
        "chat.renameChat": "대화 이름 변경",
        "chat.check": "검사",
        "chat.user": "사용자",
        "chat.model": "모델",
        "chat.type": "종류",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "시간 제한",
        "chat.test": "테스트",
        "chat.use": "사용",
        "chat.noModelSelected": "모델이 선택되지 않았습니다.",
        "chat.untitled": "제목 없음",
        "chat.loadModelsFailed": "모델 읽기 실패",
        "chat.noModels": "설정된 모델이 없습니다.",
        "chat.connectionOk": "연결 정상",
        "chat.connectionFailed": "연결 실패",
        "chat.deleteConfirm": "이 대화를 삭제하시겠습니까?",
        "chat.charCount": "글자수: {n} / {m}",
        "chat.wait": "대기: {n}초",
        "chat.sendHintEnter": "Enter 전송 / Shift+Enter 줄바꿈",
        "chat.sendHintShift": "Shift+Enter 전송 / Enter 줄바꿈",
        "chat.errorLabel": "오류",
        "chat.streamWarning": "연결이 끊겼습니다. 스트림이 중지되었습니다.",
        "chat.createFailed": "대화 만들기 실패",
        "chat.reloadFailed": "다시 불러오기 실패",
        "chat.deleteFailed": "삭제 실패",
        "chat.renameFailed": "이름 변경 실패",
        "chat.configEditorOff": "CONF.ini 편집기는 [ui] allow_online_editor 로 꺼져 있습니다.",
        "error.unknown": "알 수 없음",
        "sidebar.history": "기록",
        "sidebar.search": "대화 검색",
        "sidebar.noHistory": "기록이 없습니다.",
        "sidebar.noMatch": "일치 없음.",
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
        "login.submitting": "Вход...",
        "login.error": "Неверный пароль",
        "login.locked": "Слишком много неудачных попыток. Повторите попытку позже.",
        "chat.send": "Отправить",
        "chat.stop": "Остановить",
        "chat.regenerate": "Перегенерировать",
        "chat.generateTitle": "Создать заголовок",
        "chat.generateTitleBusy": "[Создание...]",
        "chat.generateTitleDone": "Заголовок обновлен",
        "chat.generateTitleFailed": "Не удалось создать заголовок",
        "chat.noActiveChat": "Нет активного чата.",
        "chat.countdown": "Автоповтор через {n}с...",
        "chat.connectCheck": "Проверить соединение",
        "chat.reloadConfig": "Перезагрузить конфиг",
        "chat.editConfig": "Редактировать конфиг",
        "chat.logout": "Выйти",
        "chat.about": "О программе",
        "chat.newChat": "Новый чат",
        "chat.deleteChat": "Удалить чат",
        "chat.renameChat": "Переименовать чат",
        "chat.check": "Проверить",
        "chat.user": "Пользователь",
        "chat.model": "Модель",
        "chat.type": "Тип",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "Тайм-аут",
        "chat.test": "Тест",
        "chat.use": "Использовать",
        "chat.noModelSelected": "Модель не выбрана.",
        "chat.untitled": "Без названия",
        "chat.loadModelsFailed": "Не удалось загрузить модели",
        "chat.noModels": "Модели не настроены.",
        "chat.connectionOk": "Соединение OK",
        "chat.connectionFailed": "Ошибка соединения",
        "chat.deleteConfirm": "Удалить этот чат?",
        "chat.charCount": "Символов: {n} / {m}",
        "chat.wait": "Ожидание: {n}с",
        "chat.sendHintEnter": "Enter отправляет / Shift+Enter перенос строки",
        "chat.sendHintShift": "Shift+Enter отправляет / Enter перенос строки",
        "chat.errorLabel": "Ошибка",
        "chat.streamWarning": "Соединение прервано. Поток остановлен.",
        "chat.createFailed": "Не удалось создать чат",
        "chat.reloadFailed": "Не удалось перезагрузить",
        "chat.deleteFailed": "Не удалось удалить",
        "chat.renameFailed": "Не удалось переименовать",
        "chat.configEditorOff": "Редактор CONF.ini отключен параметром [ui] allow_online_editor.",
        "error.unknown": "неизвестно",
        "sidebar.history": "История",
        "sidebar.search": "Поиск чатов",
        "sidebar.noHistory": "Истории нет.",
        "sidebar.noMatch": "Нет совпадений.",
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
        "login.submitting": "Connexion...",
        "login.error": "Mot de passe invalide",
        "login.locked": "Trop de tentatives échouées. Réessayez plus tard.",
        "chat.send": "Envoyer",
        "chat.stop": "Arrêter",
        "chat.regenerate": "Régénérer",
        "chat.generateTitle": "Créer le titre",
        "chat.generateTitleBusy": "[Création...]",
        "chat.generateTitleDone": "Titre mis à jour",
        "chat.generateTitleFailed": "Échec du titre",
        "chat.noActiveChat": "Aucune discussion active.",
        "chat.countdown": "Nouvelle tentative dans {n}s...",
        "chat.connectCheck": "Vérifier la connexion",
        "chat.reloadConfig": "Recharger la config",
        "chat.editConfig": "Modifier la config",
        "chat.logout": "Déconnexion",
        "chat.about": "À propos",
        "chat.newChat": "Nouvelle discussion",
        "chat.deleteChat": "Supprimer la discussion",
        "chat.renameChat": "Renommer la discussion",
        "chat.check": "Vérifier",
        "chat.user": "Utilisateur",
        "chat.model": "Modèle",
        "chat.type": "Catégorie",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "Délai",
        "chat.test": "Tester",
        "chat.use": "Utiliser",
        "chat.noModelSelected": "Aucun modèle sélectionné.",
        "chat.untitled": "Sans titre",
        "chat.loadModelsFailed": "Échec du chargement des modèles",
        "chat.noModels": "Aucun modèle configuré.",
        "chat.connectionOk": "Connexion OK",
        "chat.connectionFailed": "Connexion échouée",
        "chat.deleteConfirm": "Supprimer cette discussion ?",
        "chat.charCount": "Caractères : {n} / {m}",
        "chat.wait": "Attente : {n}s",
        "chat.sendHintEnter": "Enter envoie / Shift+Enter nouvelle ligne",
        "chat.sendHintShift": "Shift+Enter envoie / Enter nouvelle ligne",
        "chat.errorLabel": "Erreur",
        "chat.streamWarning": "Connexion interrompue. Le flux est arrêté.",
        "chat.createFailed": "Échec de création",
        "chat.reloadFailed": "Échec du rechargement",
        "chat.deleteFailed": "Échec de suppression",
        "chat.renameFailed": "Échec du renommage",
        "chat.configEditorOff": "L'éditeur CONF.ini est désactivé par [ui] allow_online_editor.",
        "error.unknown": "inconnu",
        "sidebar.history": "Historique",
        "sidebar.search": "Rechercher",
        "sidebar.noHistory": "Aucun historique.",
        "sidebar.noMatch": "Aucune correspondance.",
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
        "login.submitting": "Anmeldung...",
        "login.error": "Ungültiges Passwort",
        "login.locked": "Zu viele Fehlversuche. Bitte später erneut versuchen.",
        "chat.send": "Senden",
        "chat.stop": "Stoppen",
        "chat.regenerate": "Neu generieren",
        "chat.generateTitle": "Titel erzeugen",
        "chat.generateTitleBusy": "[Erzeuge...]",
        "chat.generateTitleDone": "Titel aktualisiert",
        "chat.generateTitleFailed": "Titel fehlgeschlagen",
        "chat.noActiveChat": "Kein aktiver Chat.",
        "chat.countdown": "Automatischer Neustart in {n}s...",
        "chat.connectCheck": "Verbindung prüfen",
        "chat.reloadConfig": "Konfiguration laden",
        "chat.editConfig": "Konfiguration bearbeiten",
        "chat.logout": "Abmelden",
        "chat.about": "Über",
        "chat.newChat": "Neuer Chat",
        "chat.deleteChat": "Chat löschen",
        "chat.renameChat": "Chat umbenennen",
        "chat.check": "Prüfen",
        "chat.user": "Benutzer",
        "chat.model": "Modell",
        "chat.type": "Typ",
        "chat.api": "API",
        "chat.tokens": "Tokens",
        "chat.timeout": "Zeitlimit",
        "chat.test": "Prüfen",
        "chat.use": "Nutzen",
        "chat.noModelSelected": "Kein Modell gewählt.",
        "chat.untitled": "Ohne Titel",
        "chat.loadModelsFailed": "Modelle konnten nicht geladen werden",
        "chat.noModels": "Keine Modelle konfiguriert.",
        "chat.connectionOk": "Verbindung OK",
        "chat.connectionFailed": "Verbindung fehlgeschlagen",
        "chat.deleteConfirm": "Diesen Chat löschen?",
        "chat.charCount": "Zeichen: {n} / {m}",
        "chat.wait": "Warten: {n}s",
        "chat.sendHintEnter": "Enter sendet / Shift+Enter neue Zeile",
        "chat.sendHintShift": "Shift+Enter sendet / Enter neue Zeile",
        "chat.errorLabel": "Fehler",
        "chat.streamWarning": "Verbindung unterbrochen. Streaming wurde gestoppt.",
        "chat.createFailed": "Chat konnte nicht erstellt werden",
        "chat.reloadFailed": "Laden fehlgeschlagen",
        "chat.deleteFailed": "Löschen fehlgeschlagen",
        "chat.renameFailed": "Umbenennen fehlgeschlagen",
        "chat.configEditorOff": "Der CONF.ini-Editor ist durch [ui] allow_online_editor deaktiviert.",
        "error.unknown": "unbekannt",
        "sidebar.history": "Verlauf",
        "sidebar.search": "Chats suchen",
        "sidebar.noHistory": "Kein Verlauf.",
        "sidebar.noMatch": "Keine Treffer.",
        "common.close": "Schließen",
        "error.network": "Netzwerkfehler",
        "error.timeout": "Zeitüberschreitung",
        "error.unauthorized": "Nicht autorisiert",
        "role.user": "Sie",
        "role.assistant": "KI",
        "role.system": "Systemrolle"
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

  function getQueryValue(name) {
    var search = (typeof location !== 'undefined' && location.search)
               ? location.search : '';
    if (search.charAt(0) === '?') {
      search = search.substring(1);
    }
    var parts = search.split('&');
    for (var i = 0; i < parts.length; i++) {
      var pair = parts[i].split('=');
      var key = safeDecode(pair[0] || '');
      if (key === name) {
        return safeDecode(pair.length > 1 ? pair[1] : '');
      }
    }
    return null;
  }

  function safeDecode(value) {
    try {
      return decodeURIComponent(String(value).replace(/\+/g, ' '));
    } catch (e) {
      return '';
    }
  }

  /* ----- Pick a valid language from ?lang= or return null ----- */
  function pickFromQuery(supported) {
    var value = getQueryValue('lang');
    if (!value) return null;
    for (var i = 0; i < supported.length; i++) {
      if (supported[i] === value) return value;
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
    currentLang = pickFromQuery(supportedLangs) || defaultLang;
    table = load(currentLang);
  }

  /* ----- Public: t(key) - lookup with English fallback ----- */
  function t(key) {
    if (table[key]) return table[key];
    if (FALLBACK_EN[key]) return FALLBACK_EN[key];
    return key;
  }

  /* ----- Public: setLang(lang) - rewrites ?lang= and reloads page ----- */
  function setLang(lang) {
    var base = 'chat.htm';
    if (typeof location !== 'undefined' && location.pathname) {
      var path = location.pathname;
      var slash = path.lastIndexOf('/');
      base = slash >= 0 ? path.substring(slash + 1) : path;
      if (base === '') { base = 'chat.htm'; }
    }
    if (typeof location !== 'undefined') {
      location.href = base + '?lang=' + encodeURIComponent(lang);
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
