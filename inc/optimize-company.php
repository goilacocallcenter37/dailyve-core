<?php

function paginate_large_sections($data, $page = 1, $per_page = 10) {
    $total_items = count($data);
    $total_pages = ceil($total_items / $per_page);
    
    $page = max(1, min($total_pages, $page));
    
    // Xác định vị trí bắt đầu cắt mảng
    $offset = ($page - 1) * $per_page;
    
    return [
        'data' => array_slice($data, $offset, $per_page),
        'current_page' => $page,
        'total_pages' => $total_pages
    ];
}

function call_paginate_price_table_list_via_ajax() {
    if (isset($_GET['action']) && $_GET['action'] == 'price_table_list') {
        // check_ajax_referer('load_company_details', 'nonce');
        $post_id = intval($_GET['post_id']);
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
        $cache_key = 'company_page_list_' . $page . '_' . $post_id;
        $cached_data = get_transient($cache_key);
        if ($cached_data) {
            wp_send_json_success($cached_data);
            return;
        }
        $data = get_field('price_table_list', $post_id) ? get_field('price_table_list', $post_id) : [];
        
        $pagination = paginate_large_sections($data, $page, $per_page);
        set_transient($cache_key, $pagination, 10 * DAY_IN_SECONDS);
        wp_send_json_success($pagination);
    }
}
add_action('wp_ajax_nopriv_price_table_list', 'call_paginate_price_table_list_via_ajax');
add_action('wp_ajax_price_table_list', 'call_paginate_price_table_list_via_ajax');

function optimize_page_load()
{
    if (is_single() && in_category(6)) {
        // $essential_fields = [
        //     'company_name',
        //     'company_routes',
        //     'company_id'
        // ];

        // foreach ($essential_fields as $field) {
        //     $value = get_field($field, get_the_ID());
        //     echo "<div id='{$field}'>{$value}</div>";
        // }

        wp_enqueue_script('load-additional-details', get_stylesheet_directory_uri() . '/assets/js/load-details.js', [], null, true);
        wp_localize_script('load-additional-details', 'ajaxData', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('load_company_details'),
            'post_id'  => get_the_ID(),
            'company_name' => get_field('company_name', get_the_ID()),
        ]);
    }
}

add_action('wp_enqueue_scripts', 'optimize_page_load');

add_action('wp_ajax_load_additional_company_details', 'load_company_details');
add_action('wp_ajax_nopriv_load_additional_company_details', 'load_company_details');


function load_company_details()
{
    // check_ajax_referer('load_company_details', 'nonce');

    $post_id = intval($_POST['post_id']);
    
    // Thêm caching
    $cache_key = 'company_details_' . $post_id;
    $cached_data = get_transient($cache_key);
    
    if ($cached_data) {
        wp_send_json_success($cached_data);
        return;
    }

    // $data = get_field('price_table_list', $post_id);
    // $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    
    // $pagination = paginate_large_sections($data, $page);
    $response = [
        'driving_schedule' => get_field('driving_schedule', $post_id),
        'company_brand'    => get_field('company_brand', $post_id),
        'company_phone' => get_field('company_phone', $post_id)
        // 'price_table_list' => $pagination['data'],
    ];

    set_transient($cache_key, $response, 60 * DAY_IN_SECONDS);

    wp_send_json_success($response);
}

function update_company_data($post_id) {
    $cache_key = 'company_details_' . $post_id;
    $cache_key2 = 'company_page_list_1' . '_' . $post_id;
    delete_transient($cache_key);
    delete_transient($cache_key2);
}
add_action('save_post', 'update_company_data');