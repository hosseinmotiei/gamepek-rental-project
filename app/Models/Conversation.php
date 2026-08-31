<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'subject', 'status',
        'last_message_at', 'user_read_at', 'admin_read_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'user_read_at' => 'datetime',
            'admin_read_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'open' => 'باز',
            'closed' => 'بسته شده',
            default => $this->status,
        };
    }

    public function isUnreadByAdmin(): bool
    {
        return $this->last_message_at !== null
            && (!$this->admin_read_at || $this->admin_read_at->lt($this->last_message_at));
    }

    public function isUnreadByUser(): bool
    {
        return $this->last_message_at !== null
            && (!$this->user_read_at || $this->user_read_at->lt($this->last_message_at));
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSearch($query, string $term)
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $pattern = "%{$escaped}%";

        return $query->where(function ($q) use ($pattern) {
            $q->where('subject', 'LIKE', $pattern)
              ->orWhereHas('user', function ($userQuery) use ($pattern) {
                  $userQuery->where('full_name', 'LIKE', $pattern)
                            ->orWhere('mobile', 'LIKE', $pattern);
              });
        });
    }
}
