<?php

use App\Livewire\Auth\Login;
use App\Livewire\Contacts;
use App\Livewire\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', Login::class)->name('login');
});

Route::middleware('auth')->group(function () {
    Route::livewire('/dashboard', Dashboard::class)->name('dashboard');

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

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
});

if (app()->environment('local')) {
    Route::view('/design', 'design');
}
