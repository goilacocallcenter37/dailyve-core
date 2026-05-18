<?php

add_action('admin_init', 'redirect_contributor_to_frontend');
function redirect_contributor_to_frontend()
{
    if (is_admin() && !wp_doing_ajax()) {
        $user = wp_get_current_user();
        if (in_array('contributor', (array)$user->roles)) {
            wp_safe_redirect(home_url());
            exit;
        }
    }
}

add_action('after_setup_theme', 'remove_admin_bar_for_contributor');
function remove_admin_bar_for_contributor()
{
    if (current_user_can('contributor')) {
        show_admin_bar(false);
    }
}

function create_contributor_ticket_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'contributor_ticket';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        contributor_code VARCHAR(6) NOT NULL,
        ticket_id BIGINT(20) UNSIGNED NOT NULL,
        status TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX (contributor_code),
        INDEX (user_id),
        INDEX (ticket_id),
        FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE CASCADE,
        FOREIGN KEY (ticket_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('after_switch_theme', 'create_contributor_ticket_table');

function generate_unique_contributor_code()
{
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $max_attempts = 10;
    $code = '';

    for ($i = 0; $i < $max_attempts; $i++) {
        $code = '';
        for ($j = 0; $j < 6; $j++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }

        // check trùng lặp
        $existing_users = get_users([
            'meta_key' => 'contributor_code',
            'meta_value' => $code,
            'number' => 1,
            'count_total' => false
        ]);

        if (empty($existing_users)) {
            break;
        }
    }

    return $code;
}

// Tạo mã cho cộng tác viên khi người dùng được tạo
add_action('user_register', 'assign_contributor_code', 10, 1);
function assign_contributor_code($user_id)
{
    $user = get_userdata($user_id);
    if (in_array('contributor', (array)$user->roles)) {
        $code = generate_unique_contributor_code();
        update_user_meta($user_id, 'contributor_code', $code);
    }
}

add_action('show_user_profile', 'display_contributor_code_field');
add_action('edit_user_profile', 'display_contributor_code_field');
function display_contributor_code_field($user)
{
    if (in_array('contributor', (array)$user->roles)) {
        $contributor_code = get_user_meta($user->ID, 'contributor_code', true);
?>
        <h3><?php _e('Mã cộng tác viên', 'your-text-domain'); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="contributor_code"><?php _e('Mã cộng tác viên', 'your-text-domain'); ?></label></th>
                <td>
                    <input type="text" name="contributor_code" id="contributor_code" value="<?php echo esc_attr($contributor_code); ?>" class="regular-text" />
                    <p class="description"><?php _e('Mã này phải là 6 ký tự và duy nhất.', 'your-text-domain'); ?></p>
                </td>
            </tr>
        </table>
    <?php
    }
}

add_action('personal_options_update', 'save_contributor_code_field');
add_action('edit_user_profile_update', 'save_contributor_code_field');
function save_contributor_code_field($user_id)
{
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    if (isset($_POST['contributor_code'])) {
        $new_code = sanitize_text_field($_POST['contributor_code']);

        if (strlen($new_code) !== 6) {
            add_action('user_profile_update_errors', function ($errors) {
                $errors->add('contributor_code_error', __('Mã cộng tác viên phải có đúng 6 ký tự.', 'your-text-domain'));
            });
            return false;
        }

        // Kiểm tra trùng lặp
        $existing_users = get_users([
            'meta_key' => 'contributor_code',
            'meta_value' => $new_code,
            'exclude' => [$user_id],
            'number' => 1,
            'count_total' => false
        ]);

        if (!empty($existing_users)) {
            add_action('user_profile_update_errors', function ($errors) {
                $errors->add('contributor_code_error', __('Mã cộng tác viên đã tồn tại. Vui lòng chọn mã khác.', 'your-text-domain'));
            });
            return false;
        }

        update_user_meta($user_id, 'contributor_code', $new_code);
    }
}


function contributor_tickets_admin_menu()
{
    add_submenu_page(
        'users.php',
        'Quản lý vé cộng tác viên',
        'Quản lý vé',
        'manage_options',
        'contributor-tickets',
        'contributor_tickets_admin_page'
    );
}
add_action('admin_menu', 'contributor_tickets_admin_menu');

// Handle Excel export
function handle_contributor_tickets_export()
{
    if (!isset($_GET['action']) || $_GET['action'] !== 'export_excel') {
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_die('Bạn không có quyền thực hiện thao tác này.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'contributor_ticket';

    // Get export filters
    $export_type = isset($_GET['export_type']) ? sanitize_text_field($_GET['export_type']) : 'all';
    $export_month = isset($_GET['export_month']) ? sanitize_text_field($_GET['export_month']) : '';
    $export_year = isset($_GET['export_year']) ? sanitize_text_field($_GET['export_year']) : '';
    $export_contributor = isset($_GET['export_contributor']) ? sanitize_text_field($_GET['export_contributor']) : '';
    $export_status = isset($_GET['export_status']) ? sanitize_text_field($_GET['export_status']) : '';
    $export_start_date = isset($_GET['export_start_date']) ? sanitize_text_field($_GET['export_start_date']) : '';
    $export_end_date = isset($_GET['export_end_date']) ? sanitize_text_field($_GET['export_end_date']) : '';

    // Build query based on export type
    $where = [];
    $params = [];

    switch ($export_type) {
        case 'month':
            if ($export_month && $export_year) {
                $where[] = "MONTH(t.created_at) = %d AND YEAR(t.created_at) = %d";
                $params[] = intval($export_month);
                $params[] = intval($export_year);
            }
            break;
        case 'contributor':
            if ($export_contributor) {
                $where[] = "t.contributor_code = %s";
                $params[] = $export_contributor;
            }
            break;
        case 'date_range':
            if ($export_start_date && $export_end_date) {
                $where[] = "t.created_at BETWEEN %s AND %s";
                $params[] = $export_start_date . ' 00:00:00';
                $params[] = $export_end_date . ' 23:59:59';
            }
            break;
        case 'status':
            if ($export_status !== '') {
                $where[] = "t.status = %d";
                $params[] = intval($export_status);
            }
            break;
    }

    // Join with postmeta for additional data
    $join = "
        LEFT JOIN {$wpdb->postmeta} pm1 ON t.ticket_id = pm1.post_id AND pm1.meta_key = 'full_name'
        LEFT JOIN {$wpdb->postmeta} pm2 ON t.ticket_id = pm2.post_id AND pm2.meta_key = 'phone'
        LEFT JOIN {$wpdb->postmeta} pm3 ON t.ticket_id = pm3.post_id AND pm3.meta_key = 'payment_status'
        LEFT JOIN {$wpdb->postmeta} pm4 ON t.ticket_id = pm4.post_id AND pm4.meta_key = 'booking_codes'
        LEFT JOIN {$wpdb->postmeta} pm5 ON t.ticket_id = pm5.post_id AND pm5.meta_key = 'search_from'
        LEFT JOIN {$wpdb->postmeta} pm6 ON t.ticket_id = pm6.post_id AND pm6.meta_key = 'search_to'
        LEFT JOIN {$wpdb->postmeta} pm7 ON t.ticket_id = pm7.post_id AND pm7.meta_key = 'total_price'
    ";

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = "SELECT t.*, 
                     pm1.meta_value as full_name, 
                     pm2.meta_value as phone,
                     pm3.meta_value as payment_status,
                     pm4.meta_value as booking_codes,
                     pm5.meta_value as search_from,
                     pm6.meta_value as search_to,
                     pm7.meta_value as total_price
              FROM $table_name t $join $where_sql 
              ORDER BY t.created_at DESC";

    if (!empty($params)) {
        $results = $wpdb->get_results($wpdb->prepare($query, $params));
    } else {
        $results = $wpdb->get_results($query);
    }

    // Generate Excel file
    $filename = 'contributor-tickets-' . date('Y-m-d-H-i-s') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Headers
    fputcsv($output, [
        'STT',
        'Mã vé',
        'Mã CTV',
        'Tên CTV',
        'Khách hàng',
        'Số điện thoại',
        'Điểm đi',
        'Điểm đến',
        'Giá vé',
        'Ngày đặt',
        'Trạng thái vé',
        'Trạng thái quyết toán'
    ]);

    // Data rows
    $stt = 1;
    foreach ($results as $row) {
        $payment_status = (int) $row->payment_status;
        $payment_status_text = match ($payment_status) {
            1 => 'Chưa thanh toán',
            2 => 'Đã thanh toán',
            3 => 'Đã hủy',
            4 => 'Thanh toán thất bại',
            default => 'Không xác định'
        };

        $status_text = $row->status == 1 ? 'Đã quyết toán' : 'Chưa quyết toán';

        // Get contributor name
        $contributor_user = get_users([
            'meta_key' => 'contributor_code',
            'meta_value' => $row->contributor_code,
            'number' => 1
        ]);
        $contributor_name = !empty($contributor_user) ? $contributor_user[0]->display_name : '';

        // Get location names
        $search_from_name = function_exists('timTuyenDuongID') ? timTuyenDuongID($row->search_from) : $row->search_from;
        $search_to_name = function_exists('timTuyenDuongID') ? timTuyenDuongID($row->search_to) : $row->search_to;

        fputcsv($output, [
            $stt++,
            $row->booking_codes,
            $row->contributor_code,
            $contributor_name,
            $row->full_name,
            $row->phone,
            $search_from_name,
            $search_to_name,
            number_format($row->total_price) . ' VNĐ',
            $row->created_at,
            $payment_status_text,
            $status_text
        ]);
    }

    fclose($output);
    exit;
}
add_action('admin_init', 'handle_contributor_tickets_export');

function contributor_tickets_admin_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'contributor_ticket';

    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['ticket_ids'])) {
        $ticket_ids = array_map('intval', $_POST['ticket_ids']);
        $action = sanitize_text_field($_POST['bulk_action']);
        // var_dump($ticket_ids);
        // die();

        switch ($action) {
            case 'delete':
                foreach ($ticket_ids as $id) {
                    $wpdb->delete($table_name, ['id' => $id], ['%d']);
                }
                echo '<div class="updated"><p>Đã xóa các vé đã chọn.</p></div>';
                break;
            case 'update_status':
                $new_status = isset($_POST['bulk_status']) ? intval($_POST['bulk_status']) : 0;
                foreach ($ticket_ids as $id) {
                    $wpdb->update(
                        $table_name,
                        ['status' => $new_status],
                        ['id' => $id],
                        ['%d'],
                        ['%d']
                    );
                }
                echo '<div class="updated"><p>Đã cập nhật trạng thái các vé đã chọn.</p></div>';
                break;
        }
    }

    // Handle single status update
    if (isset($_POST['update_payment_status']) && check_admin_referer('update_ticket_status')) {
        $ticket_id = intval($_POST['ticket_id']);
        $new_status = intval($_POST['ticket_status']);

        $updated = $wpdb->update(
            $table_name,
            ['status' => $new_status],
            ['id' => $ticket_id],
            ['%d'],
            ['%d']
        );

        if ($updated !== false) {
            echo '<div class="updated"><p>Đã cập nhật trạng thái thanh toán thành công.</p></div>';
        } else {
            echo '<div class="error"><p>Có lỗi xảy ra khi cập nhật trạng thái.</p></div>';
        }
    }

    // Get filters
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $contributor = isset($_GET['contributor']) ? sanitize_text_field($_GET['contributor']) : '';
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

    // Build query
    $where = [];
    $params = [];

    if ($search) {
        $where[] = "(t.contributor_code LIKE %s OR pm1.meta_value LIKE %s OR pm2.meta_value LIKE %s)";
        $search_param = '%' . $wpdb->esc_like($search) . '%';
        $params = array_merge($params, [$search_param, $search_param, $search_param]);
    }

    if ($contributor) {
        $where[] = "t.contributor_code = %s";
        $params[] = $contributor;
    }

    if ($status !== '') {
        $where[] = "t.status = %d";
        $params[] = intval($status);
    }

    if ($start_date && $end_date) {
        $where[] = "t.created_at BETWEEN %s AND %s";
        $params[] = $start_date . ' 00:00:00';
        $params[] = $end_date . ' 23:59:59';
    }

    // Pagination
    $items_per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Build final query
    $join = "
        LEFT JOIN {$wpdb->postmeta} pm1 ON t.ticket_id = pm1.post_id AND pm1.meta_key = 'full_name'
        LEFT JOIN {$wpdb->postmeta} pm2 ON t.ticket_id = pm2.post_id AND pm2.meta_key = 'phone'
        LEFT JOIN {$wpdb->postmeta} pm3 ON t.ticket_id = pm3.post_id AND pm3.meta_key = 'payment_status'
    ";

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $count_query = "SELECT COUNT(*) FROM $table_name t $join $where_sql";
    $total_items = $wpdb->get_var($wpdb->prepare($count_query, $params));
    $total_pages = ceil($total_items / $items_per_page);

    $query = "SELECT t.*, pm1.meta_value as full_name, pm2.meta_value as phone, pm3.meta_value as payment_status 
              FROM $table_name t $join $where_sql 
              ORDER BY t.created_at DESC LIMIT %d OFFSET %d";

    $params[] = $items_per_page;
    $params[] = $offset;

    $results = $wpdb->get_results($wpdb->prepare($query, $params));

    // Get unique contributor codes for filter
    $contributor_codes = $wpdb->get_col("SELECT DISTINCT contributor_code FROM $table_name");

    ?>
    <div class="wrap">
        <h1>Quản lý vé cộng tác viên</h1>
        <!-- Export Excel Section -->
        
        <!-- Search and Filter Form -->
        <form method="get" class="search-box">
            <input type="hidden" name="page" value="contributor-tickets">
            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Tìm kiếm...">
            </p>
            <select name="contributor">
                <option value="">Tất cả cộng tác viên</option>
                <?php foreach ($contributor_codes as $code): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected($contributor, $code); ?>>
                        <?php echo esc_html($code); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">Tất cả trạng thái</option>
                <option value="1" <?php selected($status, '1'); ?>>Đã quyết toán</option>
                <option value="0" <?php selected($status, '0'); ?>>Chưa quyết toán</option>
            </select>
            <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" placeholder="Từ ngày">
            <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>" placeholder="Đến ngày">
            <input type="submit" class="button" value="Lọc">
        </form>

        <?php if (empty($results)): ?>
            <p>Chưa có vé nào sử dụng mã cộng tác viên.</p>
        <?php else: ?>
            <form method="post" action="">
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action">
                            <option value="">Hành động</option>
                            <option value="delete">Xóa</option>
                            <option value="update_status">Cập nhật trạng thái</option>
                        </select>
                        <select name="bulk_status" style="display:none;">
                            <option value="1">Đã quyết toán</option>
                            <option value="0">Chưa quyết toán</option>
                        </select>
                        <input type="submit" class="button action" value="Áp dụng">
                    </div>
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $current_page
                        ]);
                        ?>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="check-column" style="padding: 10px 3px 0px;"><input type="checkbox" /></th>
                            <th>Mã vé</th>
                            <th>Mã cộng tác viên</th>
                            <th>Khách hàng</th>
                            <th>Số điện thoại</th>
                            <th>Điểm đi</th>
                            <th>Điểm đến</th>
                            <th>Ngày đặt</th>
                            <th>Trạng thái vé</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row):
                            $post_id = $row->ticket_id;
                            $booking_codes = get_post_meta($post_id, 'booking_codes', true);
                            $search_from = get_post_meta($post_id, 'search_from', true);
                            $search_to = get_post_meta($post_id, 'search_to', true);
                            $payment_status = (int) get_post_meta($post_id, 'payment_status', true);
                            $payment_status_text = match ($payment_status) {
                                1 => 'Chưa thanh toán',
                                2 => 'Đã thanh toán',
                                3 => 'Đã hủy',
                                4 => 'Thanh toán thất bại'
                            };
                            // $status_text = $row->status == 1 ? 'Đã quyết toán' : 'Chưa quyết toán';
                        ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="ticket_ids[]" value="<?php echo esc_attr($row->id); ?>" />
                                </th>
                                <td><?php echo esc_html($booking_codes); ?></td>
                                <td>
                                    <?php
                                    $contributor_user = get_users([
                                        'meta_key' => 'contributor_code',
                                        'meta_value' => $row->contributor_code,
                                        'number' => 1
                                    ]);

                                    if (!empty($contributor_user)) {
                                        $user_id = $contributor_user[0]->ID;
                                        $edit_link = get_edit_user_link($user_id);
                                        echo '<a href="' . esc_url($edit_link) . '">' . esc_html($row->contributor_code) . '</a>';
                                    } else {
                                        echo esc_html($row->contributor_code);
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($row->full_name); ?></td>
                                <td><?php echo esc_html($row->phone); ?></td>
                                <td><?php echo esc_html(timTuyenDuongID($search_from)); ?></td>
                                <td><?php echo esc_html(timTuyenDuongID($search_to)); ?></td>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td><?php echo esc_html($payment_status_text); ?></td>
                                <td>
                                    <form method="post" action="" style="display:inline;">
                                        <?php wp_nonce_field('update_ticket_status'); ?>
                                        <input type="hidden" name="ticket_id" value="<?php echo esc_attr($row->id); ?>">
                                        <select name="ticket_status">
                                            <option value="0" <?php selected($row->status, 0); ?>>Chưa quyết toán</option>
                                            <option value="1" <?php selected($row->status, 1); ?>>Đã quyết toán</option>
                                        </select>
                                        <input type="submit" name="update_payment_status" class="button" value="Cập nhật">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Toggle bulk status select
            $('select[name="bulk_action"]').change(function() {
                if ($(this).val() === 'update_status') {
                    $('select[name="bulk_status"]').show();
                } else {
                    $('select[name="bulk_status"]').hide();
                }
            });

            // Handle select all checkbox
            $('.wp-list-table thead .check-column input').change(function() {
                var checked = $(this).prop('checked');
                $('.wp-list-table tbody .check-column input').prop('checked', checked);
            });
        });

        function toggleExportOptions() {
            var exportType = document.getElementById('export_type').value;
            
            document.getElementById('month_options').style.display = 'none';
            document.getElementById('contributor_options').style.display = 'none';
            document.getElementById('date_range_options').style.display = 'none';
            document.getElementById('status_options').style.display = 'none';
            
            switch(exportType) {
                case 'month':
                    document.getElementById('month_options').style.display = 'block';
                    break;
                case 'contributor':
                    document.getElementById('contributor_options').style.display = 'block';
                    break;
                case 'date_range':
                    document.getElementById('date_range_options').style.display = 'block';
                    break;
                case 'status':
                    document.getElementById('status_options').style.display = 'block';
                    break;
            }
        }
    </script>
<?php
}

function contributor_tickets_shortcode()
{
    if (!is_user_logged_in()) {
        return '<p>Vui lòng đăng nhập để xem thông tin vé.</p>';
    }

    $user = wp_get_current_user();
    if (!in_array('contributor', (array)$user->roles) && !in_array('administrator', (array)$user->roles)) {
        return '<p>Chỉ cộng tác viên và quản trị viên mới có quyền truy cập trang này.</p>';
    }

    $contributor_code = get_user_meta($user->ID, 'contributor_code', true);
    if (empty($contributor_code)) {
        return '<p>Bạn chưa được gán mã cộng tác viên.</p>';
    }

    // Get filter parameters
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
    $phone_search = isset($_GET['phone']) ? sanitize_text_field($_GET['phone']) : '';
    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $payment_status_filter = isset($_GET['payment_status']) ? sanitize_text_field($_GET['payment_status']) : '';

    // Pagination settings
    $items_per_page = 15;
    $current_page = isset($_GET['pages']) ? max(1, intval($_GET['pages'])) : 1;
    $offset = ($current_page - 1) * $items_per_page;
    global $wpdb;
    $table_name = $wpdb->prefix . 'contributor_ticket';

    // Build query with filters
    $where_clauses = ["contributor_code = %s"];
    $query_params = [$contributor_code];

    if ($start_date && $end_date) {
        $where_clauses[] = "DATE(created_at) BETWEEN %s AND %s";
        $query_params[] = $start_date;
        $query_params[] = $end_date;
    }

    if ($status !== '') {
        $where_clauses[] = "status = %d";
        $query_params[] = intval($status);
    }

    // Count total records for pagination
    $count_query = "SELECT COUNT(*) FROM $table_name WHERE " . implode(' AND ', $where_clauses);
    $total_items = $wpdb->get_var($wpdb->prepare($count_query, $query_params));
    $total_pages = ceil($total_items / $items_per_page);

    // Get paginated results
    $query = "SELECT * FROM $table_name WHERE " . implode(' AND ', $where_clauses) .
        " ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $query_params[] = $items_per_page;
    $query_params[] = $offset;

    $results = $wpdb->get_results($wpdb->prepare($query, $query_params));

    ob_start();
?>
    <div class="contributor-tickets">
        <h2>Danh sách vé sử dụng mã của bạn (<?php echo esc_html($contributor_code); ?>)</h2>

        <!-- Filter Form -->
        <form method="get" class="filter-form">
            <div class="filter-row">
                <div class="filter-item">
                    <label>Từ ngày:</label>
                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                </div>
                <div class="filter-item">
                    <label>Đến ngày:</label>
                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                </div>
                <div class="filter-item">
                    <label>Số điện thoại:</label>
                    <input type="text" name="phone" value="<?php echo esc_attr($phone_search); ?>">
                </div>
                <div class="filter-item">
                    <label>Trạng thái đơn:</label>
                    <select name="status">
                        <option value="">Tất cả</option>
                        <option value="1" <?php selected($status, '1'); ?>>Đã quyết toán</option>
                        <option value="0" <?php selected($status, '0'); ?>>Chưa quyết toán</option>
                    </select>
                </div>
                <div class="filter-item">
                    <label>Trạng thái vé:</label>
                    <select name="payment_status">
                        <option value="">Tất cả</option>
                        <option value="1" <?php selected($payment_status_filter, '1'); ?>>Chưa thanh toán</option>
                        <option value="2" <?php selected($payment_status_filter, '2'); ?>>Đã thanh toán</option>
                        <option value="3" <?php selected($payment_status_filter, '3'); ?>>Đã hủy</option>
                        <option value="4" <?php selected($payment_status_filter, '3'); ?>>Thanh toán thất bại</option>
                    </select>
                </div>
                <button type="submit" class="btn-filler">Lọc</button>
            </div>
        </form>

        <?php if (empty($results)): ?>
            <p>Chưa có vé nào sử dụng mã cộng tác viên của bạn.</p>
        <?php else: ?>
            <table class="contributor-tickets-table">
                <thead>
                    <tr>
                        <th>Mã vé</th>
                        <th>Khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Điểm đi</th>
                        <th>Điểm đến</th>
                        <th>Ngày đặt</th>
                        <th>Trạng thái vé</th>
                        <th>Trạng thái thanh toán</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $row):
                        $post_id = $row->ticket_id;
                        $booking_codes = get_post_meta($post_id, 'booking_codes', true);
                        $full_name = get_post_meta($post_id, 'full_name', true);
                        $phone = get_post_meta($post_id, 'phone', true);

                        if ($phone_search && stripos($phone, $phone_search) === false) {
                            continue;
                        }

                        if ($payment_status_filter !== '') {
                            $payment_status = get_post_meta($post_id, 'payment_status', true);
                            if ($payment_status != $payment_status_filter) {
                                continue;
                            }
                        }

                        $search_from = get_post_meta($post_id, 'search_from', true);
                        $search_to = get_post_meta($post_id, 'search_to', true);
                        $created_at = date('d/m/Y', strtotime($row->created_at));
                        $payment_status = (int) get_post_meta($post_id, 'payment_status', true);
                        $payment_status_text = match ($payment_status) {
                            1 => 'Chưa thanh toán',
                            2 => 'Đã thanh toán',
                            3 => 'Đã hủy',
                            4 => 'Thanh toán thất bại',
                        };
                        $ticket_status = (int) $row->status;
                        $ticket_status_text = match ($ticket_status) {
                            0 => 'Chưa quyết toán',
                            1 => 'Đã quyết toán',
                            // default => 'Chưa quyết toán',
                        };
                    ?>
                        <tr>
                            <td><?php echo esc_html($booking_codes); ?></td>
                            <td><?php echo esc_html($full_name); ?></td>
                            <td><?php echo esc_html($phone); ?></td>
                            <td><?php echo esc_html(timTuyenDuongID($search_from)); ?></td>
                            <td><?php echo esc_html(timTuyenDuongID($search_to)); ?></td>
                            <td><?php echo esc_html($created_at); ?></td>
                            <td><?php echo esc_html($payment_status_text); ?></td>
                            <td><?php echo esc_html($ticket_status_text); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $current_class = ($i === $current_page) ? 'current' : '';
                        echo sprintf(
                            '<a href="?pages=%d&start_date=%s&end_date=%s&phone=%s&status=%s&payment_status=%s" class="%s">%d</a>',
                            $i,
                            esc_attr($start_date),
                            esc_attr($end_date),
                            esc_attr($phone_search),
                            esc_attr($status),
                            esc_attr($payment_status_filter),
                            $current_class,
                            $i
                        );
                    }
                    ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('contributor_tickets', 'contributor_tickets_shortcode');
