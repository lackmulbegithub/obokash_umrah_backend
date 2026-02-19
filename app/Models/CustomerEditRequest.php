<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerEditRequest extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'requested_by_user_id',
        'approved_by_user_id',
        'status',
        'old_data_json',
        'new_data_json',
        'note',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_data_json' => 'array',
            'new_data_json' => 'array',
            'decided_at' => 'datetime',
        ];
    }
}
