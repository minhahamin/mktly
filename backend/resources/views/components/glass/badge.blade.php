@props(['status' => 'draft'])

@php
    $map = [
        'draft' => ['임시저장', 'bg-white/60 text-ink-700 border-line'],
        'scheduled' => ['예약됨', 'bg-lavender-100 text-ink-900 border-lavender-400/50'],
        'sending' => ['발송 중', 'bg-peach-100 text-ink-900 border-peach-400/50'],
        'sent' => ['발송 완료', 'bg-mint-100 text-ink-900 border-mint-400/50'],
        'failed' => ['실패', 'bg-danger/10 text-danger border-danger/30'],
    ];
    [$text, $classes] = $map[$status] ?? [$status, $map['draft'][1]];
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold $classes") }}>
    <span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ $text }}
</span>
