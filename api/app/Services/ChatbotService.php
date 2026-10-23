<?php

namespace App\Services;

use App\Models\ChatbotConversation;
use App\Models\ChatbotKnowledge;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatbotService
{
    private const KNOWLEDGE_INTENTS = ['greeting', 'payment', 'checkout', 'order', 'delivery', 'warranty'];

    public function knowledgeAnswer(string $intent): ?string
    {
        if (! Schema::hasTable('chatbot_huan_luyen')) {
            return null;
        }

        return ChatbotKnowledge::query()
            ->where('y_dinh', $intent)
            ->where('trang_thai', 1)
            ->whereNotNull('phan_hoi')
            ->where('phan_hoi', '<>', '')
            ->value('phan_hoi');
    }

    public function matchKnowledge(string $question): ?array
    {
        if (! Schema::hasTable('chatbot_huan_luyen')) {
            return null;
        }

        $questionTokens = $this->knowledgeTokens($question);
        if ($questionTokens === []) {
            return null;
        }

        $bestMatch = null;
        foreach (ChatbotKnowledge::query()
            ->where('trang_thai', 1)
            ->whereIn('y_dinh', self::KNOWLEDGE_INTENTS)
            ->whereNotNull('phan_hoi')
            ->where('phan_hoi', '<>', '')
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'y_dinh', 'cau_hoi', 'phan_hoi']) as $entry) {
            $entryTokens = $this->knowledgeTokens($entry->cau_hoi);
            if ($entryTokens === []) {
                continue;
            }

            $overlap = count(array_intersect($questionTokens, $entryTokens));
            if ($overlap < 2 && ! (count($questionTokens) <= 2 && count($entryTokens) <= 3 && $overlap === 1)) {
                continue;
            }

            $score = (2 * $overlap) / (count($questionTokens) + count($entryTokens));
            if ($score < 0.55 || ($bestMatch !== null && $score <= $bestMatch['score'])) {
                continue;
            }

            $bestMatch = [
                'intent' => $entry->y_dinh,
                'answer' => $entry->phan_hoi,
                'score' => round($score, 3),
            ];
        }

        return $bestMatch;
    }

    public function trainingExamples(array $filters): LengthAwarePaginator
    {
        return ChatbotKnowledge::query()
            ->when(isset($filters['intent']), fn ($query) => $query->where('y_dinh', $filters['intent']))
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    public function createTrainingExample(array $data, string $adminId, ?string $ipAddress): ChatbotKnowledge
    {
        $entry = ChatbotKnowledge::query()->create([
            ...$data,
            'thoi_gian_tao' => now(),
        ]);
        $this->auditTrainingChange($adminId, 'chat.training.create', (string) $entry->id, $ipAddress);

        return $entry;
    }

    public function updateTrainingExample(int $id, array $data, string $adminId, ?string $ipAddress): ?ChatbotKnowledge
    {
        $entry = ChatbotKnowledge::query()->find($id);
        if (! $entry) {
            return null;
        }

        $entry->fill($data)->save();
        $this->auditTrainingChange($adminId, 'chat.training.update', (string) $entry->id, $ipAddress);

        return $entry->refresh();
    }

    public function deleteTrainingExample(int $id, string $adminId, ?string $ipAddress): bool
    {
        $entry = ChatbotKnowledge::query()->find($id);
        if (! $entry) {
            return false;
        }

        $entry->delete();
        $this->auditTrainingChange($adminId, 'chat.training.delete', (string) $id, $ipAddress);

        return true;
    }

    private function knowledgeTokens(string $text): array
    {
        $normalized = Str::ascii(Str::lower($text));
        $normalized = str_replace('đ', 'd', $normalized);
        $tokens = preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = ['bao', 'bang', 'cho', 'co', 'cua', 'gi', 'hang', 'la', 'nao', 'nhu', 'shop', 'the', 'toi', 'va'];

        return array_values(array_unique(array_filter(
            $tokens,
            fn (string $token) => strlen($token) > 1 && ! in_array($token, $stopWords, true)
        )));
    }

    private function auditTrainingChange(string $adminId, string $action, string $details, ?string $ipAddress): void
    {
        DB::table('nhat_ky_hoat_dong')->insert([
            'tk_admin' => $adminId,
            'hanh_dong' => $action,
            'chi_tiet' => $details,
            'dia_chi_ip' => $ipAddress,
            'thoi_gian_tao' => now(),
        ]);
    }

    public function createConversation(string $conversationId): object
    {
        return ChatbotConversation::query()->firstOrCreate(
            ['id' => $conversationId],
            ['status' => 'bot'],
        );
    }

    public function addMessage(string $conversationId, array $data): array
    {
        return DB::transaction(function () use ($conversationId, $data): array {
            $conversation = ChatbotConversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                return ['not_found' => true];
            }
            if ($conversation->status === 'closed') {
                return ['closed' => true, 'status' => $conversation->status];
            }
            if ($data['sender'] === 'bot' && $conversation->status === 'waiting_admin') {
                throw ValidationException::withMessages(['message' => ['Hội thoại đang chờ nhân viên hỗ trợ.']]);
            }

            $message = $conversation->messages()->create([
                'conversation_id' => $conversationId,
                'sender' => $data['sender'],
                'message' => $data['message'],
                'created_at' => now(),
            ]);
            $conversation->touch();

            return ['id' => $message->id, 'status' => $conversation->status];
        });
    }

    public function requestHandoff(string $conversationId, string $message): array
    {
        return DB::transaction(function () use ($conversationId, $message): array {
            $conversation = ChatbotConversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                return ['not_found' => true];
            }
            if ($conversation->status === 'closed') {
                return ['closed' => true];
            }
            if ($conversation->status === 'waiting_admin') {
                return ['status' => 'waiting_admin'];
            }

            $conversation->status = 'waiting_admin';
            $conversation->save();
            $conversation->messages()->create([
                'conversation_id' => $conversationId,
                'sender' => 'bot',
                'message' => $message,
                'created_at' => now(),
            ]);

            return ['status' => 'waiting_admin'];
        });
    }

    public function conversationHistory(string $conversationId): ?array
    {
        $conversation = ChatbotConversation::query()->whereKey($conversationId)->first();
        if (! $conversation) {
            return null;
        }

        return [
            'conversation' => $conversation,
            'messages' => $conversation->messages()
                ->orderBy('id')
                ->limit(200)
                ->get(['id', 'sender', 'message', 'created_at']),
        ];
    }

    public function adminConversations(array $filters): LengthAwarePaginator
    {
        $conversations = DB::table('chatbot_conversations as c')
            ->select(['c.id', 'c.status', 'c.created_at', 'c.updated_at'])
            ->addSelect('c.assigned_admin_id')
            ->selectSub(
                DB::table('chatbot_messages')->select('created_at')
                    ->whereColumn('conversation_id', 'c.id')
                    ->where('sender', 'visitor')
                    ->orderByDesc('id')->limit(1),
                'last_customer_message_at'
            )
            ->selectSub(
                DB::table('chatbot_messages')->select('created_at')
                    ->whereColumn('conversation_id', 'c.id')
                    ->where('sender', 'admin')
                    ->orderByDesc('id')->limit(1),
                'last_staff_message_at'
            )
            ->selectSub(
                DB::table('chatbot_messages')->select('message')
                    ->whereColumn('conversation_id', 'c.id')
                    ->orderByDesc('id')->limit(1),
                'latest_message'
            )
            ->selectSub(
                DB::table('chatbot_messages')->select('sender')
                    ->whereColumn('conversation_id', 'c.id')
                    ->orderByDesc('id')->limit(1),
                'latest_sender'
            )
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('c.status', $filters['status']),
                fn ($query) => $query->where('c.status', 'waiting_admin')
            )
            ->when(isset($filters['assigned_to']), fn ($query) => $query->where('c.assigned_admin_id', $filters['assigned_to']))
            ->when(isset($filters['available_to']), fn ($query) => $query->where(fn ($assigned) => $assigned->whereNull('c.assigned_admin_id')->orWhere('c.assigned_admin_id', $filters['available_to'])))
            ->when(($filters['unassigned'] ?? false) === true, fn ($query) => $query->whereNull('c.assigned_admin_id'))
            ->orderByDesc('c.updated_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        $conversations->getCollection()->transform(function ($conversation): object {
            $customerIsWaiting = $conversation->last_customer_message_at
                && (! $conversation->last_staff_message_at || $conversation->last_customer_message_at > $conversation->last_staff_message_at);
            $conversation->waiting_minutes = $conversation->status === 'waiting_admin' && $customerIsWaiting
                ? max(0, (int) now()->diffInMinutes($conversation->last_customer_message_at))
                : 0;

            return $conversation;
        });

        return $conversations;
    }

    public function updateConversation(string $conversationId, array $data, string $adminId, ?string $ipAddress): array
    {
        $result = DB::transaction(function () use ($data, $conversationId): array {
            $conversation = ChatbotConversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                return ['not_found' => true];
            }
            if ($conversation->status === 'closed') {
                throw ValidationException::withMessages(['action' => ['Hội thoại đã kết thúc.']]);
            }

            if ($data['action'] === 'assign') {
                $conversation->assigned_admin_id = $data['assigned_to'] ?? null;
                $conversation->save();

                return ['action' => 'assign'];
            }

            if ($data['action'] === 'reply') {
                $conversation->messages()->create([
                    'conversation_id' => $conversationId,
                    'sender' => 'admin',
                    'message' => $data['message'],
                    'created_at' => now(),
                ]);
                $conversation->status = 'waiting_admin';
                $conversation->save();

                return ['action' => 'reply'];
            }

            $conversation->status = 'closed';
            $conversation->save();
            if (! empty($data['message'])) {
                $conversation->messages()->create([
                    'conversation_id' => $conversationId,
                    'sender' => 'admin',
                    'message' => $data['message'],
                    'created_at' => now(),
                ]);
            }

            return ['action' => 'resolve'];
        });

        if (! isset($result['not_found'])) {
            DB::table('nhat_ky_hoat_dong')->insert([
                'tk_admin' => $adminId,
                'hanh_dong' => 'chat.'.$data['action'],
                'chi_tiet' => $conversationId,
                'dia_chi_ip' => $ipAddress,
                'thoi_gian_tao' => now(),
            ]);
        }

        return $result;
    }

    public function supportStaff(): Collection
    {
        return DB::table('quan_tri')
            ->whereIn('role', ['chu_quan', 'chu_doanh_nghiep', 'giam_doc_chi_nhanh', 'nhan_vien'])
            ->orderBy('ten_admin')
            ->get(['tk_admin as id', 'ten_admin as name', 'role']);
    }

    public function claimConversation(string $conversationId, string $adminId, ?string $ipAddress): array
    {
        $result = DB::transaction(function () use ($conversationId, $adminId): array {
            $conversation = ChatbotConversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                return ['not_found' => true];
            }
            if ($conversation->status === 'closed' || ($conversation->assigned_admin_id && $conversation->assigned_admin_id !== $adminId)) {
                return ['conflict' => true];
            }
            $conversation->assigned_admin_id = $adminId;
            $conversation->save();

            return ['claimed' => true];
        });
        if (isset($result['claimed'])) {
            $this->auditConversation('chat.claim', $conversationId, $adminId, $ipAddress);
        }

        return $result;
    }

    public function staffUpdateConversation(string $conversationId, array $data, string $adminId, ?string $ipAddress): array
    {
        $result = DB::transaction(function () use ($conversationId, $data, $adminId): array {
            $conversation = ChatbotConversation::query()->whereKey($conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                return ['not_found' => true];
            }
            if ($conversation->status === 'closed') {
                return ['closed' => true];
            }
            if ($conversation->assigned_admin_id !== $adminId) {
                return ['forbidden' => true];
            }
            if ($data['action'] === 'reply') {
                $conversation->messages()->create([
                    'conversation_id' => $conversationId,
                    'sender' => 'admin',
                    'message' => $data['message'],
                    'created_at' => now(),
                ]);
                $conversation->status = 'waiting_admin';
                $conversation->save();

                return ['action' => 'reply'];
            }

            $conversation->status = 'closed';
            $conversation->save();

            return ['action' => 'resolve'];
        });
        if (isset($result['action'])) {
            $this->auditConversation('chat.'.$data['action'], $conversationId, $adminId, $ipAddress);
        }

        return $result;
    }

    private function auditConversation(string $action, string $conversationId, string $adminId, ?string $ipAddress): void
    {
        DB::table('nhat_ky_hoat_dong')->insert([
            'tk_admin' => $adminId,
            'hanh_dong' => $action,
            'chi_tiet' => $conversationId,
            'dia_chi_ip' => $ipAddress,
            'thoi_gian_tao' => now(),
        ]);
    }
}
