<x-layouts.app title="디자인 키트" heading="디자인 키트">
    <x-slot:actions>
        <x-glass.button icon="plus">새 캠페인</x-glass.button>
    </x-slot:actions>

    <div class="grid gap-6 xl:grid-cols-2" x-data="{ on: true, checked: true }">
        <x-glass.card>
            <h2 class="mb-4 text-lg font-bold">버튼</h2>
            <div class="flex flex-wrap items-center gap-3">
                <x-glass.button tint="pink" icon="send">주요 버튼</x-glass.button>
                <x-glass.button tint="lavender">보조 버튼</x-glass.button>
                <x-glass.button tint="mint" icon="check">완료</x-glass.button>
                <x-glass.button tint="peach" icon="search">검색</x-glass.button>
                <x-glass.button tint="plain" size="sm">기본 작게</x-glass.button>
                <button class="glass tint-lavender focus-ring grid size-12 place-items-center rounded-full" aria-label="알림"><x-icon name="bell" /></button>
                <button class="glass tint-pink focus-ring grid size-12 place-items-center rounded-full" aria-label="잠금"><x-icon name="lock" /></button>
            </div>
        </x-glass.card>

        <x-glass.card>
            <h2 class="mb-4 text-lg font-bold">입력</h2>
            <div class="space-y-4">
                <x-glass.input label="검색" icon="search" placeholder="고객, 캠페인 검색" />
                <x-glass.input label="이메일" type="email" placeholder="you@company.com" error="올바른 이메일 형식이 아닙니다." />
                <x-glass.select label="채널">
                    <option>이메일</option>
                    <option>SMS</option>
                    <option>푸시</option>
                </x-glass.select>
                <x-glass.textarea label="본문" placeholder="안녕하세요, 고객님" />
            </div>
        </x-glass.card>

        <x-glass.card>
            <h2 class="mb-4 text-lg font-bold">선택 컨트롤</h2>
            <div class="flex flex-wrap items-center gap-8">
                <x-glass.switch label="예약 발송" x-model="on" />
                <x-glass.checkbox label="수신 동의 고객만" x-model="checked" />
            </div>
        </x-glass.card>

        <x-glass.card>
            <h2 class="mb-4 text-lg font-bold">상태 배지</h2>
            <div class="flex flex-wrap gap-3">
                @foreach (['draft', 'scheduled', 'sending', 'sent', 'failed'] as $s)
                    <x-glass.badge :status="$s" />
                @endforeach
            </div>
        </x-glass.card>

        <x-glass.card class="xl:col-span-2" strong>
            <h2 class="mb-4 text-lg font-bold">표 (불투명도 높은 유리)</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-ink-500">
                    <tr><th class="pb-3 font-medium">캠페인</th><th class="pb-3 font-medium">상태</th><th class="pb-3 text-right font-medium">오픈율</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr><td class="py-3 font-semibold">가을 시즌 프로모션</td><td><x-glass.badge status="sent" /></td><td class="text-right">42.1%</td></tr>
                    <tr><td class="py-3 font-semibold">휴면 고객 재활성화</td><td><x-glass.badge status="scheduled" /></td><td class="text-right">-</td></tr>
                </tbody>
            </table>
        </x-glass.card>
    </div>
</x-layouts.app>
