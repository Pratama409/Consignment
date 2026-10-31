<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A SAP document number for one stage, shared by every requirement that cites it. */
class ProcurementReference extends Model
{
    use HasUuids;

    protected $fillable = ['stage', 'document_no'];

    public function lines(): HasMany
    {
        return $this->hasMany(ProcurementDocumentLine::class);
    }
}
