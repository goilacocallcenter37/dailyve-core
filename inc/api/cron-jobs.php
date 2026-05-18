<?php

/**
 * Cron Job: Tự động hủy vé Goopay quá hạn 10 phút.
 */
function dailyve_cron_auto_cancel_expired_tickets()
{
    $args = array(
        'post_type'      => 'book-ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => 'payment_status',
                'value'   => 1, // Pending
                'compare' => '=',
            ),
            array(
                'key'     => 'partner_id',
                'value'   => 'goopay',
                'compare' => '=',
            ),
        ),
        'date_query'     => array(
            array(
                'before' => '10 minutes ago',
            ),
        ),
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $processed_groups = [];
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            // Try to group by journey_group_id to avoid calling cancel multiple times for round trips
            $group_id = get_post_meta($post_id, 'journey_group_id', true);
            $cancel_key = !empty($group_id) ? $group_id : get_the_title();

            if (in_array($cancel_key, $processed_groups)) {
                continue;
            }

            if (function_exists('dailyve_perform_ticket_cancellation')) {
                dailyve_perform_ticket_cancellation($cancel_key);
            }
            $processed_groups[] = $cancel_key;
        }
        wp_reset_postdata();
    }
}

/**
 * Schedule the cron job for ticket cleanup.
 */
function dailyve_schedule_ticket_cleanup()
{
    if (!wp_next_scheduled('dailyve_ticket_cleanup_cron')) {
        wp_schedule_event(time(), 'every_minute', 'dailyve_ticket_cleanup_cron');
    }
}
add_action('init', 'dailyve_schedule_ticket_cleanup');
add_action('dailyve_ticket_cleanup_cron', 'dailyve_cron_auto_cancel_expired_tickets');


/**
 * Cron Job: Đồng bộ đánh giá nhà xe từ Vexere mỗi ngày
 */
function dailyve_sync_company_reviews_cron()
{
    $args = [
        'post_type' => 'page',
        'post_parent' => 15764,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ];
    $company_ids = get_posts($args);

    foreach ($company_ids as $post_id) {
        $vexere_company_id = get_post_meta($post_id, 'company_id', true);
        if (!$vexere_company_id) continue;

        $url = "companies/vexere/$vexere_company_id/reviews?page=1&limit=20";
        if (!function_exists('call_api_v2')) continue;

        $response = call_api_v2($url, 'GET');

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data) && is_array($data) && isset($data['data'])) {
                if (isset($data['data']['average_rating'])) {
                    update_post_meta($post_id, 'rating', round((float)$data['data']['average_rating'], 1));
                }
                if (isset($data['data']['total'])) {
                    update_post_meta($post_id, 'reviews', (int)$data['data']['total']);
                }

                if (isset($data['data']['items']) && is_array($data['data']['items'])) {
                    if (function_exists('generate_dailyve_review_html')) {
                        $html = generate_dailyve_review_html($data['data']['items']);
                        set_transient('dailyve_reviews_html_' . $vexere_company_id, $html, 2 * DAY_IN_SECONDS);
                    }
                }
            }
        }

        // Sync Rating Details
        $url_rating = "companies/vexere/$vexere_company_id/rating";
        $response_rating = call_api_v2($url_rating, 'GET');

        if (!is_wp_error($response_rating)) {
            $data_rating = json_decode(wp_remote_retrieve_body($response_rating), true);
            if (!empty($data_rating) && is_array($data_rating) && isset($data_rating['data'])) {
                // Save full rating data (categories and overall)
                update_post_meta($post_id, 'vexere_rating_data', $data_rating['data']);

                // Also update summary fields for quick access if available in the rating response
                if (isset($data_rating['data']['overall']['rv_main_value'])) {
                    update_post_meta($post_id, 'rating', round((float)$data_rating['data']['overall']['rv_main_value'], 1));
                }
                if (isset($data_rating['data']['overall']['total_reviews'])) {
                    update_post_meta($post_id, 'reviews', (int)$data_rating['data']['overall']['total_reviews']);
                }
            }
        }
    }
}

if (!wp_next_scheduled('dailyve_sync_reviews_event')) {
    wp_schedule_event(time(), 'daily', 'dailyve_sync_reviews_event');
}
add_action('dailyve_sync_reviews_event', 'dailyve_sync_company_reviews_cron');
