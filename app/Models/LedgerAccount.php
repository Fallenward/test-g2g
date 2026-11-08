<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'owner_type', 'code', 'currency',
    ];

    protected $casts = [
        'owner_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (LedgerAccount $account): void {
            $account->owner_key = $account->ownerKey();
        });
    }

    /**
     * Stable identity for the unique index. A null owner_id (platform
     * accounts) is stored as 0 so MySQL and SQLite cannot insert duplicates.
     */
    public function ownerKey(): string
    {
        $ownerId = $this->owner_id === null ? '0' : (string) $this->owner_id;

        return $this->owner_type.':'.$ownerId.':'.$this->code.':'.strtoupper((string) $this->currency);
    }
}
