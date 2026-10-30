# Mktly 아키텍처

요구사항은 [SPEC.md](../SPEC.md)가 기준입니다. 이 문서는 구성 요소가 어떻게 연결되고 주요 기능이 어떤 순서로 동작하는지를 그림으로 정리합니다.

## 1. 시스템 구성 (로컬, Docker Compose)

```mermaid
flowchart LR
    browser["브라우저"]

    subgraph laravel["Laravel 13 (같은 이미지)"]
        app["app :8100<br/>Blade + Livewire"]
        queue["queue 워커<br/>발송, 임베딩, 이탈 점수"]
        scheduler["scheduler<br/>예약 발송, 배치"]
    end

    ai["FastAPI ai :8101<br/>LLM, 임베딩, ML, 이미지 렌더"]
    mysql[("MySQL 8")]
    redis[("Redis<br/>큐, 캐시, 세션")]
    mailpit["Mailpit<br/>개발용 SMTP"]
    llm["외부 AI API (선택)<br/>Gemini, OpenAI"]

    browser -- "HTML, Livewire 요청" --> app
    browser -- "오픈 픽셀, 클릭 리디렉션" --> app
    app --> mysql
    app --> redis
    queue --> redis
    queue --> mysql
    scheduler -- "잡 등록" --> redis
    queue -- "SMTP" --> mailpit
    app -- "HTTP + X-Internal-Token" --> ai
    queue -- "HTTP + X-Internal-Token" --> ai
    ai -. "키가 있을 때만" .-> llm
```

- 브라우저는 Laravel만 호출합니다. AI 서비스는 외부에 열지 않고, Laravel이 내부 토큰을 붙여 중계합니다.
- AI 서비스는 DB에 접근하지 않습니다. 필요한 데이터(고객 특징, 참고 후보 벡터)는 Laravel이 요청에 담아 보냅니다.
- 키가 없으면 AI 서비스는 Mock 결과, 해시 벡터, 템플릿 배경으로 동작해서 외부 호출 없이 전체 기능이 돌아갑니다.

## 2. 배포 구성 (Railway)

```mermaid
flowchart LR
    user["사용자"]

    subgraph railway["Railway"]
        subgraph web["mktly-web (FrankenPHP 단일 컨테이너)"]
            webproc["웹"]
            worker["queue:work"]
            sched["schedule:work"]
        end
        aisvc["mktly-ai (FastAPI)"]
        db[("MySQL<br/>데이터 + 큐, 세션, 캐시")]
    end

    smtp["SMTP (Brevo 등)"]
    llm["Gemini, OpenAI"]

    user -- "HTTPS" --> webproc
    webproc --> db
    worker --> db
    sched --> db
    webproc -- "내부 네트워크" --> aisvc
    worker -- "내부 네트워크" --> aisvc
    worker --> smtp
    aisvc -.-> llm
```

- 웹, 큐 워커, 스케줄러를 한 컨테이너에 묶고 큐, 세션, 캐시를 MySQL에 두어 서비스를 3개(웹, AI, MySQL)로 줄였습니다.
- 자세한 절차는 [DEPLOY.md](../DEPLOY.md)를 보세요.

## 3. Laravel 내부 계층

```mermaid
flowchart TB
    routes["routes/web.php"]
    livewire["Livewire 컴포넌트<br/>검증, 인가(authorize)"]
    controllers["컨트롤러<br/>추적, 이미지 다운로드"]
    services["Services<br/>Campaigns, Segments, Contacts, Brand, Images, Dashboard"]
    aiclient["Services/Ai/AiClient<br/>AI 서비스 호출의 단일 창구"]
    usage["AiUsage<br/>일일 상한"]
    jobs["Jobs<br/>SendCampaignChunk, EmbedCampaign,<br/>EmbedBrandDocument, ScoreWorkspaceChurn"]
    models["Eloquent 모델<br/>워크스페이스 글로벌 스코프 + Policy"]

    routes --> livewire
    routes --> controllers
    livewire --> services
    controllers --> models
    services --> models
    services --> jobs
    services --> aiclient
    services --> usage
    jobs --> aiclient
    jobs --> models
```

- 화면용 JSON API가 없습니다. 목록, 폼, 대시보드 상호작용은 Livewire 액션으로 처리합니다.
- 워크스페이스 소유 모델은 글로벌 스코프로 걸러지고, 역할(admin, marketer, viewer)은 Policy로 검사합니다.

## 4. 주요 흐름

### 4.1 캠페인 발송과 추적

```mermaid
sequenceDiagram
    actor M as 마케터
    participant L as Laravel
    participant S as scheduler
    participant Q as queue 워커
    participant DB as MySQL
    participant Mail as SMTP
    actor R as 수신자

    alt 즉시 발송
        M->>L: 발송 버튼
    else 예약 발송
        S->>L: campaigns:dispatch-scheduled (매분)
    end
    L->>DB: 상태 선점(scheduled/draft -> sending)
    L->>DB: 세그먼트 대상으로 수신자 메시지, 추적 링크 생성
    L->>Q: SendCampaignChunk (500건 단위)
    Q->>Q: 변수 치환, 링크를 추적 URL로 변환, 오픈 픽셀 삽입
    Q->>Mail: 메일 발송
    Q->>DB: 메시지별 sent/failed 기록
    Q->>DB: 마지막 묶음이면 캠페인 sent 처리
    Q->>Q: EmbedCampaign 등록 (AI 카피 참고 자료용)
    R->>L: GET /t/o/{token}.gif (오픈)
    R->>L: GET /t/c/{token} (클릭)
    L->>DB: events 기록
    L-->>R: 저장된 URL로 리디렉션
```

### 4.2 AI 카피 생성 (참고 자료 RAG 포함)

```mermaid
sequenceDiagram
    actor M as 마케터
    participant L as Laravel
    participant A as FastAPI
    participant G as Gemini / OpenAI

    M->>L: 제품, 타겟, 톤 입력
    L->>L: 일일 상한 확인 (AiUsage)
    L->>L: 후보 수집: 고성과 캠페인 최대 100개, 브랜드 문서 조각 최대 200개 (벡터 포함, 같은 워크스페이스만)
    L->>A: POST /copy/generate (후보 포함)
    A->>G: 질의 임베딩 (상한 도달 시 해시 벡터)
    A->>A: 같은 공급자 벡터끼리 코사인 유사도로 캠페인 3개, 브랜드 조각 4개 선택
    A->>G: 생성 요청 (Gemini -> OpenAI 순서, 실패 시 다음 공급자)
    G-->>A: 제목 후보, 본문
    A-->>L: 결과 + references + provider
    L->>L: ai_generations 기록, references를 보낸 후보 안에서만 표시
    L-->>M: 제목 후보 5개, 본문, 참고한 자료
```

키가 없으면 FastAPI가 고정 샘플(Mock)을 돌려주고 화면에 "샘플 결과"가 표시됩니다.

### 4.3 카드뉴스 / 대표 이미지

```mermaid
sequenceDiagram
    actor M as 마케터
    participant L as Laravel
    participant A as FastAPI
    participant G as Gemini

    M->>L: 주제, 종류, 비율, 장수
    L->>A: POST /cardnews/draft (참고 후보 포함)
    A-->>L: 장별 제목(20자), 본문(60자)
    M->>L: 문구 수정 후 이미지 만들기 (AI 배경 선택 가능)
    L->>L: AI 배경 상한 확인
    L->>A: POST /cardnews/render (seed = 세트 uuid)
    opt AI 배경 선택 + 상한 남음 + 키 있음
        A->>G: 글자 없는 배경 삽화 1장
        G-->>A: 이미지 (실패, 차단이면 템플릿 배경)
    end
    A->>A: Pillow로 배경, 유리 카드, Pretendard 글자 렌더
    A-->>L: PNG 배열 + background_provider
    L->>L: PNG 검증 후 generated_images에 저장
    M->>L: 목록에서 미리보기, PNG, ZIP 다운로드 (인증된 라우트)
```

### 4.4 이탈 예측과 세그먼트 추천

```mermaid
sequenceDiagram
    participant S as scheduler
    participant Q as queue 워커
    participant L as Laravel
    participant A as FastAPI
    participant DB as MySQL
    actor M as 마케터

    S->>Q: contacts:score-churn (매일 03:00, 워크스페이스별 잡)
    Q->>DB: 고객 특징 계산 (구매액, 가입 기간, 메일 반응, 개인정보 제외)
    Q->>A: POST /predict/churn
    A-->>Q: 고객별 이탈 확률, 모델 성능(AUC, ROC)
    Q->>DB: contacts.churn_score 대량 갱신
    M->>L: AI 세그먼트 추천 화면
    L->>A: POST /cluster (K-means)
    A-->>L: 클러스터 라벨과 그룹 요약
    M->>L: 그룹을 세그먼트로 저장
```

### 4.5 백그라운드 작업 정리

| 작업 | 실행 시점 | 하는 일 |
|---|---|---|
| `campaigns:dispatch-scheduled` | 매분 | 예약 시각이 지난 캠페인 발송 시작 |
| `SendCampaignChunk` | 발송 시작 시 | 500건 단위 메일 발송, 끝나면 캠페인 마감 |
| `EmbedCampaign` | 캠페인 발송 완료 시 | 제목, 본문 임베딩 저장 (수신자 30명 이상) |
| `EmbedBrandDocument` | 브랜드 문서 등록 시 | 조각별 임베딩 저장 |
| `mktly:embeddings:rebuild` | 매시간 | 빠진 임베딩 채우기 |
| `contacts:score-churn` | 매일 03:00 | 워크스페이스별 이탈 점수 갱신 |
