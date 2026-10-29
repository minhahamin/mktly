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
| `GEMINI_API_KEY` | (선택) 무료 Gemini 모델용. 먼저 시도한다 |
| `GEMINI_MODEL` | (선택) 기본 `gemini-2.5-flash` |
| `OPENAI_API_KEY` | (선택) 유료 OpenAI용. Gemini가 한도 초과나 오류일 때 넘어간다 |
| `OPENAI_MODEL` | (선택) 기본 `gpt-4o-mini` |

두 키가 모두 없으면 카피 생성은 샘플 결과로 동작합니다. 유료 키를 넣을 때는 `mktly-web`의 `MKTLY_AI_OPENAI_DAILY_LIMIT`로 하루 호출 상한을 함께 설정하세요.

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
| `MKTLY_DEMO_LOGIN` | `true`이면 로그인 화면에 "데모 계정으로 시작하기" 버튼이 나타납니다(읽기 전용 데모 계정이 있을 때만). 운영 환경에서는 데모 계정 역할이 viewer가 아니면 버튼이 숨겨집니다. |
| `MKTLY_SIGNUP_ENABLED` | 공개 회원가입 켜기/끄기 (기본 `true`) |
| `MKTLY_AI_COPY_DAILY_LIMIT` | 하루에 실제 모델로 만들 수 있는 AI 카피 생성 횟수(전체 합계). **유료 API 키를 넣을 때 반드시 설정**하세요. 샘플 모드 결과는 세지 않습니다. |

이미지에 이미 설정된 값(변경 불필요): `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `LOG_CHANNEL=stderr`.

변수는 대시보드에서 넣거나 CLI로 넣을 수 있습니다.

```bash
railway variable set --service mktly-ai "AI_INTERNAL_TOKEN=<토큰>" "PORT=8001"
railway variable set --service mktly-web "APP_NAME=Mktly" "APP_URL=https://..." ...
```

### 메일 발송 (Brevo)

`MAIL_*`을 설정하지 않으면 `MAIL_MAILER=log`로 동작해서 캠페인이 "발송 완료"로 기록되지만 메일은 로그에만 남습니다. 실제로 보내려면 [Brevo](https://www.brevo.com)의 SMTP 릴레이를 씁니다.

**Brevo에서 먼저 할 일**

1. 계정을 만들고 **보내는 사람(Sender) 이메일 또는 도메인을 인증**합니다. 인증되지 않은 주소로는 발송이 거부됩니다.
2. 대시보드의 **SMTP & API** 메뉴 > **SMTP** 탭에서 SMTP 로그인을 확인하고 **SMTP 키**를 만듭니다. API 키가 아니라 *SMTP 키*여야 합니다.

**`mktly-web` 변수**

| 변수 | 값 |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp-relay.brevo.com` |
| `MAIL_PORT` | `587` (막혀 있으면 `2525`) |
| `MAIL_SCHEME` | 비워 두거나 `smtp` (587은 STARTTLS). 465 포트를 쓸 때만 `smtps` |
| `MAIL_USERNAME` | Brevo의 SMTP 로그인 |
| `MAIL_PASSWORD` | 위에서 만든 SMTP 키 |
| `MAIL_FROM_ADDRESS` | Brevo에서 인증한 보내는 사람 주소 |
| `MAIL_FROM_NAME` | 보내는 사람 이름 (예: `Mktly`) |
| `MKTLY_MAIL_DAILY_LIMIT` | 요금제의 일일 발송 한도. 무료 요금제는 하루 **300통**입니다(최신 값은 Brevo 도움말에서 확인). |

`MKTLY_MAIL_DAILY_LIMIT`을 설정하면 오늘 남은 한도보다 대상이 많은 캠페인은 **발송 시작 전에** 막히고, 작성 화면에 남은 한도가 표시됩니다. 설정하지 않으면 Brevo 쪽에서 한도를 넘는 순간 발송이 실패합니다.

**설정 확인**

배포 후 테스트 메일을 한 통 보내 봅니다. 실패하면 원인 한 줄과 점검 안내가 나옵니다.

```bash
railway ssh --service mktly-web php artisan mktly:mail-test 내이메일@example.com
```

> **주의**: 시드 데이터의 고객 이메일은 `example.test` 같은 가짜 주소입니다. 데모 데이터가 있는 워크스페이스에서 메일 발송을 허용하면 존재하지 않는 주소로 보내져 반송(bounce)이 쌓이고 Brevo 계정 평판이 나빠질 수 있습니다. 그래서 공개 데모 계정은 읽기 전용(viewer)으로 만드는 것을 권장합니다. 실제 워크스페이스에는 본인 주소나 실제 고객 주소만 넣으세요.

## 4. 배포

저장소 루트에서 실행합니다.

먼저 `mktly-web`이 운영용 Dockerfile을 쓰도록 지정합니다. (CLI 업로드에서는 `railway.json`의 `dockerfilePath`가 적용되지 않아, 지정하지 않으면 개발용 `Dockerfile`로 빌드됩니다.)

```bash
railway variable set --service mktly-web --skip-deploys "RAILWAY_DOCKERFILE_PATH=Dockerfile.prod"
```

```bash
railway domain --service mktly-web          # 공개 주소 생성 후 APP_URL에 넣습니다 (AI 서비스에는 만들지 않습니다)
railway up ai      --path-as-root --service mktly-ai  --ci
railway up backend --path-as-root --service mktly-web --ci
```

`mktly-web`은 시작할 때 자동으로 마이그레이션을 실행하고, 사용자가 한 명도 없고 `MKTLY_ADMIN_*`가 있으면 첫 관리자를 만듭니다(여러 번 실행해도 안전합니다).

헬스체크는 `/up`(웹), `/health`(AI)입니다. 웹의 부팅 로그(`railway logs --service mktly-web`)에서 `AI 서비스 연결 정상`이 보이면 사설 네트워크와 내부 토큰이 맞게 설정된 것입니다. 토큰이 다르면 `AI_INTERNAL_TOKEN`을 맞추라는 메시지가 나옵니다.

## 5. 배포 후 확인

1. 주소로 접속해 `MKTLY_ADMIN_EMAIL` 계정으로 로그인합니다.
2. 고객 화면에서 "이탈 점수 갱신"을 눌러 AI 서비스 연결을 확인합니다(고객이 없으면 먼저 CSV로 추가).
3. 캠페인 작성 화면의 "AI로 작성"으로 카피 생성을 확인합니다.

## 6. 공개 데모 데이터 (선택)

배포 DB는 비어 있습니다. 포트폴리오 데모용 샘플 데이터는 **별도 워크스페이스**에 만들어서 실제 데이터와 섞이지 않게 합니다. 서버에 접속하지 않아도 되도록 환경변수로 켭니다. 부팅할 때 데모 계정이 없을 때만 한 번 만들고, 이후 재시작에서는 건너뜁니다.

```bash
railway variable set --service mktly-web   "MKTLY_SEED_DEMO=true" "MKTLY_DEMO_EMAIL=demo@내도메인" "MKTLY_DEMO_PASSWORD=<공개해도 되는 데모 비밀번호>"
```

변수를 바꾼 뒤 재배포하면 적용되고, 부팅 로그에 `데모 데이터를 만들었습니다`가 남습니다. 데모 계정 역할은 `MKTLY_DEMO_ROLE`(기본 `viewer`)로 바꿀 수 있습니다.

서버에서 직접 실행하려면 `railway ssh`를 쓰는데, 먼저 SSH 키를 Railway 계정에 등록해야 합니다(`railway ssh keys add`). 등록하면 `railway ssh --service mktly-web php artisan mktly:demo --force`로도 만들 수 있습니다.

- 데모 계정은 기본적으로 **읽기 전용(viewer)** 입니다. 마케터 이상으로 만들면 방문자가 메일 발송과 AI 호출을 할 수 있어 SMTP와 AI 비용이 발생할 수 있습니다.
- 데모 비밀번호는 공개용이므로 관리자 비밀번호와 다르게 정하세요.

## 7. 회원가입 운영

공개 회원가입을 켜 두면 누구나 가입해 자기 워크스페이스를 만들 수 있습니다. 스팸 남용을 막기 위해 **새 워크스페이스는 이메일 실제 발송이 꺼진 체험 모드**로 시작합니다. 고객, 세그먼트, 캠페인 작성, SMS와 푸시(모의), AI 기능은 그대로 쓸 수 있습니다.

신뢰하는 사용자에게 실제 발송을 열어 주려면 서버에서 명령을 실행합니다(`railway ssh` 키 등록이 필요합니다).

```bash
railway ssh --service mktly-web php artisan mktly:workspace-sending <워크스페이스 id> on
```

가입을 받고 싶지 않다면 `MKTLY_SIGNUP_ENABLED=false`로 끕니다. 이메일 인증과 비밀번호 찾기는 아직 없습니다.

## 8. 운영 메모

- **재배포**: 위 `railway up` 명령을 다시 실행합니다.
- **백업**: Railway MySQL 백업 기능을 확인해 두세요. 고객 데이터가 들어갑니다.
- **메일 한도**: Brevo 무료 요금제는 하루 발송 한도가 있습니다. `MKTLY_MAIL_DAILY_LIMIT`로 미리 막아 두세요.
- **AI 비용**: 카피 생성은 사용자당 분당 10회로 제한됩니다. 하루 상한과 예산 상한은 아직 없으므로 유료 키를 넣을 때는 AI 공급자 쪽에서 월 사용 한도를 함께 설정하세요.
- **관리자 추가**: 설정 화면의 "멤버 추가"를 쓰거나 `railway ssh --service mktly-web php artisan mktly:create-admin --email=...` 을 사용합니다(비밀번호는 `MKTLY_ADMIN_PASSWORD` 환경변수로 전달).
