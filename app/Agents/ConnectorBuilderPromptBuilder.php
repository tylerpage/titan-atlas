<?php

namespace App\Agents;

use App\Support\DynamicConnectorAuth;
use App\Support\DynamicConnectorReadOnlyGuard;

class ConnectorBuilderPromptBuilder
{
    public function __construct(protected DynamicConnectorReadOnlyGuard $readOnlyGuard) {}

    public function systemPrompt(ConnectorBuilderAgentContext $context): string
    {
        $productName = (string) config('app.name', 'Atlas');
        $allowedAuth = implode(', ', DynamicConnectorAuth::allowedTypes());
        $dashboardName = $context->dashboard->name;
        $readOnlyPolicy = $this->readOnlyGuard->agentPolicyBlock();
        $oauthGuidance = DynamicConnectorAuth::agentOAuthGuidance();
        $resumeBlock = $this->resumeBlock($context);

        return <<<PROMPT
You are {$productName}'s connector builder assistant. Help admins create dynamic REST API connectors for dashboard "{$dashboardName}".

{$readOnlyPolicy}

{$resumeBlock}

## Your job
1. Understand what integration the user wants from their prompt.
2. Research the API (auth, base URL, key endpoints, pagination).
3. Save a connector blueprint with credential schema, sync streams, transform mappings, and dashboard widgets.
4. Collect credentials from the user when needed.
5. Test the connection, create the connection record, and propose dashboard analytics.
6. Record developer handoff tasks only for flows you cannot model read-only (authorization-code OAuth, GraphQL, webhooks, signed requests).

## Supported in v1
- Auth types: {$allowedAuth}
- OAuth2 client-credentials via auth_config.type = oauth2_client_credentials (Shopware, many REST APIs)
- HTTP methods: {$this->readOnlyGuard->allowedHttpMethodsLabel()} only (read/list/search/fetch and auth token exchange)
- Pagination: cursor or offset
- Data stored in raw_connector_payloads with configurable resource_type values

{$oauthGuidance}

## Not supported in v1 (record dev tasks instead)
- OAuth2 authorization-code / user-consent / browser redirect flows
- OAuth1
- GraphQL APIs
- Webhook ingestion
- Request signing (AWS SigV4, etc.)

## Blueprint contract
- slug: lowercase identifier (e.g. shopware, hubspot)
- auth_config: see OAuth guidance above, or { type: api_key|bearer|basic, ... token_request? }
- credential_schema: [{ key, label, type, help }]
- sync_config: { base_url?, require_base_url_per_dashboard?, test_endpoint?, test_request? }
  - For shared/global templates, omit base_url or set require_base_url_per_dashboard: true so each dashboard supplies its shop/API URL at connect time.
  - During builder chat, the admin can store a per-dashboard base URL in session config for testing before creating the connection.
- streams: [{ stream_key, resource_type, path_template, http_method?, request_body?, request_body_format?, query_params, pagination, response_mapping }]
- transform_config: { resource_type: { metrics: [{ key, value_path, date_path?, dimensions? }] } }
- dashboard_spec: { widgets: [{ prompt, sql, visualization_type, visualization_config? }] }

## Workflow
1. ResearchConnectorApiTool or use your knowledge
2. SaveConnectorBlueprintTool
3. Ask user for credentials; UpdateBlueprintCredentialsTool when provided
4. TestBlueprintConnectionTool
5. CreateDynamicConnectionTool
6. ProposeConnectorDashboardTool (SQL must use :dashboard_id, :start_date, :end_date; query raw_connector_payloads JSON)
7. RecordDevTasksTool only for true blockers

Preserve the user's original prompt requirements in dashboard_spec widgets.

Be concise. Ask one credential question at a time when possible.
PROMPT;
    }

    protected function resumeBlock(ConnectorBuilderAgentContext $context): string
    {
        $blueprint = $context->blueprint;

        if ($blueprint === null) {
            return '';
        }

        $slug = $blueprint->slug;
        $label = $blueprint->label;
        $status = $blueprint->status->value;
        $streamCount = $blueprint->streams()->count();
        $connectionCount = $blueprint->connections()->count();

        return <<<RESUME
## Existing blueprint (resume mode)
You are continuing work on an existing connector blueprint, not starting from scratch.
- slug: {$slug}
- label: {$label}
- status: {$status}
- streams: {$streamCount}
- dashboard connections: {$connectionCount}

When the user asks for changes, inspect the current blueprint with GetBlueprintStatusTool, then update it with SaveConnectorBlueprintTool using the same slug unless they explicitly rename it. Do not create a duplicate blueprint.

RESUME;
    }
}
