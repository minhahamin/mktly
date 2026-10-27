<?php

namespace App\Services\Campaigns;

use App\Models\Contact;

/**
 * 캠페인 본문(일반 텍스트)을 수신자별 HTML로 만든다.
 *
 * 본문은 먼저 이스케이프하고, 변수({{name}}, {{email}})는 이스케이프된 값으로 치환하므로
 * 고객 이름이나 본문에 들어간 HTML이 그대로 실행되지 않는다.
 */
class MessageRenderer
{
    /** 본문에서 추적 대상이 되는 URL */
    private const URL_PATTERN = '~(https?://[^\s<>"\']+)~u';

    /** @return list<string> 본문에 들어 있는 고유 URL */
    public function extractUrls(string $body): array
    {
        preg_match_all(self::URL_PATTERN, $body, $matches);

        return array_values(array_unique(array_map(fn ($url) => $this->trimUrl($url)[0], $matches[1])));
    }

    public function subject(string $subject, Contact $contact): string
    {
        return $this->replaceVariables($subject, $contact, escape: false);
    }

    /**
     * @param  array<string, string>  $links  URL => 추적 링크 토큰
     * @param  string|null  $messageToken  null이면 추적 없이 원본 URL로 렌더링한다(미리보기).
     */
    public function html(string $body, Contact $contact, array $links = [], ?string $messageToken = null): string
    {
        $parts = preg_split(self::URL_PATTERN, $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $html = '';

        foreach ($parts as $i => $part) {
            // preg_split의 캡처 그룹 덕분에 홀수 인덱스가 URL이다.
            if ($i % 2 === 0) {
                $html .= $this->replaceVariables($part, $contact, escape: true);

                continue;
            }

            [$url, $tail] = $this->trimUrl($part);
            $href = isset($links[$url], $messageToken)
                ? url('/t/c/'.$links[$url]).'?m='.$messageToken
                : $url;

            $html .= '<a href="'.e($href).'">'.e($url).'</a>'.e($tail);
        }

        $html = nl2br($html, false);

        if ($messageToken !== null) {
            $html .= '<img src="'.e(url('/t/o/'.$messageToken.'.gif')).'" width="1" height="1" alt="" style="display:none">';
        }

        return '<div style="font-family:sans-serif;font-size:15px;line-height:1.7;color:#2b2230">'.$html.'</div>';
    }

    private function replaceVariables(string $text, Contact $contact, bool $escape): string
    {
        $values = ['name' => $contact->name, 'email' => $contact->email];

        return preg_replace_callback('/\{\{\s*(name|email)\s*\}\}/', function ($m) use ($values, $escape) {
            return $escape ? e($values[$m[1]]) : $values[$m[1]];
        }, $escape ? e($text) : $text) ?? $text;
    }

    /** @return array{0: string, 1: string} URL 끝에 붙은 문장부호를 분리한다. */
    private function trimUrl(string $url): array
    {
        $trimmed = rtrim($url, '.,;:!?)]}');

        return [$trimmed, substr($url, strlen($trimmed))];
    }
}
