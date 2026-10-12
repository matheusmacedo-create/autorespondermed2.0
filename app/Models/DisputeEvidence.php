<?php

namespace App\Models;

use App\Enums\EvidenceKind;
use Database\Factories\DisputeEvidenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DisputeEvidence extends Model
{
    /** @use HasFactory<DisputeEvidenceFactory> */
    use HasFactory;

    protected $table = 'dispute_evidences';

    protected $fillable = [
        'dispute_id',
        'kind',
        'description',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
        'correlation_id',
        'woovi_file_id',
        'woovi_url',
        'sent_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'kind' => EvidenceKind::class,
            'size' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $evidence): void {
            $evidence->correlation_id ??= (string) Str::uuid();
        });
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function contents(): string
    {
        return (string) Storage::disk($this->disk)->get($this->path);
    }

    /**
     * Descrição que vai para a Woovi: o tipo e o que o seller escreveu.
     */
    public function wooviDescription(): string
    {
        return Str::limit($this->kind->label().': '.$this->description, 500, '');
    }
}
