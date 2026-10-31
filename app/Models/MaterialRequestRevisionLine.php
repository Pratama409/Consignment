<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestRevisionLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestRevision::class, 'material_request_revision_id');
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestLine::class, 'material_request_line_id');
    }
}
