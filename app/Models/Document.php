<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'uploaded_by',
        'title',
        'file_path',
        'doc_type',
        'is_encrypted',
        'mime_type',
        'byte_size',
        'checksum_sha256',
        'is_signed',
    ];

    protected function casts(): array
    {
        return [
            'is_signed' => 'boolean',
            'is_encrypted' => 'boolean',
            'byte_size' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
