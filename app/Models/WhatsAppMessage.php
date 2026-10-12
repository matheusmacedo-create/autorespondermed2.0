<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensagem de WhatsApp (Kapso) enviada ao seller por causa de um MED.
 */
class WhatsAppMessage extends Model
{
    public const TYPE_TEMPLATE = 'template';

    public const TYPE_LINK = 'link';

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'dispute_id',
        'type',
        'recipient',
        'wamid',
        'status',
        'delivery_status',
        'error',
        'replied_at',
        'link_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
            'link_sent_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
