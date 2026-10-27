<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\Cache;

/** 워크스페이스에서 사용 중인 태그 목록. 고객이 바뀌면 forget()으로 무효화한다. */
class ContactTagCatalog
{
    private const TTL_SECONDS = 300;

    /** @return list<string> */
    public function all(int $workspaceId): array
    {
        return Cache::remember($this->key($workspaceId), self::TTL_SECONDS, function () use ($workspaceId) {
            return Contact::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->whereNotNull('tags')
                ->pluck('tags')
                ->flatten()
                ->unique()
                ->sort()
                ->values()
                ->all();
        });
    }

    public function forget(int $workspaceId): void
    {
        Cache::forget($this->key($workspaceId));
    }

    private function key(int $workspaceId): string
    {
        return "contact-tags:{$workspaceId}";
    }
}
