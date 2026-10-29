<?php

namespace App\Services\Brand;

/** 브랜드 문서를 검색용 조각으로 나눈다. 문단 단위로 약 500자씩 묶고, 앞 조각의 끝 50자를 다음 조각 앞에 겹쳐 넣는다. */
class ChunkSplitter
{
    public const SIZE = 500;

    public const OVERLAP = 50;

    /** @return list<string> 각 조각은 최대 SIZE + OVERLAP + 1자 */
    public function split(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return [];
        }

        $units = [];

        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph !== '') {
                array_push($units, ...$this->fit($paragraph));
            }
        }

        $chunks = [];
        $current = '';

        foreach ($units as $unit) {
            if ($current === '') {
                $current = $unit;
            } elseif (mb_strlen($current) + 2 + mb_strlen($unit) <= self::SIZE) {
                $current .= "\n\n".$unit;
            } else {
                $chunks[] = $current;
                $current = $unit;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $result = [];

        foreach ($chunks as $i => $chunk) {
            $result[] = $i === 0 ? $chunk : trim(mb_substr($chunks[$i - 1], -self::OVERLAP))."\n".$chunk;
        }

        return $result;
    }

    /**
     * 한 문단이 SIZE보다 길면 문장 단위로, 한 문장이 SIZE보다 길면 글자 수로 자른다.
     *
     * @return list<string>
     */
    private function fit(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= self::SIZE) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [] as $sentence) {
            if (mb_strlen($sentence) > self::SIZE) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }

                array_push($pieces, ...mb_str_split($sentence, self::SIZE));

                continue;
            }

            if ($current === '') {
                $current = $sentence;
            } elseif (mb_strlen($current) + 1 + mb_strlen($sentence) <= self::SIZE) {
                $current .= ' '.$sentence;
            } else {
                $pieces[] = $current;
                $current = $sentence;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
