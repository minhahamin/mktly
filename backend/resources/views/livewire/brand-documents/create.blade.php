<div>
    <x-page-header title="브랜드 문서 등록" subtitle="텍스트를 붙여넣거나 .txt, .md 파일을 올려 주세요. 최대 50KB, PDF는 지원하지 않습니다.">
        <x-glass.button href="/brand-documents" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    <x-glass.card strong class="max-w-3xl">
        <form wire:submit="save" class="space-y-5">
            <x-glass.input label="문서 제목" wire:model="title" :error="$errors->first('title')" placeholder="예: 톤앤매너 가이드" maxlength="100" />

            <x-glass.textarea label="내용" wire:model="content" :error="$errors->first('content')" class="min-h-64" placeholder="예: 우리 브랜드는 존댓말을 쓰고, 과장된 표현과 느낌표를 쓰지 않습니다." />

            <div>
                <p class="mb-1.5 text-sm font-medium text-ink-700">또는 파일 올리기</p>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="focus-within:ring-2 focus-within:ring-pink-500/60 glass-strong inline-flex h-10 cursor-pointer items-center gap-2 rounded-full px-5 text-sm font-semibold">
                        <x-icon name="upload" :size="16" /> 파일 선택
                        <input type="file" accept=".txt,.md,text/plain,text/markdown" wire:model="file" class="sr-only">
                    </label>
                    @if ($file)
                        <span class="text-sm text-ink-700">{{ $file->getClientOriginalName() }} ({{ number_format($file->getSize() / 1024, 1) }} KB)</span>
                        <span class="text-xs text-ink-500">파일을 올리면 위 내용 대신 파일 내용을 씁니다.</span>
                    @endif
                    <span wire:loading wire:target="file" class="text-xs text-ink-500">파일을 올리는 중...</span>
                </div>
                @error('file')<p class="mt-1.5 px-2 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="flex justify-end">
                <x-glass.button type="submit" size="lg" icon="plus" wire:loading.attr="disabled" wire:target="save,file">
                    <span wire:loading.remove wire:target="save">등록</span>
                    <span wire:loading wire:target="save">등록 중...</span>
                </x-glass.button>
            </div>
        </form>
    </x-glass.card>
</div>
