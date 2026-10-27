<div>
    <x-page-header title="CSV 업로드" subtitle="고객 목록을 한 번에 불러옵니다. 이미 있는 이메일은 건너뜁니다.">
        <x-glass.button href="/contacts" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    <div class="grid gap-6 xl:grid-cols-5">
        <x-glass.card strong class="xl:col-span-3">
            <form wire:submit="import" class="space-y-5">
                <div x-data="{ dragging: false }"
                     @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="dragging = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change', { bubbles: true }))"
                     :class="dragging ? 'tint-pink' : ''"
                     class="glass relative grid place-items-center rounded-3xl border-dashed px-6 py-12 text-center transition">
                    <div class="flex flex-col items-center gap-3">
                        <span class="glass tint-lavender grid size-14 place-items-center rounded-full"><x-icon name="upload" :size="24" /></span>
                        @if ($file)
                            <p class="font-semibold">{{ $file->getClientOriginalName() }}</p>
                            <p class="text-xs text-ink-500">{{ number_format($file->getSize() / 1024, 1) }} KB</p>
                        @else
                            <p class="font-semibold">CSV 파일을 끌어다 놓거나 선택하세요</p>
                            <p class="text-xs text-ink-500">최대 2MB, 5,000행까지</p>
                        @endif
                        <label class="focus-within:ring-2 focus-within:ring-pink-500/60 glass-strong mt-1 inline-flex h-10 cursor-pointer items-center rounded-full px-5 text-sm font-semibold">
                            파일 선택
                            <input x-ref="input" type="file" accept=".csv,text/csv,text/plain" wire:model="file" class="sr-only">
                        </label>
                    </div>
                    <div wire:loading wire:target="file" class="absolute bottom-3 text-xs text-ink-500">파일을 올리는 중...</div>
                </div>

                @error('file')<p class="px-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
                @if ($failure)<p class="px-2 text-sm text-danger" role="alert">{{ $failure }}</p>@endif

                <div class="flex justify-end">
                    <x-glass.button type="submit" size="lg" icon="upload" wire:loading.attr="disabled" wire:target="import,file" :disabled="! $file">
                        <span wire:loading.remove wire:target="import">불러오기</span>
                        <span wire:loading wire:target="import">처리 중...</span>
                    </x-glass.button>
                </div>
            </form>

            @if ($summary)
                <div class="mt-8 border-t border-line pt-6" role="status">
                    <h2 class="text-lg font-bold">업로드 결과</h2>
                    <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ([['추가됨', $summary['imported'], 'tint-mint'], ['중복 건너뜀', $summary['duplicates'], 'tint-lavender'], ['오류', $summary['invalid'], 'tint-peach'], ['전체 행', $summary['total'], '']] as [$label, $value, $tint])
                            <div class="glass {{ $tint }} rounded-2xl p-4">
                                <dt class="text-xs text-ink-700">{{ $label }}</dt>
                                <dd class="mt-1 text-2xl font-bold">{{ number_format($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($summary['errors'])
                        <h3 class="mb-2 mt-6 text-sm font-semibold">오류가 있는 행</h3>
                        <ul class="space-y-1.5 text-sm">
                            @foreach ($summary['errors'] as $error)
                                <li class="flex gap-3 rounded-2xl bg-white/60 px-4 py-2">
                                    <span class="w-16 shrink-0 font-semibold text-danger">{{ $error['line'] }}행</span>
                                    <span class="text-ink-700">{{ $error['reason'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($summary['invalid'] > count($summary['errors']))
                            <p class="mt-2 px-1 text-xs text-ink-500">처음 {{ $summary['max_errors'] }}건만 표시합니다.</p>
                        @endif
                    @endif

                    @if ($summary['imported'] > 0)
                        <x-glass.button href="/contacts" class="mt-6" tint="mint" icon="users">고객 목록 보기</x-glass.button>
                    @endif
                </div>
            @endif
        </x-glass.card>

        <x-glass.card class="xl:col-span-2">
            <h2 class="text-lg font-bold">파일 형식</h2>
            <p class="mt-2 text-sm text-ink-700">첫 줄에 열 이름을 적어 주세요. 한글 열 이름도 인식합니다.</p>

            <table class="mt-4 w-full text-left text-sm">
                <tbody class="divide-y divide-line/70">
                    @foreach ([['email / 이메일', '필수'], ['name / 이름', '필수'], ['tags / 태그', '| 또는 ;로 구분'], ['joined_at / 가입일', 'YYYY-MM-DD'], ['last_purchase_at / 마지막구매일', 'YYYY-MM-DD'], ['total_spent / 누적구매액', '숫자']] as [$col, $note])
                        <tr><td class="py-2 font-semibold">{{ $col }}</td><td class="py-2 text-right text-ink-500">{{ $note }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            <p class="mt-4 text-xs text-ink-500">엑셀에서 저장한 CSV(한글 인코딩)도 자동으로 변환합니다.</p>
            <x-glass.button href="/contacts/import/template" tint="lavender" icon="download" class="mt-5">샘플 CSV 받기</x-glass.button>
        </x-glass.card>
    </div>
</div>
