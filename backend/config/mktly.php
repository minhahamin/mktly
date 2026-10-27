<?php

return [
    // 로컬 데모용 계정. 로그인 화면의 "데모 계정 입력하기" 버튼(로컬 전용)과 로컬 시더에서만 쓴다.
    // 배포 환경의 데모 계정 비밀번호는 저장소에 두지 않고 MKTLY_DEMO_PASSWORD 환경변수로 받는다.
    'demo' => [
        'email' => env('MKTLY_DEMO_EMAIL', 'demo@mktly.test'),
        'password' => env('MKTLY_DEMO_PASSWORD', env('APP_ENV') === 'local' ? 'mktly-demo-1234' : null),
    ],

    // 배포 후 첫 관리자 계정. 값이 모두 있고 사용자가 한 명도 없을 때만 `mktly:bootstrap`이 계정을 만든다.
    'admin' => [
        'name' => env('MKTLY_ADMIN_NAME', '관리자'),
        'email' => env('MKTLY_ADMIN_EMAIL'),
        'password' => env('MKTLY_ADMIN_PASSWORD'),
        'workspace' => env('MKTLY_WORKSPACE_NAME', '내 워크스페이스'),
    ],
];
