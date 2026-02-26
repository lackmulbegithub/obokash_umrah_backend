<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueryItem extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'query_id',
        'service_id',
        'assigned_type',
        'assigned_user_id',
        'assigned_by_user_id',
        'assignment_note',
        'team_id',
        'team_queue_owner_user_id',
        'item_status',
        'workflow_status',
        'quotation_date',
        'follow_up_date',
        'follow_up_count',
        'finished_note',
        'review_status',
        'review_note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'follow_up_date' => 'date',
            'follow_up_count' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function queryRecord(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teamQueueOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_queue_owner_user_id');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
