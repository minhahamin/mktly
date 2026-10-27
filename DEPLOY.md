# Railway 배포 가이드

비용을 줄이기 위해 **서비스 3개**로 구성합니다.

| 서비스 | 내용 | 공개 |
|---|---|---|
| `mktly-web` | Laravel 앱. 웹 서버, **큐 워커, 스케줄러를 한 컨테이너**에서 실행 (`backend/Dockerfile.prod`) | 공개 도메인 |
| `mktly-ai` | FastAPI AI 서비스 (`ai/Dockerfile`) | **비공개** (사설 네트워크만) |
| `MySQL` | 데이터베이스. 큐, 세션, 캐시도 DB에 저장하므로 Redis와 Mailpit이 필요 없습니다 | 비공개 |

로컬 개발용 `docker-compose.yml`과는 별개입니다. 로컬은 Redis와 Mailpit을 계속 씁니다.

> 요금은 사용량 기반이고 자주 바뀝니다. 만들기 전에 Railway 요금 페이지를 확인하고, 대시보드에서 **사용량 한도(Usage limit)** 를 걸어 두세요.

## 1. 준비

```bash
railway login          # 브라우저로 로그인 (토큰이 만료되었다면 다시)
railway init --name mktly
```

운영용 이미지는 로컬에서 먼저 검증했습니다(마이그레이션, 첫 관리자 생성, 큐 발송, 헬스체크).

## 2. 서비스 만들기

```bash
railway add --database mysql                 # 서비스 이름: MySQL
railway add --service mktly-ai
railway add --service mktly-web
```

## 3. 환경변수

비밀값은 직접 만든 값으로 정합니다. `<...>` 자리를 채우세요. **채팅이나 저장소에 비밀번호와 키를 붙여 넣지 마세요.**

### `mktly-ai`

| 변수 | 값 |
|---|---|
| `AI_INTERNAL_TOKEN` | 임의의 긴 문자열 (`mktly-web`과 같은 값) |
| `PORT` | `8001` |
| `ANTHROPIC_API_KEY` | (선택) 없으면 카피 생성은 샘플 결과로 동작 |
| `ANTHROPIC_MODEL` | (선택) 기본 `claude-sonnet-5-5` |

### `mktly-web`

| 변수 | 값 |
|---|---|
| `APP_NAME` | `Mktly` |
| `APP_KEY` | `php artisan key:generate --show` 로 만든 값 (`base64:...`) |
| `APP_URL` | 배포 주소 (예: `https://mktly-web-production.up.railway.app`) |
| `APP_LOCALE` / `APP_TIMEZONE` | `ko` / `Asia/Seoul` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` | `${{MySQL.MYSQLHOST}}` / `${{MySQL.MYSQLPORT}}` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `${{MySQL.MYSQLDATABASE}}` / `${{MySQL.MYSQLUSER}}` / `${{MySQL.MYSQLPASSWORD}}` |
| `AI_SERVICE_URL` | `http://mktly-ai.railway.internal:8001` |
| `AI_INTERNAL_TOKEN` | `mktly-ai`와 같은 값 |
| `MKTLY_ADMIN_EMAIL` | 첫 관리자 이메일 |
| `MKTLY_ADMIN_PASSWORD` | 첫 관리자 비밀번호 (**12자 이상**) |
| `MKTLY_ADMIN_NAME` / `MKTLY_WORKSPACE_NAME` | (선택) 관리자 이름 / 워크스페이스 이름 |

이미지에 이미 설정된 값(변경 불필요): `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `LOG_CHANNEL=stderr`.

변수는 대시보드에서 넣거나 CLI로 넣을 수 있습니다.

```bash
railway variable set --service mktly-ai "AI_INTERNAL_TOKEN=<토큰>" "PORT=8001"
railway variable set --service mktly-web "APP_NAME=Mktly" "APP_URL=https://..." ...
```

### 메일 발송 (선택)

`MAIL_*`을 설정하지 않으면 `MAIL_MAILER=log`로 동작해서 캠페인이 "발송 완료"로 기록되지만 메일은 로그에만 남습니다. 실제로 보내려면 SMTP 제공업체(Resend, Brevo 등)를 정하고 아래를 설정하세요.

```
MAIL_MAILER=smtp
MAIL_HOST=...  MAIL_PORT=587  MAIL_USERNAME=...  MAIL_PASSWORD=...  MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=no-reply@내도메인
```

## 4. 배포

저장소 루트에서 실행합니다.

```bash
railway up ai      --path-as-root --service mktly-ai  --ci
railway up backend --path-as-root --service mktly-web --ci
railway domain --service mktly-web          # 공개 주소 생성 (AI 서비스에는 만들지 않습니다)
```

`mktly-web`은 시작할 때 자동으로 마이그레이션을 실행하고, 사용자가 한 명도 없고 `MKTLY_ADMIN_*`가 있으면 첫 관리자를 만듭니다(여러 번 실행해도 안전합니다).

헬스체크는 `/up`(웹), `/health`(AI)입니다.

## 5. 배포 후 확인

1. 주소로 접속해 `MKTLY_ADMIN_EMAIL` 계정으로 로그인합니다.
2. 고객 화면에서 "이탈 점수 갱신"을 눌러 AI 서비스 연결을 확인합니다(고객이 없으면 먼저 CSV로 추가).
3. 캠페인 작성 화면의 "AI로 작성"으로 카피 생성을 확인합니다.

## 6. 공개 데모 데이터 (선택)

배포 DB는 비어 있습니다. 포트폴리오 데모용 샘플 데이터는 **별도 워크스페이스**에 만들어서 실제 데이터와 섞이지 않게 합니다.

```bash
railway variable set --service mktly-web "MKTLY_DEMO_PASSWORD=<공개해도 되는 데모 비밀번호>"
railway ssh --service mktly-web php artisan mktly:demo --force
```

- 데모 계정은 기본적으로 **읽기 전용(viewer)** 입니다. 마케터 이상으로 만들면 방문자가 메일 발송과 AI 호출을 할 수 있어 SMTP와 AI 비용이 발생할 수 있습니다.
- 데모 비밀번호는 공개용이므로 관리자 비밀번호와 다르게 정하세요.

## 7. 운영 메모

- **재배포**: 위 `railway up` 명령을 다시 실행합니다.
- **백업**: Railway MySQL 백업 기능을 확인해 두세요. 고객 데이터가 들어갑니다.
- **AI 비용**: 카피 생성은 사용자당 분당 10회로 제한됩니다. 하루 상한과 예산 상한은 아직 없으므로 유료 키를 넣을 때는 AI 공급자 쪽에서 월 사용 한도를 함께 설정하세요.
- **관리자 추가**: 설정 화면의 "멤버 추가"를 쓰거나 `railway ssh --service mktly-web php artisan mktly:create-admin --email=...` 을 사용합니다(비밀번호는 `MKTLY_ADMIN_PASSWORD` 환경변수로 전달).
