<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFeedbackRequest;
use App\Services\Feedback\SubmitFeedbackService;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function store(StoreFeedbackRequest $request, SubmitFeedbackService $service): JsonResponse
    {
        $submission = $service->submit(
            $request->user(),
            $request->safe()->only(['reason', 'message', 'page_url', 'client_dashboard_id']),
            $request->file('attachments', []) ?? [],
        );

        return response()->json([
            'id' => $submission->id,
            'message' => 'Thanks — your feedback was sent to the team.',
        ], 201);
    }
}
