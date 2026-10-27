<div>
    <x-page-header :title="$campaignId ? '캠페인 수정' : '새 캠페인'" subtitle="내용을 작성하고 대상을 고른 뒤 바로 발송하거나 예약하세요.">
        <x-glass.button href="/campaigns" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    <div class="grid items-start gap-6 xl:grid-cols-5">
        <div class="space-y-6 xl:col-span-3">
            <x-glass.card strong>
                <h2 class="mb-4 text-lg font-bold">기본 정보</h2>
                <div class="space-y-4">
                    <x-glass.input label="캠페인 이름" wire:model="name" :error="$errors->first('name')" placeholder="예: 가을 시즌 프로모션" maxlength="100" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-glass.select label="채널" wire:model="channel" :error="$errors->first('channel')">
                            @foreach (\App\Models\Campaign::CHANNELS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-glass.select>

                        <x-glass.select label="발송 대상" wire:model.live="segment_id" :error="$errors->first('segment_id')">
                            <option value="">전체 고객</option>
                            @foreach ($this->segments as $segment)
                                <option value="{{ $segment->id }}">{{ $segment->name }}</option>
                            @endforeach
                        </x-glass.select>
                    </div>
                </div>
            </x-glass.card>

            <x-glass.card strong>
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="flex items-center gap-2 text-lg font-bold"><x-icon name="sparkles" :size="20" class="text-pink-600" /> AI 카피 도우미</h2>
                    @if ($aiMock)
                        <span class="rounded-full border border-lavender-400/50 bg-lavender-100 px-3 py-1 text-xs font-semibold">샘플 결과 (API 키 없음)</span>
                    @endif
                    @if ($this->aiRemaining !== null)
                        <span class="rounded-full border border-line bg-white/70 px-3 py-1 text-xs">오늘 남은 생성 {{ $this->aiRemaining }}회</span>
                    @endif
                </div>

                <div class="space-y-4">
                    <x-glass.input label="제품 또는 서비스" wire:model="aiProduct" :error="$errors->first('aiProduct')" placeholder="예: 가을 신상 니트 컬렉션 20% 할인" maxlength="300" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-glass.input label="타겟 고객" wire:model="aiTarget" :error="$errors->first('aiTarget')" placeholder="예: 30대 직장인" maxlength="200" />
                        <x-glass.select label="톤" wire:model="aiTone">
                            <option value="friendly">친근하게</option>
                            <option value="professional">전문적으로</option>
                            <option value="playful">재치 있게</option>
                            <option value="urgent">긴박하게</option>
                        </x-glass.select>
                    </div>

                    <x-glass.button type="button" tint="lavender" icon="sparkles" wire:click="generateCopy" wire:loading.attr="disabled" wire:target="generateCopy">
                        <span wire:loading.remove wire:target="generateCopy">AI로 작성</span>
                        <span wire:loading wire:target="generateCopy">작성 중...</span>
                    </x-glass.button>

                    @if ($aiError)
                        <p class="rounded-2xl bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{{ $aiError }}</p>
                    @endif
                </div>

                @if ($aiSubjects)
                    <div class="mt-6 border-t border-line pt-5">
                        <h3 class="mb-2 text-sm font-semibold">제목 후보</h3>
                        <ul class="space-y-2">
                            @foreach ($aiSubjects as $i => $candidate)
                                <li wire:key="ai-subject-{{ $i }}" class="flex items-center justify-between gap-3 rounded-2xl bg-white/70 px-4 py-2.5 text-sm">
                                    <span class="min-w-0 break-words">{{ $candidate }}</span>
                                    <button type="button" wire:click="useSubject({{ $i }})" class="focus-ring shrink-0 rounded-full border border-line px-3 py-1 text-xs font-semibold hover:bg-pink-100">사용</button>
                                </li>
                            @endforeach
                        </ul>

                        <div class="mb-2 mt-5 flex items-center justify-between">
                            <h3 class="text-sm font-semibold">본문 초안</h3>
                            <button type="button" wire:click="useBody" class="focus-ring rounded-full border border-line px-3 py-1 text-xs font-semibold hover:bg-pink-100">본문에 적용</button>
                        </div>
                        <p class="whitespace-pre-line rounded-2xl bg-white/70 px-4 py-3 text-sm">{{ $aiBody }}</p>
                    </div>
                @endif
            </x-glass.card>

            <x-glass.card strong>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-bold">내용</h2>
                    <p class="text-xs text-ink-500">변수: <code class="rounded bg-white/70 px-1.5 py-0.5">&#123;&#123;name&#125;&#125;</code> <code class="rounded bg-white/70 px-1.5 py-0.5">&#123;&#123;email&#125;&#125;</code></p>
                </div>
                <div class="space-y-4">
                    <x-glass.input label="제목" wire:model.live.debounce.400ms="subject" :error="$errors->first('subject')" placeholder="예: 가을 시즌 단독 혜택을 확인하세요" maxlength="150" />
                    <x-glass.textarea label="본문" wire:model.live.debounce.400ms="body" :error="$errors->first('body')" class="min-h-56" placeholder="본문을 입력하세요. https:// 링크는 클릭이 자동으로 추적됩니다." maxlength="5000" />
                </div>
            </x-glass.card>
        </div>

        <div class="space-y-6 xl:sticky xl:top-6 xl:col-span-2">
            <x-glass.card>
                <p class="text-sm font-medium text-ink-500">발송 대상</p>
                <p class="mt-2 text-4xl font-bold tracking-tight">{{ number_format($this->recipientCount) }}<span class="ml-1 text-lg font-semibold text-ink-500">명</span></p>
                @if ($channel === 'email' && $this->emailQuota !== null)
                    <p class="mt-3 rounded-2xl px-3 py-2 text-xs {{ $this->recipientCount > $this->emailQuota ? 'bg-danger/10 text-danger' : 'bg-mint-100/80 text-ink-700' }}">
                        오늘 남은 이메일 발송 한도 {{ number_format($this->emailQuota) }}건
                        @if ($this->recipientCount > $this->emailQuota) · 대상이 한도보다 많아 지금 발송할 수 없습니다 @endif
                    </p>
                @endif
                @if ($channel !== 'email')
                    <p class="mt-3 rounded-2xl bg-lavender-100/80 px-3 py-2 text-xs text-ink-700">{{ \App\Models\Campaign::CHANNELS[$channel] ?? '' }} 채널은 실제 전송 없이 발송 완료로만 기록됩니다.</p>
                @endif
            </x-glass.card>

            <x-glass.card strong>
                <h2 class="mb-3 font-bold">미리보기 <span class="text-xs font-normal text-ink-500">({{ $this->preview['name'] }}님 기준)</span></h2>
                <div class="rounded-2xl border border-line bg-white/80 p-4">
                    <p class="border-b border-line pb-2 text-sm font-semibold">{{ $this->preview['subject'] ?: '(제목 없음)' }}</p>
                    <div class="mt-3 break-words text-sm">
                        @if (trim($body) === '')
                            <span class="text-ink-500">본문을 입력하면 여기에 표시됩니다.</span>
                        @else
                            {!! $this->preview['html'] !!}
                        @endif
                    </div>
                </div>
            </x-glass.card>

            <x-glass.card strong>
                <h2 class="mb-3 font-bold">발송</h2>
                <x-glass.input label="예약 일시" type="datetime-local" wire:model="scheduled_at" :error="$errors->first('scheduled_at')" />

                <div class="mt-5 grid gap-3">
                    <x-glass.button type="button" size="lg" icon="send" class="w-full" wire:click="requestSend" wire:loading.attr="disabled" wire:target="requestSend,confirmSend">지금 발송</x-glass.button>
                    <div class="grid grid-cols-2 gap-3">
                        <x-glass.button type="button" tint="lavender" icon="clock" wire:click="schedule" wire:loading.attr="disabled" wire:target="schedule">예약 발송</x-glass.button>
                        <x-glass.button type="button" tint="plain" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">임시저장</x-glass.button>
                    </div>
                </div>
            </x-glass.card>
        </div>
    </div>

    @if ($confirmingSend)
        <x-glass.modal title="지금 발송할까요?" close="cancelSend">
            <p class="text-sm text-ink-700">
                <strong>{{ number_format($this->recipientCount) }}명</strong>에게 '{{ $name }}' 캠페인을 발송합니다. 발송을 시작하면 되돌릴 수 없습니다.
            </p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelSend">취소</x-glass.button>
                <x-glass.button type="button" icon="send" wire:click="confirmSend" wire:loading.attr="disabled" wire:target="confirmSend">
                    <span wire:loading.remove wire:target="confirmSend">발송</span>
                    <span wire:loading wire:target="confirmSend">준비 중...</span>
                </x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
