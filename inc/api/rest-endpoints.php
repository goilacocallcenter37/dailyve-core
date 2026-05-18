<?php
// Tách phần khai báo REST API endpoints từ api-functions.php

function register_custom_booking_ams_endpoint()
{
    register_rest_route('api/v1', '/booking', array(
        'methods' => 'POST',
        'callback' => 'handle_booking_ams',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('api/v2', '/booking', array(
        'methods' => 'POST',
        'callback' => 'handle_booking_ams_without_session',
        'permission_callback' => 'is_valid_api_key',
    ));

    register_rest_route('api/v2', '/pay-ticket', array(
        'methods' => 'POST',
        'callback' => 'handle_pay_ticket_ams',
        'permission_callback' => 'is_valid_api_key',
        'args' => array(
            'secret_key' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return !empty($param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'booking_codes' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return !empty($param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'token' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    register_rest_route('api/v2', '/delete-ticket', array(
        'methods' => 'POST',
        'callback' => 'api_handle_delete_ticket',
        'permission_callback' => 'is_valid_api_key',
        'args' => array(
            'code' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return !empty($param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    register_rest_route('api/v2', '/booking/vexere/refund', array(
        'methods' => 'POST',
        'callback' => 'handle_vexere_refund',
        'permission_callback' => 'is_valid_api_key',
        'args' => array(
            'code' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'transaction_id' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    register_rest_route('api/v1', '/state-city-new', array(
        'methods' => 'GET',
        'callback' => 'handle_get_state_city_new',
        // 'permission_callback' => 'is_valid_api_key',
    ));
    register_rest_route('api/v1', '/vnpay-payment', array(
        'methods' => 'GET',
        'callback' => 'handle_vnpay_payment',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('api/v1', '/post/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_post_details',
        'permission_callback' => 'is_valid_api_key',
    ]);
    register_rest_route('api/v1', '/company/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_company_details',
        'permission_callback' => 'is_valid_api_key',
    ]);
    register_rest_route('api/v1', '/companies', [
        'methods' => 'GET',
        'callback' => 'get_all_companies',
        'permission_callback' => 'is_valid_api_key_automation',
        'args' => [
            'page' => [
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
            ],
            'per_page' => [
                'default' => 10,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
            ]
        ]
    ]);
    register_rest_route('api/v1', '/tickets', [
        'methods' => 'GET',
        'callback' => 'get_ticket_by_phone_number',
        'permission_callback' => 'is_valid_api_key_automation',
        'args' => [
            'page' => [
                'required' => true,
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
            'phone_number' => [
                'required' => true,
                'type' => 'string',
                'validate_callback' => function ($param) {
                    return !empty($param) && preg_match('/^[0-9]{10}$/', $param);
                }
            ],
            'start_date' => [
                'required' => false,
                'type' => 'string',
                'validate_callback' => function ($param) {
                    // Validate date format DD/MM/YYYY
                    if (empty($param))
                        return true;
                    return (bool) preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $param) && strtotime(str_replace('/', '-', $param));
                }
            ],
            'end_date' => [
                'required' => false,
                'type' => 'string',
                'validate_callback' => function ($param) {
                    // Validate date format DD/MM/YYYY
                    if (empty($param))
                        return true;
                    return (bool) preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $param) && strtotime(str_replace('/', '-', $param));
                }
            ],
            'status' => [
                'required' => false,
                'default' => null,
                'type' => 'integer',
                'enum' => [1, 2, 3, 4],
                'validate_callback' => function ($param) {
                    return is_null($param) || in_array((int) $param, [1, 2, 3, 4]);
                }
            ],
            'per_page' => [
                'required' => false,
                'default' => 10,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 1 && $param <= 100;
                }
            ]
        ]
    ]);
    register_rest_route('api/v1', '/ticket/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_ticket_detail_by_id',
        'permission_callback' => 'is_valid_api_key_automation',
        'args' => array(
            'id' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
    register_rest_route('api/v1', '/coupons', [
        'methods' => 'GET',
        'callback' => 'get_valid_coupons',
        'permission_callback' => 'is_valid_api_key',
        'args' => [
            'use_coupon' => [
                'required' => false,
                'type' => 'string',
                'validate_callback' => function ($param) {
                    return empty($param) || in_array($param, ['app', 'web']);
                },
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'page' => [
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
            'coupon_id' => [
                'required' => false,
                'type' => 'integer',
                'minimum' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
            'per_page' => [
                'default' => 10,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 1 && $param <= 100;
                }
            ]
        ]
    ]);
    register_rest_route('api/v1', '/check-coupon', array(
        'methods' => 'POST',
        'callback' => 'api_check_add_coupon',
        'permission_callback' => 'is_valid_api_key',
        'args' => array(
            'coupon' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'booking_codes' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'token' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));
    register_rest_route('api/v1', '/remove-coupon', array(
        'methods' => 'POST',
        'callback' => 'api_remove_pending_coupon',
        'permission_callback' => 'is_valid_api_key',
        'args' => array(
            'ticket_id' => array(
                'required' => true,
                'type' => 'integer',
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ),
            'id' => array(
                'required' => true,
                'type' => 'integer',
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ),
        ),
    ));
    register_rest_route('api/v1', '/tickets-today', [
        'methods' => 'GET',
        'callback' => 'get_tickets_today',
        'permission_callback' => 'is_valid_api_key_automation',
        'args' => [
            'page' => [
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
            'per_page' => [
                'default' => 10,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 1 && $param <= 100;
                }
            ]
        ]
    ]);

    register_rest_route('api/v1', '/posts', [
        'methods' => 'GET',
        'callback' => 'get_posts_by_category',
        'permission_callback' => 'is_valid_api_key',
        'args' => [
            'page' => [
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
            'per_page' => [
                'default' => 10,
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 1 && $param <= 100;
                }
            ],
            'category' => [
                'required' => false,
                'default' => null,
                'type' => 'integer',
                'validate_callback' => function ($param) {
                    return is_null($param) || (is_numeric($param) && $param > 0);
                }
            ]
        ]
    ]);

    register_rest_route('api/v1', '/bus-route', [
        'methods' => 'GET',
        'callback' => 'get_bus_route',
        'permission_callback' => 'is_valid_api_key_automation',
        'args' => [
            'from' => [
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
            ],
            'to' => [
                'default' => 1,
                'type' => 'integer',
                'minimum' => 1,
            ]
        ]
    ]);
    register_rest_route('api/v1', '/test-list-company', [
        'methods' => 'GET',
        'callback' => 'get_test_list_company',
        'permission_callback' => 'is_valid_api_key',
    ]);

    register_rest_route('api/v1', '/check-ticket-discount', array(
        'methods' => 'GET',
        'callback' => 'check_ticket_discount',
        'permission_callback' => '__return_true'
    ));
}
add_action('rest_api_init', 'register_custom_booking_ams_endpoint');
