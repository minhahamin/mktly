<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** RefreshDatabase가 마이그레이션을 초기화하기 직전에 호출된다. 개발용 DB를 지우는 사고를 막는다. */
    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'sqlite') {
            throw new RuntimeException('테스트는 sqlite에서만 실행할 수 있습니다. 현재 연결: '.config('database.default'));
        }
    }
}
