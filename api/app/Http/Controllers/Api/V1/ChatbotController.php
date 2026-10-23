<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChatbotController extends Controller
{
    private const KNOWLEDGE_INTENTS = ['greeting', 'payment', 'checkout', 'order', 'delivery', 'warranty'];

    public function knowledge(string $intent, ChatbotService $chatbot): JsonResponse
    {
        abort_unless(in_array($intent, ['greeting', 'payment', 'checkout', 'order', 'delivery', 'warranty'], true), 404);

        return response()->json(['data' => ['answer' => $chatbot->knowledgeAnswer($intent)]]);
    }

    public function matchKnowledge(Request $request, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:500']]);

        return response()->json(['data' => $chatbot->matchKnowledge($data['q'])]);
    }

    public function adminTraining(Request $request, ChatbotService $chatbot): JsonResponse
    {
        $filters = $request->validate([
            'intent' => ['sometimes', 'in:'.implode(',', self::KNOWLEDGE_INTENTS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $examples = $chatbot->trainingExamples($filters);

        return response()->json([
            'data' => $examples->getCollection()->values(),
            'meta' => [
                'current_page' => $examples->currentPage(),
                'last_page' => $examples->lastPage(),
                'total' => $examples->total(),
            ],
        ]);
    }

    public function storeTrainingExample(Request $request, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate([
            'y_dinh' => ['required', 'in:'.implode(',', self::KNOWLEDGE_INTENTS)],
            'cau_hoi' => ['required', 'string', 'min:3', 'max:500'],
            'phan_hoi' => ['required', 'string', 'min:2', 'max:2000'],
            'trang_thai' => ['sometimes', 'boolean'],
        ]);
        $data['trang_thai'] = $data['trang_thai'] ?? true;
        $entry = $chatbot->createTrainingExample(
            $data,
            $request->attributes->get('api_account')['id'],
            $request->ip()
        );

        return response()->json(['data' => $entry], 201);
    }

    public function updateTrainingExample(Request $request, int $id, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate([
            'y_dinh' => ['sometimes', 'in:'.implode(',', self::KNOWLEDGE_INTENTS)],
            'cau_hoi' => ['sometimes', 'required', 'string', 'min:3', 'max:500'],
            'phan_hoi' => ['sometimes', 'required', 'string', 'min:2', 'max:2000'],
            'trang_thai' => ['sometimes', 'boolean'],
        ]);
        $entry = $chatbot->updateTrainingExample(
            $id,
            $data,
            $request->attributes->get('api_account')['id'],
            $request->ip()
        );
        if (! $entry) {
            return response()->json(['message' => 'Không tìm thấy ví dụ huấn luyện.'], 404);
        }

        return response()->json(['data' => $entry]);
    }

    public function deleteTrainingExample(Request $request, int $id, ChatbotService $chatbot): JsonResponse
    {
        if (! $chatbot->deleteTrainingExample(
            $id,
            $request->attributes->get('api_account')['id'],
            $request->ip()
        )) {
            return response()->json(['message' => 'Không tìm thấy ví dụ huấn luyện.'], 404);
        }

        return response()->json(['message' => 'Đã xóa ví dụ huấn luyện.']);
    }

    public function createConversation(Request $request, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate(['conversation_id' => ['required', 'uuid']]);

        return response()->json(['data' => $chatbot->createConversation($data['conversation_id'])]);
    }

    public function addMessage(Request $request, string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate([
            'sender' => ['required', 'in:visitor,bot'],
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        $result = $chatbot->addMessage($conversationId, $data);

        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy hội thoại.'], 404);
        }

        if (isset($result['closed'])) {
            return response()->json(['message' => 'Hội thoại đã kết thúc.', 'data' => $result], 409);
        }

        return response()->json(['data' => $result], 201);
    }

    public function requestHandoff(Request $request, string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:1', 'max:2000']]);
        $result = $chatbot->requestHandoff($conversationId, $data['message']);

        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy hội thoại.'], 404);
        }

        if (isset($result['closed'])) {
            return response()->json(['message' => 'Hội thoại đã kết thúc.'], 409);
        }

        return response()->json(['data' => $result]);
    }

    public function internalHistory(string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $history = $chatbot->conversationHistory($conversationId);
        if ($history === null) {
            return response()->json(['message' => 'Không tìm thấy hội thoại.'], 404);
        }

        return response()->json(['data' => $this->historyData($history)]);
    }

    public function adminConversations(Request $request, ChatbotService $chatbot): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'in:bot,waiting_admin,closed'],
            'assigned_to' => ['sometimes', 'string', 'max:50'],
            'unassigned' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $conversations = $chatbot->adminConversations($filters);

        return response()->json([
            'data' => $conversations->getCollection()->values(),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    public function adminSupportStaff(ChatbotService $chatbot): JsonResponse
    {
        return response()->json(['data' => $chatbot->supportStaff()]);
    }

    public function adminShow(string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $history = $chatbot->conversationHistory($conversationId);
        if ($history === null) {
            return response()->json(['message' => 'Không tìm thấy hội thoại.'], 404);
        }

        return response()->json(['data' => $this->historyData($history)]);
    }

    public function adminUpdate(Request $request, string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:reply,resolve,assign'],
            'message' => ['required_if:action,reply', 'nullable', 'string', 'min:1', 'max:2000'],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'max:20', Rule::exists('quan_tri', 'tk_admin')],
        ]);
        $result = $chatbot->updateConversation(
            $conversationId,
            $data,
            $request->attributes->get('api_account')['id'],
            $request->ip()
        );

        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy hội thoại.'], 404);
        }

        $message = match ($result['action']) {
            'reply' => 'Đã gửi phản hồi cho khách hàng.',
            'assign' => 'Đã cập nhật nhân viên phụ trách.',
            default => 'Đã kết thúc hội thoại.',
        };

        return response()->json(['message' => $message]);
    }

    public function staffConversations(Request $request, ChatbotService $chatbot): JsonResponse
    {
        $accountId = $request->attributes->get('api_account')['id'];
        $conversations = $chatbot->adminConversations([
            'available_to' => $accountId,
            'per_page' => 50,
        ]);

        return response()->json([
            'data' => $conversations->getCollection()->values(),
            'meta' => ['current_page' => $conversations->currentPage(), 'last_page' => $conversations->lastPage(), 'total' => $conversations->total()],
        ]);
    }

    public function staffShow(Request $request, string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $accountId = $request->attributes->get('api_account')['id'];
        $history = $chatbot->conversationHistory($conversationId);
        if ($history === null || $history['conversation']->assigned_admin_id !== $accountId) {
            return response()->json(['message' => 'Không tìm thấy hội thoại được giao cho bạn.'], 404);
        }

        return response()->json(['data' => $this->historyData($history)]);
    }

    public function staffUpdate(Request $request, string $conversationId, ChatbotService $chatbot): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:claim,reply,resolve'],
            'message' => ['required_if:action,reply', 'nullable', 'string', 'min:1', 'max:2000'],
        ]);
        $accountId = $request->attributes->get('api_account')['id'];
        $result = $data['action'] === 'claim'
            ? $chatbot->claimConversation($conversationId, $accountId, $request->ip())
            : $chatbot->staffUpdateConversation($conversationId, $data, $accountId, $request->ip());
        if (isset($result['not_found'])) {
            return response()->json(['message' => 'Không tìm thấy hội thoại.'], 404);
        }
        if (isset($result['forbidden']) || isset($result['conflict'])) {
            return response()->json(['message' => 'Hội thoại đã được giao cho người khác hoặc không còn khả dụng.'], 409);
        }
        if (isset($result['closed'])) {
            return response()->json(['message' => 'Hội thoại đã kết thúc.'], 409);
        }

        return response()->json(['message' => $data['action'] === 'claim' ? 'Đã nhận xử lý hội thoại.' : 'Đã cập nhật hội thoại.']);
    }

    private function historyData(array $history): array
    {
        return [
            'id' => $history['conversation']->id,
            'status' => $history['conversation']->status,
            'messages' => $history['messages'],
        ];
    }
}
