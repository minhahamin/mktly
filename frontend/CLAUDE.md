# Frontend (Next.js)

Mktly 대시보드 UI. 화면 목록은 `../SPEC.md` 9장, 디자인 토큰은 3장을 따른다.

## 스택

Next.js(App Router), TypeScript, Tailwind CSS v4, Recharts, lucide-react(SVG 아이콘).

## 디자인 규칙

- 파스텔 핑크 팔레트를 `globals.css`의 CSS 변수(`@theme`)로 정의하고, 컴포넌트에서는 토큰 이름으로만 참조한다. 색상 hex를 컴포넌트에 직접 쓰지 않는다.
- 이모지 금지. 아이콘은 lucide-react 등 SVG만 사용한다.
- 전체 스타일은 리퀴드 글래스(반투명 유리)다. 상세 규칙은 `../SPEC.md` 3.1을 따른다.
- 유리 효과는 `components/ui`의 공용 컴포넌트(`GlassCard`, `GlassButton`, `GlassInput`, `GlassSwitch`, `GlassCheckbox` 등)에만 구현하고, 페이지에서는 유리 스타일을 직접 작성하지 않는다. 공용 유틸 클래스(`.glass`, `.glass-strong`)는 `globals.css`에 정의한다.
- 앱 배경은 파스텔 blob 그라데이션 레이어(고정, `-z-10`)로 만든다. 유리 뒤에 색이 있어야 효과가 보인다.
- 유리 표면은 `backdrop-filter: blur` + 반투명 흰색 + 1px 흰색 테두리 + 안쪽 상단 하이라이트 + 옅은 바깥 그림자 조합이다. `@supports not (backdrop-filter: blur(1px))` 대체 스타일을 반드시 둔다.
- blur 레이어를 중첩하지 않는다(사이드바, 최상위 카드 위주). 표와 폼 본문처럼 글이 많은 영역은 `.glass-strong`(불투명도 0.7 이상)을 쓴다.
- 버튼과 입력창은 알약형(`rounded-full`), 카드는 `rounded-3xl`. 과한 그라데이션은 피한다.
- `prefers-reduced-motion`, `prefers-reduced-transparency`를 존중한다.
- 상태 배지(draft/scheduled/sending/sent/failed)는 색 외에 텍스트 라벨을 항상 함께 표시한다.
- 차트 색은 핑크 계열 + 구분용 보조색 1~2개만 쓰고, 범례와 툴팁을 제공한다.
- 모바일 폭에서도 깨지지 않게 반응형으로 만든다(사이드바는 작은 화면에서 접힘).

## 구조 규칙

- `app/` 라우트는 SPEC 화면 단위로 나눈다: `login`, `dashboard`, `campaigns`, `contacts`, `segments`, `settings`.
- API 호출은 `lib/api.ts` 클라이언트 한 곳을 거친다. 베이스 URL은 `NEXT_PUBLIC_API_URL`, 토큰은 httpOnly 쿠키 또는 서버 측에서 다룬다.
- 서버 응답 타입은 `types/`에 정의하고 `any`를 쓰지 않는다.
- 공용 UI(Button, Card, Badge, Table, Modal)는 `components/ui`에 두고 페이지 전용 컴포넌트는 해당 라우트 폴더에 둔다.
- 폼은 클라이언트 검증 + 서버 검증 오류(422)를 필드별로 표시한다.
- 로딩, 빈 상태, 오류 상태를 모든 목록/차트 화면에 구현한다.

## 접근성

- 버튼과 입력에 라벨, 키보드 포커스 링 유지, 색 대비 확보(본문 `--ink-900` on `--pink-50`).

## 명령

```
npm run dev     # http://localhost:3000
npm run build
npm run lint
```

## 환경변수

`NEXT_PUBLIC_API_URL` (예: http://localhost:8000/api/v1)
