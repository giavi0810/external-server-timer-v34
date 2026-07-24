<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreshdeskGroup extends Model
{
    protected $table = 'freshdesk_groups';
    protected $primaryKey = 'group_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'group_id',
        'name',
        'main_layer',
        'is_active',
        'is_default_assignment',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default_assignment' => 'boolean',
    ];

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'group_id', 'group_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }
}
