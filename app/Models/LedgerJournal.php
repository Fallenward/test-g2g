<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerJournal extends Model
{
    protected $fillable = [
        'type', 'order_id', 'idempotency_key',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'journal_id');
    }
}
