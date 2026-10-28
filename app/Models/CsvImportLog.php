<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsvImportLog extends Model
{
    use HasFactory;

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_SKIPPED = 'skipped';

    public const ACTION_FAILED = 'failed';

    protected $fillable = [
        'csv_import_id',
        'row_number',
        'file_name',
        'sku',
        'product_title',
        'action',
        'reason',
        'error_message',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(CsvImport::class, 'csv_import_id');
    }
}
