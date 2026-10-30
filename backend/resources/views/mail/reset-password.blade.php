{{-- 메일 클라이언트는 외부 CSS와 CSS 변수를 지원하지 않아 팔레트 값(SPEC 3장)을 인라인으로 쓴다. --}}
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>비밀번호 재설정 안내</title>
</head>
<body style="margin:0;padding:32px 16px;background:#FFF5F8;font-family:-apple-system,BlinkMacSystemFont,'Apple SD Gothic Neo','Malgun Gothic',sans-serif;color:#2B2230;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border:1px solid #F0DCE4;border-radius:24px;">
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 24px;font-size:18px;font-weight:700;">Mktly</p>
                            <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">{{ $name }}님, 안녕하세요.</p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;">비밀번호 재설정 요청을 받았습니다. 아래 버튼을 눌러 새 비밀번호를 정해 주세요. 링크는 {{ $minutes }}분 동안 한 번만 쓸 수 있습니다.</p>
                            <p style="margin:0 0 24px;">
                                <a href="{{ $url }}" style="display:inline-block;padding:12px 28px;border-radius:999px;background:#F07AA0;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">비밀번호 재설정하기</a>
                            </p>
                            <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#7A6F80;">버튼이 눌리지 않으면 아래 주소를 브라우저에 붙여 넣어 주세요.</p>
                            <p style="margin:0 0 24px;font-size:13px;line-height:1.6;word-break:break-all;"><a href="{{ $url }}" style="color:#C2476F;">{{ $url }}</a></p>
                            <p style="margin:0;font-size:13px;line-height:1.6;color:#7A6F80;">요청하지 않았다면 이 메일을 무시해 주세요. 비밀번호는 바뀌지 않습니다.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
