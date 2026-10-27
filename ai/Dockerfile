FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

RUN useradd --create-home appuser && chown -R appuser /app
USER appuser

ENV PORT=8001
EXPOSE 8001

# 운영 기본 실행: IPv4/IPv6 동시 수신(serve.py). 로컬 compose는 --reload 명령으로 덮어쓴다.
CMD ["python", "serve.py"]
