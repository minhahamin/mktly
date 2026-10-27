@php
    $roles = \App\Livewire\Settings\Index::ROLES;
    $me = auth()->id();
@endphp

<div>
    <x-page-header title="설정" subtitle="워크스페이스와 멤버, 내 계정을 관리합니다." />

    @if ($newCredentials)
        <div class="glass tint-mint mb-6 rounded-3xl p-5" role="status">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="font-semibold">멤버를 추가했습니다. 임시 비밀번호는 지금만 표시됩니다.</p>
                    <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                        <dt class="text-ink-700">이메일</dt><dd class="font-semibold">{{ $newCredentials['email'] }}</dd>
                        <dt class="text-ink-700">임시 비밀번호</dt><dd class="font-mono font-semibold">{{ $newCredentials['password'] }}</dd>
                    </dl>
                    <p class="mt-3 text-xs text-ink-700">안전한 방법으로 전달하고, 로그인 후 설정에서 비밀번호를 바꾸도록 안내해 주세요.</p>
                </div>
                <button type="button" wire:click="dismissCredentials" class="focus-ring grid size-9 shrink-0 place-items-center rounded-full hover:bg-white/60" aria-label="닫기"><x-icon name="x" :size="18" /></button>
            </div>
        </div>
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-5">
        <div class="space-y-6 xl:col-span-3">
            <x-glass.card strong>
                <h2 class="mb-4 text-lg font-bold">워크스페이스</h2>
                @if ($this->isAdmin)
                    <form wire:submit="saveWorkspace" class="flex flex-wrap items-end gap-3" novalidate>
                        <div class="min-w-56 flex-1">
                            <x-glass.input label="워크스페이스 이름" wire:model="workspaceName" :error="$errors->first('workspaceName')" maxlength="100" />
                        </div>
                        <x-glass.button type="submit" wire:loading.attr="disabled" wire:target="saveWorkspace">저장</x-glass.button>
                    </form>
                @else
                    <p class="text-sm text-ink-700">{{ $workspaceName }}</p>
                    <p class="mt-1 text-xs text-ink-500">이름은 관리자만 바꿀 수 있습니다.</p>
                @endif
            </x-glass.card>

            <x-glass.card strong>
                <h2 class="mb-4 text-lg font-bold">멤버 <span class="text-sm font-normal text-ink-500">{{ $this->members->count() }}명</span></h2>

                <div class="divide-y divide-line/70">
                    @foreach ($this->members as $member)
                        <div wire:key="member-{{ $member->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="tint-pink grid size-10 shrink-0 place-items-center rounded-full border text-sm font-bold">{{ mb_substr($member->name, 0, 1) }}</span>
                                <div class="min-w-0">
                                    <p class="truncate font-semibold">{{ $member->name }} @if ($member->id === $me)<span class="text-xs font-normal text-ink-500">(나)</span>@endif</p>
                                    <p class="truncate text-xs text-ink-500">{{ $member->email }}</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                @if ($this->isAdmin && $member->id !== $me)
                                    <div class="w-32">
                                        <x-glass.select :aria-label="$member->name.' 역할'" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                                            @foreach ($roles as $value => $label)
                                                <option value="{{ $value }}" @selected($member->pivot->role === $value)>{{ $label }}</option>
                                            @endforeach
                                        </x-glass.select>
                                    </div>
                                    <button type="button" wire:click="confirmRemove({{ $member->id }})" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="{{ $member->name }} 제거"><x-icon name="trash" :size="16" /></button>
                                @else
                                    <span class="rounded-full border border-line bg-white/70 px-3 py-1 text-xs font-semibold">{{ $roles[$member->pivot->role] ?? $member->pivot->role }}</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-glass.card>

            @if ($this->isAdmin)
                <x-glass.card strong>
                    <h2 class="mb-1 text-lg font-bold">멤버 추가</h2>
                    <p class="mb-4 text-sm text-ink-500">새 계정을 만들고 임시 비밀번호를 발급합니다.</p>

                    <form wire:submit="addMember" class="space-y-4" novalidate>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-glass.input label="이름" wire:model="memberName" :error="$errors->first('memberName')" maxlength="100" />
                            <x-glass.input label="이메일" type="email" wire:model="memberEmail" :error="$errors->first('memberEmail')" maxlength="255" />
                        </div>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="w-48">
                                <x-glass.select label="역할" wire:model="memberRole" :error="$errors->first('memberRole')">
                                    @foreach ($roles as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-glass.select>
                            </div>
                            <x-glass.button type="submit" icon="plus" wire:loading.attr="disabled" wire:target="addMember">멤버 추가</x-glass.button>
                        </div>
                    </form>
                </x-glass.card>
            @endif
        </div>

        <div class="space-y-6 xl:col-span-2">
            <x-glass.card strong>
                <h2 class="mb-1 text-lg font-bold">내 계정</h2>
                <p class="mb-4 text-sm text-ink-500">{{ auth()->user()->name }} · {{ auth()->user()->email }}</p>

                @if (auth()->user()->is_demo)
                    <p class="rounded-2xl bg-lavender-100/70 px-4 py-3 text-sm text-ink-700">데모 계정은 읽기 전용이라 비밀번호를 바꿀 수 없습니다.</p>
                @else
                <form wire:submit="changePassword" class="space-y-4" novalidate>
                    <x-glass.input label="현재 비밀번호" type="password" autocomplete="current-password" wire:model="currentPassword" :error="$errors->first('currentPassword')" />
                    <x-glass.input label="새 비밀번호 (8자 이상, 영문과 숫자)" type="password" autocomplete="new-password" wire:model="newPassword" :error="$errors->first('newPassword')" />
                    <x-glass.input label="새 비밀번호 확인" type="password" autocomplete="new-password" wire:model="newPasswordConfirmation" />
                    <x-glass.button type="submit" tint="lavender" wire:loading.attr="disabled" wire:target="changePassword">비밀번호 변경</x-glass.button>
                </form>
                @endif
            </x-glass.card>

            <x-glass.card>
                <h2 class="mb-3 text-lg font-bold">역할 안내</h2>
                <dl class="space-y-2 text-sm">
                    <div><dt class="font-semibold">관리자</dt><dd class="text-ink-500">모든 기능과 멤버, 워크스페이스 설정</dd></div>
                    <div><dt class="font-semibold">마케터</dt><dd class="text-ink-500">고객, 세그먼트, 캠페인 작성과 발송</dd></div>
                    <div><dt class="font-semibold">뷰어</dt><dd class="text-ink-500">조회만 가능</dd></div>
                </dl>
            </x-glass.card>
        </div>
    </div>

    @if ($removingId)
        <x-glass.modal title="멤버를 제거할까요?" close="cancelRemove">
            <p class="text-sm text-ink-700">제거한 멤버는 이 워크스페이스의 데이터에 더 이상 접근할 수 없습니다. 계정 자체는 삭제되지 않습니다.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelRemove">취소</x-glass.button>
                <x-glass.button type="button" tint="peach" icon="trash" wire:click="removeMember">제거</x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
