<?php

namespace App\Services\Client;

use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\SavedDashboard;
use App\Services\AI\ChatMessageSerializer;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ClientDashboardTabDataService
{
    public function __construct(
        protected ChatMessageSerializer $messages,
        protected SavedDashboardService $savedDashboards,
    ) {}

    /**
     * @return array{
     *     aiView: string,
     *     aiSession: array<string, mixed>|null,
     *     aiSavedDashboards: \Illuminate\Support\Collection<int, array{id: int, title: string}>,
     *     aiSessions: list<array<string, mixed>>,
     *     previewStart: string,
     *     previewEnd: string,
     * }
     */
    public function aiTabData(
        Request $request,
        ClientDashboard $dashboard,
        string $defaultStart,
        string $defaultEnd,
    ): array {
        $preview = $this->previewRange($request, $defaultStart, $defaultEnd);
        $aiView = (string) $request->query('ai_view', 'chat');

        $sessionId = (int) $request->query('session', 0);
        $session = null;

        if ($sessionId > 0) {
            $session = AnalyticsReportSession::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->where('user_id', $request->user()->id)
                ->with('messages')
                ->findOrFail($sessionId);
        }

        $aiSessions = [];

        if ($aiView === 'history') {
            $aiSessions = AnalyticsReportSession::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->where('user_id', $request->user()->id)
                ->latest('updated_at')
                ->get()
                ->map(fn (AnalyticsReportSession $item) => [
                    'id' => $item->id,
                    'title' => $item->title ?? 'Untitled chat',
                    'status' => $item->status->value,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                ])
                ->all();
        }

        $savedDashboards = $dashboard->savedDashboards()
            ->orderByDesc('sort_order')
            ->get(['id', 'title']);

        return [
            'aiView' => $aiView,
            'aiSession' => $session
                ? $this->serializeSession($session, $dashboard, $preview['start'], $preview['end'])
                : null,
            'aiSavedDashboards' => $savedDashboards->map(fn (SavedDashboard $board) => [
                'id' => $board->id,
                'title' => $board->title,
            ])->values()->all(),
            'aiSessions' => $aiSessions,
            'previewStart' => $preview['previewStart'],
            'previewEnd' => $preview['previewEnd'],
        ];
    }

    /**
     * @return array{
     *     savedBoards: list<array<string, mixed>>,
     *     savedBoard: array<string, mixed>|null,
     *     previewStart: string,
     *     previewEnd: string,
     * }
     */
    public function savedTabData(
        Request $request,
        ClientDashboard $dashboard,
        string $defaultStart,
        string $defaultEnd,
    ): array {
        $preview = $this->previewRange($request, $defaultStart, $defaultEnd);

        $boardId = (int) $request->query('board', 0);
        $savedBoard = null;

        $boardModels = $dashboard->savedDashboards()
            ->withCount('blocks')
            ->orderByDesc('sort_order')
            ->get();

        if ($boardId > 0) {
            $board = $boardModels->firstWhere('id', $boardId)
                ?? SavedDashboard::query()
                    ->where('client_dashboard_id', $dashboard->id)
                    ->findOrFail($boardId);

            $savedBoard = $this->savedDashboards->resolveBoard(
                $board,
                $dashboard,
                $preview['start'],
                $preview['end'],
            );
        }

        $boards = $boardId > 0
            ? $boardModels->map(fn (SavedDashboard $board) => [
                'id' => $board->id,
                'title' => $board->title,
                'description' => $board->description,
                'blocks_count' => $board->blocks_count,
                'updated_at' => $board->updated_at?->toIso8601String(),
            ])->all()
            : $boardModels
                ->map(fn (SavedDashboard $board) => $this->savedDashboards->resolveBoard(
                    $board,
                    $dashboard,
                    $preview['start'],
                    $preview['end'],
                ))
                ->map(fn (array $resolved) => [
                    ...$resolved,
                    'blocks_count' => count($resolved['blocks']),
                ])
                ->all();

        return [
            'savedBoards' => $boards,
            'savedBoard' => $savedBoard,
            'previewStart' => $preview['previewStart'],
            'previewEnd' => $preview['previewEnd'],
        ];
    }

    /**
     * @return array{previewStart: string, previewEnd: string, start: Carbon, end: Carbon}
     */
    protected function previewRange(Request $request, string $defaultStart, string $defaultEnd): array
    {
        $previewStart = (string) $request->input('preview_start', $defaultStart);
        $previewEnd = (string) $request->input('preview_end', $defaultEnd);

        return [
            'previewStart' => $previewStart,
            'previewEnd' => $previewEnd,
            'start' => Carbon::parse($previewStart)->startOfDay(),
            'end' => Carbon::parse($previewEnd)->endOfDay(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSession(
        AnalyticsReportSession $session,
        ClientDashboard $dashboard,
        Carbon $start,
        Carbon $end,
    ): array {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status->value,
            'messages' => $this->messages->serialize($session->messages, $dashboard, $start, $end, $session->status),
        ];
    }
}
