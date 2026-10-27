# AI Service (FastAPI)

Laravel이 내부에서만 호출하는 AI 서비스. 명세는 `../SPEC.md` 4.4, 4.5, 8장을 따른다.

## 규칙

- 모든 엔드포인트는 `/health`를 제외하고 `X-Internal-Token` 검증(`verify_internal_token`)을 거친다. 브라우저에 직접 노출하지 않는다.
- 요청/응답은 Pydantic 모델로 정의하고 검증한다.
- `ANTHROPIC_API_KEY`가 없으면 Mock 모드로 고정 샘플을 반환해야 한다. 키 값은 출력하거나 커밋하지 않는다.
- 모델 학습/추론 코드는 `app/ml`, LLM 호출은 `app/llm`, 라우터는 `app/routers`로 분리한다.
- 테스트는 pytest. 외부 API 호출 없이 통과해야 한다.
- 화면, 응답 문구, 프롬프트에 이모지를 쓰지 않는다.

## 명령

```
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
pytest
```
