<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Vendor;

class ReferenceResource
{
    public static function definition(string $resource): array
    {
        return match ($resource) {
            'vendors' => ['model' => Vendor::class, 'table' => 'vendors', 'code' => 'vendor_code', 'name' => 'vendor_name', 'label' => 'Vendor'],
            'locations' => ['model' => Location::class, 'table' => 'locations', 'code' => 'location_code', 'name' => 'location_name', 'label' => 'Lokasi'],
            default => abort(404),
        };
    }
}
