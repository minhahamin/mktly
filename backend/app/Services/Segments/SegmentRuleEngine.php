<?php

namespace App\Services\Segments;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * 세그먼트 규칙({match, conditions[]})을 고객 쿼리로 변환한다.
 *
 * 컬럼과 연산자는 FIELDS 화이트리스트에서만 고르므로 사용자 입력이 SQL 식별자로 들어가지 않는다.
 */
class SegmentRuleEngine
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /**
     * field => [label, type(int|float|text|none-value ops), ops => label]
     *
     * @var array<string, array{label: string, input: string, ops: array<string, string>}>
     */
    public const FIELDS = [
        'total_spent' => [
            'label' => '누적 구매액',
            'input' => 'number',
            'ops' => ['>=' => '이상', '<=' => '이하', '=' => '같음'],
        ],
        'joined_at' => [
            'label' => '가입일',
            'input' => 'number',
            'ops' => ['within_days' => '최근 N일 이내', 'older_than_days' => 'N일 이전'],
        ],
        'last_purchase_at' => [
            'label' => '마지막 구매일',
            'input' => 'number',
            'ops' => ['within_days' => '최근 N일 이내', 'older_than_days' => 'N일 이상 지남', 'never' => '구매 이력 없음'],
        ],
        'tags' => [
            'label' => '태그',
            'input' => 'text',
            'ops' => ['has' => '포함', 'not_has' => '포함하지 않음'],
        ],
        'churn_score' => [
            'label' => '이탈 확률',
            'input' => 'decimal',
            'ops' => ['>=' => '이상', '<=' => '이하'],
        ],
    ];

    /** 값이 필요 없는 연산자 */
    private const VALUELESS_OPS = ['never'];

    public function apply(Builder $query, array $rules): Builder
    {
        $normalized = $this->normalize($rules);

        return $query->where(function (Builder $outer) use ($normalized) {
            foreach ($normalized['conditions'] as $condition) {
                $method = $normalized['match'] === self::MATCH_ANY ? 'orWhere' : 'where';
                $outer->{$method}(fn (Builder $q) => $this->applyCondition($q, $condition));
            }
        });
    }

    public function count(array $rules): int
    {
        return $this->apply(Contact::query(), $rules)->count();
    }

    /**
     * 저장된 규칙을 {match, conditions} 형태로 맞춘다. 과거 형식(조건 배열)도 받아들인다.
     *
     * @return array{match: string, conditions: list<array{field: string, op: string, value: mixed}>}
     */
    public function normalize(array $rules): array
    {
        $conditions = array_is_list($rules) ? $rules : ($rules['conditions'] ?? []);
        $match = ! array_is_list($rules) && ($rules['match'] ?? null) === self::MATCH_ANY ? self::MATCH_ANY : self::MATCH_ALL;

        $result = [];
        foreach ($conditions as $condition) {
            $this->assertValid($condition);
            $result[] = [
                'field' => $condition['field'],
                'op' => $condition['op'],
                'value' => $condition['value'] ?? null,
            ];
        }

        return ['match' => $match, 'conditions' => $result];
    }

    /** 입력이 덜 끝난 조건(값 누락 등)을 걸러낸다. 미리보기에서 사용한다. */
    public function completeConditions(array $conditions): array
    {
        return array_values(array_filter($conditions, function ($c) {
            if (! isset(self::FIELDS[$c['field'] ?? ''])
                || ! isset(self::FIELDS[$c['field']]['ops'][$c['op'] ?? ''])) {
                return false;
            }

            if (in_array($c['op'], self::VALUELESS_OPS, true)) {
                return true;
            }

            $value = $c['value'] ?? null;

            return $value !== null && $value !== '' && $this->isValidValue($c['field'], $value);
        }));
    }

    public function describe(array $condition): string
    {
        $field = self::FIELDS[$condition['field']] ?? null;

        if ($field === null) {
            return '알 수 없는 조건';
        }

        $value = $condition['value'] ?? '';
        $label = $field['label'];

        return match ($condition['op']) {
            '>=' => $label.' '.$this->format($condition['field'], $value).' 이상',
            '<=' => $label.' '.$this->format($condition['field'], $value).' 이하',
            '=' => $label.' '.$this->format($condition['field'], $value),
            'within_days' => "{$label} 최근 {$value}일 이내",
            'older_than_days' => $condition['field'] === 'joined_at' ? "{$label} {$value}일 이전" : "{$label} {$value}일 이상 지남",
            'never' => '구매 이력 없음',
            'has' => "태그 '{$value}' 포함",
            'not_has' => "태그 '{$value}' 제외",
            default => $label,
        };
    }

    public function isValidValue(string $field, mixed $value): bool
    {
        return match (self::FIELDS[$field]['input'] ?? null) {
            'number' => is_numeric($value) && (int) $value == $value && $value >= 0,
            'decimal' => is_numeric($value) && $value >= 0 && $value <= 1,
            'text' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 50,
            default => false,
        };
    }

    private function assertValid(array $condition): void
    {
        $field = $condition['field'] ?? null;
        $op = $condition['op'] ?? null;

        if (! isset(self::FIELDS[$field]) || ! isset(self::FIELDS[$field]['ops'][$op])) {
            throw new InvalidArgumentException('지원하지 않는 세그먼트 조건입니다.');
        }

        if (! in_array($op, self::VALUELESS_OPS, true) && ! $this->isValidValue($field, $condition['value'] ?? null)) {
            throw new InvalidArgumentException('세그먼트 조건의 값이 올바르지 않습니다.');
        }
    }

    private function applyCondition(Builder $q, array $c): void
    {
        $value = $c['value'];

        match ($c['field']) {
            'total_spent' => $q->where('total_spent', $c['op'], (int) $value),
            'churn_score' => $q->where('churn_score', $c['op'], (float) $value),
            'joined_at' => $this->applyDate($q, 'joined_at', $c['op'], (int) $value),
            'last_purchase_at' => $c['op'] === 'never'
                ? $q->whereNull('last_purchase_at')
                : $this->applyDate($q, 'last_purchase_at', $c['op'], (int) $value),
            'tags' => $c['op'] === 'has'
                ? $q->whereJsonContains('tags', $value)
                : $q->where(fn (Builder $inner) => $inner->whereNull('tags')->orWhereJsonDoesntContain('tags', $value)),
        };
    }

    private function applyDate(Builder $q, string $column, string $op, int $days): void
    {
        $cutoff = now()->subDays($days)->toDateString();

        $op === 'within_days'
            ? $q->where($column, '>=', $cutoff)
            : $q->where($column, '<', $cutoff);
    }

    private function format(string $field, mixed $value): string
    {
        return match ($field) {
            'total_spent' => number_format((int) $value).'원',
            'churn_score' => (string) ((float) $value * 100).'%',
            default => (string) $value,
        };
    }
}
