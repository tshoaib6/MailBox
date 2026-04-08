<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactList extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'name', 'columns'];

    protected $casts = [
        'columns' => 'json',
    ];

    /**
     * The workspace this contact list belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(\Sendportal\Base\Models\Workspace::class);
    }

    /**
     * Column mappings for this list (CSV column → merge variable).
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(ContactListColumnMapping::class);
    }

    /**
     * All subscribers in this list.
     */
    public function subscribers(): HasMany
    {
        return $this->hasMany(\Sendportal\Base\Models\Subscriber::class);
    }
}
