<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    protected $fillable = [
        'journal_id', 'account_id', 'type', 'amount_minor',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
    ];

    public $timestamps = false;

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \RuntimeException('Ledger entries are immutable.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Ledger entries are immutable.');
        });
    }
}
