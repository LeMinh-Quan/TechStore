<?php

namespace Tests\Feature;

use Database\Seeders\ChatbotKnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatbotConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_service_can_read_active_intent_knowledge(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.chatbot.token' => 'test-chatbot-service-token']);
        DB::table('chatbot_huan_luyen')->insert([
            'y_dinh' => 'payment',
            'cau_hoi' => 'Thanh toán như thế nào?',
            'phan_hoi' => 'Chuyển khoản trong phiên bản này chưa kết nối ngân hàng.',
            'trang_thai' => 1,
        ]);

        $this->getJson('/api/v1/internal/chatbot/knowledge/payment')
            ->assertUnauthorized();
        $this->withToken('test-chatbot-service-token')
            ->getJson('/api/v1/internal/chatbot/knowledge/payment')
            ->assertOk()
            ->assertJsonPath('data.answer', 'Chuyển khoản trong phiên bản này chưa kết nối ngân hàng.');
        $this->withToken('test-chatbot-service-token')
            ->getJson('/api/v1/internal/chatbot/knowledge/unknown')
            ->assertNotFound();
    }

    public function test_chatbot_knowledge_seeder_is_idempotent(): void
    {
        $this->artisan('db:seed', ['--class' => ChatbotKnowledgeSeeder::class])
            ->assertSuccessful();
        $this->assertSame(116, DB::table('chatbot_huan_luyen')->count());
        $this->assertDatabaseHas('chatbot_huan_luyen', [
            'y_dinh' => 'payment',
            'cau_hoi' => 'Có thanh toán bằng QR không?',
            'trang_thai' => 1,
        ]);

        $this->artisan('db:seed', ['--class' => ChatbotKnowledgeSeeder::class])
            ->assertSuccessful();
        $this->assertSame(116, DB::table('chatbot_huan_luyen')->count());
    }

    public function test_admin_can_manage_training_examples_and_chatbot_matches_similar_questions(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.chatbot.token' => 'test-chatbot-service-token']);
        DB::table('quan_tri')->insert([
            'tk_admin' => 'admin01',
            'mat_khau' => Hash::make('admin-password'),
            'ten_admin' => 'Administrator',
            'email' => 'admin@example.test',
        ]);
        $adminToken = $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'admin01',
            'password' => 'admin-password',
        ])->assertOk()->json('token');

        $this->getJson('/api/v1/admin/chatbot-training')->assertUnauthorized();
        $this->withToken($adminToken)
            ->postJson('/api/v1/admin/chatbot-training', [
                'y_dinh' => 'payment',
                'cau_hoi' => 'Thanh toán bằng cách nào?',
                'phan_hoi' => 'Cửa hàng hỗ trợ COD.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.trang_thai', true);

        $this->withToken('test-chatbot-service-token')
            ->getJson('/api/v1/internal/chatbot/knowledge/match?q=Thanh%20to%C3%A1n%20nh%C6%B0%20th%E1%BA%BF%20n%C3%A0o')
            ->assertOk()
            ->assertJsonPath('data.intent', 'payment')
            ->assertJsonPath('data.answer', 'Cửa hàng hỗ trợ COD.');

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/chatbot-training')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.cau_hoi', 'Thanh toán bằng cách nào?');

        $this->withToken($adminToken)
            ->patchJson('/api/v1/admin/chatbot-training/1', ['trang_thai' => false])
            ->assertOk()
            ->assertJsonPath('data.trang_thai', 0);
        $this->withToken('test-chatbot-service-token')
            ->getJson('/api/v1/internal/chatbot/knowledge/match?q=Thanh%20to%C3%A1n%20nh%C6%B0%20th%E1%BA%BF%20n%C3%A0o')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->withToken($adminToken)
            ->deleteJson('/api/v1/admin/chatbot-training/1')
            ->assertOk();
        $this->assertDatabaseHas('nhat_ky_hoat_dong', [
            'tk_admin' => 'admin01',
            'hanh_dong' => 'chat.training.delete',
            'chi_tiet' => '1',
        ]);
    }

    public function test_chatbot_service_can_persist_conversations_and_admin_can_reply_and_resolve(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['services.chatbot.token' => 'test-chatbot-service-token']);
        $conversationId = (string) Str::uuid();
        $this->postJson('/api/v1/internal/chatbot/conversations', ['conversation_id' => $conversationId])
            ->assertUnauthorized();

        $service = $this->withToken('test-chatbot-service-token');
        $service->postJson('/api/v1/internal/chatbot/conversations', ['conversation_id' => $conversationId])
            ->assertOk()
            ->assertJsonPath('data.status', 'bot');
        $service->postJson("/api/v1/internal/chatbot/conversations/{$conversationId}/messages", [
            'sender' => 'visitor',
            'message' => 'Cần tư vấn',
        ])->assertCreated();
        $service->postJson("/api/v1/internal/chatbot/conversations/{$conversationId}/handoff", [
            'message' => 'Mình đã chuyển câu hỏi cho nhân viên.',
        ])->assertOk();
        $service->postJson("/api/v1/internal/chatbot/conversations/{$conversationId}/messages", [
            'sender' => 'bot',
            'message' => 'Bot không được trả lời khi nhân viên đang xử lý.',
        ])->assertUnprocessable();

        $this->getJson('/api/v1/admin/conversations')->assertUnauthorized();
        DB::table('quan_tri')->insert([
            'tk_admin' => 'admin01',
            'mat_khau' => Hash::make('admin-password'),
            'ten_admin' => 'Administrator',
            'email' => 'admin@example.test',
        ]);
        DB::table('quan_tri')->insert([
            'tk_admin' => 'staff01',
            'mat_khau' => Hash::make('staff-password'),
            'ten_admin' => 'Support Staff',
            'email' => 'staff@example.test',
            'role' => 'nhan_vien',
        ]);
        $adminToken = $this->postJson('/api/v1/auth/admin/login', [
            'identifier' => 'admin01',
            'password' => 'admin-password',
        ])->assertOk()->json('token');

        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId)
            ->assertJsonPath('data.0.status', 'waiting_admin');
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/conversations?unassigned=1')
            ->assertOk()
            ->assertJsonPath('data.0.waiting_minutes', 0);
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/conversations/{$conversationId}", [
                'action' => 'assign',
                'assigned_to' => 'staff01',
            ])
            ->assertOk();
        $staffToken = $this->postJson('/api/v1/auth/staff/login', [
            'identifier' => 'staff01',
            'password' => 'staff-password',
        ])->assertOk()->json('token');
        $this->withToken($staffToken)
            ->getJson('/api/v1/staff/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.assigned_admin_id', 'staff01');
        $this->withToken($staffToken)
            ->patchJson("/api/v1/staff/conversations/{$conversationId}", [
                'action' => 'reply',
                'message' => 'Nhân viên đang hỗ trợ bạn.',
            ])
            ->assertOk();
        $this->withToken('test-chatbot-service-token')
            ->getJson("/api/v1/internal/chatbot/conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.messages.2.sender', 'admin')
            ->assertJsonPath('data.messages.2.message', 'Nhân viên đang hỗ trợ bạn.');
        $this->withToken($adminToken)
            ->patchJson("/api/v1/admin/conversations/{$conversationId}", ['action' => 'resolve'])
            ->assertOk();
        $this->withToken($adminToken)
            ->getJson('/api/v1/admin/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('nhat_ky_hoat_dong', [
            'tk_admin' => 'admin01',
            'hanh_dong' => 'chat.resolve',
            'chi_tiet' => $conversationId,
        ]);
    }
}
