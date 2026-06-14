<?php
/**
 * stoneChat Server language file — Korean (ko).
 *
 * Returns an associative array of translation keys => UTF-8 strings.
 * Consumed by Server/i18n.php sc_t(). Compatible with PHP 5.2.
 *
 * Korean uses formal speech level (합쇼체 / 존댓말):
 *   - Button / menu labels stay in the idiomatic nominal (-기) or noun form.
 *   - Error / status / notification messages end with formal predicates
 *     (-입니다, -됩니다, -되었습니다, -하십시오) so the user is
 *     addressed respectfully, with the polite request particle -주.
 *
 * Keys cover: app, login, chat, newchat, about, history, common, error.
 */
return array(
    // --- app ---
    'app.title'              => 'stoneChat',
    'app.tagline'            => '로컬에서 호스팅되는 다중 공급자 LLM 웹 채팅',

    // --- login ---
    'login.title'            => '로그인',
    'login.password'         => '비밀번호',
    'login.submit'           => '로그인',
    'login.error'            => '비밀번호가 올바르지 않습니다. 다시 시도해 주십시오.',
    'login.locked'           => '로그인 시도 횟수가 너무 많습니다. 잠시 후 다시 시도해 주십시오.',

    // --- chat (action buttons / labels) ---
    'chat.send'              => '보내기',
    'chat.stop'              => '중지',
    'chat.regenerate'        => '다시 생성',
    'chat.delete'            => '삭제',
    'chat.new'               => '새로 만들기',
    'chat.newChat'           => '새 대화',
    'chat.deleteChat'        => '대화 삭제',
    'chat.renameChat'        => '대화 이름 변경',
    'chat.confirmDelete'     => '이 대화를 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.',
    'chat.settings'          => '설정',
    'chat.model'             => '모델',
    'chat.model.label'       => '모델:',
    'chat.provider'          => '공급자',
    'chat.tokens.label'      => '토큰 수:',
    'chat.timeout.label'     => '시간 초과(초):',
    'chat.connectCheck'      => '연결 확인',
    'chat.reloadConfig'      => '설정 다시 불러오기',
    'chat.about'             => '정보',
    'chat.empty'             => '아직 메시지가 없습니다. 대화를 시작하려면 메시지를 입력해 주십시오.',

    // --- chat: status & errors ---
    'chat.connected'         => '연결됨',
    'chat.disconnected'      => '연결 끊김',
    'chat.stream.warning'    => '연결이 끊어졌습니다. 스트리밍이 중지되었습니다.',
    'chat.error.network'     => '네트워크 오류입니다. 연결을 확인해 주십시오.',
    'chat.error.timeout'     => '요청 시간이 초과되었습니다. 다시 시도해 주십시오.',
    'chat.error.unauthorized'=> '인증되지 않았습니다. 다시 로그인해 주십시오.',

    // --- chat: input / countdown ---
    'chat.input.placeholder' => '메시지를 입력하십시오...',
    'chat.countdown.waiting' => '응답을 기다리는 중입니다...',
    'chat.countdown.seconds' => '초',

    // --- new chat dialog ---
    'newchat.title'          => '새 대화',
    'newchat.testAll'        => '모든 공급자 테스트',
    'newchat.create'         => '만들기',
    'newchat.cancel'         => '취소',

    // --- about dialog ---
    'about.protocol'         => '프로토콜',
    'about.author'           => '작성자',
    'about.brief'            => '간단히 보기',
    'about.github'           => 'GitHub 저장소',
    'about.close'            => '닫기',

    // --- history ---
    'history.title'          => '기록',
    'history.empty'          => '아직 기록이 없습니다.',
    'history.delete'         => '기록 삭제',
    'history.new'            => '새 대화',
    'history.lastUsed'       => '마지막 사용',

    // --- common (shared UI buttons) ---
    'common.cancel'          => '취소',
    'common.confirm'         => '확인',
    'common.save'            => '저장',
    'common.close'           => '닫기',
    'common.yes'             => '예',
    'common.no'              => '아니오',

    // --- top-level error namespace (subtask spec) ---
    'error.network'          => '네트워크 오류입니다. 연결을 확인해 주십시오.',
    'error.config'           => '구성 파일이 잘못되었습니다. 관리자에게 문의해 주십시오.',
    'error.auth'             => '인증에 실패했습니다. 다시 로그인해 주십시오.',
);
