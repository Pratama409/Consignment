<?php

namespace App\Enums;

enum TrackingType: string
{
    case Quantity = 'QUANTITY';
    case Serialized = 'SERIALIZED';
}
