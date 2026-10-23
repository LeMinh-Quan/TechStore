<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ChatbotController;
use App\Http\Controllers\Api\V1\CustomerPromotionController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ReturnRequestController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\StoreWorkflowController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\AuthenticateChatbotService;
use App\Http\Middleware\RequireAdministrator;
use App\Http\Middleware\RequireCustomer;
use App\Http\Middleware\RequireStaffRole;
use App\Http\Middleware\VerifyCsrfOrigin;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([VerifyCsrfOrigin::class, 'throttle:api'])->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:5,1');
    Route::post('/auth/staff/login', [AuthController::class, 'staffLogin'])->middleware('throttle:5,1');
    Route::post('/auth/admin/logout', [AuthController::class, 'logout'])
        ->middleware([AuthenticateApiToken::class, RequireAdministrator::class]);

    Route::middleware([AuthenticateApiToken::class, RequireStaffRole::class])->prefix('staff')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/revenue', [StaffController::class, 'revenue']);
        Route::get('/conversations', [ChatbotController::class, 'staffConversations']);
        Route::get('/conversations/{conversationId}', [ChatbotController::class, 'staffShow']);
        Route::patch('/conversations/{conversationId}', [ChatbotController::class, 'staffUpdate']);
        Route::get('/payroll', [StaffController::class, 'payroll'])
            ->middleware(RequireStaffRole::class.':nhan_vien');
        Route::patch('/warranties/{serialNumber}', [StaffController::class, 'updateWarranty'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh');
        Route::get('/inventory', [StaffController::class, 'inventory'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh,nhan_vien');
        Route::get('/inventory/history', [StaffController::class, 'inventoryHistory'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh,nhan_vien');
        Route::get('/orders', [StaffController::class, 'orders'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh,nhan_vien');
        Route::patch('/products/{productId}/stock', [StaffController::class, 'updateStock'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh,nhan_vien');
        Route::post('/orders/{orderId}/claim', [StaffController::class, 'claimOrder'])
            ->middleware(RequireStaffRole::class.':nhan_vien');
        Route::post('/products', [AdminController::class, 'storeProduct'])
            ->middleware(RequireStaffRole::class.':chu_quan,giam_doc_chi_nhanh');
        Route::get('/products', [AdminController::class, 'products'])
            ->middleware(RequireStaffRole::class.':chu_quan,giam_doc_chi_nhanh');
        Route::patch('/products/{productId}', [AdminController::class, 'updateProduct'])
            ->middleware(RequireStaffRole::class.':chu_quan,giam_doc_chi_nhanh');
        Route::delete('/products/{productId}', [AdminController::class, 'destroyProduct'])
            ->middleware(RequireStaffRole::class.':chu_quan,giam_doc_chi_nhanh');
        Route::get('/stock-receipts', [AdminController::class, 'stockReceipts'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh');
        Route::post('/stock-receipts', [AdminController::class, 'storeStockReceipt'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh');
        Route::get('/warranties', [AdminController::class, 'warranties'])
            ->middleware(RequireStaffRole::class.':giam_doc_chi_nhanh');
    });

    Route::prefix('admin')
        ->middleware([AuthenticateApiToken::class, RequireAdministrator::class])
        ->group(function () {
            Route::get('/products', [AdminController::class, 'products']);
            Route::get('/products/trash', [AdminController::class, 'trashedProducts']);
            Route::post('/products', [AdminController::class, 'storeProduct']);
            Route::patch('/products/{productId}', [AdminController::class, 'updateProduct']);
            Route::delete('/products/{productId}', [AdminController::class, 'destroyProduct']);
            Route::post('/products/{productId}/restore', [AdminController::class, 'restoreProduct']);
            Route::delete('/products/{productId}/force', [AdminController::class, 'forceDeleteProduct']);
            Route::get('/vouchers', [AdminController::class, 'vouchers']);
            Route::post('/vouchers', [AdminController::class, 'storeVoucher']);
            Route::patch('/vouchers/{code}', [AdminController::class, 'updateVoucher']);
            Route::get('/stock-receipts', [AdminController::class, 'stockReceipts']);
            Route::post('/stock-receipts', [AdminController::class, 'storeStockReceipt']);
            Route::get('/warranties', [AdminController::class, 'warranties']);
            Route::get('/customers', [AdminController::class, 'customers']);
            Route::get('/branches', [AdminController::class, 'branches']);
            Route::post('/branches', [AdminController::class, 'storeBranch']);
            Route::put('/payroll/{employeeId}/{year}/{month}', [AdminController::class, 'updatePayroll']);
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::get('/reports', [AdminController::class, 'reports']);
            Route::get('/returns', [ReturnRequestController::class, 'adminIndex']);
            Route::patch('/returns/{requestId}', [ReturnRequestController::class, 'adminUpdate'])->whereNumber('requestId');
            Route::get('/education-discounts', [CustomerPromotionController::class, 'adminEducationRequests']);
            Route::get('/education-discounts/{customerId}/evidence', [CustomerPromotionController::class, 'adminEducationEvidence']);
            Route::patch('/education-discounts/{customerId}', [CustomerPromotionController::class, 'reviewEducationRequest']);
            Route::get('/orders/{orderId}', [AdminController::class, 'showOrder']);
            Route::patch('/orders/{orderId}', [AdminController::class, 'updateOrder']);
            Route::get('/conversations', [ChatbotController::class, 'adminConversations']);
            Route::get('/support-staff', [ChatbotController::class, 'adminSupportStaff']);
            Route::get('/conversations/{conversationId}', [ChatbotController::class, 'adminShow']);
            Route::patch('/conversations/{conversationId}', [ChatbotController::class, 'adminUpdate']);
            Route::get('/chatbot-training', [ChatbotController::class, 'adminTraining']);
            Route::post('/chatbot-training', [ChatbotController::class, 'storeTrainingExample']);
            Route::patch('/chatbot-training/{id}', [ChatbotController::class, 'updateTrainingExample'])
                ->whereNumber('id');
            Route::delete('/chatbot-training/{id}', [ChatbotController::class, 'deleteTrainingExample'])
                ->whereNumber('id');
        });

    Route::middleware('throttle:120,1')->group(function () {
        Route::get('/categories', [CatalogController::class, 'categories']);
        Route::get('/brands', [CatalogController::class, 'brands']);
        Route::get('/filters', [CatalogController::class, 'filters']);
        Route::get('/products', [CatalogController::class, 'index']);
        Route::get('/products/{slug}', [CatalogController::class, 'show']);
        Route::get('/products/{slug}/reviews', [StoreWorkflowController::class, 'reviews']);
    });

    Route::middleware([AuthenticateApiToken::class, RequireCustomer::class])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/merge', [CartController::class, 'merge']);
        Route::post('/cart/items', [CartController::class, 'add']);
        Route::patch('/cart/items/{productId}', [CartController::class, 'update']);
        Route::delete('/cart/items/{productId}', [CartController::class, 'remove']);
        Route::get('/addresses', [StoreWorkflowController::class, 'addresses']);
        Route::post('/addresses', [StoreWorkflowController::class, 'storeAddress']);
        Route::delete('/addresses/{label}', [StoreWorkflowController::class, 'deleteAddress']);
        Route::post('/vouchers/validate', [StoreWorkflowController::class, 'validateVoucher']);
        Route::get('/vouchers', [CustomerPromotionController::class, 'vouchers']);
        Route::post('/vouchers/{code}/claim', [CustomerPromotionController::class, 'claimVoucher'])->where('code', '[A-Za-z0-9_-]+');
        Route::get('/education-discount', [CustomerPromotionController::class, 'educationStatus']);
        Route::post('/education-discount/request-code', [CustomerPromotionController::class, 'requestEducationCode'])->middleware('throttle:5,1');
        Route::post('/education-discount/verify-code', [CustomerPromotionController::class, 'verifyEducationCode'])->middleware('throttle:10,1');
        Route::post('/products/{productId}/reviews', [StoreWorkflowController::class, 'storeReview']);
        Route::get('/warranties', [OrderController::class, 'warranties']);
        Route::get('/returns', [ReturnRequestController::class, 'index']);
        Route::post('/orders/{orderId}/returns', [ReturnRequestController::class, 'store']);
        Route::get('/notifications', [OrderController::class, 'notifications']);
        Route::patch('/notifications/read-all', [OrderController::class, 'readAllNotifications']);
        Route::patch('/notifications/{notificationId}/read', [OrderController::class, 'readNotification'])
            ->whereNumber('notificationId');

        Route::post('/orders', [OrderController::class, 'checkout']);
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{orderId}', [OrderController::class, 'show']);
        Route::post('/orders/{orderId}/cancel', [OrderController::class, 'cancel']);
    });

    Route::prefix('internal/chatbot')
        ->middleware(['throttle:60,1', AuthenticateChatbotService::class])
        ->group(function () {
            Route::get('/knowledge/match', [ChatbotController::class, 'matchKnowledge']);
            Route::get('/products', [CatalogController::class, 'chatbotSearch']);
            Route::get('/knowledge/{intent}', [ChatbotController::class, 'knowledge']);
            Route::post('/conversations', [ChatbotController::class, 'createConversation']);
            Route::post('/conversations/{conversationId}/messages', [ChatbotController::class, 'addMessage']);
            Route::post('/conversations/{conversationId}/handoff', [ChatbotController::class, 'requestHandoff']);
            Route::get('/conversations/{conversationId}', [ChatbotController::class, 'internalHistory']);
        });
});
