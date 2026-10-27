@if ($paginator->hasPages())
    <nav role="navigation" aria-label="페이지 이동" class="mt-6 flex flex-wrap items-center justify-between gap-3 text-sm">
        <p class="text-ink-500">
            총 {{ number_format($paginator->total()) }}명 중 {{ number_format($paginator->firstItem()) }}-{{ number_format($paginator->lastItem()) }}
        </p>

        <div class="flex items-center gap-1.5">
            <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" @disabled($paginator->onFirstPage())
                    class="focus-ring glass grid h-9 min-w-9 place-items-center rounded-full px-3 font-semibold disabled:opacity-40" aria-label="이전 페이지">이전</button>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-ink-500">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <button type="button" wire:key="page-{{ $paginator->getPageName() }}-{{ $page }}"
                                wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                @if ($page == $paginator->currentPage()) aria-current="page" @endif
                                class="focus-ring grid h-9 min-w-9 place-items-center rounded-full px-3 font-semibold {{ $page == $paginator->currentPage() ? 'glass tint-pink' : 'text-ink-700 hover:bg-white/60' }}">
                            {{ $page }}
                        </button>
                    @endforeach
                @endif
            @endforeach

            <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" @disabled(! $paginator->hasMorePages())
                    class="focus-ring glass grid h-9 min-w-9 place-items-center rounded-full px-3 font-semibold disabled:opacity-40" aria-label="다음 페이지">다음</button>
        </div>
    </nav>
@endif
