<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Team extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_name',
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

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(TeamRoleAssignment::class);
    }

    public function heads(): HasManyThrough
    {
        return $this->hasManyThrough(
            User::class,
            TeamRoleAssignment::class,
            'team_id',
            'id',
            'id',
            'user_id',
        )->where('team_role_assignments.team_role', 'head')->where('team_role_assignments.is_active', true);
    }
}
