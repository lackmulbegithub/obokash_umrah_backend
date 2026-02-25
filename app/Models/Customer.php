<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mobile_number',
        'customer_name',
        'gender',
        'whatsapp_number',
        'visit_record',
        'country',
        'district',
        'address_line',
        'customer_email',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CustomerCategory::class, 'customer_category_map', 'customer_id', 'category_id')->withTimestamps();
    }

    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }
}
