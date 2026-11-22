<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const string SUPER_ADMIN = 'super-admin';

    /** @var list<string> */
    public const array PANEL_ROLES = [
        'admin',
        self::SUPER_ADMIN,
        'coordinador',
        'ayudante',
    ];

    protected $fillable = [
        'name',
        'guard_name',
    ];
}
