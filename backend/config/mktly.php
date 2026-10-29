<?php

return [
    // 데모 계정. 비밀번호는 저장소에 두지 않고 배포 환경에서는 MKTLY_DEMO_PASSWORD 환경변수로 받는다.
    // (로컬 개발에서만 편의상 기본 비밀번호를 쓴다.)
    'demo' => [
        'email' => env('MKTLY_DEMO_EMAIL', 'demo@mktly.test'),
        'password' => env('MKTLY_DEMO_PASSWORD', env('APP_ENV') === 'local' ? 'mktly-demo-1234' : null),
        // true이면 `mktly:bootstrap`(배포 시 자동 실행)이 데모 계정이 없을 때 데모 데이터를 만든다.
        'seed' => filter_var(env('MKTLY_SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN),
        // 공개 데모는 읽기 전용(viewer)을 권장한다. 마케터 이상이면 방문자가 메일 발송과 AI 호출을 할 수 있다.
        'role' => env('MKTLY_DEMO_ROLE', 'viewer'),
        // 로그인 화면의 "데모 계정으로 시작하기" 버튼(비밀번호 없이 읽기 전용 데모 계정으로 입장). 로컬에서는 기본 켜짐.
        'login' => filter_var(env('MKTLY_DEMO_LOGIN', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOLEAN),
    ],

    // 공개 회원가입. 가입한 계정은 새 워크스페이스의 관리자가 되며, 메일 발송은 운영자가 켜 주기 전까지 비활성화된다.
    'signup' => [
        'enabled' => filter_var(env('MKTLY_SIGNUP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // 메일 제공업체(예: Brevo 무료 요금제는 하루 300통)의 일일 발송 한도. 비워 두면 제한하지 않는다.
    // 한도를 넘는 발송은 시작 전에 막아서 캠페인이 중간에 실패하는 일을 피한다.
    'mail' => [
        'daily_limit' => env('MKTLY_MAIL_DAILY_LIMIT') ? (int) env('MKTLY_MAIL_DAILY_LIMIT') : null,
    ],

    // LLM 비용 보호: 하루에 실제 모델로 만들 수 있는 AI 카피 생성 횟수(모든 워크스페이스 합계, 샘플 모드는 제외).
    // 비워 두면 제한하지 않는다. 유료 API 키를 넣을 때는 반드시 설정한다.
    'ai' => [
        'copy_daily_limit' => env('MKTLY_AI_COPY_DAILY_LIMIT') ? (int) env('MKTLY_AI_COPY_DAILY_LIMIT') : null,
        // 유료 OpenAI 호출만 따로 세는 일일 상한. 도달하면 OpenAI는 건너뛰고 무료 Gemini만 쓴다. 비워 두면 제한하지 않는다.
        'openai_daily_limit' => env('MKTLY_AI_OPENAI_DAILY_LIMIT') ? (int) env('MKTLY_AI_OPENAI_DAILY_LIMIT') : null,
        // 임베딩(참고 자료 검색) 외부 호출 일일 상한. 도달하면 외부 호출 없이 로컬 해시 벡터를 쓴다. 비워 두면 제한하지 않는다.
        'embed_daily_limit' => env('MKTLY_AI_EMBED_DAILY_LIMIT') ? (int) env('MKTLY_AI_EMBED_DAILY_LIMIT') : null,
    ],

    // 배포 후 첫 관리자 계정. 값이 모두 있고 사용자가 한 명도 없을 때만 `mktly:bootstrap`이 계정을 만든다.
    'admin' => [
        'name' => env('MKTLY_ADMIN_NAME', '관리자'),
        'email' => env('MKTLY_ADMIN_EMAIL'),
        'password' => env('MKTLY_ADMIN_PASSWORD'),
        'workspace' => env('MKTLY_WORKSPACE_NAME', '내 워크스페이스'),
    ],
];
