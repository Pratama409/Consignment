<?php

namespace App\Enums;

enum OwnershipType: string
{
    case Stock = 'STOCK';
    case Consignment = 'CONSIGNMENT';
}
