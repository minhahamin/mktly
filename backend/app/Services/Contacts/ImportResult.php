<?php

namespace App\Services\Contacts;

class ImportResult
{
    /** 화면에 보여줄 오류 행의 최대 개수 */
    public const MAX_REPORTED_ERRORS = 20;

    public int $total = 0;

    public int $imported = 0;

    public int $duplicates = 0;

    public int $invalid = 0;

    /** @var list<array{line: int, reason: string}> */
    public array $errors = [];

    public function addInvalid(int $line, string $reason): void
    {
        $this->invalid++;

        if (count($this->errors) < self::MAX_REPORTED_ERRORS) {
            $this->errors[] = ['line' => $line, 'reason' => $reason];
        }
    }
}
