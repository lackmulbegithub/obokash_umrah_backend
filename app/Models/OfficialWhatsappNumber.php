<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficialWhatsappNumber extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = ['wa_number', 'label', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
