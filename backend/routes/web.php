<?php

use App\Http\Controllers\TrackingController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\BrandDocuments;
use App\Livewire\Campaigns;
use App\Livewire\Contacts;
use App\Livewire\Dashboard;
use App\Livewire\Segments;
use App\Livewire\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// 공개 추적 엔드포인트 (인증 없음). 토큰 형식이 아니면 404.
Route::get('/t/o/{token}.gif', [TrackingController::class, 'open'])->where('token', '[A-Za-z0-9]{40}')->name('track.open');
Route::get('/t/c/{token}', [TrackingController::class, 'click'])->where('token', '[A-Za-z0-9]{40}')->name('track.click');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
    Route::livewire('/register', Register::class)->name('register');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');

    Route::livewire('/campaigns', Campaigns\Index::class)->name('campaigns.index');
    Route::livewire('/campaigns/create', Campaigns\Form::class)->name('campaigns.create');
    Route::livewire('/campaigns/{campaign}', Campaigns\Show::class)->name('campaigns.show');
    Route::livewire('/campaigns/{campaign}/edit', Campaigns\Form::class)->name('campaigns.edit');

    Route::livewire('/contacts', Contacts\Index::class)->name('contacts.index');
    Route::livewire('/contacts/import', Contacts\Import::class)->name('contacts.import');
    Route::get('/contacts/import/template', function () {
        $csv = "\xEF\xBB\xBF"
            ."email,name,tags,joined_at,last_purchase_at,total_spent\n"
            ."hong@example.com,홍길동,VIP|뉴스레터,2025-03-01,2025-10-02,150000\n"
            ."kim@example.com,김영희,신규,2025-09-15,,0\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mktly-contacts-sample.csv"',
        ]);
    })->name('contacts.template');

    Route::livewire('/segments', Segments\Index::class)->name('segments.index');
    Route::livewire('/segments/suggest', Segments\Suggest::class)->name('segments.suggest');
    Route::livewire('/segments/create', Segments\Form::class)->name('segments.create');
    Route::livewire('/segments/{segment}/edit', Segments\Form::class)->name('segments.edit');

    Route::livewire('/brand-documents', BrandDocuments\Index::class)->name('brand-documents.index');
    Route::livewire('/brand-documents/create', BrandDocuments\Create::class)->name('brand-documents.create');

    Route::livewire('/settings', Settings\Index::class)->name('settings');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // 데모 계정에서 "나만의 워크스페이스 만들기"를 누르면 로그아웃 후 회원가입 화면으로 보낸다(허용된 값만).
        return redirect($request->input('next') === 'register' && config('mktly.signup.enabled') ? '/register' : '/login');
    })->name('logout');
});

if (app()->environment('local')) {
    Route::view('/design', 'design');
}
