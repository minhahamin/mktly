# Mktly

마케팅 자동화 + AI 인사이트 플랫폼 포트폴리오. 전체 요구사항은 `SPEC.md`가 기준이다. 작업 전에 읽고, 범위가 바뀌면 SPEC.md부터 수정한다.

## 구조

```
Mktly/
  SPEC.md
  backend/    Laravel 13 + Blade + Livewire (PHP 8.4, MySQL, Redis)  -> backend/CLAUDE.md (프론트엔드 규칙 포함)
  ai/         FastAPI (Python 3.12) AI 서비스                         -> ai/CLAUDE.md
  docker-compose.yml
```

## 공통 규칙

- 화면, 서버 응답, 주석, 문서, 프롬프트 어디에도 이모지를 쓰지 않는다. 아이콘은 SVG만 쓴다.
- 디자인은 파스텔 핑크 팔레트 + 리퀴드 글래스(반투명 유리) 스타일(SPEC.md 3장, 3.1)을 따른다.
- 비밀값(API 키 등)은 `.env`로만 다루고 출력하거나 커밋하지 않는다. `.env.example`만 커밋한다.
- 작업은 SPEC.md 10장의 마일스톤 순서로 진행하고, 기능 단위로 커밋 후 푸시한다.
- 커밋 메시지와 PR에 Claude 귀속 문구(Co-Authored-By 등)를 넣지 않는다.
- 브라우저는 Laravel(Blade + Livewire)만 호출한다. AI 서비스는 Laravel을 통해서만 접근한다.
- 외부 유료 서비스에 의존하지 않는다. 메일은 Mailpit, AI 키가 없으면 Mock 모드로 동작해야 한다.
- 요청받지 않은 기능을 추가하지 않는다. MVP 제외 항목은 만들지 않는다.

## 개발 명령 (M0 이후 유효)

```
docker compose up -d        # 전체 기동
docker compose logs -f app  # Laravel 로그
```
