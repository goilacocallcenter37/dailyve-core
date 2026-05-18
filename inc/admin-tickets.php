<?php
/**
 * Admin Ticket Table Customizations & Filters
 * Extracted from flatsome-child theme to maintain admin functionality for book-ticket post type.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('manage_edit-book-ticket_columns', 'dailyve_add_ticket_columns');
function dailyve_add_ticket_columns($columns)
{
    $new_columns = [];

    foreach ($columns as $key => $title) {
        // Insert new columns before the 'date' column
        if ($key === 'date') {
            $new_columns['full_name'] = __('Full Name', 'dailyve-core');
            $new_columns['phone'] = __('Phone', 'dailyve-core');
            $new_columns['route_name'] = __('Tuyến đường', 'dailyve-core');
            $new_columns['total_price'] = __('Giá vé', 'dailyve-core');
            $new_columns['applied_coupon'] = __('Mã giảm giá', 'dailyve-core');
            $new_columns['partner_id'] = __('Partner ID', 'dailyve-core');
            $new_columns['ticket_status'] = __('Trạng thái', 'dailyve-core');
        }
        $new_columns[$key] = $title;
    }

    return $new_columns;
}

add_action('manage_book-ticket_posts_custom_column', 'dailyve_render_ticket_columns', 10, 2);
function dailyve_render_ticket_columns($column, $post_id)
{
    if ($column === 'full_name') {
        $fullname = get_post_meta($post_id, 'full_name', true);
        echo esc_html($fullname);
    }

    if ($column === 'phone') {
        $phone = get_post_meta($post_id, 'phone', true);
        echo esc_html($phone);
    }

    if ($column === 'partner_id') {
        $partner_id = get_post_meta($post_id, 'partner_id', true);
        echo esc_html(strtoupper($partner_id));
    }

    if ($column === 'ticket_status') {
        $status = (int)get_post_meta($post_id, 'payment_status', true);
        $status_label = 'Chờ thanh toán';
        $status_color = '#f39c12';

        if ($status === 2) {
            $status_label = 'Đã thanh toán';
            $status_color = '#27ae60';
        } elseif ($status === 3) {
            $status_label = 'Đã hủy';
            $status_color = '#e74c3c';
        } elseif ($status === 5) {
            $status_label = 'Hủy vé hoàn tiền';
            $status_color = '#f36412';
        }

        printf('<span style="color: %s; font-weight: bold;">%s</span>', $status_color, $status_label);
    }

    if ($column === 'route_name') {
        $route = get_post_meta($post_id, 'routeName', true);
        echo esc_html($route);
    }

    if ($column === 'total_price') {
        $price = get_post_meta($post_id, 'total_price', true);
        echo $price ? number_format($price, 0, ',', '.') . 'đ' : '0đ';
    }

    if ($column === 'applied_coupon') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ticket_coupon';
        
        // Ensure the table exists before querying
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
            $coupon_info = $wpdb->get_row($wpdb->prepare(
                "SELECT code, status FROM $table_name WHERE ticket_id = %d ORDER BY created_at DESC LIMIT 1",
                $post_id
            ));
            if ($coupon_info) {
                $bg = $coupon_info->status === 'completed' ? '#e7f5ea' : '#fff7ed';
                $color = $coupon_info->status === 'completed' ? '#15803d' : '#ea580c';
                echo '<span style="background: ' . $bg . '; color: ' . $color . '; padding: 2px 6px; border-radius: 4px; font-weight: bold; border: 1px solid ' . $color . '20;">' . esc_html($coupon_info->code) . '</span>';
            } else {
                echo '<span style="color: #999;">-</span>';
            }
        } else {
            echo '<span style="color: #999;">-</span>';
        }
    }
}

add_action('restrict_manage_posts', 'dailyve_add_book_ticket_filters');
function dailyve_add_book_ticket_filters($post_type)
{
    if ($post_type === 'book-ticket') {
        $current_partner = isset($_GET['partner_id_filter']) ? sanitize_text_field($_GET['partner_id_filter']) : '';

        $partners = [
            'vexere' => 'Vexere',
            'goopay' => 'Goopay'
        ];

        echo '<select name="partner_id_filter">';
        echo '<option value="">' . __('Tất cả đối tác', 'dailyve-core') . '</option>';
        foreach ($partners as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current_partner, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }
}

add_action('pre_get_posts', 'dailyve_filter_book_tickets_by_partner_id');
function dailyve_filter_book_tickets_by_partner_id($query)
{
    global $pagenow;
    if (is_admin() && $pagenow === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'book-ticket' && !empty($_GET['partner_id_filter'])) {
        $partner_id = sanitize_text_field($_GET['partner_id_filter']);
        $meta_query = (array)$query->get('meta_query');
        $meta_query[] = [
            'key'     => 'partner_id',
            'value'   => $partner_id,
            'compare' => '=',
        ];
        $query->set('meta_query', $meta_query);
    }
}
