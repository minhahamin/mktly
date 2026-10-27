# Backend (Laravel 13)

JSON API 서버. 요구사항과 API 명세는 `../SPEC.md` 4~8장을 따른다.

## 스택

PHP 8.4, Laravel 13, MySQL 8, Redis(Queue/Cache), Sanctum, Pest.

## 구조 규칙

- 라우트는 `routes/api.php`에서 `/api/v1` 접두사로 관리한다.
- 컨트롤러는 얇게 유지한다: 검증은 FormRequest, 응답 형태는 API Resource, 비즈니스 로직은 `app/Services`.
- 외부 호출(AI 서비스)은 `app/Services/Ai/AiClient.php` 한 곳에서만 한다. 타임아웃 10초, `X-Internal-Token` 헤더 필수.
- 세그먼트 규칙 -> 쿼리 변환은 `app/Services/Segments`에 두고 단위 테스트를 작성한다.
- 대량 작업(발송, 배치 예측)은 Job으로 분리하고 500건 단위로 청크 처리한다.
- 스케줄은 `routes/console.php`에 등록한다(예약 발송 매분, 이탈 예측 일 1회).

## 데이터와 권한

- `workspace_id`가 있는 모델은 Global Scope 또는 Policy로 워크스페이스 격리를 강제한다. 다른 워크스페이스 데이터가 조회되는 경로가 없어야 한다.
- 역할: `admin`(전체), `marketer`(캠페인/고객/세그먼트), `viewer`(읽기). Policy로 검사한다.
- 마이그레이션은 한 변경당 한 파일, 되돌릴 수 있게(`down`) 작성한다. 자주 조회하는 컬럼에 인덱스를 둔다.
- N+1 방지: 목록 API는 `with()`로 eager loading, 페이지네이션 필수.

## 추적 링크

- 오픈 픽셀 `/t/o/{token}.gif`, 클릭 `/t/c/{token}`은 인증이 없는 공개 라우트다.
- 토큰은 추측 불가능한 값(랜덤 40자)을 쓰고, 클릭 리디렉션 대상은 DB에 저장된 URL만 허용한다(오픈 리디렉션 금지).

## 테스트

- Pest 사용. 기능 테스트는 인증, 워크스페이스 격리, 캠페인 발송 흐름, CSV 임포트 검증을 우선한다.
- AI 서비스는 `Http::fake()`로 대체한다. 테스트가 외부 네트워크에 의존하면 안 된다.

## 명령

```
php artisan migrate --seed       # 데모 데이터 포함
php artisan queue:work           # 큐 워커
php artisan schedule:work        # 스케줄러(로컬)
./vendor/bin/pest                # 테스트
./vendor/bin/pint                # 코드 스타일
```

## 환경변수 (.env.example로만 공유)

`DB_*`, `REDIS_*`, `MAIL_*`(Mailpit), `AI_SERVICE_URL`, `AI_INTERNAL_TOKEN`
