<?php
/**
 * stoneChat Server language file — Japanese (ja).
 *
 * Returns an associative array of translation keys => UTF-8 strings.
 * Consumed by Server/i18n.php sc_t(). Compatible with PHP 5.2.
 *
 * Keys cover: app, login, chat, newchat, about, history, common, error.
 * Uses polite form (-です / -ます).
 */
return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => 'ローカルでホストされるマルチプロバイダー LLM Web チャットです。',

    // --- login ---
    'login.title'            => 'ログイン',
    'login.password'         => 'パスワード',
    'login.submit'           => 'ログイン',
    'login.error'            => 'パスワードが正しくありません。もう一度お試しください。',
    'login.locked'           => 'ログイン失敗が多すぎます。しばらくしてから再度お試しください。',
    'login.locked'           => 'ログイン試行回数が多すぎます。しばらくしてからもう一度お試しください。',

    // --- chat (action buttons / labels) ---
    'chat.send'              => '送信',
    'chat.stop'              => '停止',
    'chat.regenerate'        => '再生成',
    'chat.delete'            => '削除',
    'chat.new'               => '新規',
    'chat.newChat'           => '新規チャット',
    'chat.deleteChat'        => 'チャットを削除',
    'chat.renameChat'        => 'チャット名を変更',
    'chat.confirmDelete'     => 'このチャットを削除しますか？この操作は取り消せません。',
    'chat.settings'          => '設定',
    'chat.model'             => 'モデル',
    'chat.model.label'       => 'モデル：',
    'chat.provider'          => 'プロバイダー',
    'chat.tokens.label'      => 'トークン数：',
    'chat.timeout.label'     => 'タイムアウト（秒）：',
    'chat.connectCheck'      => '接続を確認',
    'chat.reloadConfig'      => '設定を再読み込み',
    'chat.about'             => 'について',
    'chat.empty'             => 'メッセージがありません。会話を始めるには何か入力してください。',

    // --- chat: status & errors ---
    'chat.connected'         => '接続済み',
    'chat.disconnected'      => '切断されました',
    'chat.stream.warning'    => '接続が中断されました。ストリーミングを停止しました。',
    'chat.error.network'     => 'ネットワークエラーです。接続を確認してください。',
    'chat.error.timeout'     => 'リクエストがタイムアウトしました。もう一度お試しください。',
    'chat.error.unauthorized'=> '認証されていません。もう一度ログインしてください。',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => 'メッセージを入力…',
    'chat.countdown.waiting' => '返信を待機中…',
    'chat.countdown.seconds' => '秒',

    // --- new chat dialog ---
    'newchat.title'          => '新規チャット',
    'newchat.testAll'        => 'すべてのプロバイダーをテスト',
    'newchat.create'         => '作成',
    'newchat.cancel'         => 'キャンセル',

    // --- about dialog ---
    'about.protocol'         => 'プロトコル',
    'about.author'           => '作者',
    'about.brief'            => '概要',
    'about.github'           => 'GitHub リポジトリ',
    'about.close'            => '閉じる',

    // --- history ---
    'history.title'          => '履歴',
    'history.empty'          => '履歴はまだありません。',
    'history.delete'         => '履歴を削除',
    'history.new'            => '新規チャット',
    'history.lastUsed'       => '最終使用',

    // --- common (shared UI buttons) ---
    'common.cancel'          => 'キャンセル',
    'common.confirm'         => '確認',
    'common.save'            => '保存',
    'common.close'           => '閉じる',
    'common.yes'             => 'はい',
    'common.no'              => 'いいえ',

    // --- top-level error namespace (subtask spec) ---
    'error.network'          => 'ネットワークエラーです。接続を確認してください。',
    'error.config'           => '設定ファイルが無効です。管理者にお問い合わせください。',
    'error.auth'             => '認証に失敗しました。もう一度ログインしてください。',
);
