<?php

namespace App\Ai\Concerns;

use App\Models\ConnectorBuilderMessage;
use Laravel\Ai\Messages\Message;

trait CapsConnectorBuilderConversationHistory
{
    /**
     * @return list<Message>
     */
    protected function cappedSessionMessages(): array
    {
        $limit = max(2, (int) config('titan.reporting.max_history_messages', 12));

        return $this->context->session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->reorder()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (ConnectorBuilderMessage $message) => new Message(
                $message->role === 'user' ? 'user' : 'assistant',
                $message->content,
            ))
            ->all();
    }
}
