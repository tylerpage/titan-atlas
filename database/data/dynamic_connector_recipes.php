<?php

/**
 * Curated read-only dynamic connector recipes consumed by docs/ecommerce_connector_catalog.json generation.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'shopware' => [
        'resources' => [
            ['stream_key' => 'orders', 'resource_type' => 'order', 'description' => 'Orders via Admin API search'],
            ['stream_key' => 'products', 'resource_type' => 'product', 'description' => 'Products via Admin API search'],
        ],
        'metrics' => [
            ['key' => 'total', 'label' => 'Order total', 'payload_path' => 'amountTotal', 'format' => 'currency'],
            ['key' => 'order_count', 'label' => 'Orders', 'aggregation' => 'count'],
        ],
        'dimensions' => ['status', 'order_date', 'currency'],
        'join_keys' => [
            ['left' => 'order.product_id', 'right' => 'product.id', 'notes' => 'Use separate product stream and join in analytics SQL when both streams are synced.'],
        ],
        'pagination' => 'page_in_body',
        'incremental_field' => 'orderDateTime',
        'unsupported_in_v1' => ['webhooks', 'graphql', 'oauth_authorization_code'],
        'authentication' => [
            'type' => 'oauth2_client_credentials',
            'notes' => 'Integration access via client ID and secret. Token endpoint /api/oauth/token.',
        ],
        'blueprint_template' => [
            'slug' => 'shopware',
            'label' => 'Shopware',
            'auth_config' => [
                'type' => 'oauth2_client_credentials',
                'token_url' => '/api/oauth/token',
                'grant_type' => 'client_credentials',
                'body_format' => 'form',
                'client_auth' => 'body',
            ],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'require_base_url_per_dashboard' => true,
                'test_endpoint' => '/api/search/order',
                'test_request' => [
                    'method' => 'POST',
                    'path' => '/api/search/order',
                    'body' => [
                        'limit' => 1,
                        'page' => 1,
                        'filter' => [],
                        'sort' => [['field' => 'orderDateTime', 'order' => 'DESC']],
                    ],
                    'body_format' => 'json',
                ],
            ],
            'streams' => [
                [
                    'stream_key' => 'orders',
                    'resource_type' => 'order',
                    'http_method' => 'POST',
                    'path_template' => '/api/search/order',
                    'request_body' => [
                        'filter' => [],
                        'sort' => [['field' => 'orderDateTime', 'order' => 'DESC']],
                    ],
                    'request_body_format' => 'json',
                    'pagination' => [
                        'type' => 'page',
                        'location' => 'body',
                        'page_param' => 'page',
                        'limit_param' => 'limit',
                        'page_size' => 50,
                    ],
                    'response_mapping' => [
                        'records_path' => 'data',
                        'id_path' => 'id',
                        'date_path' => 'orderDateTime',
                        'fields' => [
                            ['source' => 'amountTotal', 'target' => 'total'],
                            ['source' => 'orderDateTime', 'target' => 'order_date'],
                            ['source' => 'orderNumber', 'target' => 'order_number'],
                            ['source' => 'stateMachineState.technicalName', 'target' => 'status'],
                            ['source' => 'currency.isoCode', 'target' => 'currency'],
                        ],
                    ],
                ],
            ],
            'transform_config' => [
                'order' => [
                    'metrics' => [
                        ['key' => 'total', 'value_path' => 'total', 'date_path' => 'order_date'],
                    ],
                ],
            ],
        ],
    ],
    'magento' => [
        'resources' => [
            ['stream_key' => 'orders', 'resource_type' => 'order', 'description' => 'Sales orders via REST searchCriteria'],
            ['stream_key' => 'products', 'resource_type' => 'product', 'description' => 'Catalog products via REST searchCriteria'],
        ],
        'metrics' => [
            ['key' => 'grand_total', 'label' => 'Order grand total', 'payload_path' => 'grand_total', 'format' => 'currency'],
            ['key' => 'order_count', 'label' => 'Orders', 'aggregation' => 'count'],
        ],
        'dimensions' => ['status', 'created_at', 'store_id'],
        'join_keys' => [
            ['left' => 'order.item.product_id', 'right' => 'product.id', 'notes' => 'Line items are nested under order payloads; explode in analytics SQL if needed.'],
        ],
        'pagination' => 'searchCriteria_query',
        'incremental_field' => 'created_at',
        'unsupported_in_v1' => ['webhooks', 'graphql', 'oauth1', 'admin_password_token_in_production'],
        'authentication' => [
            'type' => 'bearer',
            'notes' => 'Use a Magento integration access token (recommended) via credential access_token. Admin username/password token exchange is for dev only.',
        ],
        'blueprint_template' => [
            'slug' => 'magento',
            'label' => 'Adobe Commerce (Magento)',
            'auth_config' => [
                'type' => 'bearer',
                'credential_key' => 'access_token',
            ],
            'credential_schema' => [
                ['key' => 'access_token', 'label' => 'Integration Access Token', 'type' => 'password', 'help' => 'Create an integration in Magento Admin and copy the access token.'],
                ['key' => 'store_code', 'label' => 'Store View Code', 'type' => 'text', 'help' => 'Usually default or the store code from Stores > All Stores.'],
            ],
            'sync_config' => [
                'require_base_url_per_dashboard' => true,
                'test_endpoint' => '/rest/{{store_code}}/V1/orders',
            ],
            'streams' => [
                [
                    'stream_key' => 'orders',
                    'resource_type' => 'order',
                    'http_method' => 'GET',
                    'path_template' => '/rest/{{store_code}}/V1/orders',
                    'query_params' => [
                        'searchCriteria[sortOrders][0][field]' => 'created_at',
                        'searchCriteria[sortOrders][0][direction]' => 'DESC',
                    ],
                    'pagination' => [
                        'type' => 'page',
                        'location' => 'query',
                        'page_param' => 'searchCriteria[currentPage]',
                        'limit_param' => 'searchCriteria[pageSize]',
                        'page_size' => 100,
                    ],
                    'response_mapping' => [
                        'records_path' => 'items',
                        'id_path' => 'entity_id',
                        'date_path' => 'created_at',
                        'fields' => [
                            ['source' => 'entity_id', 'target' => 'entity_id'],
                            ['source' => 'increment_id', 'target' => 'order_number'],
                            ['source' => 'created_at', 'target' => 'created_at'],
                            ['source' => 'status', 'target' => 'status'],
                            ['source' => 'grand_total', 'target' => 'grand_total'],
                            ['source' => 'order_currency_code', 'target' => 'currency'],
                        ],
                    ],
                ],
            ],
            'transform_config' => [
                'order' => [
                    'metrics' => [
                        ['key' => 'grand_total', 'value_path' => 'grand_total', 'date_path' => 'created_at'],
                    ],
                ],
            ],
        ],
    ],
    'woocommerce' => [
        'resources' => [
            ['stream_key' => 'orders', 'resource_type' => 'order', 'description' => 'WooCommerce REST orders collection'],
            ['stream_key' => 'products', 'resource_type' => 'product', 'description' => 'WooCommerce REST products collection'],
        ],
        'metrics' => [
            ['key' => 'total', 'label' => 'Order total', 'payload_path' => 'total', 'format' => 'currency'],
            ['key' => 'order_count', 'label' => 'Orders', 'aggregation' => 'count'],
        ],
        'dimensions' => ['status', 'date_created', 'currency'],
        'join_keys' => [
            ['left' => 'order.line_items.product_id', 'right' => 'product.id', 'notes' => 'Line items are nested in order payloads.'],
        ],
        'pagination' => 'page_query',
        'incremental_field' => 'date_created',
        'unsupported_in_v1' => ['webhooks', 'graphql', 'oauth1'],
        'authentication' => [
            'type' => 'basic',
            'notes' => 'REST API keys over HTTPS. Consumer key as username, consumer secret as password.',
        ],
        'blueprint_template' => [
            'slug' => 'woocommerce',
            'label' => 'WooCommerce',
            'auth_config' => [
                'type' => 'basic',
                'username_key' => 'consumer_key',
                'password_key' => 'consumer_secret',
            ],
            'credential_schema' => [
                ['key' => 'consumer_key', 'label' => 'Consumer Key', 'type' => 'text'],
                ['key' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'require_base_url_per_dashboard' => true,
                'test_endpoint' => '/wp-json/wc/v3/orders',
            ],
            'streams' => [
                [
                    'stream_key' => 'orders',
                    'resource_type' => 'order',
                    'http_method' => 'GET',
                    'path_template' => '/wp-json/wc/v3/orders',
                    'query_params' => [
                        'orderby' => 'date',
                        'order' => 'desc',
                    ],
                    'pagination' => [
                        'type' => 'page',
                        'location' => 'query',
                        'page_param' => 'page',
                        'limit_param' => 'per_page',
                        'page_size' => 100,
                    ],
                    'response_mapping' => [
                        'records_path' => '@root',
                        'id_path' => 'id',
                        'date_path' => 'date_created',
                        'fields' => [
                            ['source' => 'id', 'target' => 'id'],
                            ['source' => 'number', 'target' => 'order_number'],
                            ['source' => 'status', 'target' => 'status'],
                            ['source' => 'date_created', 'target' => 'date_created'],
                            ['source' => 'total', 'target' => 'total'],
                            ['source' => 'currency', 'target' => 'currency'],
                        ],
                    ],
                ],
            ],
            'transform_config' => [
                'order' => [
                    'metrics' => [
                        ['key' => 'total', 'value_path' => 'total', 'date_path' => 'date_created'],
                    ],
                ],
            ],
        ],
    ],
    'miva' => [
        'resources' => [
            ['stream_key' => 'orders', 'resource_type' => 'order', 'description' => 'Orders via OrderList_Load_Query'],
            ['stream_key' => 'products', 'resource_type' => 'product', 'description' => 'Products via ProductList_Load_Query'],
        ],
        'metrics' => [
            ['key' => 'total', 'label' => 'Order total', 'payload_path' => 'total', 'format' => 'currency'],
            ['key' => 'order_count', 'label' => 'Orders', 'aggregation' => 'count'],
        ],
        'dimensions' => ['status', 'orderdate', 'cust_id'],
        'join_keys' => [
            ['left' => 'order.items.product_id', 'right' => 'product.id', 'notes' => 'Request ondemandcolumns items when syncing orders for line-item analytics.'],
        ],
        'pagination' => 'offset_in_body',
        'incremental_field' => 'orderdate',
        'unsupported_in_v1' => ['webhooks', 'graphql', 'hmac_only_without_token'],
        'authentication' => [
            'type' => 'api_key',
            'notes' => 'API access token in X-Miva-API-Authorization header as MIVA {token}. HMAC signing is recommended by Miva but optional for private integrations.',
        ],
        'blueprint_template' => [
            'slug' => 'miva',
            'label' => 'Miva Merchant',
            'auth_config' => [
                'type' => 'api_key',
                'location' => 'header',
                'header_name' => 'X-Miva-API-Authorization',
                'prefix' => 'MIVA ',
                'credential_key' => 'access_token',
            ],
            'credential_schema' => [
                ['key' => 'access_token', 'label' => 'API Access Token', 'type' => 'password', 'help' => 'Users > API Tokens in Miva Admin.'],
                ['key' => 'store_code', 'label' => 'Store Code', 'type' => 'text', 'help' => 'Store code from Miva Admin, e.g. MAIN.'],
            ],
            'sync_config' => [
                'require_base_url_per_dashboard' => true,
                'test_endpoint' => '/mm5/json.mvc',
                'test_request' => [
                    'method' => 'POST',
                    'path' => '/mm5/json.mvc',
                    'body' => [
                        'Store_Code' => '{{store_code}}',
                        'Function' => 'OrderList_Load_Query',
                        'Count' => 1,
                        'Offset' => 0,
                        'Filter' => [
                            ['name' => 'ondemandcolumns', 'value' => ['items', 'charges']],
                        ],
                    ],
                    'body_format' => 'json',
                ],
            ],
            'streams' => [
                [
                    'stream_key' => 'orders',
                    'resource_type' => 'order',
                    'http_method' => 'POST',
                    'path_template' => '/mm5/json.mvc',
                    'request_body' => [
                        'Store_Code' => '{{store_code}}',
                        'Function' => 'OrderList_Load_Query',
                        'Filter' => [
                            ['name' => 'ondemandcolumns', 'value' => ['items', 'charges', 'customer']],
                        ],
                        'Sort' => [['field' => 'id', 'order' => 'DESC']],
                    ],
                    'request_body_format' => 'json',
                    'pagination' => [
                        'type' => 'offset',
                        'location' => 'body',
                        'limit_param' => 'Count',
                        'offset_param' => 'Offset',
                        'page_size' => 100,
                    ],
                    'response_mapping' => [
                        'records_path' => 'data.data',
                        'id_path' => 'id',
                        'date_path' => 'orderdate',
                        'fields' => [
                            ['source' => 'id', 'target' => 'id'],
                            ['source' => 'orderdate', 'target' => 'orderdate'],
                            ['source' => 'status', 'target' => 'status'],
                            ['source' => 'total', 'target' => 'total'],
                            ['source' => 'cust_id', 'target' => 'customer_id'],
                        ],
                    ],
                ],
            ],
            'transform_config' => [
                'order' => [
                    'metrics' => [
                        ['key' => 'total', 'value_path' => 'total', 'date_path' => 'orderdate'],
                    ],
                ],
            ],
        ],
    ],
];
