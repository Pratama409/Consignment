<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends MasterDataModel
{
    protected $fillable = ['department_code', 'department_name', 'is_active'];

    protected function codeColumn(): ?string
    {
        return 'department_code';
    }

    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
    }
}
