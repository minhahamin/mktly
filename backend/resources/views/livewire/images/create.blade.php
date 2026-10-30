<div>
    <x-page-header title="카드뉴스 만들기" subtitle="주제를 입력해 문구 초안을 만들고, 다듬은 뒤 이미지로 만듭니다.">
        <x-glass.button href="/images" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    <div class="grid items-start gap-6 xl:grid-cols-5">
        <x-glass.card strong class="xl:col-span-2">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-lg font-bold"><x-icon name="sparkles" :size="20" class="text-pink-600" /> 1. 문구 초안</h2>
                @if ($this->aiRemaining !== null)
                    <span class="rounded-full border border-line bg-white/70 px-3 py-1 text-xs">오늘 남은 생성 {{ $this->aiRemaining }}회</span>
                @endif
            </div>

            <div class="space-y-4">
                <x-glass.input label="주제 또는 제품" wire:model="topic" :error="$errors->first('topic')" placeholder="예: 가을 신상 니트 컬렉션 20% 할인" maxlength="300" />
                <x-glass.input label="타겟 고객" wire:model="target" :error="$errors->first('target')" placeholder="예: 30대 직장인" maxlength="200" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-glass.select label="종류" wire:model.live="kind" :error="$errors->first('kind')">
                        @foreach (\App\Models\GeneratedImage::KINDS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-glass.select>

                    <x-glass.select label="비율" wire:model="ratio" :error="$errors->first('ratio')">
                        @foreach (\App\Models\GeneratedImage::RATIOS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-glass.select>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-glass.select label="톤" wire:model="tone">
                        <option value="friendly">친근하게</option>
                        <option value="professional">전문적으로</option>
                        <option value="playful">재치 있게</option>
                        <option value="urgent">긴박하게</option>
                    </x-glass.select>

                    @if ($kind === 'cardnews')
                        <x-glass.select label="장수" wire:model.live="slideCount" :error="$errors->first('slideCount')">
                            @foreach (range(3, 6) as $count)
                                <option value="{{ $count }}">{{ $count }}장</option>
                            @endforeach
                        </x-glass.select>
                    @endif
                </div>

                <x-glass.button type="button" tint="lavender" icon="sparkles" wire:click="draft" wire:loading.attr="disabled" wire:target="draft">
                    <span wire:loading.remove wire:target="draft">{{ $slides ? '초안 다시 만들기' : '문구 초안 만들기' }}</span>
                    <span wire:loading wire:target="draft">작성 중...</span>
                </x-glass.button>

                @if ($error)
                    <p class="rounded-2xl bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{{ $error }}</p>
                @endif
            </div>
        </x-glass.card>

        <x-glass.card strong class="xl:col-span-3">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-bold">2. 문구 다듬기와 이미지 만들기</h2>
                @if ($aiMock)
                    <span class="rounded-full border border-lavender-400/50 bg-lavender-100 px-3 py-1 text-xs font-semibold">샘플 결과 (API 키 없음)</span>
                @elseif ($aiProvider)
                    <span class="rounded-full border border-line bg-white/70 px-3 py-1 text-xs">{{ ['gemini' => 'Gemini', 'openai' => 'OpenAI'][$aiProvider] ?? $aiProvider }} 응답</span>
                @endif
            </div>

            @if (! $slides)
                <div class="rounded-2xl bg-white/60 px-5 py-10 text-center">
                    <span class="glass tint-lavender mx-auto grid size-12 place-items-center rounded-full"><x-icon name="image" :size="22" /></span>
                    <p class="mt-3 text-sm font-semibold">먼저 문구 초안을 만들어 주세요</p>
                    <p class="mt-1 text-xs text-ink-500">초안이 나오면 장마다 제목(20자)과 본문(60자)을 고칠 수 있습니다.</p>
                </div>
                @error('slides')<p class="mt-3 px-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
            @else
                <ol class="space-y-4">
                    @foreach ($slides as $i => $slide)
                        <li wire:key="slide-{{ $i }}" class="rounded-2xl bg-white/60 p-4">
                            <p class="mb-2 text-xs font-semibold text-ink-500">{{ $kind === 'cover' ? '대표 이미지' : ($i + 1).'장' }}</p>
                            <div class="space-y-3">
                                <x-glass.input label="제목" wire:model="slides.{{ $i }}.title" :error="$errors->first('slides.'.$i.'.title')" maxlength="20" />
                                <x-glass.input label="본문" wire:model="slides.{{ $i }}.body" :error="$errors->first('slides.'.$i.'.body')" maxlength="60" />
                            </div>
                        </li>
                    @endforeach
                </ol>
                @error('slides')<p class="mt-3 px-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror

                @if ($aiReferences)
                    <h3 class="mb-2 mt-5 text-sm font-semibold">참고한 자료</h3>
                    <ul class="space-y-2">
                        @foreach ($aiReferences as $reference)
                            <li wire:key="image-reference-{{ $reference['kind'] }}-{{ $reference['id'] }}" class="flex items-center justify-between gap-3 rounded-2xl bg-white/70 px-4 py-2.5 text-sm">
                                <span class="min-w-0 break-words font-medium">{{ $reference['title'] }}</span>
                                <span class="shrink-0 rounded-full border border-line bg-white/70 px-3 py-1 text-xs">
                                    {{ $reference['kind'] === 'campaign' ? '캠페인 · 오픈율 '.number_format($reference['open_rate'] * 100, 1).'%' : '브랜드 문서' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-6 flex flex-wrap items-center justify-end gap-4">
                    @if ($this->imageRemaining !== null)
                        <div class="mr-auto">
                            @if ($this->imageRemaining > 0)
                                <x-glass.checkbox label="AI 배경 삽화 사용 (오늘 남은 {{ $this->imageRemaining }}회)" wire:model="aiBackground" />
                                <p class="mt-1 text-xs text-ink-500">만들지 못하면 기본 배경으로 그립니다.</p>
                            @else
                                <p class="text-xs text-ink-500">오늘 AI 배경 삽화 한도를 모두 사용해 기본 배경으로 그립니다.</p>
                            @endif
                        </div>
                    @endif

                    <x-glass.button type="button" size="lg" icon="image" wire:click="generate" wire:loading.attr="disabled" wire:target="generate">
                        <span wire:loading.remove wire:target="generate">이미지 만들기</span>
                        <span wire:loading wire:target="generate">이미지를 그리는 중...</span>
                    </x-glass.button>
                </div>
            @endif
        </x-glass.card>
    </div>
</div>
