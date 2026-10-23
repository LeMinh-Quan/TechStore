<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotConversation extends Model
{
    protected $table = 'chatbot_conversations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'status',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id', 'id');
    }
}
