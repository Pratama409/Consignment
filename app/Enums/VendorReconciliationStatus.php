<?php

namespace App\Enums;

enum VendorReconciliationStatus: string
{
    case Draft = 'DRAFT';
    case VendorReview = 'VENDOR_REVIEW';
    case Agreed = 'AGREED';
    case Disputed = 'DISPUTED';
    case Resolved = 'RESOLVED';
    case Final = 'FINAL';
}
