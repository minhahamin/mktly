<?php

return [
    // 로컬 데모용 계정. 로그인 화면의 "데모 계정 입력하기" 버튼(로컬 전용)과 로컬 시더에서만 쓴다.
    // 배포 환경의 데모 계정 비밀번호는 저장소에 두지 않고 MKTLY_DEMO_PASSWORD 환경변수로 받는다.
    'demo' => [
        'email' => env('MKTLY_DEMO_EMAIL', 'demo@mktly.test'),
        'password' => env('MKTLY_DEMO_PASSWORD', env('APP_ENV') === 'local' ? 'mktly-demo-1234' : null),
        // true이면 `mktly:bootstrap`(배포 시 자동 실행)이 데모 계정이 없을 때 데모 데이터를 만든다.
        'seed' => filter_var(env('MKTLY_SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN),
        // 공개 데모는 읽기 전용(viewer)을 권장한다. 마케터 이상이면 방문자가 메일 발송과 AI 호출을 할 수 있다.
        'role' => env('MKTLY_DEMO_ROLE', 'viewer'),
    ],

    // 메일 제공업체(예: Brevo 무료 요금제는 하루 300통)의 일일 발송 한도. 비워 두면 제한하지 않는다.
    // 한도를 넘는 발송은 시작 전에 막아서 캠페인이 중간에 실패하는 일을 피한다.
    'mail' => [
        'daily_limit' => env('MKTLY_MAIL_DAILY_LIMIT') ? (int) env('MKTLY_MAIL_DAILY_LIMIT') : null,
    ],

    // 배포 후 첫 관리자 계정. 값이 모두 있고 사용자가 한 명도 없을 때만 `mktly:bootstrap`이 계정을 만든다.
    'admin' => [
        'name' => env('MKTLY_ADMIN_NAME', '관리자'),
        'email' => env('MKTLY_ADMIN_EMAIL'),
        'password' => env('MKTLY_ADMIN_PASSWORD'),
        'workspace' => env('MKTLY_WORKSPACE_NAME', '내 워크스페이스'),
    ],
];
