<?php

namespace Marvel\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

class Conversation extends Model
{

    public $guarded = [];

    protected $appends = [
        'latest_message',
        'unseen',
    ];

    /**
     * @return belongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return belongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /**
     * @return hasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    /**
     * The single most-recent message — a real relation so it can be eager-loaded
     * (eliminates the per-row latest-message query when listing conversations).
     */
    public function latestMessage(): HasOne
    {
        // latestOfMany('created_at') matches the original messages()->latest()->first() ordering
        // (latest() orders by created_at), not the default primary-key ordering.
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany('created_at');
    }

    /**
     * Returns the latest message from a conversation.
     *
     * @return Message
     */
    public function getLatestMessageAttribute()
    {
        // Read the eager-loaded relation when present (list path), else load it once.
        return $this->latestMessage;
    }

    /**
     * Get all of the conversations participants.
     */
    public function participants()
    {
        return $this->hasMany(Participant::class, 'conversation_id');
    }

    public function getUnseenAttribute()
    {
        if (Auth::check()) {
            $instance = $this->participants()->whereNull('last_read')->where('user_id', auth()->user()->id)->where('type', 'user')->count();

            if (0 == $instance) {
                // N+1 fix: use the loaded `shops` relation, not the `shops()` method, which
                // re-queried the user's shops on every conversation row.
                $instance = $this->participants()->whereNull('last_read')->whereIn('shop_id', auth()->user()->shops->pluck('id'))->where('type', 'shop')->count();
            }

            return $instance;
        } else {
            return '0';
        }
    }
}
