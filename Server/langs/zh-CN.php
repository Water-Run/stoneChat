<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/langs/zh-CN.php
 *
 * Simplified Chinese (zh-CN) language table. Returns an associative
 * array of translation keys => UTF-8 strings. Consumed by
 * Server/i18n.php sc_t(). Keys cover: app, login, chat, newchat,
 * about, history, common, error. PHP 5.2 compatible.
 * ------------------------------------------------------------------------- */
return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => '本地部署的多模型大语言模型 Web 对话',

    // --- login ---
    'login.title'            => '登录',
    'login.password'         => '密码',
    'login.submit'           => '登录',
    'login.error'            => '密码错误，请重试。',
    'login.locked'           => '登录尝试次数过多，请稍后再试。',
    'login.locked'           => '登录尝试次数过多，请稍后再试。',

    // --- chat (action buttons / labels) ---
    'chat.send'              => '发送',
    'chat.stop'              => '停止',
    'chat.regenerate'        => '重新生成',
    'chat.delete'            => '删除',
    'chat.new'               => '新建',
    'chat.newChat'           => '新建对话',
    'chat.deleteChat'        => '删除对话',
    'chat.renameChat'        => '重命名对话',
    'chat.confirmDelete'     => '确定要删除这个对话吗？此操作无法撤销。',
    'chat.settings'          => '设置',
    'chat.model'             => '模型',
    'chat.model.label'       => '模型：',
    'chat.provider'          => '服务商',
    'chat.tokens.label'      => '令牌数：',
    'chat.timeout.label'     => '超时（秒）：',
    'chat.connectCheck'      => '检测连接',
    'chat.reloadConfig'      => '重新加载配置',
    'chat.about'             => '关于',
    'chat.empty'             => '没有消息。说点什么开始对话吧。',

    // --- chat: status & errors ---
    'chat.connected'         => '已连接',
    'chat.disconnected'      => '已断开',
    'chat.stream.warning'    => '连接已中断，已停止接收。',
    'chat.error.network'     => '网络错误，请检查连接。',
    'chat.error.timeout'     => '请求超时，请重试。',
    'chat.error.unauthorized'=> '未授权，请重新登录。',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => '输入消息…',
    'chat.countdown.waiting' => '等待回复中…',
    'chat.countdown.seconds' => '秒',

    // --- new chat dialog ---
    'newchat.title'          => '新建对话',
    'newchat.testAll'        => '测试全部连接',
    'newchat.create'         => '创建',
    'newchat.cancel'         => '取消',

    // --- about dialog ---
    'about.protocol'         => '协议',
    'about.author'           => '作者',
    'about.brief'            => '简介',
    'about.github'           => 'GitHub 仓库',
    'about.close'            => '关闭',

    // --- history ---
    'history.title'          => '历史记录',
    'history.empty'          => '暂无历史记录',
    'history.delete'         => '删除记录',
    'history.new'            => '新对话',
    'history.lastUsed'       => '上次使用',

    // --- common (shared UI buttons) ---
    'common.cancel'          => '取消',
    'common.confirm'         => '确认',
    'common.save'            => '保存',
    'common.close'           => '关闭',
    'common.yes'             => '是',
    'common.no'              => '否',

    // --- top-level error namespace (subtask spec) ---
    'error.network'          => '网络错误，请检查连接。',
    'error.config'           => '配置文件错误，请联系管理员。',
    'error.auth'             => '身份验证失败，请重新登录。',
);