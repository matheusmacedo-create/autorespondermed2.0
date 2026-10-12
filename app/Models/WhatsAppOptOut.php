<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Telefone que pediu para parar de receber WhatsApp do MED. Vale para sempre: o
 * seller continua avisado por e-mail.
 */
class WhatsAppOptOut extends Model
{
    protected $table = 'whatsapp_opt_outs';

    protected $fillable = ['recipient', 'message', 'requested_at'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime'];
    }

    public static function blocks(string $recipient): bool
    {
        return self::query()->where('recipient', $recipient)->exists();
    }
}
