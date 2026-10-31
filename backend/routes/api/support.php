<?php

use App\Http\Controllers\Api\V1\Hellom\SupportController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->middleware('throttle:hellom-support')->group(function () {
    Route::get('/categories', [SupportController::class, 'categories']);
    Route::get('/tickets', [SupportController::class, 'userTickets']);
    Route::post('/tickets', [SupportController::class, 'createTicket']);
    Route::get('/tickets/{ticketNumber}', [SupportController::class, 'userTicket']);
    Route::post('/tickets/{ticketNumber}/messages', [SupportController::class, 'userMessage']);
    Route::post('/tickets/{ticketNumber}/escalate', [SupportController::class, 'escalateTicket']);
    Route::post('/tickets/{ticketNumber}/close', [SupportController::class, 'closeTicket']);
    Route::post('/tickets/{ticketNumber}/reopen', [SupportController::class, 'reopenTicket']);
    Route::post('/tickets/{ticketNumber}/feedback', [SupportController::class, 'feedback']);
    Route::post('/assistant/messages', [SupportController::class, 'assistantMessage'])->middleware('throttle:hellom-support-ai');
    Route::get('/attachments/{id}', [SupportController::class, 'attachment'])->whereNumber('id');

    Route::prefix('agent')->middleware('supportAgent')->group(function () {
        Route::get('/tickets', [SupportController::class, 'agentQueue']);
        Route::get('/tickets/{ticketNumber}', [SupportController::class, 'agentTicket']);
        Route::post('/tickets/{ticketNumber}/take', [SupportController::class, 'takeTicket']);
        Route::post('/tickets/{ticketNumber}/assign', [SupportController::class, 'assignTicket']);
        Route::post('/tickets/{ticketNumber}/messages', [SupportController::class, 'agentMessage']);
        Route::patch('/tickets/{ticketNumber}', [SupportController::class, 'updateTicket']);
        Route::get('/canned-responses', [SupportController::class, 'cannedResponses']);
    });

    Route::prefix('admin')->middleware('superAdmin')->group(function () {
        Route::get('/overview', [SupportController::class, 'adminOverview']);
        Route::get('/stats', [SupportController::class, 'stats']);
        Route::post('/agents', [SupportController::class, 'saveAgent']);
        Route::delete('/agents/{userId}', [SupportController::class, 'deactivateAgent'])->whereNumber('userId');
        Route::get('/knowledge/articles', [SupportController::class, 'knowledgeArticles']);
        Route::post('/knowledge/articles', [SupportController::class, 'saveKnowledgeArticle']);
        Route::put('/knowledge/articles/{id}', [SupportController::class, 'saveKnowledgeArticle'])->whereNumber('id');
        Route::delete('/knowledge/articles/{id}', [SupportController::class, 'deleteKnowledgeArticle'])->whereNumber('id');
        Route::post('/knowledge/import', [SupportController::class, 'importKnowledge']);
        Route::post('/categories', [SupportController::class, 'saveCategory']);
        Route::put('/categories/{id}', [SupportController::class, 'saveCategory'])->whereNumber('id');
        Route::post('/canned-responses', [SupportController::class, 'saveCannedResponse']);
        Route::put('/canned-responses/{id}', [SupportController::class, 'saveCannedResponse'])->whereNumber('id');
    });
});
