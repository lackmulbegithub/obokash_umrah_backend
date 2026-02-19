<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerReferral extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'referrer_customer_id',
        'referred_customer_id',
        'created_by_user_id',
    ];
}
