# Backend (Laravel 13 + Blade + Livewire)

화면과 서버 로직을 모두 담당하는 Laravel 앱. 요구사항과 라우트 명세는 `../SPEC.md`를 따른다.

## 스택

PHP 8.4, Laravel 13, Blade, Livewire 4, Alpine.js, Tailwind CSS v4, Chart.js, Vite, MySQL 8, Redis(Queue/Cache/Session), Pest.

## 구조 규칙

- 화면 라우트는 `routes/web.php`에서 관리한다. 페이지는 Livewire 컴포넌트(`app/Livewire`)를 라우트에 직접 연결한다. 별도 JSON API는 만들지 않는다.
- Livewire 컴포넌트는 얇게 유지한다: 검증은 Form Object 또는 `#[Validate]`, 비즈니스 로직은 `app/Services`.
- 외부 호출(AI 서비스)은 `app/Services/Ai/AiClient.php` 한 곳에서만 한다. 타임아웃 10초, `X-Internal-Token` 헤더 필수.
- 세그먼트 규칙 -> 쿼리 변환은 `app/Services/Segments`에 두고 단위 테스트를 작성한다.
- 대량 작업(발송, 배치 예측)은 Job으로 분리하고 500건 단위로 청크 처리한다.
- 스케줄은 `routes/console.php`에 등록한다(예약 발송 매분, 이탈 예측 일 1회).

## 데이터와 권한

- `workspace_id`가 있는 모델은 Global Scope 또는 Policy로 워크스페이스 격리를 강제한다. 다른 워크스페이스 데이터가 조회되는 경로가 없어야 한다.
- 역할: `admin`(전체), `marketer`(캠페인/고객/세그먼트), `viewer`(읽기). Policy로 검사하고 Livewire 액션 안에서도 `authorize()`를 호출한다.
- 마이그레이션은 한 변경당 한 파일, 되돌릴 수 있게(`down`) 작성한다. 자주 조회하는 컬럼에 인덱스를 둔다.
- N+1 방지: 목록은 `with()`로 eager loading, 페이지네이션 필수.

## 추적 링크

- 오픈 픽셀 `/t/o/{token}.gif`, 클릭 `/t/c/{token}`은 인증이 없는 공개 라우트다.
- 토큰은 추측 불가능한 값(랜덤 40자)을 쓰고, 클릭 리디렉션 대상은 DB에 저장된 URL만 허용한다(오픈 리디렉션 금지).

## 프론트엔드 (Blade + Livewire)

디자인 토큰은 `../SPEC.md` 3장, 리퀴드 글래스 규칙은 3.1을 따른다.

- 파스텔 핑크 팔레트를 `resources/css/app.css`의 CSS 변수(`@theme`)로 정의하고, 컴포넌트에서는 토큰 이름으로만 참조한다. hex를 Blade에 직접 쓰지 않는다.
- 이모지 금지. 아이콘은 인라인 SVG(Blade 컴포넌트 `<x-icon name="..."/>`)만 사용한다.
- 유리 효과는 공용 Blade 컴포넌트(`resources/views/components/glass/*`: `card`, `button`, `input`, `switch`, `checkbox`, `modal` 등)와 `.glass`, `.glass-strong` 유틸 클래스에만 구현한다. 페이지에서는 유리 스타일을 직접 작성하지 않는다.
- 앱 배경은 파스텔 blob 그라데이션 레이어(고정, `-z-10`)로 만든다. 유리 뒤에 색이 있어야 효과가 보인다.
- 유리 표면은 `backdrop-filter: blur` + 반투명 흰색 + 1px 흰색 테두리 + 안쪽 상단 하이라이트 + 옅은 바깥 그림자 조합이다. `@supports not (backdrop-filter: blur(1px))` 대체 스타일을 반드시 둔다.
- blur 레이어를 중첩하지 않는다(사이드바, 최상위 카드 위주). 표와 폼 본문은 `.glass-strong`(불투명도 0.7 이상)을 쓴다.
- 버튼과 입력창은 알약형(`rounded-full`), 카드는 `rounded-3xl`.
- 상태 배지(draft/scheduled/sending/sent/failed)는 색 외에 텍스트 라벨을 항상 함께 표시한다.
- 차트는 Chart.js를 Alpine 컴포넌트로 감싸 사용한다. 색은 핑크 계열 + 보조색 1~2개, 범례와 툴팁을 제공한다.
- 모든 목록/차트 화면에 로딩(`wire:loading`), 빈 상태, 오류 상태를 구현한다. 폼 검증 오류는 필드별로 표시한다.
- 반응형으로 만든다(사이드바는 작은 화면에서 접힘). `prefers-reduced-motion`, `prefers-reduced-transparency`를 존중한다.
- 접근성: 입력에 라벨, 포커스 링 유지, 본문 색 대비 확보.

## 테스트

- Pest 사용. 기능 테스트는 인증, 워크스페이스 격리, 캠페인 발송 흐름, CSV 임포트 검증을 우선한다. Livewire 컴포넌트는 `Livewire::test()`로 검증한다.
- AI 서비스는 `Http::fake()`로 대체한다. 테스트가 외부 네트워크에 의존하면 안 된다.

## 명령

```
php artisan migrate --seed       # 데모 데이터 포함
php artisan queue:work           # 큐 워커 (docker compose의 queue 서비스가 실행)
php artisan schedule:work        # 스케줄러 (docker compose의 scheduler 서비스가 실행)
php artisan contacts:score-churn # 이탈 점수 갱신 잡 등록 (매일 03시 자동 실행)
php artisan campaigns:dispatch-scheduled  # 예약 시각이 지난 캠페인 발송 (매분 자동 실행)
npm run dev                      # Vite 개발 서버 (로컬 Node)
npm run build                    # 프로덕션 에셋 빌드
./vendor/bin/pest                # 테스트
./vendor/bin/pint                # 코드 스타일
```

## 환경변수 (.env.example로만 공유)

`DB_*`, `REDIS_*`, `MAIL_*`(Mailpit), `AI_SERVICE_URL`, `AI_INTERNAL_TOKEN`, `APP_TIMEZONE`(Asia/Seoul)

## 주의

- `docker-compose.yml`에서 `env_file`로 `.env`를 주입하지 않는다. 컨테이너 환경변수가 `phpunit.xml`의 sqlite 설정을 덮어써 테스트가 개발 DB를 초기화한다.
- 컨테이너 안에서 `artisan`을 실행할 때는 작업 디렉터리를 `/var/www/html`로 지정한다(`-w /var/www/html`). 기본 작업 디렉터리는 `public`이다.
- 새 Pest 헬퍼 함수는 파일 간 이름이 겹치면 치명적 오류가 나므로 파일별 접두사를 붙인다.
