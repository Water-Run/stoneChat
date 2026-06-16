<?php
/* -------------------------------------------------------------------------
 * stoneChat / Server/langs/ru.php
 *
 * Russian (ru) language table. Returns an associative array of
 * translation keys => UTF-8 strings. Consumed by Server/i18n.php
 * sc_t(). Uses a formal register: button / menu labels are
 * infinitives (Отправить, Войти, Создать); error / status messages
 * use the formal Вы form or impersonal constructions. PHP 5.2
 * compatible.
 * ------------------------------------------------------------------------- */
return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => 'Локально размещённый веб-чат с несколькими LLM-провайдерами.',

    // --- login ---
    'login.title'            => 'Вход',
    'login.password'         => 'Пароль',
    'login.submit'           => 'Войти',
    'login.error'            => 'Неверный пароль. Пожалуйста, попробуйте снова.',
    'login.locked'           => 'Слишком много неудачных попыток. Повторите попытку позже.',
    'login.locked'           => 'Слишком много неудачных попыток. Пожалуйста, подождите и повторите позже.',

    // --- chat (action buttons / labels) ---
    'chat.send'              => 'Отправить',
    'chat.stop'              => 'Остановить',
    'chat.regenerate'        => 'Сгенерировать заново',
    'chat.delete'            => 'Удалить',
    'chat.new'               => 'Создать',
    'chat.newChat'           => 'Новый чат',
    'chat.deleteChat'        => 'Удалить чат',
    'chat.renameChat'        => 'Переименовать чат',
    'chat.confirmDelete'     => 'Удалить этот чат? Это действие нельзя отменить.',
    'chat.settings'          => 'Настройки',
    'chat.model'             => 'Модель',
    'chat.model.label'       => 'Модель:',
    'chat.provider'          => 'Провайдер',
    'chat.tokens.label'      => 'Токены:',
    'chat.timeout.label'     => 'Таймаут (с):',
    'chat.connectCheck'      => 'Проверить соединение',
    'chat.reloadConfig'      => 'Перезагрузить конфигурацию',
    'chat.about'             => 'О программе',
    'chat.empty'             => 'Сообщений пока нет. Напишите что-нибудь, чтобы начать беседу.',

    // --- chat: status & errors ---
    'chat.connected'         => 'Подключено',
    'chat.disconnected'      => 'Отключено',
    'chat.stream.warning'    => 'Соединение прервано. Потоковая передача остановлена.',
    'chat.error.network'     => 'Сетевая ошибка. Пожалуйста, проверьте подключение.',
    'chat.error.timeout'     => 'Время ожидания истекло. Пожалуйста, попробуйте снова.',
    'chat.error.unauthorized'=> 'Не авторизован. Пожалуйста, войдите снова.',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => 'Введите сообщение...',
    'chat.countdown.waiting' => 'Ожидание ответа...',
    'chat.countdown.seconds' => 'с',

    // --- new chat dialog ---
    'newchat.title'          => 'Новый чат',
    'newchat.testAll'        => 'Проверить всех провайдеров',
    'newchat.create'         => 'Создать',
    'newchat.cancel'         => 'Отмена',

    // --- about dialog ---
    'about.protocol'         => 'Протокол',
    'about.author'           => 'Автор',
    'about.brief'            => 'Краткое описание',
    'about.github'           => 'Репозиторий GitHub',
    'about.close'            => 'Закрыть',

    // --- history ---
    'history.title'          => 'История',
    'history.empty'          => 'История пока пуста.',
    'history.delete'         => 'Удалить запись',
    'history.new'            => 'Новый чат',
    'history.lastUsed'       => 'Последнее использование',

    // --- common (shared UI buttons) ---
    'common.cancel'          => 'Отмена',
    'common.confirm'         => 'Подтвердить',
    'common.save'            => 'Сохранить',
    'common.close'           => 'Закрыть',
    'common.yes'             => 'Да',
    'common.no'              => 'Нет',

    // --- top-level error namespace (subtask spec) ---
    'error.network'          => 'Сетевая ошибка. Пожалуйста, проверьте подключение.',
    'error.config'           => 'Файл конфигурации недействителен. Пожалуйста, обратитесь к администратору.',
    'error.auth'             => 'Ошибка аутентификации. Пожалуйста, войдите снова.',
);
