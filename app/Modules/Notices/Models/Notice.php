<?php

namespace App\Modules\Notices\Models;

use App\Models\User;
use App\Support\Localization\Bilingual;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notice for one or more audiences: public (website and everyone signed in),
 * staff, parents, students. Visible once published, from publish_at until
 * expires_at.
 *
 * @property int $id
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $body
 * @property string|null $body_bn
 * @property list<string> $audiences
 * @property bool $is_pinned
 * @property \Illuminate\Support\Carbon|null $publish_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property string $status
 */
class Notice extends Model
{
    use Bilingual, BelongsToSchool;

    public const AUDIENCES = ['public', 'staff', 'parents', 'students'];

    protected $fillable = ['title', 'title_bn', 'body', 'body_bn', 'audiences', 'is_pinned', 'publish_at', 'expires_at', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['audiences' => 'array', 'is_pinned' => 'boolean', 'publish_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /**
     * Published, live now, and addressed to any of the given audiences
     * ("public" notices are for everyone).
     *
     * @param  Builder<Notice>  $query
     * @param  list<string>  $audiences
     */
    public function scopeVisibleTo(Builder $query, array $audiences): void
    {
        $query->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($q) use ($audiences) {
                foreach (array_unique(['public', ...$audiences]) as $audience) {
                    $q->orWhereJsonContains('audiences', $audience);
                }
            });
    }

    /** @param  Builder<Notice>  $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('is_pinned')->orderByRaw('COALESCE(publish_at, created_at) DESC')->orderByDesc('id');
    }

    public function publishedOn(): \Carbon\CarbonInterface
    {
        return $this->publish_at ?? $this->created_at;
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
