<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Vendor extends MasterDataModel
{
    protected $fillable = ['vendor_code', 'vendor_name', 'pic_name', 'is_active'];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $vendor): void {
            if (mb_strtoupper(trim((string) $vendor->vendor_code)) === 'WAREHOUSE'
                || mb_strtoupper(trim((string) $vendor->vendor_name)) === 'WAREHOUSE') {
                throw ValidationException::withMessages(['vendor_code' => 'WAREHOUSE adalah pihak internal, bukan Vendor.']);
            }

            if ($vendor->exists && $vendor->isDirty('is_active') && ! $vendor->is_active
                && $vendor->vendorItems()->active()->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Nonaktifkan atau ganti hubungan Vendor yang masih aktif terlebih dahulu.']);
            }
        });
    }

    protected function codeColumn(): ?string
    {
        return 'vendor_code';
    }

    public function vendorItems(): HasMany
    {
        return $this->hasMany(VendorItem::class);
    }
}
