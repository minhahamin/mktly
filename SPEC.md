# Mktly - 마케팅 자동화 + AI 인사이트 플랫폼 (SPEC)

중소 비즈니스가 고객 세그먼트를 만들고, 캠페인을 발송하고, 성과를 분석하고, AI로 카피 작성과 이탈 예측을 할 수 있는 마케팅 플랫폼. 취업 포트폴리오용 프로젝트.

## 1. 목표

- PHP(Laravel) 비즈니스 서버와 Python(FastAPI) AI 서버를 HTTP로 연동한 멀티 서비스 구조를 보여준다.
- 큐, 스케줄러, 권한, 추적 링크 등 실무형 백엔드 요소를 포함한다.
- 외부 유료 서비스 없이 로컬(Docker)에서 전체가 재현된다. 메일 발송은 Mailpit으로 대체한다.

## 2. 기술 스택

| 영역 | 선택 |
|---|---|
| Backend | PHP 8.4, Laravel 13, Sanctum(토큰 인증), Queue(Redis), Scheduler |
| DB | MySQL 8 |
| Cache/Queue | Redis |
| AI 서비스 | Python 3.12, FastAPI, scikit-learn, pandas, Anthropic SDK |
| Frontend | Next.js (App Router), TypeScript, Tailwind CSS v4, Recharts |
| 인프라 | Docker Compose (web, api, ai, mysql, redis, mailpit) |
| 테스트 | Pest(PHP), pytest(Python), Playwright(E2E, 선택) |

프론트엔드는 Next.js로 분리하고 Laravel은 JSON API만 제공한다. (Blade 렌더링으로 바꾸려면 이 항목과 `frontend/CLAUDE.md`만 수정)

## 3. 디자인 방향

- 파스텔 핑크 중심의 부드럽고 깔끔한 SaaS 대시보드.
- 이모지 금지, 아이콘은 SVG(예: lucide)만 사용.
- 팔레트(초안, `frontend`의 CSS 변수로 관리)

| 토큰 | 값 | 용도 |
|---|---|---|
| `--pink-50` | #FFF5F8 | 페이지 배경 |
| `--pink-100` | #FFE4EC | 카드 배경, hover |
| `--pink-300` | #F9B4C8 | 보조 강조, 차트 보조색 |
| `--pink-500` | #F07AA0 | 주요 버튼, 활성 상태 |
| `--pink-700` | #C2476F | 버튼 hover, 링크 |
| `--ink-900` | #2B2230 | 본문 텍스트 |
| `--ink-500` | #7A6F80 | 보조 텍스트 |
| `--line` | #F0DCE4 | 테두리 |

- 상태색(성공/경고/오류)은 핑크와 구분되도록 채도 낮은 초록/앰버/레드 사용.
- 다크 모드는 MVP 범위 밖.

### 3.1 리퀴드 글래스 스타일

레퍼런스: 반투명 유리 질감의 "Liquid glass UI kit" (밝은 회백색 배경 위에 투명한 버튼, 입력창, 토글, 체크박스, 말풍선, 폴더형 카드).

- **배경**: 단색이 아니라 `--pink-50` 바탕에 라벤더, 피치, 민트 계열의 흐릿한 큰 원형 그라데이션(blob)을 깔아서 유리 뒤에 비칠 색을 만든다. 유리 효과는 뒤에 색이 있어야 보인다.
- **유리 표면**: 반투명 흰색 배경 `rgba(255,255,255,0.35~0.55)` + `backdrop-filter: blur(16~24px) saturate(140%)`.
- **테두리와 빛**: 1px 반투명 흰색 테두리, 안쪽 상단 하이라이트(`inset 0 1px 0 rgba(255,255,255,.8)`), 아래쪽 안쪽 그림자, 바깥은 옅고 넓은 그림자.
- **형태**: 버튼과 입력창은 완전한 알약형(`rounded-full`), 카드는 `rounded-3xl`.
- **파스텔 글로우**: 주요 버튼은 핑크 틴트 + 같은 색 계열 글로우, 보조 버튼은 라벤더/민트/피치 틴트(레퍼런스의 파랑/초록/주황 버튼에 해당). 의미 구분용으로만 사용.
- **컴포넌트 세트**: 버튼(주요/보조/검색), 텍스트 필드(아이콘 포함), 드롭다운 제안 목록, 체크박스, 스위치(토글), 원형 아이콘 버튼, 말풍선(툴팁/AI 응답), 폴더형 카드.
- **가독성 규칙**: 유리 위 텍스트는 `--ink-900`, 본문 대비 4.5:1 이상 유지. 텍스트가 많은 영역(표, 폼 본문)은 불투명도를 높인 유리(0.7 이상)를 쓴다.
- **성능/호환**: `backdrop-filter` 미지원 브라우저는 불투명 흰색 배경으로 대체(`@supports`). 한 화면에 blur 레이어는 중첩하지 않고 최상위 카드와 사이드바 위주로 제한한다. `prefers-reduced-transparency` 및 `prefers-reduced-motion`을 존중한다.
- 추가 팔레트(보조 틴트): 라벤더 `#E3E3FA`, 민트 `#DDF1E6`, 피치 `#FFE6D2`.

## 4. MVP 범위

### 4.1 캠페인 관리 (Laravel)
- 캠페인 CRUD: 이름, 채널(이메일 우선, SMS/푸시는 Mock), 제목, 본문, 대상 세그먼트
- 상태: `draft` -> `scheduled` -> `sending` -> `sent` / `failed`
- 즉시 발송 또는 예약 발송(Scheduler가 매분 검사)
- 발송은 Queue Job으로 청크 처리, 수신자별 `campaign_messages` 기록
- 본문 변수 치환: `{{name}}`, `{{email}}`
- 본문 내 링크는 추적 URL로 자동 치환 (오픈 픽셀 + 클릭 리디렉션)

### 4.2 고객 세그먼트
- 고객(contacts) CRUD, CSV 업로드(검증, 중복 이메일 건너뛰기)
- 세그먼트: 조건 기반(가입일, 마지막 구매일, 누적 구매액, 태그)
- 세그먼트 미리보기(대상 수)
- AI 클러스터링 결과를 세그먼트로 저장하는 기능(4.5와 연동)

### 4.3 성과 대시보드
- KPI: 발송 수, 오픈율, 클릭률, 전환 수
- 기간별 추이 라인 차트, 캠페인별 비교 바 차트
- 캠페인 상세: 퍼널(발송 -> 오픈 -> 클릭 -> 전환), 링크별 클릭 수
- 데모용 시더로 고객 1,000명과 이벤트 데이터 생성

### 4.4 AI 카피 생성 (Python)
- 입력: 제품 설명, 타겟, 톤, 채널
- 출력: 제목 후보 5개 + 본문 초안 (Anthropic API)
- 결과를 캠페인 편집 화면에 한 번에 적용
- API 키 없을 때는 고정 샘플을 반환하는 Mock 모드 지원

### 4.5 이탈/구매 가능성 예측 (Python)
- RFM(최근성, 빈도, 금액) 기반 특징 생성
- 로지스틱 회귀 또는 랜덤 포레스트로 이탈 확률 산출
- K-means로 고객 클러스터링 후 세그먼트 추천
- Laravel이 일 1회 배치로 `/predict/churn`을 호출해 `contacts.churn_score`에 저장

### 4.6 인증과 권한 (공통)
- 이메일/비밀번호 로그인, Sanctum 토큰
- 워크스페이스 단위 데이터 분리, 역할: `admin`, `marketer`, `viewer`

### MVP 제외 (후속)
A/B 테스트, 실제 SMS/푸시 발송, 감성 분석, 주간 리포트 자동 생성, 다크 모드, 다국어

## 5. 아키텍처

```
[Next.js :3000] --REST--> [Laravel API :8000] --MySQL--> [mysql :3306]
                                |   |
                                |   +--Queue/Cache--> [redis :6379]
                                |   +--SMTP--> [mailpit :1025 / UI :8025]
                                +--HTTP (내부 토큰)--> [FastAPI :8001]
```

- 브라우저는 Laravel API만 호출한다. AI 서비스는 외부에 노출하지 않고 Laravel이 중계한다.
- Laravel -> FastAPI 호출은 `X-Internal-Token` 헤더로 보호한다.
- AI 호출 실패 시 Laravel은 타임아웃(10초) 후 명확한 오류를 반환하고, 배치는 재시도한다.

## 6. 데이터 모델 (MySQL)

| 테이블 | 주요 컬럼 |
|---|---|
| `users` | id, name, email, password |
| `workspaces` | id, name |
| `workspace_user` | workspace_id, user_id, role |
| `contacts` | id, workspace_id, email, name, tags(json), joined_at, last_purchase_at, total_spent, churn_score, cluster_id |
| `segments` | id, workspace_id, name, rules(json) |
| `campaigns` | id, workspace_id, segment_id, name, channel, subject, body, status, scheduled_at, sent_at |
| `campaign_messages` | id, campaign_id, contact_id, status, sent_at, opened_at |
| `tracked_links` | id, campaign_id, token, target_url |
| `events` | id, campaign_id, contact_id, type(open/click/conversion), link_id, created_at |
| `ai_generations` | id, workspace_id, type, input(json), output(json), created_at |

모든 워크스페이스 소유 테이블은 `workspace_id` 인덱스를 가진다.

## 7. API 개요 (Laravel, prefix `/api/v1`)

| 메서드 | 경로 | 설명 |
|---|---|---|
| POST | `/auth/login`, `/auth/logout` | 인증 |
| GET/POST/PUT/DELETE | `/contacts`, `/contacts/import` | 고객 |
| GET/POST/PUT/DELETE | `/segments`, `/segments/{id}/preview` | 세그먼트 |
| GET/POST/PUT/DELETE | `/campaigns`, `/campaigns/{id}/send`, `/campaigns/{id}/schedule` | 캠페인 |
| GET | `/dashboard/summary`, `/dashboard/timeseries`, `/campaigns/{id}/funnel` | 성과 |
| POST | `/ai/copy` | 카피 생성(중계) |
| POST | `/ai/clusters` | 클러스터 추천(중계) |
| GET | `/t/o/{token}.gif`, `/t/c/{token}` | 오픈/클릭 추적 (인증 없음, 공개) |

## 8. AI 서비스 API (FastAPI)

| 메서드 | 경로 | 요청 -> 응답 |
|---|---|---|
| POST | `/copy/generate` | 제품/타겟/톤 -> 제목 후보, 본문 |
| POST | `/predict/churn` | 고객 RFM 배열 -> 고객별 확률 |
| POST | `/cluster` | 고객 RFM 배열, k -> 클러스터 라벨과 요약 |
| GET | `/health` | 상태 확인 |

## 9. 화면 목록 (Frontend)

1. 로그인
2. 대시보드(KPI, 추이, 캠페인 비교)
3. 캠페인 목록 / 생성 / 편집(AI 카피 패널 포함) / 상세(퍼널)
4. 고객 목록 / CSV 업로드
5. 세그먼트 목록 / 편집(규칙 빌더, 대상 수 미리보기, AI 추천)
6. 설정(워크스페이스, 멤버)

## 10. 마일스톤

| 단계 | 내용 | 완료 기준 |
|---|---|---|
| M0 | 모노레포 + Docker Compose 스캐폴딩 | 모든 컨테이너 기동, health 확인 |
| M1 | 인증, 워크스페이스, 고객 CRUD, CSV 업로드 | Pest 테스트 통과 |
| M2 | 세그먼트 규칙 엔진 | 규칙별 대상 수 일치 |
| M3 | 캠페인 발송(Queue), 추적 링크 | Mailpit에서 수신, 이벤트 기록 |
| M4 | 대시보드 집계와 화면 | 시드 데이터로 차트 표시 |
| M5 | AI 카피 생성 | Mock/실서비스 모두 동작 |
| M6 | 이탈 예측, 클러스터링, 배치 연동 | churn_score 저장, 세그먼트 추천 |
| M7 | 마무리: README, 스크린샷, 데모 시드 | 한 줄 명령으로 데모 기동 |

각 마일스톤은 기능 단위로 커밋하고 푸시한다.

## 11. 비기능 요구사항

- 모든 입력은 FormRequest(PHP) / Pydantic(Python)으로 검증
- 비밀값은 `.env`로만 관리, 저장소에 커밋 금지
- N+1 쿼리 방지(eager loading), 대시보드 집계는 인덱스와 캐시 활용
- 대량 발송은 청크(500건) 단위 Job으로 분할
- 로컬 개발 환경에서 비용이 발생하지 않을 것
