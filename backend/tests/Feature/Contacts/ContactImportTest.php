<?php

use App\Livewire\Contacts\Import;
use App\Models\Contact;
use App\Services\Contacts\ContactImporter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function csvFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, $content);

    return $path;
}

function importCsv(string $content, int $workspaceId)
{
    return app(ContactImporter::class)->import(csvFile($content), $workspaceId);
}

it('정상 행을 모두 가져온다', function () {
    [, $workspace] = userInWorkspace();
    $csv = "email,name,tags,joined_at,last_purchase_at,total_spent\n"
        ."a@example.test,김가,VIP|신규,2025-01-02,2025-06-01,\"150,000\"\n"
        ."b@example.test,이나,,,,\n";

    $result = importCsv($csv, $workspace->id);

    expect($result->imported)->toBe(2)->and($result->invalid)->toBe(0);

    $a = Contact::withoutGlobalScopes()->where('email', 'a@example.test')->first();
    expect($a->tags)->toBe(['VIP', '신규'])
        ->and($a->joined_at->toDateString())->toBe('2025-01-02')
        ->and($a->total_spent)->toBe(150000);
});

it('한글 열 이름과 BOM을 인식한다', function () {
    [, $workspace] = userInWorkspace();

    $result = importCsv("\xEF\xBB\xBF이메일,이름\nkr@example.test,한글\n", $workspace->id);

    expect($result->imported)->toBe(1);
});

it('엑셀 CP949 인코딩을 변환한다', function () {
    [, $workspace] = userInWorkspace();
    $csv = mb_convert_encoding("이메일,이름\nenc@example.test,홍길동\n", 'CP949', 'UTF-8');

    importCsv($csv, $workspace->id);

    expect(Contact::withoutGlobalScopes()->where('email', 'enc@example.test')->value('name'))->toBe('홍길동');
});

it('파일 안의 중복과 기존 고객은 건너뛴다', function () {
    [, $workspace] = userInWorkspace();
    Contact::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'email' => 'old@example.test', 'name' => '기존']);

    $result = importCsv("email,name\nold@example.test,새이름\nnew@example.test,신규\nNEW@example.test,대소문자중복\n", $workspace->id);

    expect($result->imported)->toBe(1)->and($result->duplicates)->toBe(2);
    expect(Contact::withoutGlobalScopes()->where('email', 'old@example.test')->value('name'))->toBe('기존');
});

it('잘못된 행은 줄 번호와 사유를 기록하고 나머지는 가져온다', function () {
    [, $workspace] = userInWorkspace();
    $csv = "email,name,joined_at,total_spent\n"
        ."ok@example.test,정상,2025-01-01,0\n"
        ."bad-email,이메일오류,,\n"
        ."date@example.test,날짜오류,2025-13-45,\n"
        ."num@example.test,숫자오류,,abc\n"
        ."noname@example.test,,,\n";

    $result = importCsv($csv, $workspace->id);

    expect($result->imported)->toBe(1)->and($result->invalid)->toBe(4)
        ->and(array_column($result->errors, 'line'))->toBe([3, 4, 5, 6]);
});

it('필수 열이 없으면 예외를 던진다', function () {
    [, $workspace] = userInWorkspace();

    expect(fn () => importCsv("name,tags\n홍길동,VIP\n", $workspace->id))->toThrow(InvalidArgumentException::class);
    expect(fn () => importCsv('', $workspace->id))->toThrow(InvalidArgumentException::class);
});

it('행 수 제한을 넘으면 예외를 던진다', function () {
    [, $workspace] = userInWorkspace();
    $rows = collect(range(1, ContactImporter::MAX_ROWS + 1))->map(fn ($i) => "u{$i}@example.test,이름{$i}")->implode("\n");

    expect(fn () => importCsv("email,name\n{$rows}\n", $workspace->id))->toThrow(InvalidArgumentException::class);
});

it('임포트는 해당 워크스페이스에만 저장한다', function () {
    [, $workspace] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');

    importCsv("email,name\nx@example.test,엑스\n", $workspace->id);

    expect(Contact::withoutGlobalScopes()->where('workspace_id', $other->id)->count())->toBe(0);
});

it('화면에서 CSV를 업로드하면 결과를 보여준다', function () {
    [$user] = userInWorkspace();
    $file = UploadedFile::fake()->createWithContent('contacts.csv', "email,name\nui@example.test,화면업로드\nbad,오류\n");

    Livewire::actingAs($user)->test(Import::class)
        ->set('file', $file)->call('import')->assertHasNoErrors()
        ->assertSet('summary.imported', 1)->assertSet('summary.invalid', 1)->assertSee('업로드 결과');

    expect(Contact::withoutGlobalScopes()->where('email', 'ui@example.test')->exists())->toBeTrue();
});

it('CSV가 아닌 파일은 거부한다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Import::class)
        ->set('file', UploadedFile::fake()->create('photo.png', 10, 'image/png'))
        ->call('import')->assertHasErrors(['file']);
});

it('viewer는 업로드 화면에 접근할 수 없다', function () {
    [$viewer] = userInWorkspace('viewer');

    $this->actingAs($viewer)->get('/contacts/import')->assertForbidden();
});

it('샘플 CSV를 내려받을 수 있다', function () {
    [$user] = userInWorkspace();

    $this->actingAs($user)->get('/contacts/import/template')
        ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="mktly-contacts-sample.csv"');
});
