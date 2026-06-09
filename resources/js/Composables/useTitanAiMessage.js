function stripDuplicateDataArtifacts(content) {
    const lines = content.split('\n').filter((line) => {
        const trimmed = line.trim();

        if (trimmed.startsWith('|') || /^\|?[\s:|-]+\|?$/.test(trimmed)) {
            return false;
        }

        if (/^[-*]\s+(\*\*)?[A-Za-z][^:]*:\s*[\$0-9]/.test(trimmed)) {
            return false;
        }

        return true;
    });

    return lines.join('\n').trim();
}

export function displayMessageContent(message) {
    const content = message?.content ?? '';

    if (message?.role !== 'assistant' || !message?.report_preview) {
        return content;
    }

    const hasMarkdownTable = /\|.+\|/.test(content);
    const hasMetricBullets = /^[-*]\s+(\*\*)?[A-Za-z][^:]*:\s*[\$0-9]/m.test(content);

    if (!hasMarkdownTable && !hasMetricBullets) {
        return content;
    }

    const trimmed = stripDuplicateDataArtifacts(content);

    return trimmed || 'Here is your answer:';
}

export function hasDashboardVisual(message) {
    return Boolean(message?.report_preview);
}
