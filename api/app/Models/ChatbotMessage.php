<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotMessage extends Model
{
    protected $table = 'chatbot_messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'sender',
        'message',
        'created_at',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id', 'id');
    }
}
