<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuerySourceLog extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'query_id',
        'source_id',
        'source_wa_id',
        'source_email_id',
        'referred_by_user_id',
        'referred_by_customer_id',
        'created_by_user_id',
    ];
}
