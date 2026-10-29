<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Warehouse = 'warehouse';
    case Vendor = 'vendor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Warehouse => 'Warehouse',
            self::Vendor => 'Vendor',
        };
    }
}
