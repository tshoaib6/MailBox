<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactListColumnMapping extends Model
{
    protected $fillable = ['contact_list_id', 'csv_column', 'merge_variable'];

    public $timestamps = true;

    /**
     * The contact list this mapping belongs to.
     */
    public function contactList(): BelongsTo
    {
        return $this->belongsTo(ContactList::class);
    }
}
