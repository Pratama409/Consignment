<?php

namespace App\Actions\Procurement;

use App\Models\ProcurementDocument;
use App\Models\ProcurementRequirement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class StoreProcurementDocument
{
    public function handle(ProcurementRequirement $r, UploadedFile $file, string $type, bool $visible, User $actor): ProcurementDocument
    {
        if (! in_array(strtolower($file->extension()), ['pdf', 'xls', 'xlsx', 'png', 'jpg', 'jpeg']) || $file->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'File harus PDF, Excel, atau gambar maksimal 10 MB.']);
        }$version = (int) $r->documents()->where('type', $type)->max('version') + 1;
        $path = $file->store('procurement/'.date('Y/m'), 'private');

        return $r->documents()->create(['type' => $type, 'original_name' => $file->getClientOriginalName(), 'disk' => 'private', 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()), 'version' => $version, 'is_vendor_visible' => $visible, 'uploaded_by' => $actor->id]);
    }
}
