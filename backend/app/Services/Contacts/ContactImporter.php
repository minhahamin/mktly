<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContactImporter
{
    public const MAX_ROWS = 5000;

    private const CHUNK_SIZE = 500;

    /** 헤더 별칭 -> 내부 컬럼명 */
    private const HEADER_ALIASES = [
        'email' => 'email', '이메일' => 'email',
        'name' => 'name', '이름' => 'name',
        'tags' => 'tags', '태그' => 'tags',
        'joined_at' => 'joined_at', '가입일' => 'joined_at',
        'last_purchase_at' => 'last_purchase_at', '마지막구매일' => 'last_purchase_at',
        'total_spent' => 'total_spent', '누적구매액' => 'total_spent',
    ];

    public function __construct(private readonly ContactTagCatalog $tags) {}

    /**
     * CSV 파일을 읽어 워크스페이스에 고객을 추가한다. 이미 있는 이메일은 건너뛴다.
     *
     * @throws InvalidArgumentException 파일 형식 자체가 잘못된 경우
     */
    public function import(string $path, int $workspaceId): ImportResult
    {
        $handle = $this->open($path);
        $columns = $this->readHeader($handle);
        $result = new ImportResult;

        $seen = [];
        $batch = [];
        $line = 1;

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;

            if ($row === [null] || $this->isBlank($row)) {
                continue;
            }

            if (++$result->total > self::MAX_ROWS) {
                fclose($handle);
                throw new InvalidArgumentException('한 번에 최대 '.number_format(self::MAX_ROWS).'행까지 업로드할 수 있습니다.');
            }

            $record = $this->mapRow($columns, $row);
            $error = $this->validate($record);

            if ($error !== null) {
                $result->addInvalid($line, $error);

                continue;
            }

            $email = mb_strtolower($record['email']);

            if (isset($seen[$email])) {
                $result->duplicates++;

                continue;
            }

            $seen[$email] = true;
            $batch[] = $record;

            if (count($batch) >= self::CHUNK_SIZE) {
                $this->flush($batch, $workspaceId, $result);
                $batch = [];
            }
        }

        fclose($handle);
        $this->flush($batch, $workspaceId, $result);
        $this->tags->forget($workspaceId);

        return $result;
    }

    /** @return resource */
    private function open(string $path)
    {
        $content = file_get_contents($path);

        if ($content === false || trim($content) === '') {
            throw new InvalidArgumentException('빈 파일입니다.');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // 엑셀에서 저장한 한글 CSV(CP949)를 UTF-8로 변환한다.
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'CP949');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @return array<int, string> 열 위치 -> 내부 컬럼명
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle, escape: '\\');
        $columns = [];

        foreach ($header ?: [] as $index => $name) {
            $key = mb_strtolower(trim((string) $name));

            if (isset(self::HEADER_ALIASES[$key])) {
                $columns[$index] = self::HEADER_ALIASES[$key];
            }
        }

        if (! in_array('email', $columns, true) || ! in_array('name', $columns, true)) {
            fclose($handle);
            throw new InvalidArgumentException('첫 줄에 email(이메일)과 name(이름) 열이 필요합니다.');
        }

        return $columns;
    }

    /** @param  array<int, string>  $columns */
    private function mapRow(array $columns, array $row): array
    {
        $record = [];

        foreach ($columns as $index => $column) {
            $record[$column] = trim((string) ($row[$index] ?? ''));
        }

        return $record + ['tags' => '', 'joined_at' => '', 'last_purchase_at' => '', 'total_spent' => ''];
    }

    private function isBlank(array $row): bool
    {
        return trim(implode('', array_map(fn ($v) => (string) $v, $row))) === '';
    }

    private function validate(array $record): ?string
    {
        if ($record['email'] === '' || ! filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
            return '이메일 형식이 올바르지 않습니다.';
        }

        if ($record['name'] === '' || mb_strlen($record['name']) > 255) {
            return '이름은 1~255자여야 합니다.';
        }

        foreach (['joined_at' => '가입일', 'last_purchase_at' => '마지막구매일'] as $field => $label) {
            if ($record[$field] !== '' && $this->parseDate($record[$field]) === null) {
                return "{$label}은 YYYY-MM-DD 형식이어야 합니다.";
            }
        }

        if ($record['total_spent'] !== '' && ! preg_match('/^\d[\d,]*$/', $record['total_spent'])) {
            return '누적구매액은 0 이상의 숫자여야 합니다.';
        }

        return null;
    }

    /** @param  list<array<string, string>>  $batch */
    private function flush(array $batch, int $workspaceId, ImportResult $result): void
    {
        if ($batch === []) {
            return;
        }

        $existing = Contact::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->whereIn('email', array_column($batch, 'email'))
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower($email))
            ->flip();

        $now = now();
        $rows = [];

        foreach ($batch as $record) {
            if ($existing->has(mb_strtolower($record['email']))) {
                $result->duplicates++;

                continue;
            }

            $rows[] = [
                'workspace_id' => $workspaceId,
                'email' => $record['email'],
                'name' => $record['name'],
                'tags' => $this->parseTags($record['tags']),
                'joined_at' => $this->parseDate($record['joined_at']),
                'last_purchase_at' => $this->parseDate($record['last_purchase_at']),
                'total_spent' => (int) str_replace(',', '', $record['total_spent'] ?: '0'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
                DB::table('contacts')->insert($chunk);
            }
        });

        $result->imported += count($rows);
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(['/', '.'], '-', $value);
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function parseTags(string $value): ?string
    {
        $tags = array_values(array_unique(array_filter(array_map('trim', preg_split('/[|;]/', $value) ?: []))));

        return $tags === [] ? null : json_encode($tags, JSON_UNESCAPED_UNICODE);
    }
}
