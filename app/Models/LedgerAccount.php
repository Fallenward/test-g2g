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
        static::saving(function (LedgerAccount $account): void {
            $account->owner_key = $account->ownerKey();
        });
    }

    /**
     * Identity stored in owner_key. Null owners use 0. The database trigger
     * uses the same string; SQLite cannot assign NEW columns, so the model sets it.
     */
    public function ownerKey(): string
    {
        $ownerId = $this->owner_id === null ? '0' : (string) $this->owner_id;

        return $this->owner_type.':'.$ownerId.':'.$this->code.':'.strtoupper((string) $this->currency);
    }
}
