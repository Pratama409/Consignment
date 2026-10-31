<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Department;
use App\Models\Location;
use App\Models\Vendor;

class ReferenceResource
{
    public static function definition(string $resource): array
    {
        return match ($resource) {
            'vendors' => ['model' => Vendor::class, 'table' => 'vendors', 'code' => 'vendor_code', 'name' => 'vendor_name', 'label' => 'Vendor', 'usage_relations' => ['vendorItems']],
            'locations' => ['model' => Location::class, 'table' => 'locations', 'code' => 'location_code', 'name' => 'location_name', 'label' => 'Lokasi', 'usage_relations' => ['vendorItems', 'stockItemLocations']],
            'categories' => ['model' => Category::class, 'table' => 'categories', 'code' => 'code', 'name' => 'name', 'label' => 'Kategori', 'usage_relations' => ['items']],
            'departments' => ['model' => Department::class, 'table' => 'departments', 'code' => 'department_code', 'name' => 'department_name', 'label' => 'Departemen', 'usage_relations' => ['materialRequests']],
            default => abort(404),
        };
    }
}
