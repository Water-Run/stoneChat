<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/langs/zh-TW.php
 *
 * Traditional Chinese (zh-TW) language table. Returns an associative
 * array of translation keys => UTF-8 strings. Consumed by
 * Server/i18n.php sc_t(). Keys cover: app, login, chat, newchat,
 * about, history, common, error. Uses Taiwan-specific terminology
 * (軟體, 網路, 訊息, 設定). PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */
return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => '本地部署的多模型大語言模型 Web 對話',

    // --- login ---
    'login.title'            => '登入',
    'login.password'         => '密碼',
    'login.submit'           => '登入',
    'login.error'            => '密碼錯誤，請重試。',
    'login.locked'           => '登入失敗次數過多，請稍後再試。',
    'login.locked'           => '登入嘗試次數過多，請稍後再試。',

    // --- chat (action buttons / labels) ---
    'chat.send'              => '傳送',
    'chat.stop'              => '停止',
    'chat.regenerate'        => '重新生成',
    'chat.delete'            => '刪除',
    'chat.new'               => '新增',
    'chat.newChat'           => '新增對話',
    'chat.deleteChat'        => '刪除對話',
    'chat.renameChat'        => '重新命名對話',
    'chat.confirmDelete'     => '確定要刪除這個對話嗎？此操作無法復原。',
    'chat.settings'          => '設定',
    'chat.model'             => '模型',
    'chat.model.label'       => '模型：',
    'chat.provider'          => '服務商',
    'chat.tokens.label'      => '令牌數：',
    'chat.timeout.label'     => '逾時（秒）：',
    'chat.connectCheck'      => '檢查連線',
    'chat.reloadConfig'      => '重新載入設定',
    'chat.about'             => '關於',
    'chat.empty'             => '沒有訊息。說點什麼開始對話吧。',

    // --- chat: status & errors ---
    'chat.connected'         => '已連線',
    'chat.disconnected'      => '已斷線',
    'chat.stream.warning'    => '連線已中斷，已停止接收。',
    'chat.error.network'     => '網路錯誤，請檢查連線。',
    'chat.error.timeout'     => '請求逾時，請重試。',
    'chat.error.unauthorized'=> '未授權，請重新登入。',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => '輸入訊息…',
    'chat.countdown.waiting' => '等待回覆中…',
    'chat.countdown.seconds' => '秒',

    // --- new chat dialog ---
    'newchat.title'          => '新增對話',
    'newchat.testAll'        => '測試全部連線',
    'newchat.create'         => '建立',
    'newchat.cancel'         => '取消',

    // --- about dialog ---
    'about.protocol'         => '通訊協定',
    'about.author'           => '作者',
    'about.brief'            => '簡介',
    'about.github'           => 'GitHub 儲存庫',
    'about.close'            => '關閉',

    // --- history ---
    'history.title'          => '歷史紀錄',
    'history.empty'          => '暫無歷史紀錄',
    'history.delete'         => '刪除紀錄',
    'history.new'            => '新對話',
    'history.lastUsed'       => '上次使用',

    // --- common (shared UI buttons) ---
    'common.cancel'          => '取消',
    'common.confirm'         => '確認',
    'common.save'            => '儲存',
    'common.close'           => '關閉',
    'common.yes'             => '是',
    'common.no'              => '否',

    // --- top-level error namespace (subtask spec) ---
    'error.network'          => '網路錯誤，請檢查連線。',
    'error.config'           => '設定檔錯誤，請聯絡管理員。',
    'error.auth'             => '身分驗證失敗，請重新登入。',
);
