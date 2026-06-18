<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $table = 'reviews';

    public $guarded = [];

    protected $casts = [
        'photos' => 'json',
    ];

    protected $appends = [
        'positive_feedbacks_count',
        'negative_feedbacks_count',
        'my_feedback',
        'abusive_reports_count'
    ];

    // `feedbacks` is eager-loaded on list endpoints purely to feed the count accessors above —
    // hide it so the response shape stays identical (it was never serialized before).
    protected $hidden = ['feedbacks'];

    /**
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->BelongsTo(Product::class, 'product_id');
    }

    /**
     * @return belongsTo
     */
    public function user(): belongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all of the reviews feedbacks.
     */
    public function feedbacks()
    {
        return $this->morphMany(Feedback::class, 'model');
    }

    public function abusive_reports()
    {
        return $this->morphMany(AbusiveReport::class, 'model');
    }

    /**
     * Positive feedback count of review .
     * @return int
     */
    public function getPositiveFeedbacksCountAttribute()
    {
        // N+1 fix: read the eager-loaded feedbacks collection on list endpoints, else query.
        return $this->relationLoaded('feedbacks')
            ? $this->feedbacks->where('positive', 1)->count()
            : $this->feedbacks()->wherePositive(1)->count();
    }

    /**
     * Negative feedback count of review .
     * @return int
     */
    public function getNegativeFeedbacksCountAttribute()
    {
        return $this->relationLoaded('feedbacks')
            ? $this->feedbacks->where('negative', 1)->count()
            : $this->feedbacks()->whereNegative(1)->count();
    }

    /**
     * Get authenticated user feedback
     * @return object | null
     */
    public function getMyFeedbackAttribute()
    {
        $userId = optional(auth()->user())->id;
        if (!$userId) {
            return null;
        }
        return $this->relationLoaded('feedbacks')
            ? $this->feedbacks->firstWhere('user_id', $userId)
            : $this->feedbacks()->where('user_id', $userId)->first();
    }

    /**
     * Count no of abusive reports in the review.
     * @return int
     */
    public function getAbusiveReportsCountAttribute() {
        return $this->abusive_reports()->count();
    }

}
