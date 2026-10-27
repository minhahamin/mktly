<?php

namespace App\Services\Ai;

use RuntimeException;

/** AI 서비스를 쓸 수 없을 때(연결 실패, 시간 초과, 오류 응답). 메시지는 사용자에게 그대로 보여줄 수 있다. */
class AiUnavailableException extends RuntimeException {}
