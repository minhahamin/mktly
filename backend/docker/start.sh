#!/bin/sh
# 운영 컨테이너 시작 스크립트: 마이그레이션 -> 첫 관리자 -> 캐시 -> 큐 워커/스케줄러 -> 웹 서버
set -e
cd /app

php artisan migrate --force
php artisan mktly:bootstrap

# 설정, 라우트, 뷰를 미리 컴파일한다. 환경변수가 확정된 이 시점에 해야 한다.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 큐 워커와 스케줄러는 종료되면 다시 시작한다(비용을 아끼려고 웹 서버와 같은 컨테이너에서 실행).
(while true; do php artisan queue:work --sleep=3 --tries=3 --max-time=3600 || true; sleep 2; done) &
(while true; do php artisan schedule:work || true; sleep 2; done) &

exec frankenphp run --config /etc/caddy/Caddyfile
