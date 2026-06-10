export function formatAiConnectorDetails(blueprint) {
    if (!blueprint) {
        return '';
    }

    const payload = {
        label: blueprint.label,
        slug: blueprint.slug,
        status: blueprint.status,
        original_prompt: blueprint.original_prompt ?? null,
        auth_config: blueprint.auth_config ?? null,
        credential_schema: blueprint.credential_schema ?? [],
        sync_config: blueprint.sync_config ?? null,
        streams: blueprint.streams ?? [],
        transform_config: blueprint.transform_config ?? null,
        dashboard_spec: blueprint.dashboard_spec ?? null,
        dev_tasks: blueprint.dev_tasks ?? [],
        connections: (blueprint.connections ?? []).map((connection) => ({
            name: connection.name,
            sync_status: connection.sync_status,
            sync_error: connection.sync_error ?? null,
            dashboard: connection.dashboard?.name ?? null,
        })),
    };

    if (blueprint.connection && !payload.connections.length) {
        payload.connections = [{
            name: blueprint.connection.name,
            sync_status: blueprint.connection.sync_status,
            sync_error: blueprint.connection.sync_error ?? null,
        }];
    }

    return [
        'AI Connector blueprint (credentials are not included):',
        '',
        JSON.stringify(payload, null, 2),
    ].join('\n');
}

export async function copyAiConnectorDetails(blueprint) {
    const text = formatAiConnectorDetails(blueprint);

    if (text === '') {
        return false;
    }

    await navigator.clipboard.writeText(text);

    return true;
}
