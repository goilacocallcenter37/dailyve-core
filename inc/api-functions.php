<?php
$autoload_path = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    error_log('Autoload file not found: ' . $autoload_path);
}

// Require extracted API modules
require_once __DIR__ . '/api/cron-jobs.php';
require_once __DIR__ . '/api/ajax-company.php';
require_once __DIR__ . '/api/ajax-amenities-policy.php';
require_once __DIR__ . '/api/rest-endpoints.php';

use PragmaRX\Google2FA\Google2FA;

function call_api_v2($endpoint, $method = 'GET', $data = [], $headers = [], $timeout = 180)
{
    $method = strtoupper($method);

    if (!defined('END_POINT_V2') || !defined('X_API_KEY')) {
        return new \WP_Error('missing_api_config', 'Thiếu END_POINT_V2 hoặc X_API_KEY trong wp-config.php.');
    }

    if (preg_match('#^https?://#i', $endpoint)) {
        $url = $endpoint;
    } else {
        $base = rtrim(END_POINT_V2, '/');
        $url = $base . '/' . ltrim($endpoint, '/');
    }

    if ($method === 'GET' && !empty($data)) {
        $data = array_filter($data, function ($v) {
            return $v !== null && $v !== '';
        });

        $url = add_query_arg($data, $url);
    }

    $default_headers = [
        'x-api-key'     => X_API_KEY,
        'Content-Type'  => 'application/json',
    ];

    $args = [
        'method'  => $method,
        'headers' => array_merge($default_headers, is_array($headers) ? $headers : []),
        'timeout' => (int) $timeout,
    ];

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $args['body'] = !empty($data) ? wp_json_encode($data) : '{}';
    }

    return wp_remote_request($url, $args);
}

/**
 * Fetch operators for a specific route from API v2.
 * Results are cached via WordPress Transients for 1 hour.
 *
 * @param string $from  Departure location text (e.g. "Sài Gòn")
 * @param string $to    Destination location text (e.g. "Bạc Liêu")
 * @return array|WP_Error  Array of operator items or WP_Error on failure
 */
function dailyve_get_operators_by_route($from, $to, $from_id = '', $to_id = '')
{
    $cache_key = 'dv_operators_' . md5($from . '_' . $to . '_' . $from_id . '_' . $to_id);
    $cached = get_transient($cache_key);

    if ($cached !== false) {
        return $cached;
    }

    $params = [
        'includeSubLocations' => 'true',
        'limitRoutes'         => '1',
        'pageSize'            => '100',
    ];

    if (!empty($from_id) && !empty($to_id)) {
        $params['from_id'] = $from_id;
        $params['to_id']   = $to_id;
    } else {
        $params['from'] = $from;
        $params['to']   = $to;
    }

    $response = call_api_v2('/operators', 'GET', $params);

    if (is_wp_error($response)) {
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data) || !isset($data['items'])) {
        return new \WP_Error('api_parse_error', 'Không thể phân tích dữ liệu API.');
    }

    $result = [
        'items'       => $data['items'] ?? [],
        'total'       => $data['total'] ?? 0,
        'totalRoutes' => $data['totalRoutes'] ?? 0,
    ];

    // Cache for 1 hour
    set_transient($cache_key, $result, HOUR_IN_SECONDS);

    return $result;
}


function dv_get_client_ip()
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            return trim($ips[0]);
        }
    }

    return '0.0.0.0';
}

/**
 * Legacy helpers used by the extracted booking AJAX callbacks.
 *
 * They are registered after the active theme has loaded so Flatsome Child can
 * keep owning these global helper names when it is active. Sage does not define
 * them, so Dailyve Core provides fallbacks for the migrated booking flow.
 */
add_action('after_setup_theme', 'dailyve_core_register_legacy_helper_fallbacks', 100);
function dailyve_core_register_legacy_helper_fallbacks()
{
    if (!function_exists('formatVND')) {
        function formatVND($amount)
        {
            return number_format($amount, 0, ',', '.');
        }
    }

    if (!function_exists('convertToTitleCase')) {
        function convertToTitleCase($type)
        {
            $type = strtolower(trim($type));
            return ucwords($type);
        }
    }

    if (!function_exists('ams_normalize_points_payload')) {
        function ams_normalize_points_payload($payload)
        {
            if (!is_array($payload)) {
                return [];
            }

            $out = [];
            foreach ($payload as $item) {
                $district = '';
                $tripCount = 0;

                if (is_string($item) || is_numeric($item)) {
                    $district = trim((string) $item);
                } elseif (is_array($item)) {
                    $district = trim((string) ($item['district'] ?? $item['pointName'] ?? $item['name'] ?? ''));
                    $tripCount = (int) ($item['trip_count'] ?? $item['tripCount'] ?? 0);
                }

                if ($district === '') {
                    continue;
                }

                $out[] = [
                    'district' => $district,
                    'trip_count' => $tripCount,
                ];
            }

            return $out;
        }
    }

    if (!function_exists('ams_decode_points_query')) {
        function ams_decode_points_query($key)
        {
            if (empty($_GET[$key])) {
                return [];
            }

            $raw = wp_unslash($_GET[$key]);
            $decoded = base64_decode($raw, true);

            if ($decoded !== false) {
                $json = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return ams_normalize_points_payload($json);
                }
            }

            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ams_normalize_points_payload($json);
            }

            $parts = array_filter(array_map('trim', explode(',', (string) $raw)));
            return ams_normalize_points_payload($parts);
        }
    }

    if (!function_exists('ams_point_key')) {
        function ams_point_key($p)
        {
            return isset($p['district']) ? trim((string) $p['district']) : '';
        }
    }

    if (!function_exists('getInitialsNameToAvatar')) {
        function getInitialsNameToAvatar($fullName)
        {
            $fullName = trim((string) $fullName);
            if ($fullName === '') {
                return 'DV';
            }

            $words = preg_split('/\s+/u', $fullName);
            if (!$words) {
                return 'DV';
            }

            if (count($words) > 1) {
                $first = mb_substr($words[0], 0, 1, 'UTF-8');
                $last = mb_substr($words[count($words) - 1], 0, 1, 'UTF-8');
                return mb_strtoupper($first . $last, 'UTF-8');
            }

            return mb_strtoupper(mb_substr($words[0], 0, 1, 'UTF-8'), 'UTF-8');
        }
    }

    if (!function_exists('compareByRealTime')) {
        function compareByRealTime($a, $b)
        {
            $rawA = $a['realTime'] ?? $a['real_time'] ?? '';
            $rawB = $b['realTime'] ?? $b['real_time'] ?? '';

            $dateTimeA = DateTime::createFromFormat('H:i d-m-Y', (string) $rawA);
            $dateTimeB = DateTime::createFromFormat('H:i d-m-Y', (string) $rawB);

            if (!$dateTimeA) {
                try {
                    $dateTimeA = new DateTime((string) $rawA);
                } catch (Exception $e) {
                    $dateTimeA = new DateTime('@0');
                }
            }

            if (!$dateTimeB) {
                try {
                    $dateTimeB = new DateTime((string) $rawB);
                } catch (Exception $e) {
                    $dateTimeB = new DateTime('@0');
                }
            }

            return $dateTimeA <=> $dateTimeB;
        }
    }

    if (!function_exists('totalTimeRoute')) {
        function totalTimeRoute($thoiGianBatDau, $thoiGianKetThuc)
        {
            try {
                $thoiGianBatDau = preg_replace('/\+\d{2}:\d{2}$/', '', (string) $thoiGianBatDau);
                $thoiGianKetThuc = preg_replace('/\+\d{2}:\d{2}$/', '', (string) $thoiGianKetThuc);

                $dtBatDau = new DateTime($thoiGianBatDau);
                $dtKetThuc = new DateTime($thoiGianKetThuc);
                $seconds = max(0, $dtKetThuc->getTimestamp() - $dtBatDau->getTimestamp());
                $hours = floor($seconds / HOUR_IN_SECONDS);
                $minutes = floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);

                return $minutes > 0 ? $hours . ' giờ ' . $minutes . ' phút' : $hours . ' giờ';
            } catch (Exception $e) {
                return '';
            }
        }
    }

    if (!function_exists('formatDateISO')) {
        function formatDateISO($date)
        {
            if (empty($date)) {
                return '';
            }

            try {
                return (new DateTime((string) $date))->format('H:i d-m-Y');
            } catch (Exception $e) {
                return '';
            }
        }
    }

    if (!function_exists('getTime')) {
        function getTime($date)
        {
            $date = (string) $date;
            $timePart = strpos($date, 'T') !== false ? explode('T', $date)[1] : $date;
            return substr($timePart, 0, 5);
        }
    }

    if (!function_exists('convertDateTimeToHour')) {
        function convertDateTimeToHour($para)
        {
            if (empty($para)) {
                return '';
            }

            $clean = str_replace(['h', 'H'], ':', (string) $para);
            try {
                return (new DateTime($clean))->format('H:i');
            } catch (Exception $e) {
                return (string) $para;
            }
        }
    }

    if (!function_exists('dv_rate_limit')) {
        function dv_rate_limit($action_key, $seconds = 5, $max_requests = 5)
        {
            $ip = function_exists('dv_get_client_ip') ? dv_get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $rl_key = 'dv_rl_' . md5($action_key . '|' . $ip . '|' . $ua);
            $data = get_transient($rl_key);

            if (!$data) {
                set_transient($rl_key, ['count' => 1, 'start' => time()], $seconds);
                return true;
            }

            if (($data['count'] ?? 0) >= $max_requests) {
                return false;
            }

            $data['count'] = (int) ($data['count'] ?? 0) + 1;
            set_transient($rl_key, $data, $seconds);

            return true;
        }
    }

    if (!function_exists('dv_cached_api_call')) {
        function dv_cached_api_call($cache_key, $ttl_seconds, $endpoint, $method, $params)
        {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }

            $resp = call_api_v2($endpoint, $method, $params);

            if (!is_wp_error($resp) && isset($resp['body'])) {
                set_transient($cache_key, $resp, (int) $ttl_seconds);
            }

            return $resp;
        }
    }
}


function generateOTP($secret)
{
    $google2fa = new Google2FA();
    $otp = $google2fa->getCurrentOtp($secret);
    return $otp;
}

function convert_to_boolean($value)
{
    if (is_string($value)) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
    return $value;
}
function convertStringToDateName($dateString)
{
    // $dateString = '00:36 27/09/2024';
    if (empty($dateString)) {
        return '';
    }
    $dateString = str_replace('-', '/', $dateString);
    $date = DateTime::createFromFormat('H:i d/m/Y', $dateString);
    $formattedDate = $date->format('D, d/m/Y');
    $dayMap = [
        'Mon' => 'T2',
        'Tue' => 'T3',
        'Wed' => 'T4',
        'Thu' => 'T5',
        'Fri' => 'T6',
        'Sat' => 'T7',
        'Sun' => 'CN'
    ];
    // Thay thế tên viết tắt ngày trong chuỗi
    $formattedDate = str_replace(array_keys($dayMap), array_values($dayMap), $formattedDate);
    return $formattedDate;
}
function convertStringToTimeN($dateString)
{
    if (empty($dateString)) return '00:00';

    // Handle duplicated Goopay format: "2026-03-13 01:30:00 2026-03-13 01:30:00" → take first part
    $trimmed = trim((string)$dateString);
    // Nếu chuỗi dài hơn 19 ký tự và chứa format Y-m-d H:i:s lặp đôi
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $trimmed, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false) return date('H:i', $ts);
    }

    $dateString = str_replace('-', '/', $trimmed);

    // ISO format after dash-to-slash: "2026/03/13 01:30:00" or "Y/m/d H:i:s"
    $date = DateTime::createFromFormat('Y/m/d H:i:s', $dateString);
    if ($date) return $date->format('H:i');

    $date = DateTime::createFromFormat('Y/m/d H:i', $dateString);
    if ($date) return $date->format('H:i');

    if (strpos($dateString, 'AM') !== false || strpos($dateString, 'PM') !== false) {
        $date = DateTime::createFromFormat('h:ia d/m/Y', $dateString);
    } else {
        $date = DateTime::createFromFormat('H:i d/m/Y', $dateString);
    }
    if ($date) return $date->format('H:i');

    // Fallback: strtotime
    $ts = strtotime(str_replace('/', '-', $dateString));
    if ($ts !== false && $ts > 0) return date('H:i', $ts);

    return $dateString;
}
function caculatorPriceTotal($format = false)
{
    if (!empty($_SESSION['tickets'])) {
        $tickets = $_SESSION['tickets'];
        $total = 0;
        $total_subcharge = 0;

        $depart_price = 0;
        $return_price = 0;
        foreach ($tickets as $idx => $ticket) {
            $ticket_fare_sum = 0;
            if (!empty($ticket['selectedSeats']) && is_array($ticket['selectedSeats'])) {
                foreach ($ticket['selectedSeats'] as $item) {
                    $ticket_fare_sum += (float)($item['fare'] ?? 0);
                }
            }
            $total += $ticket_fare_sum;

            $pickup_subcharge = 0;
            $dropoff_subcharge = 0;
            $pickupPointForSurcharge = !empty($ticket['pickupPoint']) ? $ticket['pickupPoint'] : ($ticket['transferPickupPoint'] ?? []);
            $dropoffPointForSurcharge = !empty($ticket['dropoffPoint']) ? $ticket['dropoffPoint'] : ($ticket['transferDropoffPoint'] ?? []);

            if (isset($pickupPointForSurcharge['surcharge_type']) && (int)$pickupPointForSurcharge['surcharge_type'] === 2) {
                $pickup_subcharge = (float)($ticket['pickupSurcharge'] ?? 0);
            }
            if (isset($dropoffPointForSurcharge['surcharge_type']) && (int)$dropoffPointForSurcharge['surcharge_type'] === 2) {
                $dropoff_subcharge = (float)($ticket['dropoffSurcharge'] ?? 0);
            }

            $total_subcharge += $pickup_subcharge;
            $total_subcharge += $dropoff_subcharge;

            $current_leg_price = $ticket_fare_sum + $pickup_subcharge + $dropoff_subcharge;

            if ($idx === 0) {
                $depart_price = $current_leg_price;
            } elseif ($idx === 1) {
                $return_price = $current_leg_price;
            }
        }

        $total += $total_subcharge;

        return [
            'total_price' => $format ? number_format($total, 0, ",", ".") . 'đ' : $total,
            'depart_price' => $depart_price,
            'return_price' => $return_price
        ];

        //return $format ? number_format($total, 0, ",", ".") . 'đ' : $total;
    }
    return [
        'total_price' => $format ? number_format(0, 0, ",", ".") . 'đ' : 0,
        'depart_price' => 0,
        'return_price' => 0
    ];
}
function is_valid_api_key($request)
{
    $api_key = $request->get_param('api_key');
    $valid_key = API_KEY_CLIENT;
    return $api_key === $valid_key;
}
function is_valid_api_key_automation($request)
{
    $api_key = $request->get_param('api_key');
    $valid_key = API_KEY_AUTOMATION;
    $valid_key2 = API_KEY_CLIENT;
    return $api_key === $valid_key || $api_key === $valid_key2;
}
// REST Endpoints have been moved to inc/api/rest-endpoints.php
function get_post_details($data)
{
    $post_id = $data['id'];
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return new WP_Error('no_post', 'Bài viết không tồn tại hoặc chưa được công khai.', ['status' => 404]);
    }
    return [
        'id' => $post->ID,
        'title' => get_the_title($post),
        'content' => apply_filters('the_content', $post->post_content),
        'excerpt' => get_the_excerpt($post),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'date' => get_the_date('', $post),
        'categories' => wp_get_post_categories($post->ID, ['fields' => 'names']),
        'tags' => wp_get_post_tags($post->ID, ['fields' => 'names']),
        'thumbnail' => get_the_post_thumbnail_url($post, 'full'),
    ];
}
function get_company_details($data)
{
    $company_id = $data['id'];
    $args = [
        'post_type' => 'post',
        'posts_per_page' => 1,
        'category__in' => [6],
        'meta_query' => [
            [
                'key' => 'company_id',
                'value' => $company_id,
                'compare' => '=',
            ],
        ],
    ];
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $post_id = $query->posts[0]->ID;
        return [
            'id' => $post_id,
            'company' => get_field('company_id', $post_id) ?? null,
            'title' => $query->posts[0]->post_title,
            'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
            'thumbnail' => get_the_post_thumbnail_url($post_id, 'medium'),
            'vehicle_type' => get_field('vehicle_type', $post_id) ?? [],
            'gallery' => get_field('company_gallery', $post_id) ?? [],
        ];
    } else {
        return new WP_Error('no_post', 'Nhà xe không tồn tại hoặc chưa được công khai.', ['status' => 404]);
    }
}
//API ALL COMPANY
function get_test_list_company()
{
    $args = [
        'post_type' => 'post',
        'category__in' => [6],
        'meta_query' => [
            [
                'key' => 'company_id',
                'value' => '',
                'compare' => '=',
            ],
        ],
        'relation' => 'OR',
    ];
    $query = new WP_Query($args);
    $companies = [];
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $companies[] = [
                'company' => get_field('company_id', $post_id) ?? null,
                'title' => get_the_title(),
            ];
        }
        return $companies;
    } else {
        return new WP_Error('no_post', 'Nhà xe không tồn tại hoặc chưa được công khai.', ['status' => 404]);
    }
}
function get_all_companies($request)
{
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $offset = ($page - 1) * $per_page;
    $args = [
        'post_type' => 'post',
        'posts_per_page' => $per_page,
        'offset' => $offset,
        'category__in' => [6],
    ];
    $query = new WP_Query($args);
    // Lấy tổng số companies
    $total_items = $query->found_posts;
    $companies = [];
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $companies[] = [
                'id' => $post_id,
                'company' => get_field('company_id', $post_id) ?? null,
                'title' => get_the_title(),
                'address' => get_field('company_address', $post_id) ?? null,
                'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
                // 'thumbnail'  => get_the_post_thumbnail_url($post_id, 'medium'),
                'vehicle_type' => get_field('vehicle_type', $post_id) ?? [],
                // 'gallery' => get_field('company_gallery', $post_id) ?? [],
                'company_brand' => get_field('company_brand', $post_id) ?? [],
                'driving_schedule' => get_field('driving_schedule', $post_id) ?? [],
            ];
        }
    } else {
        return new WP_Error('no_post', 'Nhà xe không tồn tại hoặc chưa được công khai.', ['status' => 404]);
    }
    // Thêm thông tin phân trang vào response headers
    $total_pages = ceil($total_items / $per_page);
    return new WP_REST_Response([
        'data' => $companies,
        'meta' => [
            'current_page' => (int) $page,
            'per_page' => (int) $per_page,
            'total_items' => (int) $total_items,
            'total_pages' => $total_pages
        ]
    ], 200);
}


function get_ticket_by_phone_number($request)
{
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $phone_number = $request->get_param('phone_number');
    $status = $request->get_param('status');
    $start_date = $request->get_param('start_date');
    $end_date = $request->get_param('end_date');

    $offset = ($page - 1) * $per_page;

    $args = [
        'post_type' => 'book-ticket',
        'posts_per_page' => $per_page,
        'offset' => $offset,
        'meta_query' => [
            [
                'key' => 'phone',
                'value' => $phone_number,
                'compare' => '='
            ]
        ]
    ];

    if ($start_date && $end_date) {
        $args['date_query'] = [
            [
                'after' => date('Y-m-d', strtotime(str_replace('/', '-', $start_date))),
                'before' => date('Y-m-d', strtotime(str_replace('/', '-', $end_date))),
                'inclusive' => true,
                'column' => 'post_date'
            ]
        ];
    }

    if ($status) {
        $args['meta_query'][] = [
            'key' => 'payment_status',
            'value' => $status,
            'compare' => '='
        ];
    }

    $query = new WP_Query($args);
    $total_items = $query->found_posts;
    $tickets = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $status = get_post_meta($post_id, 'payment_status', true);

            $bookingCode = get_the_title($post_id);
            $codeArr = explode(" ", $bookingCode);
            if ($status == 1) {
                foreach ($codeArr as $code) {
                    $url = endPoint . "/Api/Ticket/BookingSearch?bookingCode=" . $code;
                    $response = call_api_with_token_agent($url, 'GET');

                    if (is_wp_error($response)) {
                        continue;
                    }

                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);

                    if (!empty($data) && isset($data['data'][0]['ticket']['status'])) {
                        $status = $data["data"][0]["ticket"]["status"];
                        $expiredTime = $data['data'][0]['expiredTime'] ?? '';

                        update_post_meta($post_id, 'payment_status', $status);
                        if (!empty($expiredTime)) {
                            update_post_meta($post_id, 'expired_time', $expiredTime);
                        }
                    }
                }
            }

            // If status is 2 (Paid), update pickup_date from v2 API
            if ($status == 2) {
                $partner = get_post_meta($post_id, 'partner_id', true);
                if ($partner && $bookingCode) {
                    $firstCode = explode(" ", $bookingCode)[0];
                    $api_v2_url = "api/v2/booking/{$firstCode}?partner={$partner}";
                    $resp_v2 = call_api_v2($api_v2_url, 'GET');
                    if (!is_wp_error($resp_v2)) {
                        $body_v2 = wp_remote_retrieve_body($resp_v2);
                        $data_v2 = json_decode($body_v2, true);
                        if (!empty($data_v2) && isset($data_v2['departureTime'])) {
                            update_post_meta($post_id, 'pickup_date', $data_v2['departureTime']);
                        }
                    }
                }
            }

            if ($status == 3) {
                $now = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
                $updated_at = get_post_meta($post_id, 'updated_at', true);
                if (empty($updated_at)) {
                    update_post_meta($post_id, 'updated_at', $now->format('H:i d/m/Y'));
                }
            }


            $post_meta = get_post_meta($post_id);

            // Get coupon info from ticket_coupon table
            global $wpdb;
            $coupon_info = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * 
                     FROM {$wpdb->prefix}ticket_coupon 
                     WHERE ticket_id = %d AND phone = %s",
                    $post_id,
                    $phone_number
                )
            );

            $tickets[] = [
                'id' => $post_id,
                'booking_codes' => $post_meta['booking_codes'][0] ?? '',
                'ticket_codes' => $post_meta['ticket_codes'][0] ?? '',
                'search_from' => [
                    'id' => $post_meta['search_from'][0] ?? '',
                    'name' => timTuyenDuongID($post_meta['search_from'][0]) ?? ''
                ],
                'search_to' => [
                    'id' => $post_meta['search_to'][0] ?? '',
                    'name' => timTuyenDuongID($post_meta['search_to'][0]) ?? ''
                ],
                'discount' => $post_meta['discount'][0] ?? '',
                'discount_type' => $post_meta['discount_type'][0] ?? '',
                'original_price' => $post_meta['original_price'][0] ?? 0,
                'total_price' => $post_meta['total_price'][0] ?? 0,
                'company_name' => $post_meta['company_bus'][0] ?? '',
                'vehicle_name' => $post_meta['vehicle_name'][0] ?? '',
                'pickup_date' => $post_meta['pickup_date'][0] ?? '',
                'arrival_date' => $post_meta['arrival_date'][0] ?? '',
                'seat_depart' => $post_meta['seat_depart'][0] ?? '',
                'seat_arrive' => $post_meta['seat_arrive'][0] ?? '',
                'payment_status' => $post_meta['payment_status'][0] ?? null,
                'payment_content' => $post_meta['payment_content'][0] ?? '',
                'full_name' => $post_meta['full_name'][0] ?? '',
                'phone' => $post_meta['phone'][0] ?? '',
                'email' => $post_meta['email'][0] ?? '',
                'note' => $post_meta['note'][0] ?? '',
                'route_name' => $post_meta['routeName'][0] ?? '',
                'expired_time' => $post_meta['expired_time'][0] ?? '',
                'coupon' => [
                    'id' => $coupon_info ? $coupon_info->id : null,
                    'code' => $coupon_info ? $coupon_info->code : null,
                    'coupon_id' => $coupon_info ? $coupon_info->coupon_id : null,
                ]
            ];
        }
        wp_reset_postdata();
    } else {
        return new WP_REST_Response([
            'data' => [],
            'meta' => [
                'current_page' => (int) $page,
                'per_page' => (int) $per_page,
                'total_items' => 0,
                'total_pages' => 0
            ]
        ], 200);
    }

    $total_pages = ceil($total_items / $per_page);

    return new WP_REST_Response([
        'data' => $tickets,
        'meta' => [
            'current_page' => (int) $page,
            'per_page' => (int) $per_page,
            'total_items' => (int) $total_items,
            'total_pages' => $total_pages
        ]
    ], 200);
}

function get_ticket_detail_by_id($request)
{
    $ticket_id = $request->get_param('id');

    if (!$ticket_id || !is_numeric($ticket_id)) {
        return new WP_REST_Response([
            'message' => 'ID vé không hợp lệ',
        ], 400);
    }

    // Check if post exists and is of correct type
    $post = get_post($ticket_id);
    if (!$post || $post->post_type !== 'book-ticket') {
        return new WP_REST_Response([
            'message' => 'Không tìm thấy vé',
        ], 404);
    }

    $post_id = $ticket_id;
    $status = get_post_meta($post_id, 'payment_status', true);
    $phone_number = get_post_meta($post_id, 'phone', true);

    // Update ticket status if payment_status = 1
    $bookingCode = get_the_title($post_id);
    $codeArr = explode(" ", $bookingCode);
    if ($status == 1) {
        foreach ($codeArr as $code) {
            $url = endPoint . "/Api/Ticket/BookingSearch?bookingCode=" . $code;
            $response = call_api_with_token_agent($url, 'GET');

            if (is_wp_error($response)) {
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (!empty($data) && isset($data['data'][0]['ticket']['status'])) {
                $status = $data["data"][0]["ticket"]["status"];
                $expiredTime = $data['data'][0]['expiredTime'] ?? '';

                update_post_meta($post_id, 'payment_status', $status);
                if (!empty($expiredTime)) {
                    update_post_meta($post_id, 'expired_time', $expiredTime);
                }
            }
        }
    }

    // If status is 2 (Paid), update pickup_date from v2 API
    if ($status == 2) {
        $partner = get_post_meta($post_id, 'partner_id', true);
        $bookingCodeMeta = get_post_meta($post_id, 'booking_codes', true) ?: get_the_title($post_id);
        if ($partner && $bookingCodeMeta) {
            $firstCode = explode(" ", $bookingCodeMeta)[0];
            $api_v2_url = "api/v2/booking/{$firstCode}?partner={$partner}";
            $resp_v2 = call_api_v2($api_v2_url, 'GET');
            if (!is_wp_error($resp_v2)) {
                $body_v2 = wp_remote_retrieve_body($resp_v2);
                $data_v2 = json_decode($body_v2, true);
                if (!empty($data_v2) && isset($data_v2['departureTime'])) {
                    update_post_meta($post_id, 'pickup_date', $data_v2['departureTime']);
                }
            }
        }
    }

    if ($status == 3) {
        $now = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
        $updated_at = get_post_meta($post_id, 'updated_at', true);
        if (empty($updated_at)) {
            update_post_meta($post_id, 'updated_at', $now->format('H:i d/m/Y'));
        }
    }

    $post_meta = get_post_meta($post_id);

    // Get coupon info from ticket_coupon table
    global $wpdb;
    $coupon_info = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * 
             FROM {$wpdb->prefix}ticket_coupon 
             WHERE ticket_id = %d AND phone = %s",
            $post_id,
            $phone_number
        )
    );

    $ticket_detail = [
        'id' => $post_id,
        'booking_codes' => $post_meta['booking_codes'][0] ?? '',
        'ticket_codes' => $post_meta['ticket_codes'][0] ?? '',
        'search_from' => [
            'id' => $post_meta['search_from'][0] ?? '',
            'name' => timTuyenDuongID($post_meta['search_from'][0]) ?? ''
        ],
        'search_to' => [
            'id' => $post_meta['search_to'][0] ?? '',
            'name' => timTuyenDuongID($post_meta['search_to'][0]) ?? ''
        ],
        'discount' => $post_meta['discount'][0] ?? '',
        'discount_type' => $post_meta['discount_type'][0] ?? '',
        'original_price' => $post_meta['original_price'][0] ?? 0,
        'total_price' => $post_meta['total_price'][0] ?? 0,
        'company_name' => $post_meta['company_bus'][0] ?? '',
        'vehicle_name' => $post_meta['vehicle_name'][0] ?? '',
        'pickup_date' => $post_meta['pickup_date'][0] ?? '',
        'arrival_date' => $post_meta['arrival_date'][0] ?? '',
        'seat_depart' => $post_meta['seat_depart'][0] ?? '',
        'seat_arrive' => $post_meta['seat_arrive'][0] ?? '',
        'payment_status' => $post_meta['payment_status'][0] ?? null,
        'payment_content' => $post_meta['payment_content'][0] ?? '',
        'full_name' => $post_meta['full_name'][0] ?? '',
        'phone' => $post_meta['phone'][0] ?? '',
        'email' => $post_meta['email'][0] ?? '',
        'note' => $post_meta['note'][0] ?? '',
        'route_name' => $post_meta['routeName'][0] ?? '',
        'expired_time' => $post_meta['expired_time'][0] ?? '',
        'coupon' => [
            'id' => $coupon_info ? $coupon_info->id : null,
            'code' => $coupon_info ? $coupon_info->code : null,
            'coupon_id' => $coupon_info ? $coupon_info->coupon_id : null,
        ]
    ];

    return new WP_REST_Response([
        'data' => $ticket_detail,
        'message' => 'Lấy chi tiết vé thành công'
    ], 200);
}

function get_tickets_today($request)
{
    $now = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));
    // $one_hour_from_now = (clone $now)->modify('+1 hour');

    $args = [
        'post_type' => 'book-ticket',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => 'pickup_date',
                'value' => $now->format('d-m-Y'),
                'compare' => 'LIKE',
            ],
            [
                'key' => 'expired_time',
                'value' => $now->format('d/m/Y'),
                'compare' => 'LIKE',
            ],
            [
                'key' => 'updated_at',
                'value' => $now->format('d/m/Y'),
                'compare' => 'LIKE',
            ]
        ]
    ];

    $query = new WP_Query($args);
    $tickets = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $status = get_post_meta($post_id, 'payment_status', true);

            $post_meta = get_post_meta($post_id);

            if ($status == 1) {
                $bookingCode = get_the_title($post_id);
                $codeArr = explode(" ", $bookingCode);

                foreach ($codeArr as $code) {
                    $url = endPoint . "/Api/Ticket/BookingSearch?bookingCode=" . $code;
                    $response = call_api_with_token_agent($url, 'GET');

                    if (!is_wp_error($response)) {
                        $body = wp_remote_retrieve_body($response);
                        $data = json_decode($body, true);

                        if (!empty($data) && isset($data['data'][0]['ticket']['status'])) {
                            $status = $data["data"][0]["ticket"]["status"];
                            $expiredTime = $data['data'][0]['expiredTime'] ?? '';
                            if ($status == 3) {
                                $updated_at = get_post_meta($post_id, 'updated_at', true);
                                if (empty($updated_at)) {
                                    update_post_meta($post_id, 'updated_at', $now->format('H:i d/m/Y'));
                                }
                            }
                            update_post_meta($post_id, 'payment_status', $status);
                            if (!empty($expiredTime)) {
                                update_post_meta($post_id, 'expired_time', $expiredTime);
                                $post_meta['expired_time'][0] = $expiredTime;
                            }
                        }
                    }
                }
            } elseif ($status == 3) {
                $updated_at = get_post_meta($post_id, 'updated_at', true);
                if (empty($updated_at)) {
                    update_post_meta($post_id, 'updated_at', $now->format('H:i d/m/Y'));
                }
            }

            $tickets[] = [
                'id' => $post_id,
                'booking_codes' => $post_meta['booking_codes'][0] ?? '',
                'ticket_codes' => $post_meta['ticket_codes'][0] ?? '',
                'search_from' => [
                    'id' => $post_meta['search_from'][0] ?? '',
                    'name' => timTuyenDuongID($post_meta['search_from'][0] ?? '') ?? '',
                ],
                'search_to' => [
                    'id' => $post_meta['search_to'][0] ?? '',
                    'name' => timTuyenDuongID($post_meta['search_to'][0] ?? '') ?? '',
                ],
                'total_price' => $post_meta['total_price'][0] ?? 0,
                'company_name' => $post_meta['company_bus'][0] ?? '',
                'vehicle_name' => $post_meta['vehicle_name'][0] ?? '',
                'pickup_date' => $post_meta['pickup_date'][0] ?? '',
                'arrival_date' => $post_meta['arrival_date'][0] ?? '',
                'seat_depart' => $post_meta['seat_depart'][0] ?? '',
                'seat_arrive' => $post_meta['seat_arrive'][0] ?? '',
                'full_name' => $post_meta['full_name'][0] ?? '',
                'phone' => $post_meta['phone'][0] ?? '',
                'email' => $post_meta['email'][0] ?? '',
                'note' => $post_meta['note'][0] ?? '',
                'expired_time' => $post_meta['expired_time'][0] ?? '',
                'payment_status' => $status
            ];
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response([
        'data' => $tickets
    ], 200);
}


function get_bus_route($request)
{
    $from = $request->get_param('from');
    $to = $request->get_param('to');
    $newDateString = date('Y-m-d', strtotime('+1 day'));
    $paramsStatistic = array(
        "newKeyFrom" => "$from",
        "newKeyTo" => "$to",
        "date" => $newDateString,
        "timeMin" => '00:00',
        "timeMax" => '23:59',
        "companies" => [],
        "seatType" => [],
        "sort" => 'time:asc',
    );
    $responseStatistic = call_api_with_token_agent(endPoint . '/Api/Book/RouteStatistic', 'POST', $paramsStatistic);
    if (!is_wp_error($responseStatistic)) {
        $dataStatistic = json_decode($responseStatistic['body'], true);
        return new WP_REST_Response([
            'data' => $dataStatistic['data']['companies'],
            'meta' => [
                'from' => timTuyenDuongID($from),
                'to' => timTuyenDuongID($to)
            ]
        ], 200);
    } else {
        wp_remote_retrieve_response_message($responseStatistic);
    }
}

//ACF FILTER
add_filter('acf/load_field/name=search_from', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=search_to', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=route_departure_point', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=route_destination_point', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=coupon_route_departure', 'populate_acf_select_route_area_field');
add_filter('acf/load_field/name=coupon_route_destination', 'populate_acf_select_route_area_field');
add_filter('acf/load_field/name=schedule_departure_point', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=schedule_destination_point', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=routes_destination_point', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=routes_departure_point', 'populate_acf_select_route_field');
add_filter('acf/load_field/name=company_id', 'populate_acf_select_company_field');
add_filter('acf/load_field/name=coupon_company', 'populate_acf_select_company_field');
add_filter('acf/load_field/name=vehicle_type_repeater', function ($field) {
    $source = acf_get_field('vehicle_type');
    $field['choices'] = $source['choices'];

    return $field;
});

// function populate_acf_select_route_field($field)
// {
//     $response = wp_remote_get(rest_url('api/v1/state-city-new?api_key=' . API_KEY_CLIENT));
//     if (is_wp_error($response)) {
//         return $field; // Nếu lỗi, giữ nguyên field
//     }
//     $data = json_decode(wp_remote_retrieve_body($response), true);
//     $field['choices'] = [];
//     // Lặp qua dữ liệu JSON và thêm vào choices
//     if (!empty($data['data']) && is_array($data['data'])) {
//         foreach ($data['data'] as $item) {
//             $field['choices'][$item['newKey']] = $item['label'];
//         }
//     }
//     return $field;
// }


function populate_acf_select_route_field($field)
{
    $cache_key = 'acf_route_choices_long_term';
    global $wpdb;
    $result = $wpdb->get_row("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'acf_route_choices_long_term'");
    $cached_data_raw = $result ? $result->option_value : null;
    $cache_time = get_option($cache_key . '_time', 0);

    $cached_data = null;
    if (!empty($cached_data_raw)) {
        $cached_data = maybe_unserialize($cached_data_raw);
        // Hoặc nếu lưu dưới dạng JSON:
        // $cached_data = json_decode($cached_data_raw, true);
    }

    $has_valid_cache = false;
    if ($cache_time > 0 && !empty($cached_data) && is_array($cached_data)) {
        $cache_age = time() - $cache_time;
        $has_valid_cache = $cache_age <= DAY_IN_SECONDS;
    }

    if ($has_valid_cache) {
        $field['choices'] = $cached_data;
        return $field;
    }

    // Gọi API để refresh cache
    $response = wp_remote_get(rest_url('api/v1/state-city-new?api_key=' . API_KEY_CLIENT));

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        if (!empty($cached_data)) {
            $field['choices'] = $cached_data;
        }
        return $field;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (json_last_error() === JSON_ERROR_NONE && !empty($data['data'])) {
        $choices = [];
        foreach ($data['data'] as $item) {
            if (isset($item['_id'], $item['name'])) {
                $choices[$item['_id']] = sanitize_text_field($item['name']);
            }
        }

        if (!empty($choices)) {
            update_option($cache_key, $choices, false);
            update_option($cache_key . '_time', time(), false);
            $field['choices'] = $choices;
        }
    }

    return $field;
}

add_action('admin_post_clear_route_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    delete_transient('acf_route_choices_' . md5(API_KEY_CLIENT));
    delete_option('acf_route_choices_backup');
    delete_option('acf_route_choices_long_term');
    delete_option('acf_route_choices_long_term_time');

    wp_redirect(admin_url('admin.php?page=your-settings-page&cache_cleared=1'));
    exit;
});

add_action('admin_notices', function () {
    if (isset($_GET['cache_cleared'])) {
        echo '<div class="notice notice-success"><p>Route cache đã được xóa!</p></div>';
    }
});

// Cron job để refresh cache tự động (optional)
add_action('wp', function () {
    if (!wp_next_scheduled('refresh_route_cache')) {
        wp_schedule_event(time(), 'daily', 'refresh_route_cache');
    }
});

add_action('refresh_route_cache', function () {
    delete_transient('acf_route_choices_' . md5(API_KEY_CLIENT));
    delete_option('acf_route_choices_long_term_time');

    $dummy_field = ['choices' => []];
    populate_acf_select_route_field($dummy_field);
});

function populate_acf_select_route_area_field($field)
{
    $response = wp_remote_get(rest_url('api/v1/state-city-new?api_key=' . API_KEY_CLIENT));
    if (is_wp_error($response)) {
        return $field;
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $field['choices'] = [];

    if (!empty($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $item) {
            if (isset($item['level']) && $item['level'] == 1)
                $field['choices'][$item['_id']] = $item['name'];
        }
    }
    return $field;
}
function populate_acf_select_company_field($field)
{
    global $dataCompany;
    $field['choices'] = [];
    if (is_array($dataCompany)) {
        foreach ($dataCompany as $item) {
            $field['choices'][$item['id']] = $item['name'];
        }
    }
    return $field;
}

/**
 * Check if the phone number has any unpaid Goopay booking (giữ ghế).
 *
 * @param string $phone
 * @return array|null Info about the existing booking or null.
 */
function ams_get_unpaid_goopay_booking($phone)
{
    if (empty($phone)) return null;
    global $wpdb;

    // Lấy chỉ các chữ số từ số điện thoại để so sánh linh hoạt
    $numeric_phone = preg_replace('/[^0-9]/', '', $phone);
    if (empty($numeric_phone)) return null;

    // Tìm kiếm trực tiếp trong bảng postmeta để tránh các filter của WP_Query
    // Chúng ta tìm vé có phone khớp (sau khi lọc số), payment_status = 1 và partner_id là goopay
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_phone ON p.ID = pm_phone.post_id AND pm_phone.meta_key = 'phone'
         INNER JOIN {$wpdb->postmeta} pm_status ON p.ID = pm_status.post_id AND pm_status.meta_key = 'payment_status'
         INNER JOIN {$wpdb->postmeta} pm_partner ON p.ID = pm_partner.post_id AND pm_partner.meta_key = 'partner_id'
         WHERE p.post_type = 'book-ticket' 
           AND p.post_status = 'publish'
           AND pm_status.meta_value = '1'
           AND pm_partner.meta_value LIKE 'goopay'
           AND (pm_phone.meta_value = %s OR REPLACE(REPLACE(REPLACE(REPLACE(pm_phone.meta_value, ' ', ''), '-', ''), '+', ''), '(', '') LIKE %s)
         LIMIT 1",
        $phone,
        '%' . $numeric_phone
    ));

    if (!empty($results)) {
        $p = $results[0];
        $jg_id = get_post_meta($p->ID, 'journey_group_id', true) ?: $p->post_title;
        return [
            'post_id'          => $p->ID,
            'journey_group_id' => $jg_id,
            'payment_url'      => home_url('/payment-results/?code=' . $jg_id),
        ];
    }

    return null;
}

function handle_vnpay_payment(WP_REST_Request $request)
{
    // Log the request and IP address
    $logData = array(
        'request' => $request->get_params(),
        'ip' => $_SERVER['REMOTE_ADDR']
    );
    file_put_contents(WP_CONTENT_DIR . '/uploads/vnpay_request_log.txt', json_encode($logData) . PHP_EOL, FILE_APPEND);
    // $vnp_CardType = $request->get_param('vnp_CardType');
    // $vnp_OrderInfo = $request->get_param('vnp_OrderInfo');
    // $vnp_PayDate = $request->get_param('vnp_PayDate');
    // $vnp_SecureHash = $request->get_param('vnp_SecureHash');
    // $vnp_TmnCode = $request->get_param('vnp_TmnCode');
    // $vnp_TransactionNo = $request->get_param('vnp_TransactionNo');
    $inputData = array();
    $vnp_TxnRef = $request->get_param('vnp_TxnRef');
    $vnp_SecureHash = $request->get_param('vnp_SecureHash');
    $vnp_ResponseCode = $request->get_param('vnp_ResponseCode');
    $vnp_TransactionStatus = $request->get_param('vnp_TransactionStatus');
    $vnp_BankCode = $request->get_param('vnp_BankCode');
    $vnp_Amount = $request->get_param('vnp_Amount');
    $price = $vnp_Amount / 100;
    foreach ($request->get_params() as $key => $value) {
        if (substr($key, 0, 4) == "vnp_") {
            $inputData[$key] = $value;
        }
    }
    unset($inputData['vnp_SecureHash']);
    ksort($inputData);
    $i = 0;
    $hashData = "";
    foreach ($inputData as $key => $value) {
        if ($i == 1) {
            $hashData = $hashData . '&' . urlencode($key) . "=" . urlencode($value);
        } else {
            $hashData = $hashData . urlencode($key) . "=" . urlencode($value);
            $i = 1;
        }
    }
    $secureHash = hash_hmac('sha512', $hashData, vnp_HashSecret);
    $arrayResponse = array();
    try {
        if ($secureHash == $vnp_SecureHash) {
            $post_type = 'book-ticket';
            $post = get_post($vnp_TxnRef);
            if ($post && $post->post_type == $post_type) {
                $amount = get_post_meta($post->ID, 'total_price', true);
                $paymentStatus = get_post_meta($post->ID, 'payment_status', true);
                if ($price != $amount) {
                    $arrayResponse['RspCode'] = "04";
                    $arrayResponse["Message"] = "Invalid amount";
                } elseif ($paymentStatus == 2 || $paymentStatus == 3 || $paymentStatus == 4) {
                    $arrayResponse['RspCode'] = "02";
                    $arrayResponse["Message"] = "Order already confirmed";
                } else {
                    if ($vnp_ResponseCode == '00') {
                        //giao dịch thành cong
                        $bookingCode = $post->post_title;
                        $urlBooking = endPoint . "/api/Wrapper/Trip/BookingSearch?code=$bookingCode";
                        $responseBooking = wp_remote_get($urlBooking);
                        if (!is_wp_error($responseBooking)) {
                            $dataBooking = json_decode($responseBooking['body'], true);
                            // return new WP_REST_Response($dataBooking, 200);
                            if (isset($dataBooking['data'][0]['transactions']) && empty($dataBooking['data'][0]['transactions'])) {
                                $dataBooking['data'][0]['transactions'] = (object) $dataBooking['data'][0]['transactions'];
                            }
                            // $otp = generateOTP(secretOTP);
                            if (count($dataBooking['data']) > 0) {
                                $args = array(
                                    'body' => json_encode($dataBooking['data'][0]),
                                    'headers' => array(
                                        'Content-Type' => 'application/json',
                                        // 'Vivutoday-Otp' => $otp
                                    ),
                                    'redirection' => 0,
                                );
                                $responsePay = wp_remote_post(endPoint . '/api/Wrapper/Trip/BookingPay', $args);
                                if (!is_wp_error($responsePay)) {
                                    $dataPay = json_decode($responsePay['body'], true);
                                    // return new WP_REST_Response($dataPay, 200);
                                    if ($dataPay['success'] == true) {
                                        update_post_meta($post->ID, 'payment_status', 2);
                                        $arrayResponse['RspCode'] = "00";
                                        $arrayResponse["Message"] = "Confirm Success";
                                    } else {
                                        update_post_meta($post->ID, 'payment_status', 5);
                                        $arrayResponse['RspCode'] = "00";
                                        $arrayResponse["Message"] = "Confirm Failed";
                                    }
                                } else {
                                    update_post_meta($post->ID, 'payment_status', 5);
                                    $arrayResponse['RspCode'] = "02";
                                    $arrayResponse["Message"] = "Order already confirmed";
                                }
                            }
                        } else {
                            // cập nhật trạng thái giao dịch thất bại và vẫn trả về cho VNPAY
                            update_post_meta($post->ID, 'payment_status', 5);
                            $arrayResponse['RspCode'] = "00";
                            $arrayResponse["Message"] = "Confirm Success";
                        }
                    } elseif ($vnp_ResponseCode != "00" || $vnp_TransactionStatus != "00") {
                        // cập nhật trạng thái giao dịch thất bại và vẫn trả về cho VNPAY
                        $arrayResponse['RspCode'] = "00";
                        $arrayResponse["Message"] = "Confirm Success";
                    }
                }
            } else {
                $arrayResponse['RspCode'] = "01";
                $arrayResponse["Message"] = "Order not found";
            }
        } else {
            $arrayResponse['RspCode'] = "97";
            $arrayResponse["Message"] = "Invalid signature";
        }
    } catch (\Exception $e) {
        $arrayResponse['RspCode'] = "99";
        $arrayResponse["Message"] = "Unknow error";
    }
    return new WP_REST_Response($arrayResponse, 200);
}

function ams_normalize_booking_api_response(array $resp): array
{
    $items         = [];
    $errors        = [];
    $journeyIds    = [];
    $success_count = 0;
    $fail_count    = 0;

    $raw_entries = [];
    $data_root   = $resp['data'] ?? $resp;

    // ----------------------------------------------------------------
    // CASE: Pay response { payResults, bookingCodes, ... }
    // Đây là response từ flow thanh toán, khác với flow booking
    // ----------------------------------------------------------------
    if (isset($data_root['payResults']) && is_array($data_root['payResults'])) {
        $booking_codes_list = (array)($data_root['bookingCodes'] ?? []);

        foreach ($data_root['payResults'] as $idx => $payResult) {
            $partner      = strtolower($payResult['partner'] ?? 'vexere');
            $bookingCode  = (string)($payResult['bookingCode'] ?? ($booking_codes_list[$idx] ?? ''));
            $raw          = $payResult['raw'] ?? [];

            // raw.data là array các vé thành công
            $rawData = [];
            if (isset($raw['data']) && is_array($raw['data'])) {
                $rawData = $raw['data'];
            } elseif (isset($raw[0])) {
                $rawData = $raw;
            }

            // Tìm entry trong raw.data khớp với bookingCode của payResult này
            $matchedInner = null;
            foreach ($rawData as $inner) {
                if (isset($inner['booking_code']) && (string)$inner['booking_code'] === $bookingCode) {
                    $matchedInner = $inner;
                    break;
                }
            }

            // Nếu không tìm thấy khớp, dùng phần tử đầu tiên theo index
            if ($matchedInner === null && isset($rawData[$idx])) {
                $matchedInner = $rawData[$idx];
            }

            if ($matchedInner !== null) {
                $message     = (string)($matchedInner['message'] ?? '');
                $ok          = strtolower($message) === 'success';
                $ticketCode  = (string)($matchedInner['ticket_code'] ?? '');
                $bc          = (string)($matchedInner['booking_code'] ?? $bookingCode);
                $price       = (float)($matchedInner['price'] ?? 0);
            } else {
                // Không có raw data khớp — fallback
                $ok         = false;
                $ticketCode = '';
                $bc         = $bookingCode;
                $price      = 0;
            }

            if ($ok) {
                $success_count++;
                if ($bc) $journeyIds[] = $bc;
            } else {
                $fail_count++;
                $errors[] = sprintf('%s: booking_code %s không tìm thấy dữ liệu thành công', $partner, $bookingCode);
            }

            $items[] = [
                'ok'            => $ok,
                'source'        => $partner,
                'booking_codes' => $bc ? [$bc] : [],
                'ticket_codes'  => $ticketCode ? [$ticketCode] : [],
                'price'         => $price,
                'raw'           => $matchedInner ?? $raw,
            ];
        }

        // Tổng hợp status
        $total = count($items);
        if ($total === 0) {
            $status = 'failed';
        } elseif ($fail_count === 0) {
            $status = 'success';
        } elseif ($success_count > 0) {
            $status = 'partial';
        } else {
            $status = 'failed';
        }

        $success_items = array_values(array_filter($items, fn($it) => $it['ok']));
        $first_code    = $success_items[0]['ticket_codes'][0]  ?? ($success_items[0]['booking_codes'][0] ?? '');
        $seat_depart   = $success_items[0]['booking_codes'][0] ?? '';
        $seat_arrive   = $success_items[1]['booking_codes'][0] ?? '';

        return [
            'status'        => $status,
            'success_count' => $success_count,
            'fail_count'    => $fail_count,
            'items'         => $items,
            'errors'        => $errors,
            'journeyIds'    => $journeyIds,
            'code'          => $first_code,
            'seat_depart'   => $seat_depart,
            'seat_arrive'   => $seat_arrive,
        ];
    }

    // --- Goopay trực tiếp: { booking_code, ... } ---
    if (isset($data_root['booking_code'])) {
        $raw_entries[] = ['source' => 'goopay', 'data' => $data_root];
    }
    // --- Vexere 1 chiều / mixed có _source: { successful, data: { data: [...] } } ---
    elseif (isset($resp['successful']) && isset($data_root['data']) && is_array($data_root['data'])) {
        foreach ($data_root['data'] as $entry) {
            $source  = $entry['_source'] ?? 'vexere';
            $payload = $entry['payload'] ?? $entry;
            $raw_entries[] = [
                'source'  => $source,
                'data'    => $payload,
                'indices' => $entry['_indices'] ?? []
            ];
        }
    }
    // --- Vexere 2 chiều: { statusCode, data: [{message,...},...] } ---
    elseif (isset($resp['statusCode']) && is_array($data_root) && isset($data_root[0])) {
        foreach ($data_root as $entry) {
            $raw_entries[] = ['source' => 'vexere', 'data' => $entry];
        }
    }
    // --- Fallback: data là mảng phẳng ---
    elseif (is_array($data_root) && isset($data_root[0]) && is_array($data_root[0])) {
        foreach ($data_root as $entry) {
            $source = (isset($entry['booking_code']) && !isset($entry['statusCode'])) ? 'goopay' : 'vexere';
            $raw_entries[] = ['source' => $source, 'data' => $entry];
        }
    }
    // --- Wrapped list: { data: { data: [...] } } ---
    elseif (isset($data_root['data']) && is_array($data_root['data'])) {
        foreach ($data_root['data'] as $entry) {
            $source = (isset($entry['booking_code']) && !isset($entry['statusCode'])) ? 'goopay' : 'vexere';
            $raw_entries[] = ['source' => $source, 'data' => $entry];
        }
    }

    // ----------------------------------------------------------------
    // Parse từng entry
    // ----------------------------------------------------------------
    foreach ($raw_entries as $entry) {
        $source = $entry['source'];
        $d      = $entry['data'];

        // ===== GOOPAY =====
        if ($source === 'goopay') {
            // Theo tài liệu Goopay: code "0" hoặc message "success" là thành công
            $is_success = (isset($d['code']) && ($d['code'] === '0' || $d['code'] === 0)) || (isset($d['message']) && strtolower($d['message']) === 'success');
            $indices = $entry['indices'] ?? [];

            // Tìm danh sách vé trong kết quả từ Goopay
            $entries = [];
            if (isset($d['data']) && is_array($d['data']) && isset($d['data'][0])) {
                $entries = $d['data']; // data là mảng vé
            } elseif (isset($d['data']['tickets']) && is_array($d['data']['tickets'])) {
                $entries = $d['data']['tickets']; // data.tickets là mảng vé
            } elseif (isset($d['tickets']) && is_array($d['tickets'])) {
                $entries = $d['tickets']; // tickets là mảng vé (trực tiếp)
            } elseif (isset($d['data']) && is_array($d['data'])) {
                $entries = [$d['data']]; // data là đối tượng duy nhất
            } else {
                $entries = [$d]; // Fallback cấp cao nhất
            }

            // Đồng bộ số lượng kết quả với số vé yêu cầu (khứ hồi/nhiều chỗ)
            $expected_count = count($indices);
            if ($expected_count <= 0) $expected_count = 1;

            if (count($entries) < $expected_count && !empty($entries)) {
                $last = end($entries);
                while (count($entries) < $expected_count) {
                    $entries[] = $last;
                }
            }

            foreach ($entries as $idx => $record) {
                // Thử tìm booking_code và price từ nhiều nguồn khác nhau của Goopay
                $booking_code = (string)($record['code'] ?? $record['booking_code'] ?? $record['bookingNo'] ?? '');

                // Nếu booking_code là mã thành công "0" thì thử tìm ở các trường chuyên biệt hơn
                if (($booking_code === '0' || $booking_code === 0) && (isset($record['booking_code']) || isset($record['bookingNo']))) {
                    $booking_code = (string)($record['booking_code'] ?? $record['bookingNo'] ?? '');
                }

                $price = (float)($record['total'] ?? $record['amount'] ?? $record['fare'] ?? $record['price'] ?? 0);
                $ok = !empty($booking_code) && ($is_success || !empty($booking_code));

                if ($ok) {
                    $success_count++;
                    $journeyIds[] = $booking_code;
                } else {
                    // Nếu là mảng nhiều kết quả, chỉ tính là lỗi nếu có dữ liệu nhưng thiếu mã, hoặc không có thành công nào
                    if (count($entries) === 1 || !empty($booking_code)) {
                        $fail_count++;
                        $errors[] = 'Goopay: thiếu booking code hoặc booking thất bại';
                    }
                    continue;
                }

                $items[] = [
                    'index'         => $indices[$idx] ?? null,
                    'ok'            => $ok,
                    'source'        => 'goopay',
                    'booking_codes' => $ok ? [$booking_code] : [],
                    'ticket_codes'  => [],
                    'price'         => $price,
                    'raw'           => $record,
                ];
            }
        } elseif ($source === 'vexere') {
            $indices = $entry['indices'] ?? [];

            // Case A: { statusCode, data: [ {message,tickets,...}, ... ] }
            if (
                isset($d['statusCode']) &&
                isset($d['data']) &&
                is_array($d['data']) &&
                isset($d['data'][0])
            ) {
                foreach ($d['data'] as $idx => $inner) {
                    $message      = (string)($inner['message'] ?? '');
                    $ok           = strtolower($message) === 'success';
                    $booking_code = (string)($inner['booking_code'] ?? '');
                    $code         = (string)($inner['code'] ?? '');
                    $tickets      = (array)($inner['tickets'] ?? []);
                    $ticket_strs  = array_map('strval', $tickets);
                    $price        = (float)($inner['price'] ?? 0);

                    if ($ok) {
                        $success_count++;
                        if ($booking_code) $journeyIds[] = $booking_code;
                    } else {
                        $fail_count++;
                        $errors[] = sprintf('Vexere: %s', $message ?: 'unknown error');
                    }

                    $items[] = [
                        'index'         => $indices[$idx] ?? null,
                        'ok'            => $ok,
                        'source'        => 'vexere',
                        'booking_codes' => $booking_code ? [$booking_code] : [],
                        'ticket_codes'  => $code ? [$code] : $ticket_strs,
                        'price'         => $price,
                        'raw'           => $inner,
                    ];
                }
                continue;
            }

            // Case B/C: 1 vé có hoặc không có wrapper statusCode
            $inner = (
                isset($d['data']) &&
                is_array($d['data']) &&
                !isset($d['data'][0])
            ) ? $d['data'] : $d;

            $status_code  = (int)($d['statusCode'] ?? $inner['statusCode'] ?? 200);
            $message      = (string)($inner['message'] ?? '');
            $ok           = ($status_code === 200 && strtolower($message) === 'success');
            $booking_code = (string)($inner['booking_code'] ?? '');
            $code         = (string)($inner['code'] ?? '');
            $tickets      = (array)($inner['tickets'] ?? []);
            $ticket_strs  = array_map('strval', $tickets);
            $price        = (float)($inner['price'] ?? 0);

            if ($ok) {
                $success_count++;
                if ($booking_code) $journeyIds[] = $booking_code;
            } else {
                $fail_count++;
                $errors[] = sprintf('Vexere [%d]: %s', $status_code, $message ?: 'unknown error');
            }

            $items[] = [
                'index'         => $indices[0] ?? null,
                'ok'            => $ok,
                'source'        => 'vexere',
                'booking_codes' => $booking_code ? [$booking_code] : [],
                'ticket_codes'  => $code ? [$code] : $ticket_strs,
                'price'         => $price,
                'raw'           => $d,
            ];
        }
    }

    $total = count($items);
    if ($total === 0) {
        $status = 'failed';
    } elseif ($fail_count === 0) {
        $status = 'success';
    } elseif ($success_count > 0) {
        $status = 'partial';
    } else {
        $status = 'failed';
    }

    $success_items = array_values(array_filter($items, fn($it) => $it['ok']));
    $first_code    = $success_items[0]['ticket_codes'][0]  ?? ($success_items[0]['booking_codes'][0] ?? '');
    $seat_depart   = $success_items[0]['booking_codes'][0] ?? '';
    $seat_arrive   = $success_items[1]['booking_codes'][0] ?? '';

    return [
        'status'        => $status,
        'success_count' => $success_count,
        'fail_count'    => $fail_count,
        'items'         => $items,
        'errors'        => $errors,
        'journeyIds'    => $journeyIds,
        'code'          => $first_code,
        'seat_depart'   => $seat_depart,
        'seat_arrive'   => $seat_arrive,
    ];
}

function ams_index_booking_items(array $normalized): array
{
    $out = [];

    foreach (($normalized['items'] ?? []) as $i => $it) {
        $idx = isset($it['index']) ? (int)$it['index'] : $i;

        if (!isset($out[$idx])) {
            $out[$idx] = [
                'ok'            => false,
                'ticket_codes'  => [],
                'booking_codes' => [],
                'errors'        => [],
                'raw'           => [],
            ];
        }

        $out[$idx]['raw'][] = $it;

        $ok = (bool)($it['ok'] ?? false);

        if ($ok) {
            $out[$idx]['ok'] = true;
            foreach ((array)($it['ticket_codes'] ?? []) as $tc) {
                if ($tc !== '') $out[$idx]['ticket_codes'][] = (string)$tc;
            }
            foreach ((array)($it['booking_codes'] ?? []) as $bc) {
                if ($bc !== '') $out[$idx]['booking_codes'][] = (string)$bc;
            }
        } else {
            $raw = $it['raw'] ?? $it;
            $out[$idx]['errors'][] = [
                'source'    => $it['source'] ?? null,
                'tripId'    => $it['tripId'] ?? ($raw['tripId'] ?? null),
                'seatIds'   => $it['seatIds'] ?? ($raw['seatIds'] ?? null),
                'partnerId' => $it['partnerId'] ?? ($raw['partnerId'] ?? null),
                'error'     => $it['error'] ?? ($it['message'] ?? ($raw['message'] ?? 'Booking failed')),
            ];
        }
    }

    foreach ($out as $idx => $v) {
        $out[$idx]['ticket_codes']  = array_values(array_unique($v['ticket_codes']));
        $out[$idx]['booking_codes'] = array_values(array_unique($v['booking_codes']));
    }

    return $out;
}

function handle_booking_ams(WP_REST_Request $request)
{
    global $wpdb;

    $customer = [
        'name'  => $request->get_param('name')  ?? '',
        'phone' => $request->get_param('phone') ?? '',
        'email' => $request->get_param('email') ?? '',
    ];

    $note            = $request->get_param('note') ?? '';
    $id_number       = $request->get_param('customer_id_number') ?? '';
    $paymentType     = $request->get_param('paymentType') ?? '66baf0020000000000000001';
    $contributorCode = sanitize_text_field($request->get_param('contributor_code') ?? '');
    $user_id         = (int)($request->get_param('user_id') ?? 0);
    $departure_dates_req = $request->get_param('departure_dates') ?? [];
    $normalize_departure_date = static function ($value) {
        $value = sanitize_text_field((string)$value);

        if ($value === '') {
            return '';
        }

        // Replace 'h' with ':' to handle Goopay format (e.g., 00h01 -> 00:01)
        $value = str_replace('h', ':', $value);

        if (preg_match('/\b\d{1,2}:\d{2}\s+\d{2}-\d{2}-\d{4}\b/', $value, $matches)) {
            return $matches[0];
        }

        return trim($value);
    };

    if (empty($customer['name']) || empty($customer['phone']) || empty($customer['email'])) {
        wp_send_json_error(['message' => 'Vui lòng nhập đầy đủ thông tin!']);
        return;
    }

    if (!empty($contributorCode)) {
        if (strlen($contributorCode) !== 6) {
            wp_send_json_error(['message' => 'Mã cộng tác viên phải có đúng 6 ký tự.']);
            return;
        }

        $users = get_users([
            'meta_key'    => 'contributor_code',
            'meta_value'  => $contributorCode,
            'number'      => 1,
            'count_total' => false
        ]);

        if (empty($users)) {
            wp_send_json_error(['message' => 'Mã cộng tác viên không hợp lệ.']);
            return;
        }
    }

    if (!isset($_SESSION['tickets']) || empty($_SESSION['tickets'])) {
        wp_send_json_error(['message' => 'Đơn hàng không tồn tại!']);
        return;
    }

    $tickets = $_SESSION['tickets'];

    $priceTotal = function_exists('caculatorPriceTotal') ? caculatorPriceTotal() : ['total_price' => 0];

    $map_ticket_to_body = function (array $ticket) use ($customer, $note, $id_number, $paymentType, $priceTotal) {
        $seats = '';
        $totalSeats = 0;

        if (empty($seats) && !empty($ticket['selectedSeats']) && is_array($ticket['selectedSeats'])) {
            $totalSeats = count($ticket['selectedSeats']);
            $seatCodes = array_map(function ($s) {
                return $s['full_code_group'] ?? $s['full_code'];
            }, $ticket['selectedSeats']);
            $seatCodes = array_values(array_filter($seatCodes));
            $seats = implode(',', $seatCodes);
        }

        $info   = $ticket['seatsAndInfoData'] ?? $ticket;
        $pickup = $ticket['pickupPoint'] ?? null;
        $drop   = $ticket['dropoffPoint'] ?? null;

        $transferPickupPoint   = $ticket['transferPickupPoint'] ?? null;
        $transferDropoffPoint  = $ticket['transferDropoffPoint'] ?? null;

        $routeName        = $ticket['routeName'] ?? '';
        $pickupMoreDesc   = $ticket['pickupPointMoreDesc'] ?? '';
        $dropoffMoreDesc  = $ticket['dropoffPointMoreDesc'] ?? '';

        $departureDate = null;
        $departureTimeRaw = $info['departure_time'] ?? null;

        if (!empty($info['departure_date'])) {
            try {
                $dtApi = new DateTime((string)$info['departure_date']);
                $departureDate = $dtApi->format('d-m-Y');
                if (empty($departureTimeRaw)) {
                    $departureTimeRaw = $dtApi->format('H:i');
                }
            } catch (Exception $e) {
                // bỏ qua, sẽ dùng fallback phía dưới
            }
        } elseif (!empty($ticket['departure_date'])) {
            $depRaw = (string)$ticket['departure_date'];
            try {
                $dt = new DateTime($depRaw);
                $departureDate = $dt->format('d-m-Y');
                if (empty($departureTimeRaw)) {
                    $departureTimeRaw = $dt->format('H:i');
                }
            } catch (Exception $e) {
                $dt = DateTime::createFromFormat('d-m-Y', $depRaw) ?: DateTime::createFromFormat('Y-m-d', $depRaw) ?: DateTime::createFromFormat('d/m/Y', $depRaw);
                if ($dt) {
                    $departureDate = $dt->format('d-m-Y');
                }
            }
        }

        $pickupName = '';
        $pickupId = '';
        $dropoffName = '';
        $dropoffId = '';

        if ($departureTimeRaw) {
            $timePart = $departureTimeRaw;
            $departureTime = function_exists('convertDateTimeToHour') ? convertDateTimeToHour($timePart) : $timePart;
        } else {
            $departureTime = null;
        }

        // ===== Pickup =====
        if (!empty($pickupMoreDesc)) {
            if (!empty($transferPickupPoint)) {
                $pickupName = '';
                $pickupId   = '';
            } elseif (!empty($pickup)) {
                $pickupName = (string)$pickupMoreDesc;
                $pickupId   = $pickup['id'] ?? '';
            }
        } else {
            $hasPointNameOrOfficeId = !empty($pickup) && (isset($pickup['name']) || isset($pickup['officeId']));
            $isTransfer = !empty($transferPickupPoint);

            if ($hasPointNameOrOfficeId) {
                $pickupName = (string)($pickup['name'] ?? '');
                $pickupId   = (string)($pickup['id'] ?? '');
            } elseif ($isTransfer) {
                if (isset($transferPickupPoint['officeId'])) {
                    $pickupName = (string)($transferPickupPoint['name'] ?? '');
                    $pickupId   = (string)($transferPickupPoint['id'] ?? '');
                } else {
                    $pickupName = '';
                    $pickupId   = '';
                }
            } else {
                $pickupName = (string)($pickup['name'] ?? '');
                $pickupId   = (string)($pickup['id'] ?? '');
            }
        }

        // ===== Dropoff =====
        if (!empty($dropoffMoreDesc)) {
            if (!empty($transferDropoffPoint)) {
                $dropoffName = '';
                $dropoffId   = '';
            } elseif (!empty($drop)) {
                $dropoffName = (string)$dropoffMoreDesc;
                $dropoffId   = $drop['id'] ?? '';
            }
        } else {
            $hasDropPointNameOrOfficeId = !empty($drop) && (!empty($drop['pointName']) || !empty($drop['officeId']));
            $isDropTransfer = !empty($transferDropoffPoint);

            if ($hasDropPointNameOrOfficeId) {
                $dropoffName = (string)($drop['name'] ?? '');
                $dropoffId   = (string)($drop['id'] ?? '');
            } elseif ($isDropTransfer) {
                if (isset($transferDropoffPoint['officeId'])) {
                    $dropoffName = (string)($transferDropoffPoint['name'] ?? '');
                    $dropoffId   = (string)($transferDropoffPoint['id'] ?? '');
                } else {
                    $dropoffName = '';
                    $dropoffId   = '';
                }
            } else {
                $dropoffName = (string)($drop['name'] ?? '');
                $dropoffId   = (string)($drop['id'] ?? '');
            }
        }

        return [
            "tripId"                    => $ticket['tripId'] ?? '',
            "partnerId"                 => $ticket['partnerId'] ?? '',
            "seats"                     => (string)$seats,
            "wayId"                     => (int)$ticket['wayId'] ?? '',
            "bookingId"                 => $ticket['bookingId'] ?? '',
            "routeName"                 => (string)$routeName,
            "departureDate"             => (string)$departureDate,
            "departureTime"             => (string)$departureTime,

            "customer_phone"            => (string)$customer['phone'],
            "customer_name"             => (string)$customer['name'],
            "customer_email"            => (string)$customer['email'],
            "customer_id_number"        => (string)$id_number,

            "pickup"                    => (string)$pickupName,
            "pickup_address"            => (string)($pickup['address'] ?? ''),
            "pickup_lat"                => (string)($pickup['latitude'] ?? ''),
            "pickup_lng"                => (string)($pickup['longitude'] ?? ''),
            "pickup_id"                 => (int)$pickupId,

            "transfer"                  => empty($transferPickupPoint) ? '' : (!empty($pickupMoreDesc) ? $pickupMoreDesc : ($transferPickupPoint['name'] ?? '')),
            "transshipmentPointUpId"    => empty($transferPickupPoint) ? '' : ($transferPickupPoint['id'] ?? ''),
            "transshipmentPointUpPrice" => (float)($ticket['transshipmentPointUpPrice'] ?? 0),

            "drop_off_info"             => (string)$dropoffName,
            "drop_off_point_id"         => (int)$dropoffId,
            "drop_off_address"          => (string)($drop['address'] ?? ''),
            "drop_off_lat"              => (string)($drop['latitude'] ?? ''),
            "drop_off_lng"              => (string)($drop['longitude'] ?? ''),
            "drop_off_transfer_info"    => empty($transferDropoffPoint) ? '' : (!empty($dropoffMoreDesc) ? $dropoffMoreDesc : ($transferDropoffPoint['name'] ?? '')),

            "transshipmentPointDownId"    => empty($transferDropoffPoint) ? '' : ($transferDropoffPoint['id'] ?? ''),
            "transshipmentPointDownPrice" => 0,

            "agencyPrice"               => (float)($ticket['subtotal'] ?? ($priceTotal['total_price'] ?? 0)),
            "discount"                  => 0,

            "num_of_ticket"             => (int)$totalSeats,
            "have_eating"               => 0,

            "note"                      => (string)$note,
            "user_agent"                => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            "paymentType"               => (string)$paymentType,
        ];
    };

    $map_vexere_body = function (array $ticket) use ($map_ticket_to_body) {
        $base = $map_ticket_to_body($ticket);
        $info   = $ticket['seatsAndInfoData'] ?? $ticket;
        $tripCode = $info['trip_code'] ?? ($ticket['trip_code'] ?? ($ticket['tripId'] ?? ''));
        $transferPickupPoint   = $ticket['transferPickupPoint'] ?? null;
        $transferDropoffPoint  = $ticket['transferDropoffPoint'] ?? null;
        // wp_send_json_error($base);
        // Áp dụng rule từ request.md:
        $hasPickupPoint = !empty($base['pickup_id']) || $base['pickup'] >= 0;
        $hasTransferPickup = !empty($transferPickupPoint);
        $hasDropoffPoint = !empty($base['drop_off_point_id']) || $base['drop_off_info'] >= 0;
        $hasTransferDropoff = !empty($transferDropoffPoint);

        $pickupVal = null;
        $pickupIdVal = null;
        $transferVal = null;
        $transferIdVal = null;

        if ($hasPickupPoint) {
            $pickupVal = (string)($base['pickup'] ?? '');
            $pickupIdVal = (int)($base['pickup_id'] ?? 0);
            $transferVal = null;
            $transferIdVal = null;
        } elseif ($hasTransferPickup) {
            $pickupVal = null;
            $pickupIdVal = null;
            $transferVal = (string)($base['transfer'] ?? '');
            $transferIdVal = isset($transferPickupPoint['id']) ? (int)$transferPickupPoint['id'] : null;
        }

        $dropoffInfoVal = null;
        $dropoffPointIdVal = null;
        $dropoffTransferInfoVal = null;
        $arriveTransferIdVal = null;

        if ($hasDropoffPoint) {
            $dropoffInfoVal = (string)($base['drop_off_info'] ?? '');
            $dropoffPointIdVal = (int)($base['drop_off_point_id'] ?? 0);
            $dropoffTransferInfoVal = null;
            $arriveTransferIdVal = null;
        } elseif ($hasTransferDropoff) {
            $dropoffInfoVal = null;
            $dropoffPointIdVal = null;
            $dropoffTransferInfoVal = (string)($base['drop_off_transfer_info'] ?? '');
            $arriveTransferIdVal = isset($transferDropoffPoint['id']) ? (int)$transferDropoffPoint['id'] : null;
        }

        return [
            'trip_code'               => (string)$tripCode,
            'seats'                   => (string)($base['seats'] ?? ''),
            'customer_phone'          => (string)($base['customer_phone'] ?? ''),
            'customer_name'           => (string)($base['customer_name'] ?? ''),
            'customer_email'          => (string)($base['customer_email'] ?? ''),
            'customer_id_number'      => (string)($base['customer_id_number'] ?? ''),
            'pickup'                  => $pickupVal,
            'pickup_id'               => $pickupIdVal,
            'transfer'                => $transferVal,
            'transfer_id'             => $transferIdVal,
            'drop_off_info'           => $dropoffInfoVal,
            'drop_off_point_id'       => $dropoffPointIdVal,
            'drop_off_transfer_info'  => $dropoffTransferInfoVal,
            'arrive_transfer_id'      => $arriveTransferIdVal,
            'have_eating'             => (int)($base['have_eating'] ?? 0),
            'note'                    => (string)($base['note'] ?? ''),
            'user_agent'              => (string)($base['user_agent'] ?? ''),
        ];
    };

    $map_goopay_ticket = function (array $ticket) use ($map_ticket_to_body, $customer) {
        $base = $map_ticket_to_body($ticket);

        $pickupArr   = $ticket['pickupPoint'] ?? null;
        $pickupTrans = $ticket['transferPickupPoint'] ?? null;
        $dropArr     = $ticket['dropoffPoint'] ?? null;
        $dropTrans   = $ticket['transferDropoffPoint'] ?? null;

        $pickupId = (string)(
            $pickupArr['point_id'] ?? $pickupArr['id'] ?? $pickupArr['officeId']
            ?? $pickupTrans['point_id'] ?? $pickupTrans['id'] ?? $pickupTrans['officeId']
            ?? ($base['pickup_id'] ?? '')
        );
        $dropId = (string)(
            $dropArr['point_id'] ?? $dropArr['id'] ?? $dropArr['officeId']
            ?? $dropTrans['point_id'] ?? $dropTrans['id'] ?? $dropTrans['officeId']
            ?? ($base['drop_off_point_id'] ?? '')
        );

        $pickupName = (string)(
            $pickupArr['name'] ?? $pickupTrans['name'] ?? ($base['pickup'] ?? '')
        );
        $dropName = (string)(
            $dropArr['name'] ?? $dropTrans['name'] ?? ($base['drop_off_info'] ?? '')
        );
        $pickupAddr = (string)(
            $pickupArr['address'] ?? $pickupTrans['address'] ?? ($base['pickup_address'] ?? '')
        );
        $dropAddr = (string)(
            $dropArr['address'] ?? $dropTrans['address'] ?? ($base['drop_off_address'] ?? '')
        );

        $pickupPointKind = (int)($pickupArr['pointKind'] ?? 0);
        $dropoffPointKind = (int)($dropArr['pointKind'] ?? 0);

        $pickupPoint = [
            'address'   => $pickupAddr,
            'pointId'   => $pickupId,
            'pointName' => $pickupName,
            'pointKind' => $pickupPointKind,
        ];
        $dropoffPoint = [
            'address'   => $dropAddr,
            'pointId'   => $dropId,
            'pointName' => $dropName,
            'pointKind' => $dropoffPointKind,
        ];
        $seatIds = [];
        $seatCodes = [];
        if (!empty($ticket['selectedSeats']) && is_array($ticket['selectedSeats'])) {
            foreach ($ticket['selectedSeats'] as $s) {
                $seatIds[] = (string)($s['seat_id'] ?? $s['id'] ?? $s['full_code'] ?? $s['seat_code'] ?? '');
                $seatCodes[] = (string)($s['seat_code'] ?? $s['full_code'] ?? $s['code'] ?? '');
            }
            $seatIds = array_values(array_filter($seatIds));
            $seatCodes = array_values(array_filter($seatCodes));
        }
        $passengers = [];
        $num = max(1, (int)($base['num_of_ticket'] ?? 1));
        for ($i = 0; $i < $num; $i++) {
            $passengers[] = [
                'custMobile' => (string)$customer['phone'],
                'custName'   => (string)$customer['name'],
            ];
        }
        return [
            'routeId'       => (string)($base['tripId'] ?? ''),
            'routeName'     => (string)($base['routeName'] ?? ''),
            'departureDate' => (string)($base['departureDate'] ?? ''),
            'departureTime' => (string)($base['departureTime'] ?? ''),
            'numOfTicket'   => (int)($base['num_of_ticket'] ?? 1),
            'bookingId'     => (string)($base['bookingId'] ?? ''),
            'pickup'        => $pickupPoint,
            'dropoff'       => $dropoffPoint,
            'passengers'    => $passengers,
            'price'         => (float)($base['agencyPrice'] ?? 0),
            'seatIds'       => $seatIds,
            'seatCodes'     => $seatCodes,
        ];
    };

    $vexereTickets = [];
    $goopayTickets = [];
    foreach ($tickets as $idx => $t) {
        $t['_orig_idx'] = $idx;
        if (strtolower($t['partnerId'] ?? '') === 'goopay') {
            $goopayTickets[] = $t;
        } else {
            $vexereTickets[] = $t;
        }
    }

    // Rule: Nếu có vé Goopay, kiểm tra xem khách hàng có vé giữ chỗ chưa thanh toán không
    // if (!empty($goopayTickets)) {
    //     $existing_unpaid = ams_get_unpaid_goopay_booking($customer['phone']);
    //     if ($existing_unpaid) {
    //         wp_send_json_error([
    //             'message'          => 'Bạn hiện có vé đang giữ chỗ chưa thanh toán. Vui lòng thanh toán hoặc huỷ giữ ghế trước khi đặt vé mới.',
    //             'status'           => 'unpaid_reservation',
    //             'payment_url'      => $existing_unpaid['payment_url'],
    //             'journey_group_id' => $existing_unpaid['journey_group_id'],
    //             'can_cancel'       => true,
    //         ]);
    //         return;
    //     }
    // }

    $respList = [];

    $vexerePartnerResp = null;
    $goopayPartnerResp = null;

    if (!empty($vexereTickets)) {
        // Prepare original indices for mapping after response
        $vexereIndices = array_column($vexereTickets, '_orig_idx');

        $items = array_map($map_vexere_body, $vexereTickets);
        $body = (count($items) === 1) ? $items[0] : $items;
        // wp_send_json_error($body);
        $r = call_api_v2('booking/vexere/reserve', 'POST', $body);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
            return;
        }
        $parsed = json_decode(wp_remote_retrieve_body($r), true);
        if (is_array($parsed)) {
            $respList[] = ['_source' => 'vexere', 'payload' => $parsed, '_indices' => $vexereIndices];
            $vexerePartnerResp = $parsed;
        }
    }

    if (!empty($goopayTickets)) {
        // Prepare original indices for mapping after response
        $goopayIndices = array_column($goopayTickets, '_orig_idx');

        $items = array_map($map_goopay_ticket, $goopayTickets);
        $body = [
            'custEmail'     => (string)$customer['email'],
            'custMobile'    => (string)$customer['phone'],
            'custName'      => (string)$customer['name'],
            'tickets'       => $items,
            'englishTicket' => 0,
            'note'          => (string)$note,
        ];

        // wp_send_json_error($body);
        $r = call_api_v2('booking/goopay/reserve', 'POST', $body);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
            return;
        }
        $parsed = json_decode(wp_remote_retrieve_body($r), true);
        if (is_array($parsed)) {
            $respList[] = ['_source' => 'goopay', 'payload' => $parsed, '_indices' => $goopayIndices];
            $goopayPartnerResp = $parsed;
        }
    }

    $resp = ['successful' => true, 'data' => ['data' => $respList]];
    $normalized = ams_normalize_booking_api_response($resp);

    if (empty($resp) || !is_array($resp)) {
        wp_send_json_error(['message' => 'Phản hồi API không hợp lệ.']);
        return;
    }

    if (isset($resp['successful']) && $resp['successful'] != true) {
        wp_send_json_error([
            'message'   => 'Booking thất bại.',
            'status'    => 'failed',
            'errorData' => $resp['errorData'] ?? $resp
        ]);
        return;
    }

    if (empty($normalized['items'])) {
        wp_send_json_error([
            'message' => 'Không nhận được dữ liệu booking từ API.',
            'status'  => 'failed',
            'raw'     => $resp,
        ]);
        return;
    }

    if ($normalized['status'] === 'failed') {
        wp_send_json_error([
            'message'       => 'Booking thất bại.',
            'status'        => 'failed',
            'items'         => $normalized['items'],
            'errors'        => $normalized['errors'],
            'success_count' => $normalized['success_count'],
            'fail_count'    => $normalized['fail_count'],
            'journeyIds'    => $normalized['journeyIds'],
            'code'          => $normalized['code'],
            'seat_depart'   => $normalized['seat_depart'],
            'seat_arrive'   => $normalized['seat_arrive'],
        ]);
        return;
    }

    // ====== CREATE POSTS (1 ticket => 1 post; 2 tickets => 2 posts) ======
    $journeyId = '';
    if (!empty($normalized['journeyIds']) && is_array($normalized['journeyIds'])) {
        $journeyId = (string)($normalized['journeyIds'][0] ?? '');
    }
    $journeyGroupId = $journeyId ?: ('JGROUP-' . time() . '-' . wp_generate_password(6, false, false));

    $itemsByIndex = ams_index_booking_items($normalized);

    $created_post_ids = [];
    $totalTickets = is_array($tickets) ? count($tickets) : 0;

    for ($i = 0; $i < $totalTickets; $i++) {
        $ticket = $tickets[$i];

        //tạo post cho ticket thành công
        $ticketOk = isset($itemsByIndex[$i]['ok']) ? (bool)$itemsByIndex[$i]['ok'] : false;
        if (!$ticketOk) {
            continue;
        }

        $company     = $ticket['seatsAndInfoData']['company_name'] ?? '';
        $vehicleName = $ticket['seatsAndInfoData']['name'] ?? '';
        $pickupDate  = $ticket['pickupPoint']['real_time'] ?? ($ticket['transferPickupPoint']['real_time'] ?? '');
        $arrivalDate = $ticket['dropoffPoint']['real_time'] ?? ($ticket['transferDropoffPoint']['real_time'] ?? '');

        $seatStr = '';
        $seatIds = [];
        if (!empty($ticket['selectedSeats']) && is_array($ticket['selectedSeats'])) {
            $seatStr = implode(',', array_map(function ($s) {
                return $s['seat_code'] ?? '';
            }, $ticket['selectedSeats']));
            $seatIds = array_values(array_filter(array_map(function ($s) {
                return (string)($s['seat_id'] ?? $s['id'] ?? $s['full_code'] ?? $s['seat_code'] ?? '');
            }, $ticket['selectedSeats'])));
        }

        $ticketCodesStr  = trim(implode(' ', $itemsByIndex[$i]['ticket_codes'] ?? []));
        $bookingCodesStr = trim(implode(' ', $itemsByIndex[$i]['booking_codes'] ?? []));

        $title = $bookingCodesStr ?: (($journeyId ?: $journeyGroupId) . '-IDX' . $i);

        $post_id = wp_insert_post([
            'post_type'   => 'book-ticket',
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);

        if (!$post_id || is_wp_error($post_id)) {
            continue;
        }

        $created_post_ids[] = (int)$post_id;

        if (isset($ticket['pickupPoint']['name']) && $ticket['pickupPoint']['name']) {
            $pickupName = $ticket['pickupPoint']['name'];
        } else {
            $pickupName = $ticket['transferPickupPoint']['name'];
        }

        if ($ticket['pickupPointMoreDesc']) {
            $pickupAddress = $ticket['pickupPointMoreDesc'];
        } elseif (isset($ticket['pickupPoint']['address']) && $ticket['pickupPoint']['address']) {
            $pickupAddress = $ticket['pickupPoint']['address'];
        } elseif (isset($ticket['transferPickupPoint']['address']) && $ticket['transferPickupPoint']['address']) {
            $pickupAddress = $ticket['transferPickupPoint']['address'];
        }

        if (isset($ticket['dropoffPoint']['name']) && $ticket['dropoffPoint']['name']) {
            $dropoffName = $ticket['dropoffPoint']['name'];
        } else {
            $dropoffName = $ticket['transferDropoffPoint']['name'];
        }

        if ($ticket['dropoffPointMoreDesc']) {
            $dropoffAddress = $ticket['dropoffPointMoreDesc'];
        } elseif (isset($ticket['dropoffPoint']['address']) && $ticket['dropoffPoint']['address']) {
            $dropoffAddress = $ticket['dropoffPoint']['address'];
        } elseif (isset($ticket['transferDropoffPoint']['address']) && $ticket['transferDropoffPoint']['address']) {
            $dropoffAddress = $ticket['transferDropoffPoint']['address'];
        }
        // group meta
        update_post_meta($post_id, 'journey_id', $journeyId);
        update_post_meta($post_id, 'journey_group_id', $journeyGroupId);
        update_post_meta($post_id, 'journey_ticket_index', $i);
        update_post_meta($post_id, 'partner_id', $ticket['partnerId'] ?? '');
        $routeName = $ticket['routeName'] ?? ($ticket['seatsAndInfoData']['routeName'] ?? '');
        update_post_meta($post_id, 'routeName', $routeName);

        // info meta
        // update_post_meta($post_id, 'search_from', $from);
        // update_post_meta($post_id, 'search_to', $to);
        update_post_meta($post_id, 'company_bus', $company ?: 'Phương Trang (Futa bus)');
        update_post_meta($post_id, 'vehicle_name', $vehicleName ?: 'Limousine');
        update_post_meta($post_id, 'pickup_name', $pickupName ?? '');
        update_post_meta($post_id, 'pickup_address', $pickupAddress ?? '');
        update_post_meta($post_id, 'dropoff_name', $dropoffName ?? '');
        update_post_meta($post_id, 'dropoff_address', $dropoffAddress ?? '');
        update_post_meta($post_id, 'pickup_date', $pickupDate);
        if (!empty($departure_dates_req[$i])) {
            $normalized_dep = $normalize_departure_date($departure_dates_req[$i]);
            update_post_meta($post_id, 'departure_date', $normalized_dep);

            if (!empty($normalized_dep)) {
                $dt = DateTime::createFromFormat('H:i d-m-Y', $normalized_dep);
                if ($dt) {
                    update_post_meta($post_id, 'departure_date_2', $dt->format('Y-m-d'));
                }
            }
        }
        update_post_meta($post_id, 'arrival_date', $arrivalDate);
        update_post_meta($post_id, 'payment_method', 'Chuyển khoản');

        // codes meta
        update_post_meta($post_id, 'ticket_codes', $ticketCodesStr ?: $bookingCodesStr);
        update_post_meta($post_id, 'booking_codes', $bookingCodesStr);

        // price meta
        update_post_meta($post_id, 'original_price', (float)($ticket['subtotalSeats'] ?? 0));
        update_post_meta($post_id, 'total_price', (float)($ticket['subtotal'] ?? 0));

        // seat meta
        update_post_meta($post_id, 'seat', $seatStr);
        update_post_meta($post_id, 'seat_ids', wp_json_encode($seatIds));

        // customer meta
        update_post_meta($post_id, 'payment_status', 1);
        update_post_meta($post_id, 'full_name', $customer['name']);
        update_post_meta($post_id, 'phone', $customer['phone']);
        update_post_meta($post_id, 'email', $customer['email']);
        update_post_meta($post_id, 'note', $note);

        $isRoundTrip = is_array($tickets) && count($tickets) >= 2;

        if ($isRoundTrip) {
            $paymentContent = trim($journeyGroupId . ' ' . $customer['phone']);
        } else {
            $paymentContent = trim(($ticketCodesStr ?: $bookingCodesStr) . ' ' . $customer['phone']);
        }

        update_post_meta($post_id, 'payment_content', $paymentContent);

        if (!empty($contributorCode)) {
            update_post_meta($post_id, 'contributor_code', $contributorCode);

            $table_name = $wpdb->prefix . 'contributor_ticket';
            $wpdb->insert(
                $table_name,
                [
                    'user_id'          => $user_id,
                    'contributor_code' => $contributorCode,
                    'ticket_id'        => $post_id,
                    'status'           => 0,
                    'created_at'       => current_time('mysql')
                ],
                ['%d', '%s', '%d', '%d', '%s']
            );
        }
    }

    if (empty($created_post_ids)) {
        wp_send_json_error([
            'message' => 'Booking có phản hồi nhưng không tạo được post nào.',
            'status'  => 'failed',
            'debug'   => [
                'journeyId'       => $journeyId,
                'journeyGroupId'  => $journeyGroupId,
                'itemsByIndex'    => $itemsByIndex,
            ],
        ]);
        return;
    }


    $partnerType = (!empty($vexereTickets) && empty($goopayTickets)) ? 'vexere'
        : ((!empty($goopayTickets) && empty($vexereTickets)) ? 'goopay' : 'mixed');
    $partnerResponse = null;
    if ($partnerType === 'vexere') {
        $partnerResponse = $vexerePartnerResp;
    } elseif ($partnerType === 'goopay') {
        $partnerResponse = $goopayPartnerResp;
    } else {
        $partnerResponse = [
            'vexere' => $vexerePartnerResp,
            'goopay' => $goopayPartnerResp,
        ];
    }

    wp_send_json_success([
        'status'          => $normalized['status'],     // success|partial
        'items'           => $normalized['items'],
        'errors'          => $normalized['errors'],
        'success_count'   => $normalized['success_count'],
        'fail_count'      => $normalized['fail_count'],
        'journeyIds'      => $normalized['journeyIds'],
        'journey_id'      => $journeyId,
        'journey_group_id' => $journeyGroupId,
        'post_ids'        => $created_post_ids,
        'code'            => $normalized['code'],
        'seat_depart'     => $normalized['seat_depart'],
        'seat_arrive'     => $normalized['seat_arrive'],
        'partner'         => $partnerType,
        'partner_response' => $partnerResponse,
    ]);
}


function handle_get_state_city_new()
{
    $cache_key = 'state_city_data_cache';
    $cached_data = get_transient($cache_key);
    if ($cached_data !== false) {
        wp_send_json_success($cached_data);
    }

    $response = call_api_v2('/locations/search', 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    // wp_send_json_success($data);
    if (isset($data['data'])) {
        set_transient($cache_key, $data['data'], 3600 * 24 * 30);
        wp_send_json_success($data['data']);
    }

    wp_send_json_error('Invalid response format');
}
// XỬ LÝ MB BANK
function save_token($token, $expiration)
{
    update_option('api_auth_token', $token);
    update_option('api_auth_expiration', $expiration);
}
function is_token_expired()
{
    $expiration = get_option('api_auth_expiration');
    if (!$expiration)
        return true;
    $current_time = current_time('mysql');
    return strtotime($current_time) >= strtotime($expiration);
}
function refresh_token()
{
    $response = wp_remote_post(DAILYVE_BANK_URL . '/Auth/Login', [
        'body' => json_encode([
            'email' => DAILYVE_BANK_EMAIL,
            'password' => DAILYVE_BANK_PASSWORD
        ]),
        'headers' => [
            'Content-Type' => 'application/json'
        ]
    ]);
    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($body['successful'] && isset($body['data']['token'])) {
            save_token($body['data']['token'], $body['data']['expiration']);
            return $body['data']['token'];
        }
    }
    return false;
}

function call_api_with_token($endpoint, $method = 'GET', $data = [])
{
    if (is_token_expired()) {
        $new_token = refresh_token();
        if (!$new_token) {
            return new WP_Error('token_refresh_failed', 'Không thể làm mới token.');
        }
    }
    $token = get_option('api_auth_token');
    $response = wp_remote_request($endpoint, [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ],
        'body' => $method === 'POST' ? json_encode($data) : null
    ]);
    return $response;
}
function fetch_data_transaction()
{
    $endpoint = DAILYVE_BANK_URL . '/Transaction?pageSize=25&page=1';
    $response = call_api_with_token($endpoint, 'GET');
    if (is_wp_error($response)) {
        return rest_ensure_response([
            'error' => $response->get_error_message()
        ]);
    }
    return rest_ensure_response(json_decode(wp_remote_retrieve_body($response), true));
}
add_action('wp_ajax_check_transaction_ticket', 'handle_check_transaction_ticket');
add_action('wp_ajax_nopriv_check_transaction_ticket', 'handle_check_transaction_ticket');
function handle_check_transaction_ticket()
{
    check_ajax_referer('ams_vexe_check_transaction', 'nonce');

    $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
    if (empty($code)) {
        wp_send_json_error(['message' => 'Thiếu mã đơn hàng!']);
        return;
    }

    $existing_posts = get_posts([
        'post_type'      => 'book-ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => 'journey_group_id',
                'value'   => $code,
                'compare' => '=',
            ]
        ],
    ]);

    if (empty($existing_posts)) {
        $existing_posts = get_posts([
            'post_type'      => 'book-ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'title'          => $code,
        ]);
    }

    if (empty($existing_posts)) {
        wp_send_json_error(['message' => 'Đơn hàng không tồn tại!']);
        return;
    }

    usort($existing_posts, function ($a, $b) {
        $ia = (int)get_post_meta($a->ID, 'journey_ticket_index', true);
        $ib = (int)get_post_meta($b->ID, 'journey_ticket_index', true);
        return $ia <=> $ib;
    });

    $post_ids = [];
    $paymentContent = '';
    $totalPrice = 0;
    $bookingCodesAll = [];
    $partnerGroups = [
        'vexere' => [],
        'goopay' => [],
    ];

    foreach ($existing_posts as $p) {
        $pid = $p->ID;
        $post_ids[] = $pid;

        if ($paymentContent === '') {
            $paymentContent = (string)(get_post_meta($pid, 'payment_content', true) ?: (function_exists('get_field') ? (get_field('payment_content', $pid) ?? '') : ''));
        }

        $tp = (float)(get_post_meta($pid, 'total_price', true) ?: (function_exists('get_field') ? (get_field('total_price', $pid) ?? 0) : 0));
        $totalPrice += $tp;

        $bc = (string)(get_post_meta($pid, 'booking_codes', true) ?: (function_exists('get_field') ? (get_field('booking_codes', $pid) ?? '') : ''));
        $partnerId = (string)(get_post_meta($pid, 'partner_id', true) ?: (function_exists('get_field') ? (get_field('partner_id', $pid) ?? '') : ''));
        if (!empty($bc)) {
            $parts = preg_split('/\s+/', trim($bc));
            if (is_array($parts)) {
                $bookingCodesAll = array_merge($bookingCodesAll, $parts);
                $partnerKey = strtolower($partnerId);
                if ($partnerKey !== 'vexere' && $partnerKey !== 'goopay') {
                    $partnerKey = '';
                }
                if ($partnerKey !== '') {
                    foreach ($parts as $codePart) {
                        $codePart = trim((string)$codePart);
                        if ($codePart !== '') {
                            $partnerGroups[$partnerKey][] = $codePart;
                        }
                    }
                }
            }
        }
    }

    $bookingCodesAll = array_values(array_unique(array_filter($bookingCodesAll)));

    // Nếu không có bookingCode thì không thể pay AMS
    if (empty($bookingCodesAll)) {
        wp_send_json_error(['message' => 'Không có booking code để xác nhận thanh toán.']);
        return;
    }

    $endpoint = DAILYVE_BANK_URL . '/Transaction?pageSize=30&page=1';
    $response = call_api_with_token($endpoint, 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
        return;
    }

    $bankData = json_decode(wp_remote_retrieve_body($response), true);

    $found = false;
    $matchedTransaction = null;

    if (isset($bankData['data']) && is_array($bankData['data'])) {
        foreach ($bankData['data'] as $transaction) {
            $content = (string)($transaction['content'] ?? '');
            $amount  = (float)($transaction['amount'] ?? 0);

            if ($paymentContent !== '' && $content !== '' && str_contains($content, $paymentContent)) {
                if ($amount >= $totalPrice) {
                    $found = true;
                    $matchedTransaction = $transaction;
                    break;
                }
            }
        }
    }

    // Nếu chưa tìm thấy giao dịch phù hợp
    if (!$found) {
        wp_send_json_success([
            'status' => false,
            'code'   => $code,
            'total'  => $totalPrice,
        ]);
        return;
    }

    $payOk = true;
    $payResults = [];

    $partnerGroups['vexere'] = array_values(array_unique(array_filter($partnerGroups['vexere'])));
    $partnerGroups['goopay'] = array_values(array_unique(array_filter($partnerGroups['goopay'])));

    if (!empty($partnerGroups['vexere'])) {
        $vexereCodes = array_values($partnerGroups['vexere']);
        $isRoundTripVexere = count($vexereCodes) > 1;

        if ($isRoundTripVexere) {
            $vexereBody = [];
            foreach ($vexereCodes as $bookingCode) {
                $vexereBody[] = [
                    'code'           => $bookingCode,
                    'coupon'         => '',
                    'transaction_id' => '',
                ];
            }
        } else {
            $vexereBody = [
                'code'           => $vexereCodes[0],
                'coupon'         => '',
                'transaction_id' => '',
            ];
        }

        $responseAMS = call_api_v2('booking/vexere/pay', 'POST', $vexereBody);

        if (is_wp_error($responseAMS)) {
            $payOk = false;
            foreach ($vexereCodes as $bookingCode) {
                $payResults[] = [
                    'bookingCode' => $bookingCode,
                    'ok'          => false,
                    'error'       => $responseAMS->get_error_message(),
                    'partner'     => 'vexere',
                ];
            }
        } else {
            $amsData = json_decode(wp_remote_retrieve_body($responseAMS), true);
            $dataField = $amsData['data'] ?? [];

            // Build map booking_code => result từ response
            // Vexere 1 vé:  data = { message, booking_code, ... }
            // Vexere 2 vé:  data = [ {message, booking_code, ...}, ... ]
            $resultMap = [];

            if (isset($dataField[0]) && is_array($dataField[0])) {
                // data là array — 2 chiều
                foreach ($dataField as $item) {
                    $bc = (string)($item['booking_code'] ?? '');
                    if ($bc !== '') {
                        $resultMap[$bc] = strtolower((string)($item['message'] ?? '')) === 'success';
                    }
                }
            } elseif (isset($dataField['message'])) {
                // data là object — 1 chiều
                $bc = (string)($dataField['booking_code'] ?? $vexereCodes[0] ?? '');
                if ($bc !== '') {
                    $resultMap[$bc] = strtolower((string)$dataField['message']) === 'success';
                }
            }

            foreach ($vexereCodes as $bookingCode) {
                // Nếu không tìm thấy trong map thì fallback: kiểm tra có bất kỳ entry nào success không
                if (isset($resultMap[$bookingCode])) {
                    $ok = $resultMap[$bookingCode];
                } else {
                    // Fallback: nếu tất cả entry đều success thì coi là ok
                    $ok = !empty($resultMap) && !in_array(false, array_values($resultMap), true);
                }

                $payResults[] = [
                    'bookingCode' => $bookingCode,
                    'ok'          => $ok,
                    'raw'         => $amsData,
                    'partner'     => 'vexere',
                ];

                if (!$ok) $payOk = false;
            }
        }
    }

    if (!empty($partnerGroups['goopay'])) {
        $responseAMS = call_api_v2('booking/goopay/pay', 'POST', [
            'bookingNos' => array_values($partnerGroups['goopay']),
            'paymentIDRef' => (string)($post_ids[0] ?? ''),
            'paymentResult' => 'success',
            'msg' => 'success',
            'note' => ''
        ]);

        if (is_wp_error($responseAMS)) {
            $payOk = false;
            foreach ($partnerGroups['goopay'] as $bookingCode) {
                $payResults[] = ['bookingCode' => $bookingCode, 'ok' => false, 'error' => $responseAMS->get_error_message(), 'partner' => 'goopay'];
            }
        } else {
            $amsData = json_decode(wp_remote_retrieve_body($responseAMS), true);
            $ok = isset($amsData['code']) && (string)$amsData['code'] === '0';
            foreach ($partnerGroups['goopay'] as $bookingCode) {
                $payResults[] = ['bookingCode' => $bookingCode, 'ok' => $ok, 'raw' => $amsData, 'partner' => 'goopay'];
            }
            if (!$ok) $payOk = false;
        }
    }

    foreach ($bookingCodesAll as $bookingCode) {
        $hasResult = false;
        foreach ($payResults as $item) {
            if (($item['bookingCode'] ?? '') === $bookingCode) {
                $hasResult = true;
                break;
            }
        }
        if (!$hasResult) {
            $payOk = false;
            $payResults[] = ['bookingCode' => $bookingCode, 'ok' => false, 'error' => 'Partner not found', 'partner' => 'unknown'];
        }
    }

    if ($payOk) {
        foreach ($post_ids as $pid) {
            update_post_meta($pid, 'payment_status', 2);
            update_post_meta($pid, 'payment_method', 'Chuyển khoản');

            // Save isHoliday from Goopay results if applicable
            $bc_raw = (string)get_post_meta($pid, 'booking_codes', true);
            $codes = preg_split('/\s+/', trim($bc_raw));
            foreach ($codes as $c) {
                foreach ($payResults as $res) {
                    if (($res['bookingCode'] ?? '') === $c && ($res['partner'] ?? '') === 'goopay') {
                        $raw = $res['raw'] ?? [];
                        $data_list = $raw['data'] ?? [];
                        if (is_array($data_list)) {
                            foreach ($data_list as $entry) {
                                if (($entry['code'] ?? '') === $c && isset($entry['isHoliday'])) {
                                    update_post_meta($pid, 'is_holiday', (bool)$entry['isHoliday']);
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    wp_send_json_success([
        'status'         => $payOk,
        'code'           => $code,
        'paymentContent' => $paymentContent,
        'total'          => $totalPrice,
        'post_ids'       => $post_ids,
        'bookingCodes'   => $bookingCodesAll,
        'transaction'    => $matchedTransaction,
        'payResults'     => $payResults,
    ]);
}

add_action('wp_ajax_delete_ticket', 'handle_delete_ticket');
add_action('wp_ajax_nopriv_delete_ticket', 'handle_delete_ticket');

function handle_pay_ticket_ams($request)
{
    $secret_key = $request->get_param('secret_key');
    $booking_codes = $request->get_param('booking_codes');
    $token = $request->get_param('token');

    if ($secret_key !== secretOTP) {
        return new WP_Error('invalid_secret', 'Invalid secret key provided', array('status' => 403));
    }

    if (empty($booking_codes)) {
        return new WP_Error('invalid_codes', 'No valid booking codes provided', array('status' => 400));
    }

    $response = array(
        'successful' => false,
        'message' => '',
        'results' => array()
    );

    $args = array(
        'post_type' => 'book-ticket',
        'title' => $booking_codes,
        'posts_per_page' => 1
    );

    $posts = get_posts($args);

    if (empty($posts)) {
        $response['results'][] = array(
            'code' => $booking_codes,
            'status' => false,
            'message' => 'Booking not found'
        );

        return rest_ensure_response($response);
    }

    $post_id = $posts[0]->ID;
    $post_booking_codes = get_field('booking_codes', $post_id);
    $codeArr = explode(' ', $post_booking_codes);
    foreach ($codeArr as $code) {
        $endpointAMS = endPoint . '/Api/Ticket/PayByBookingCode?bookingCode=' . $code;
        $otp = generateOTP(secretOTP);
        $responseAMS = call_api_with_token_agent($endpointAMS, 'POST', [], $otp);

        if (is_wp_error($responseAMS)) {
            $response['results'][] = array(
                'code' => $code,
                'status' => false,
                'message' => $responseAMS->get_error_message()
            );
            continue;
        }

        $data = json_decode(wp_remote_retrieve_body($responseAMS), true);

        if (isset($data['successful']) && $data['successful'] == true) {
            update_post_meta($post_id, 'payment_status', 2);
            update_post_meta($post_id, 'payment_method', 'Chuyển khoản');
            $now = new DateTime();
            update_post_meta($post_id, 'updated_at', $now->format('H:i d/m/Y'));
            // Update ticket coupon status to completed
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ticket_coupon',
                array('status' => 'completed'),
                array('ticket_id' => $post_id)
            );

            $coupon_result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT coupon_id FROM " . $wpdb->prefix . "ticket_coupon WHERE ticket_id = %d",
                    $post_id
                )
            );

            if ($coupon_result && $coupon_result->coupon_id == 15106) {
                $responseAuth = wp_remote_post(BMS_URL . '/v1/customer/update-used-app-first-promo', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                ]);

                $body = json_decode(wp_remote_retrieve_body($responseAuth), true);
                if (!is_wp_error($responseAuth)) {
                    if (isset($body['status']) && $body['status'] == true) {
                        $response['message'] = 'Cập nhật thành công';
                    }
                } else {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Có lỗi xảy ra khi cập nhật thông tin mã giảm giá'
                    ), 500);
                }
            }

            $response['results'][] = array(
                'code' => $code,
                'status' => true,
                'message' => 'Payment processed successfully'
            );
            $response['successful'] = true;
        } else {
            $response['results'][] = array(
                'code' => $code,
                'status' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Payment processing failed'
            );
        }
    }

    if (empty($response['message'])) {
        $response['message'] = $response['successful'] ?
            'All payments processed successfully' :
            'Some payments failed to process';
    }

    return new WP_REST_Response($response, 200);
}
/**
 * Core ticket cancellation logic.
 * Handles both journey_group_id or a single booking code.
 *
 * @param string $key The journey_group_id or booking code.
 * @return array Results of the cancellation process.
 */
function dailyve_perform_ticket_cancellation($key)
{
    if (empty($key)) {
        return ['status' => false, 'message' => 'Thiếu mã đơn hàng!'];
    }

    $posts = get_posts([
        'post_type'      => 'book-ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => 'journey_group_id',
                'value'   => $key,
                'compare' => '=',
            ]
        ],
    ]);

    if (empty($posts)) {
        $posts = get_posts([
            'post_type'      => 'book-ticket',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'title'          => $key,
        ]);
    }

    if (empty($posts)) {
        return ['status' => false, 'message' => 'Đơn hàng không tồn tại!'];
    }

    // Sort by return/depart if needed
    usort($posts, function ($a, $b) {
        $ia = (int)get_post_meta($a->ID, 'journey_ticket_index', true);
        $ib = (int)get_post_meta($b->ID, 'journey_ticket_index', true);
        return $ia <=> $ib;
    });

    $post_ids = [];
    $bookingCodesAll = [];
    $bookingCodePartnerMap = [];

    foreach ($posts as $p) {
        $pid = $p->ID;
        $post_ids[] = $pid;

        $partnerId = (string)(get_post_meta($pid, 'partner_id', true) ?: '');
        if ($partnerId === '' && function_exists('get_field')) {
            $partnerId = (string)(get_field('partner_id', $pid) ?? '');
        }

        $bc = (string)(get_post_meta($pid, 'booking_codes', true) ?: '');
        if ($bc === '' && function_exists('get_field')) {
            $bc = (string)(get_field('booking_codes', $pid) ?? '');
        }

        if (!empty($bc)) {
            $parts = preg_split('/\s+/', trim($bc));
            $parts = is_array($parts) ? array_values(array_filter($parts)) : [];

            foreach ($parts as $bookingCode) {
                $bookingCodesAll[] = $bookingCode;
                // nếu 1 bookingCode xuất hiện nhiều nơi thì ưu tiên cái có partnerId
                if (!isset($bookingCodePartnerMap[$bookingCode]) || ($bookingCodePartnerMap[$bookingCode] === '' && $partnerId !== '')) {
                    $bookingCodePartnerMap[$bookingCode] = $partnerId;
                }
            }
        }
    }

    $bookingCodesAll = array_values(array_unique(array_filter($bookingCodesAll)));

    if (empty($bookingCodesAll)) {
        foreach ($post_ids as $pid) {
            update_post_meta($pid, 'payment_status', 3);
        }
        return [
            'status'       => true,
            'post_ids'     => $post_ids,
            'bookingCodes' => [],
            'results'      => [],
            'message'      => 'Không có booking code, đã cập nhật trạng thái huỷ.',
        ];
    }

    $allOk = true;
    $results = [];
    $goopayCodes = [];
    $vexereCodes = [];
    $otherCodes = [];

    foreach ($bookingCodesAll as $bookingCode) {
        $partnerId = strtolower((string)($bookingCodePartnerMap[$bookingCode] ?? ''));
        if ($partnerId === '') {
            $allOk = false;
            $results[] = [
                'bookingCode' => $bookingCode,
                'ok'          => false,
                'error'       => 'Thiếu partner_id cho bookingCode này.',
            ];
            continue;
        }
        if ($partnerId === 'goopay') {
            $goopayCodes[] = $bookingCode;
        } elseif ($partnerId === 'vexere') {
            $vexereCodes[] = $bookingCode;
        } else {
            $otherCodes[] = $bookingCode;
        }
    }

    // Vexere - local only as per original logic
    if (!empty($vexereCodes)) {
        foreach ($vexereCodes as $bookingCode) {
            $results[] = [
                'bookingCode' => $bookingCode,
                'partnerId'   => 'vexere',
                'ok'          => true,
                'raw'         => ['message' => 'Cancelled in local database only'],
            ];
        }
    }

    // Other partners
    foreach ($otherCodes as $bookingCode) {
        $partnerId = (string)($bookingCodePartnerMap[$bookingCode] ?? '');
        $endpoint = endPoint . '/api/ticket/cancel';
        $response = call_api_with_token_agent($endpoint, 'POST', [
            "partnerId"       => $partnerId,
            "ticket_code"     => $bookingCode,
            "transaction_id"  => '',
        ]);

        if (is_wp_error($response)) {
            $allOk = false;
            $results[] = [
                'bookingCode' => $bookingCode,
                'partnerId'   => $partnerId,
                'ok'          => false,
                'error'       => $response->get_error_message(),
            ];
            continue;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $ok = isset($data['message']) && $data['message'] === 'OK';
        if (!$ok) $allOk = false;

        $results[] = [
            'bookingCode' => $bookingCode,
            'partnerId'   => $partnerId,
            'ok'          => $ok,
            'raw'         => $data,
        ];
    }

    // Goopay API cancel
    if (!empty($goopayCodes)) {
        $responseGoopay = call_api_v2('booking/goopay/cancel', 'POST', [
            'bookingNos' => $goopayCodes,
        ]);

        if (is_wp_error($responseGoopay)) {
            $allOk = false;
            foreach ($goopayCodes as $bookingCode) {
                $results[] = [
                    'bookingCode' => $bookingCode,
                    'partnerId'   => 'goopay',
                    'ok'          => false,
                    'error'       => $responseGoopay->get_error_message(),
                ];
            }
        } else {
            $goopayData = json_decode(wp_remote_retrieve_body($responseGoopay), true);
            $goopayMessage = strtolower((string)($goopayData['message'] ?? ''));
            $goopayCode = (string)($goopayData['code'] ?? '');
            $goopayOk = ($goopayMessage === 'success' || $goopayCode === '0');
            if (!$goopayOk) $allOk = false;

            foreach ($goopayCodes as $bookingCode) {
                $results[] = [
                    'bookingCode' => $bookingCode,
                    'partnerId'   => 'goopay',
                    'ok'          => $goopayOk,
                    'raw'         => $goopayData,
                ];
            }
        }
    }

    // Update local status if everything went well
    if ($allOk) {
        foreach ($post_ids as $pid) {
            update_post_meta($pid, 'payment_status', 3);
        }
    }

    return [
        'status'       => $allOk,
        'post_ids'     => $post_ids,
        'bookingCodes' => $bookingCodesAll,
        'results'      => $results,
    ];
}

/**
 * Cron callback to automatically cancel expired tickets.
 */
// Cron jobs have been moved to inc/api/cron-jobs.php
// Add custom cron interval if 'every_minute' is not available
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __('Every 1 Minute')
        );
    }
    return $schedules;
});


function handle_delete_ticket()
{
    check_ajax_referer('ams_vexe_delete_ticket', 'nonce');

    $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
    $journey_group_id = isset($_POST['journey_group_id']) ? sanitize_text_field($_POST['journey_group_id']) : '';

    $key = !empty($journey_group_id) ? $journey_group_id : $code;

    $result = dailyve_perform_ticket_cancellation($key);

    if (isset($result['status']) && $result['status'] === true) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

// function vnpay_log_request($url, $params)
// {
//     $response = wp_remote_post($url, array(
//         'body' => $params
//     ));
//     // Lấy thông tin request
//     $request_data = json_encode($params);
//     $response_data = json_encode(wp_remote_retrieve_body($response));
//     $status_code = wp_remote_retrieve_response_code($response);
//     // Lấy địa chỉ IP của request gọi vào
//     $client_ip = $_SERVER['REMOTE_ADDR']; // Địa chỉ IP của người gọi
//     // Ghi log request, response và địa chỉ IP
//     $log_entry = "VNPAY Request: " . $request_data . "\n";
//     $log_entry .= "VNPAY Response: " . $response_data . "\n";
//     $log_entry .= "Status Code: " . $status_code . "\n";
//     $log_entry .= "Client IP: " . $client_ip . "\n";
//     $log_entry .= "Time: " . date('Y-m-d H:i:s') . "\n\n";
//     // Ghi log vào file wp-content/uploads/vnpay_logs.txt
//     $log_file = WP_CONTENT_DIR . '/uploads/vnpay_logs.txt';
//     file_put_contents($log_file, $log_entry, FILE_APPEND);
// }
// add_action('init', function() {
//     // Ví dụ request đến VNPAY
//     $url = 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html'; // URL sandbox
//     $params = array(
//         'vnp_Version' => '2.1.0',
//         'vnp_Command' => 'pay',
//         'vnp_TmnCode' => 'YourTerminalCode',
//         'vnp_Amount' => 1000000,
//         // Thêm các tham số khác...
//     );
//     vnpay_log_request($url, $params);
// });
function renderTopSeatTemplateNote($type, $color)
{
    switch ($type) {
        case 1:
            $seatTemplate = '<svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg>';
            $note = '<div class="seat-info">
                <div class="seat-thumbnail unavailable not-allowed"><svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg></div>
                            <div class="seat-name">Ghế không bán</div>
                        </div>
                        <div class="seat-info">
                <div class="seat-thumbnail choose-seat">' . $seatTemplate . '</div>
                            <div class="seat-name">Đang chọn</div>
                        </div>';
            break;
        case 3:
            $seatTemplate = '<svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg>';
            $note = '<div class="seat-info"><div class="seat-thumbnail unavailable not-allowed"><svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg></div>
                                <div class="seat-name">Ghế không bán</div>
                            </div>
                            <div class="seat-info">
                    <div class="seat-thumbnail choose-seat">' . $seatTemplate . '</div>
                                <div class="seat-name">Đang chọn</div>
                            </div>';
            break;
        case 7:
            $seatTemplate = '<svg width="40" height="44" viewBox="0 0 50 40" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="2.75" width="44.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="27.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M25 8.333A6.67 6.67 0 0 0 18.334 15 6.67 6.67 0 0 0 25 21.667 6.67 6.67 0 0 0 31.667 15 6.669 6.669 0 0 0 25 8.333zm-1.333 10L20.333 15l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M29.96 11.46l-1.42-1.42L25 13.59l-3.54-3.55-1.42 1.42L23.59 15l-3.55 3.54 1.42 1.42L25 16.41l3.54 3.55 1.42-1.42L26.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            $note = '<div class="seat-info">
                <div class="seat-thumbnail unavailable not-allowed"><svg width="40" height="44" viewBox="0 0 50 40" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="2.75" width="44.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="27.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M25 8.333A6.67 6.67 0 0 0 18.334 15 6.67 6.67 0 0 0 25 21.667 6.67 6.67 0 0 0 31.667 15 6.669 6.669 0 0 0 25 8.333zm-1.333 10L20.333 15l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M29.96 11.46l-1.42-1.42L25 13.59l-3.54-3.55-1.42 1.42L23.59 15l-3.55 3.54 1.42 1.42L25 16.41l3.54 3.55 1.42-1.42L26.41 15l3.55-3.54z" fill="transparent"></path></svg></div>
                            <div class="seat-name">Ghế không bán</div>
                        </div>
                        <div class="seat-info">
                <div class="seat-thumbnail choose-seat">' . $seatTemplate . '</div>
                            <div class="seat-name">Đang chọn</div>
                        </div>';
            break;
        case 2:
            $seatTemplate = '<svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            $note = '<div class="seat-info">
                <div class="seat-thumbnail unavailable not-allowed"><svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg></div>
                            <div class="seat-name">Ghế không bán</div>
                        </div>
                        <div class="seat-info">
                <div class="seat-thumbnail choose-seat">' . $seatTemplate . '</div>
                            <div class="seat-name">Đang chọn</div>
                        </div>';
            break;
        default:
            $seatTemplate = '<svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            $note = '<div class="seat-info">
            <div class="seat-thumbnail unavailable not-allowed"><svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="#bbbbbb" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg></div>
                            <div class="seat-name">Ghế không bán</div>
                        </div>
                        <div class="seat-info">
                <div class="seat-thumbnail choose-seat">' . $seatTemplate . '</div>
                            <div class="seat-name">Đang chọn</div>
                        </div>';
            break;
    }
    return $note;
}
function renderSeatTemplate($type, $color, $isNote = false, $info = [], $isAvailable = 1, $isNotIcon = 0)
{
    $classIsNotIcon = $isNotIcon == 1 ? 'not-icon' : '';
    $color = $color ?? '#B8B8B8';
    if ($isNote) {
        $price = number_format($info['price'], 0, ",", ".");
        $originalPrice = number_format($info['originalPrice'], 0, ",", ".");
    }
    switch ($type) {
        case 1:
            if ($isAvailable != 1) {
                $seatTemplate = '<svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg>';
            } else {
                $seatTemplate = '<svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg>';
            }
            if ($isNote) {
                $note = '<div class="seat-info">
                <div class="seat-thumbnail none-seat ' . $classIsNotIcon . '">' . $seatTemplate . '</div>
                            <div class="seat-name">
                                <div class="seat-group-name">' . $info['name'] . '</div>';
                if ($info['isDiscount']) {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong> <span class="seat-fare-original">' . $originalPrice . 'đ</span></div>';
                } else {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong></div>';
                }
                $note .= '</div></div>';
            }
            break;
        case 3:
            if ($isAvailable == 1) {
                $seatTemplate = '<svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg>';
            } else {
                $seatTemplate = '<svg width="40" height="32" viewBox="0 0 40 32" fill="none" xmlns="http://www.w3.org/2000/svg" class="ant-tooltip-open"><rect x="8.75" y="2.75" width="22.5" height="26.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="10.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 10.25 11.75)" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="35.25" y="11.75" width="14.5" height="5.5" rx="2.25" transform="rotate(90 35.25 11.75)" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="8.75" y="22.75" width="22.5" height="6.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M20 6.333A6.67 6.67 0 0 0 13.334 13 6.67 6.67 0 0 0 20 19.667 6.67 6.67 0 0 0 26.667 13 6.669 6.669 0 0 0 20 6.333zm-1.333 10L15.333 13l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M24.96 9.46l-1.42-1.42L20 11.59l-3.54-3.55-1.42 1.42L18.59 13l-3.55 3.54 1.42 1.42L20 14.41l3.54 3.55 1.42-1.42L21.41 13l3.55-3.54z" fill="transparent"></path></svg>';
            }
            if ($isNote) {
                $note = '<div class="seat-info">
                    <div class="seat-thumbnail none-seat ' . $classIsNotIcon . '">' . $seatTemplate . '</div>
                                <div class="seat-name">
                                    <div class="seat-group-name">' . $info['name'] . '</div>';
                if ($info['isDiscount']) {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong> <span class="seat-fare-original">' . $originalPrice . 'đ</span></div>';
                } else {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong></div>';
                }
                $note .= '</div></div>';
            }
            break;
        case 7:
            if ($isAvailable != 1) {
                $seatTemplate = '<svg width="40" height="44" viewBox="0 0 50 40" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="2.75" width="44.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="27.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M25 8.333A6.67 6.67 0 0 0 18.334 15 6.67 6.67 0 0 0 25 21.667 6.67 6.67 0 0 0 31.667 15 6.669 6.669 0 0 0 25 8.333zm-1.333 10L20.333 15l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M29.96 11.46l-1.42-1.42L25 13.59l-3.54-3.55-1.42 1.42L23.59 15l-3.55 3.54 1.42 1.42L25 16.41l3.54 3.55 1.42-1.42L26.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            } else {
                $seatTemplate = '<svg width="40" height="44" viewBox="0 0 50 40" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2.75" y="2.75" width="44.5" height="34.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="27.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M25 8.333A6.67 6.67 0 0 0 18.334 15 6.67 6.67 0 0 0 25 21.667 6.67 6.67 0 0 0 31.667 15 6.669 6.669 0 0 0 25 8.333zm-1.333 10L20.333 15l.94-.94 2.394 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M29.96 11.46l-1.42-1.42L25 13.59l-3.54-3.55-1.42 1.42L23.59 15l-3.55 3.54 1.42 1.42L25 16.41l3.54 3.55 1.42-1.42L26.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            }
            if ($isNote) {
                $note = '<div class="seat-info">
                <div class="seat-thumbnail none-seat ' . $classIsNotIcon . '">' . $seatTemplate . '</div>
                            <div class="seat-name">
                                <div class="seat-group-name">' . $info['name'] . '</div>';
                if ($info['isDiscount']) {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong> <span class="seat-fare-original">' . $originalPrice . 'đ</span></div>';
                } else {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong></div>';
                }
                $note .= '</div></div>';
            }
            break;
        case 2:
            if ($isAvailable != 1) {
                $seatTemplate = '<svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            } else {
                $seatTemplate = '<svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            }
            if ($isNote) {
                $note = '<div class="seat-info">
                <div class="seat-thumbnail none-seat ' . $classIsNotIcon . '">' . $seatTemplate . '</div>
                            <div class="seat-name">
                                <div class="seat-group-name">' . $info['name'] . '</div>';
                if ($info['isDiscount']) {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong> <span class="seat-fare-original">' . $originalPrice . 'đ</span></div>';
                } else {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong></div>';
                }
                $note .= '</div></div>';
            }
            break;
        default:
            if ($isAvailable != 1) {
                $seatTemplate = '<svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#bbbbbb" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            } else {
                $seatTemplate = '<svg width="32" height="40" viewBox="0 0 28 40" fill="none" xmlns="http://www.w3.org/2000/svg" style="width: 27px; height: 40px;"><rect x="2.75" y="2.75" width="22.5" height="34.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><rect x="5.75" y="27.75" width="16.5" height="6.5" rx="2.25" fill="#FFF" stroke="' . $color . '" stroke-width="1.5" stroke-linejoin="round"></rect><path class="icon-selected" d="M14 8.333A6.67 6.67 0 0 0 7.333 15 6.67 6.67 0 0 0 14 21.667 6.67 6.67 0 0 0 20.667 15 6.669 6.669 0 0 0 14 8.333zm-1.333 10L9.334 15l.94-.94 2.393 2.387 5.06-5.06.94.946-6 6z" fill="transparent"></path><path class="icon-disabled" d="M18.96 11.46l-1.42-1.42L14 13.59l-3.54-3.55-1.42 1.42L12.59 15l-3.55 3.54 1.42 1.42L14 16.41l3.54 3.55 1.42-1.42L15.41 15l3.55-3.54z" fill="transparent"></path></svg>';
            }
            if ($isNote) {
                $note = '<div class="seat-info">
                <div class="seat-thumbnail none-seat ' . $classIsNotIcon . '">' . $seatTemplate . '</div>
                            <div class="seat-name">
                                <div class="seat-group-name">' . $info['name'] . '</div>';
                if ($info['isDiscount']) {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong> <span class="seat-fare-original">' . $originalPrice . 'đ</span></div>';
                } else {
                    $note .= '<div class="seat-original"><strong>' . $price . 'đ</strong></div>';
                }
                $note .= '</div></div>';
            }
            break;
    }
    return $isNote ? $note : $seatTemplate;
}
function handle_choose_trip_ajax_booking()
{
    check_ajax_referer('ams_vexe', 'nonce');

    $partnerId  = isset($_POST['partnerId']) ? sanitize_text_field($_POST['partnerId']) : null;
    $tripCode   = isset($_POST['tripCode']) ? sanitize_text_field($_POST['tripCode']) : '';
    $departureTime = isset($_POST['departureTime']) ? sanitize_text_field($_POST['departureTime']) : '';
    $wayId      = isset($_POST['wayId']) ? sanitize_text_field($_POST['wayId']) : '';
    $bookingId  = isset($_POST['bookingId']) ? sanitize_text_field($_POST['bookingId']) : '';

    $response = null;
    $params   = [];

    if ($partnerId === 'vexere') {
        $params = array(
            "tripId"      => $tripCode,
        );
        $response  = call_api_v2('/trips/vexere/trip_detail', 'GET', $params);
    } elseif ($partnerId === 'goopay') {
        $params = array(
            "routeId"      => $tripCode,
            "departureTime"   => $departureTime ? str_replace(':', 'h', $departureTime) : '',
            "wayId"       => $wayId,
            "bookingId"  => $bookingId,
        );
        $response  = call_api_v2('/trips/goopay/trip_detail', 'GET', $params);
    }

    $output    = '';
    $seatsList = [];

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
        wp_die();
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($data) || !is_array($data) || empty($data['data'])) {
        wp_send_json_error('Invalid response data');
        wp_die();
    }

    $seatGroups = [];
    $templates  = $data['data']['coach_seat_template'];

    $seat_group_list   = [];
    $seat_group_list_2 = [];

    foreach ($templates as $template) {
        foreach ($template['seats'] as $seat) {
            $groupCode = $seat['seat_group_code'];
            if (!isset($seatGroups[$groupCode])) {
                $seatGroups[$groupCode] = [
                    'name'          => $seat['seat_group'],
                    'color'         => $seat['seat_color'],
                    'type'          => $seat['seat_type'],
                    'price'         => $seat['fare'],
                    'originalPrice' => $seat['fares']['original'],
                    'isDiscount'    => $seat['fare'] < $seat['fares']['original'],
                ];
            }

            $seat_group_item = [
                'seat_group_code' => $seat['seat_group_code'],
                'name'            => $seat['seat_group'],
                'color'           => $seat['seat_color'],
                'type'            => $seat['seat_type'],
                'price'           => $seat['fare'],
                'originalPrice'   => $seat['fares']['original'],
                'isDiscount'      => $seat['fare'] < $seat['fares']['original']
            ];

            $seat_group_list[]   = $seat_group_item;
            $seat_group_list_2[] = $seat_group_item;

            $seat_groups = $seat['seat_groups'];
            foreach ($seat_groups as $seat_group) {
                $seat_group_item = [
                    'seat_group_code' => $seat_group['seat_group_code'],
                    'name'            => $seat_group['seat_group'],
                    'color'           => $seat_group['seat_color'],
                    'type'            => $seat['seat_type'],
                    'price'           => $seat_group['fare'],
                    'originalPrice'   => $seat_group['fares']['original'],
                    'isDiscount'      => $seat_group['fare'] < $seat_group['fares']['original']
                ];
                $seat_group_list_2[] = $seat_group_item;
            }
        }
    }

    $seat_group_list   = array_map('unserialize', array_unique(array_map('serialize', $seat_group_list)));
    $seat_group_list_2 = array_map('unserialize', array_unique(array_map('serialize', $seat_group_list_2)));
    $new_seat_group_list = [];

    foreach ($seat_group_list_2 as $item_1) {
        $flag = 0;
        foreach ($seat_group_list as $item_2) {
            if ($item_1['seat_group_code'] == $item_2['seat_group_code']) {
                $new_seat_group_list[] = $item_1;
                $flag = 1;
            }
        }
        if ($flag == 0) {
            $item_1['is_not_icon'] = 1;
            $new_seat_group_list[] = $item_1;
        }
    }

    $output = '<div>
        <img class="BookingDetail__IconClose" src="/wp-content/uploads/assets/images/iconCloseInfo.svg" alt="icon close" onClick="handleSeatClose();"/>
        <form id="multi-step-form">
            <div class="step-form-content">
                <div class="step active" id="step1">
                    <div class="step-count">
                        <div class="steps-item-count-icon">1</div>
                        <h3>Chỗ mong muốn</h3>
                    </div>
                    <div class="trust-message-container trust">
                        <i class="fas fa-shield-alt"></i>
                        <p class="trust-message-content">Dailyve cam kết giữ đúng chỗ bạn đã chọn.</p>
                    </div>
                    <div class="steps-content">
                        <div class="seat-selection-online__seat-selection">
                            <div class="seat-groups">
                                <div class="note">Chú thích</div>';

    $output .= renderTopSeatTemplateNote($data['data']['coach_seat_template'][0]['seats'][0]['seat_type'], '#B8B8B8');

    foreach ($new_seat_group_list as $code => $info) {
        $seatTemp = renderSeatTemplate($info['type'], $info['color'], true, $info, 1, $info['is_not_icon'] ?? 0);
        $output .= $seatTemp;
    }

    $output .= '</div>
                    </div>
                    <div class="steps-template-container">';

    foreach ($data['data']['coach_seat_template'] as $key => $coachs) {
        $coachRow = $coachs['num_rows'];
        $coachCol = $coachs['num_cols'];

        $output .= '<div class="coach-container">';
        $output .= '<span>' . esc_html($coachs['coach_name']) . '</span>';
        $output .= "<div class='coach' style='grid-template-columns: repeat($coachCol, 1fr);'>";

        $seatsList[$key] = $coachs['seats'];

        foreach ($coachs['seats'] as $seat) {
            $seatCol = $seat['col_num'];
            $seatRow = $seat['row_num'];

            if (!empty($seat['seat_groups']) && is_array($seat['seat_groups'])) {
                $fares = array_filter(array_map(function ($item) {
                    return $item['fare'] ? number_format($item['fare'], 0, ",", ".") : null;
                }, $seat['seat_groups']));
                $seatFares = implode(', ', $fares);
            } else {
                $seatFares = number_format($seat['fare'], 0, ",", ".");
            }

            $seatColor = $seat['seat_color'] ?? '#B8B8B8';
            $seatTemplate = renderSeatTemplate($seat['seat_type'], $seatColor, isAvailable: $seat['is_available']);

            $unavailable = !$seat['is_available'] ? 'unavailable' : '';
            $seatCode = (string) esc_html(json_encode($seat['seat_code']));
            $fullSeatCode = (string) esc_html(json_encode($seat['full_code']));

            $output .= '<div class="seat ' . $unavailable . '" style="grid-area:' . $seatRow . '/' . $seatCol . '/' . ($seatRow + 1) . '/' . ($seatCol + 1) . ';" onClick="handleSeatClick(this, ' . $seatCode . ', ' . $fullSeatCode . ')">
                    <div class="tooltip">
                        <div>' . $seatTemplate . '</div>
                        <span class="tooltiptext tooltip-top">Số ghế: ' . esc_html($seat['seat_code']) . ' - Giá: ' . $seatFares . '</span>
                    </div>
                </div>';
        }

        $output .= "</div></div>";
    }

    $output .= '</div>
                </div>
                <div class="form-footer-action">
                    <div class="form-footer-left"></div>
                    <div class="form-footer-right">
                        <div class="footer-price-seat"></div>
                        <button type="button" class="next-step" id="next-step-1">Tiếp tục</button>
                    </div>
                </div>
            </div>';

    // =============================
    //GỘP 4 LIST -> 2 LIST
    // =============================
    $output .= '
        <div class="step" id="step2">
            <div class="step-count">
                <div class="steps-item-count-icon">2</div>
                <h3>Điểm đón trả</h3>
            </div>
            <div class="trust-message-container trust">
                <i class="fas fa-shield-alt"></i>
                <p class="trust-message-content">An tâm được đón đúng nơi, trả đúng chỗ đã chọn và dễ dàng thay đổi khi cần.</p>
            </div>
            <div class="section-tabs-point">
                <div class="point-tab point-tab-pickup active">Điểm đón</div>
                <div class="point-tab point-tab-dropoff">Điểm trả</div>
            </div>
            <div class="steps-content">
                <div class="area_point_selection__wrapper">

                    <div class="content-pickup-point">
                        <div class="topbar__content">
                            <p class="point-type">Điểm đón</p>
                            <div class="label-container">
                                <p class="hTYbup">Sắp xếp theo</p>
                                <div class="value-container">
                                    <div style="position: relative;">
                                        <select class="select-time" onChange="sortListPickUpPoint();">
                                            <option>Sớm nhất</option>
                                            <option>Muộn nhất</option>
                                        </select>
                                        <i class="fas fa-sort-down right-arrow-select"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="content-list-point" id="list-pickup-point">';

    // -------- PICKUP LIST (GỘP) --------
    $pickup_points = is_array($data['data']['pickup_points'] ?? null) ? $data['data']['pickup_points'] : [];

    $pickup_transfer_status = isset($data['data']['way_id']) ? 1 : (int)(($data['data']['transfer_enable']) ?? 0);
    $pickup_transfer_points = is_array($data['data']['transfer_points'] ?? null) ? $data['data']['transfer_points'] : [];

    $pickup_all_points = [];

    foreach ($pickup_points as $p) {
        $p['_is_transfer'] = 0;
        $p['_point_type']  = 'pickup-point';
        $p['_id_prefix']   = 'pickup-';
        $pickup_all_points[] = $p;
    }

    if ($pickup_transfer_status === 1 && !empty($pickup_transfer_points)) {
        foreach ($pickup_transfer_points as $p) {
            $p['_is_transfer'] = 1;
            $p['_point_type']  = 'transfer-point';
            $p['_id_prefix']   = 'pickup-transfer-';
            $pickup_all_points[] = $p;
        }
    }

    usort($pickup_all_points, function ($a, $b) {
        $da = isset($a['duration']) ? (int)$a['duration'] : null;
        $db = isset($b['duration']) ? (int)$b['duration'] : null;
        if ($da !== null && $db !== null) {
            if ($da === $db) return 0;
            return ($da < $db) ? -1 : 1;
        }
        if (function_exists('compareByRealTime')) {
            return compareByRealTime($a, $b);
        }
        $ra = isset($a['real_time']) ? $a['real_time'] : '';
        $rb = isset($b['real_time']) ? $b['real_time'] : '';
        return strcmp($ra, $rb);
    });

    foreach ($pickup_all_points as $key_pick => $point) {
        $pointData   = json_encode($point);
        $encodedData = base64_encode($pointData);

        $address = '';
        $note = $point['note'] != null ? $point['note'] : null;

        $minCustomer = $point['min_customer'] ?? null;
        $minCustomerTxt = "";
        $data_mincustomer = "data-min-customer=1";
        if ($minCustomer != null && $minCustomer > 1) {
            $minCustomerTxt = "<div class='point-note-2'>Đặt từ $minCustomer ghế trở lên để chọn được điểm này</div>";
            $data_mincustomer = "data-min-customer=$minCustomer";
        }

        $surcharge = $point['surcharge'] != null ? $point['surcharge'] : 0;
        $surcharge_tiers = $point['surcharge_tiers'] != null ? $point['surcharge_tiers'] : '[]';
        $surcharge_method = "";
        $surcharge_type = 0;
        if ($surcharge != null && $surcharge != 0) {
            $surcharge_type = $point['surcharge_type'] != null ? $point['surcharge_type'] : 0;
            if ($surcharge_type == 1) {
                $surcharge_method = "Khách hàng thanh toán phụ thu sau với nhà xe";
            } elseif ($surcharge_type == 2) {
                $surcharge_method = "Khách hàng thanh toán phụ thu trước cùng với tiền vé";
            }
        }

        $unfixed_point = $point['unfixed_point'] != null ? $point['unfixed_point'] : 0;
        $unfixed_point_input = "";
        if ($unfixed_point == 1) {
            $unfixed_point_input = "<input disabled type='text' placeholder='Nhập địa chỉ' name='pickup_point_more_desc'>";
        }

        // disable time chỉ áp cho trung chuyển
        $transfer_attr = "";
        $transfer_txt  = "";
        if (!empty($point['_is_transfer'])) {
            $transfer_real_time = $point['transfer_disabled_real_time'] != null ? $point['transfer_disabled_real_time'] : null;
            if ($transfer_real_time != null) {
                date_default_timezone_set('Asia/Ho_Chi_Minh');
                $targetTime = DateTime::createFromFormat('H:i d-m-Y', $transfer_real_time);
                $now = new DateTime();
                if ($targetTime < $now) {
                    $transfer_attr = "data-transfer='disabled'";
                    $transfer_txt  = "Đã quá thời gian để chọn điểm này";
                }
            }
        }

        if (!empty($point['address'])) {
            $address = esc_html($point['address']);
        } else {
            if (!empty($point["areaDetail"]["ward_name"]))  $address .= esc_html($point["areaDetail"]["ward_name"]) . ', ';
            if (!empty($point["areaDetail"]["city_name"]))  $address .= esc_html($point["areaDetail"]["city_name"]) . ', ';
            if (!empty($point["areaDetail"]["state_name"])) $address .= esc_html($point["areaDetail"]["state_name"]) . ', ';
        }

        $transferNoteHtml = !empty($point['_is_transfer'])
            ? "<div class='point-note-transfer'><i class='fas fa-car-side'></i> Điểm trung chuyển</div>"
            : "";

        $input_id = $point['_id_prefix'] . esc_html($point["id"]) . '-' . $key_pick;
        $data_point_type = esc_attr($point['_point_type']);

        $output .= '<div class="item-list-point" ' . $data_mincustomer . '>
            <label for="' . $input_id . '" class="point-title">
                <input ' . $transfer_attr . '
                    data-point-type="' . $data_point_type . '"
                    class="data-pickup-point"
                    type="radio"
                    id="' . $input_id . '"
                    name="pickup-point"
                    data-point="' . $encodedData . '"
                    onChange="handleChangePickUp(this);"
                    data-surcharge-type=' . $surcharge_type . '
                    data-surcharge=' . $surcharge . '
                    data-surcharge-tiers=' . $surcharge_tiers . '>
                <strong>' . getTime($point["real_time"]) . '</strong>
            </label>
            <div class="content">
                <div>
                    <strong>' . esc_html($point["name"]) . '</strong>
                    <div style="color: rgb(133, 133, 133);">' . $address . '</div>
                    <div class="point-note">' . $note . '</div>
                    ' . $minCustomerTxt . '
                    <div class="content-surcharge-price"></div>
                    <div class="content-surcharge-method">' . $surcharge_method . '</div>
                    <div class="pickup-point-more-desc">' . $unfixed_point_input . '</div>
                    <div class="point-note-3">' . $transfer_txt . '</div>
                    ' . $transferNoteHtml . '
                </div>
                <div>
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="viewmap-link"
                        data-name="' . esc_html($point["name"]) . '"
                        data-long="' . esc_html($point["areaDetail"]["longitude"] ?? '') . '"
                        data-lat="' . esc_html($point["areaDetail"]["latitude"] ?? '') . '"
                        onClick="viewMap(this);">Bản đồ</span>
                </div>
            </div>
        </div>';
    }

    $output .= '</div>
            </div>

            <div class="content-dropoff-point">
                <div class="topbar__content">
                    <p class="point-type">Điểm trả</p>
                    <div class="label-container">
                        <p class="hTYbup">Sắp xếp theo</p>
                        <div class="value-container">
                            <div style="position: relative;">
                                <select class="select-time" onChange="sortListDropOffPoint();">
                                    <option>Sớm nhất</option>
                                    <option>Muộn nhất</option>
                                </select>
                                <i class="fas fa-sort-down right-arrow-select"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="content-list-point" id="list-dropoff-point">';

    // -------- DROPOFF LIST --------
    $dropoff_points = is_array($data['data']['drop_off_points_at_arrive'] ?? null) ? $data['data']['drop_off_points_at_arrive'] : [];

    $dropoff_transfer_status = isset($data['data']['way_id']) ? 1 : (int)($data['data']['transfer_at_arrive_enable'] ?? 0);
    $dropoff_transfer_points = is_array($data['data']['transfer_points_at_arrive'] ?? null) ? $data['data']['transfer_points_at_arrive'] : [];

    $dropoff_all_points = [];

    foreach ($dropoff_points as $p) {
        $p['_is_transfer'] = 0;
        $p['_point_type']  = 'dropoff-point';
        $p['_id_prefix']   = 'dropoff-';
        $dropoff_all_points[] = $p;
    }

    if ($dropoff_transfer_status === 1 && !empty($dropoff_transfer_points)) {
        foreach ($dropoff_transfer_points as $p) {
            $p['_is_transfer'] = 1;
            $p['_point_type']  = 'dropoff-transfer-point';
            $p['_id_prefix']   = 'dropoff-transfer-';
            $dropoff_all_points[] = $p;
        }
    }

    usort($dropoff_all_points, function ($a, $b) {
        $da = isset($a['duration']) ? (int)$a['duration'] : null;
        $db = isset($b['duration']) ? (int)$b['duration'] : null;
        if ($da !== null && $db !== null) {
            if ($da === $db) return 0;
            return ($da < $db) ? -1 : 1;
        }
        if (function_exists('compareByRealTime')) {
            return compareByRealTime($a, $b);
        }
        $ra = isset($a['real_time']) ? $a['real_time'] : '';
        $rb = isset($b['real_time']) ? $b['real_time'] : '';
        return strcmp($ra, $rb);
    });

    foreach ($dropoff_all_points as $key_drop => $point) {
        $pointData   = json_encode($point);
        $encodedData = base64_encode($pointData);

        $address = '';
        $note = $point['note'] != null ? $point['note'] : null;

        $minCustomer = $point['min_customer'] ?? null;
        $minCustomerTxt = "";
        $data_mincustomer = "data-min-customer=1";
        if ($minCustomer != null && $minCustomer > 1) {
            $minCustomerTxt = "<div class='point-note-2'>Đặt từ $minCustomer ghế trở lên để chọn được điểm này</div>";
            $data_mincustomer = "data-min-customer=$minCustomer";
        }

        $surcharge = $point['surcharge'] != null ? $point['surcharge'] : 0;
        $surcharge_tiers = $point['surcharge_tiers'] != null ? $point['surcharge_tiers'] : '[]';
        $surcharge_method = "";
        $surcharge_type = 0;
        if ($surcharge != null && $surcharge != 0) {
            $surcharge_type = $point['surcharge_type'] != null ? $point['surcharge_type'] : 0;
            if ($surcharge_type == 1) {
                $surcharge_method = "Khách hàng thanh toán phụ thu sau với nhà xe";
            } elseif ($surcharge_type == 2) {
                $surcharge_method = "Khách hàng thanh toán phụ thu trước cùng với tiền vé";
            }
        }

        $unfixed_point = $point['unfixed_point'] != null ? $point['unfixed_point'] : 0;
        $unfixed_point_input = "";
        if ($unfixed_point == 1) {
            $unfixed_point_input = "<input disabled type='text' placeholder='Nhập địa chỉ' name='dropoff_point_more_desc'>";
        }

        // disable time chỉ áp cho trung chuyển
        $transfer_attr = "";
        $transfer_txt  = "";
        if (!empty($point['_is_transfer'])) {
            $transfer_real_time = $point['transfer_disabled_real_time'] != null ? $point['transfer_disabled_real_time'] : null;
            if ($transfer_real_time != null) {
                date_default_timezone_set('Asia/Ho_Chi_Minh');
                $targetTime = DateTime::createFromFormat('H:i d-m-Y', $transfer_real_time);
                $now = new DateTime();
                if ($targetTime < $now) {
                    $transfer_attr = "data-transfer='disabled'";
                    $transfer_txt  = "Đã quá thời gian để chọn điểm này";
                }
            }
        }

        if (!empty($point['address'])) {
            $address = esc_html($point['address']);
        } else {
            if (!empty($point["areaDetail"]["ward_name"]))  $address .= esc_html($point["areaDetail"]["ward_name"]) . ', ';
            if (!empty($point["areaDetail"]["city_name"]))  $address .= esc_html($point["areaDetail"]["city_name"]) . ', ';
            if (!empty($point["areaDetail"]["state_name"])) $address .= esc_html($point["areaDetail"]["state_name"]) . ', ';
        }

        $transferNoteHtml = !empty($point['_is_transfer'])
            ? "<div class='point-note-transfer'><i class='fas fa-car-side'></i> Điểm trung chuyển</div>"
            : "";

        $input_id = $point['_id_prefix'] . esc_html($point["id"]) . '-' . $key_drop;
        $data_point_type = esc_attr($point['_point_type']);

        $output .= '<div class="item-list-point" ' . $data_mincustomer . '>
            <label for="' . $input_id . '" class="point-title">
                <input ' . $transfer_attr . '
                    data-point-type="' . $data_point_type . '"
                    class="data-dropoff-point"
                    type="radio"
                    id="' . $input_id . '"
                    name="dropoff-point"
                    data-point="' . $encodedData . '"
                    onChange="handleChangeDropOff(this);"
                    data-surcharge-type=' . $surcharge_type . '
                    data-surcharge=' . $surcharge . '
                    data-surcharge-tiers=' . $surcharge_tiers . '>
                <strong>' . getTime($point["real_time"]) . '</strong>
            </label>
            <div class="content">
                <div>
                    <strong>' . esc_html($point["name"]) . '</strong>
                    <div style="color: rgb(133, 133, 133);">' . $address . '</div>
                    <div class="content-note">' . $note . '</div>
                    ' . $minCustomerTxt . '
                    <div class="content-surcharge-price"></div>
                    <div class="content-surcharge-method">' . $surcharge_method . '</div>
                    <div class="dropoff-point-more-desc">' . $unfixed_point_input . '</div>
                    <div class="point-note-3">' . $transfer_txt . '</div>
                    ' . $transferNoteHtml . '
                </div>
                <div>
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="viewmap-link"
                        data-name="' . esc_html($point["name"]) . '"
                        data-long="' . esc_html($point["areaDetail"]["longitude"] ?? '') . '"
                        data-lat="' . esc_html($point["areaDetail"]["latitude"] ?? '') . '"
                        onClick="viewMap(this);">Bản đồ</span>
                </div>
            </div>
        </div>';
    }

    $output .= '</div>
                    </div>

                </div>
            </div>

            <div class="form-footer-action">
                <div class="form-footer-prev">
                    <button type="button" class="prev-step"><i class="fas fa-chevron-left"></i>Quay lại</button>
                </div>
                <div class="form-footer-right">
                    <div class="footer-price-seat"></div>
                    <button type="button" class="next-step" id="next-step-2">Tiếp tục</button>
                </div>
            </div>
        </div>';

    $output .= '
        <div class="step" id="step3">
            <div class="step-count">
                <div class="steps-item-count-icon">3</div>
                <h3>Thông tin liên hệ</h3>
            </div>
            <div class="trust-message-container trust">
                <i class="fas fa-shield-alt"></i>
                <p class="trust-message-content">Số điện thoại và email được sử dụng để gửi thông tin đơn hàng và liên hệ khi cần thiết.</p>
            </div>
            <div class="steps-content">
                <div class="booking-confirmation__container">
                    <div class="wrap-left-info">
                        <form>
                            <div class="omrs-input-group">
                                <label class="omrs-input-underlined">
                                    <input required name="customer-name">
                                    <span class="omrs-input-label">Tên người đi <span style="color: red;">*</span></span>
                                    <span class="omrs-input-helper" id="msg-err-name"></span>
                                </label>
                            </div>
                            <div class="omrs-input-group">
                                <label class="omrs-input-underlined">
                                    <input required name="customer-phone">
                                    <span class="omrs-input-label">Số điện thoại <span style="color: red;">*</span></span>
                                    <span class="omrs-input-helper" id="msg-err-phone"></span>
                                </label>
                            </div>
                            <div class="omrs-input-group">
                                <label class="omrs-input-underlined">
                                    <input required name="customer-email">
                                    <span class="omrs-input-label">mail@example.com <span style="color: red;">*</span></span>
                                    <span class="omrs-input-helper" id="msg-err-email"></span>
                                </label>
                            </div>
                            <div>
                                <textarea rows="3" style="width: 100%; border-radius: 8px;" placeholder="Ghi chú" name="customer-note"></textarea>
                            </div>
                        </form>
                    </div>
                    <div class="wrap-right-info">
                        <div class="section-info-ticket">
                            <div class="title-info-ticket">Thông tin chuyến đi</div>
                            <div class="content-info-ticket">
                                <div class="box-review-info-ticket-round-trip__container">
                                    <div class="section-ticket-header">
                                        <div class="section-ticket-header-left">
                                            <img src="/wp-content/uploads/assets/images/bus_blue_24dp.svg" alt="bus icon" width="16" height="16">
                                            <p class="base_text date-ticket-info">T5, 22/08/2024</p>
                                            <div class="total-ticket">
                                                <img src="/wp-content/uploads/assets/images/people_alt_black_24dp.svg" alt="total icon" width="16" height="16">
                                                <p class="base_text_1"></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="section-ticket-content">
                                        <div class="section-ticket-company-info">
                                            <div>
                                                <img src="https://static.vexere.com/production/images/1584418537685.jpeg" alt="Avatar">
                                            </div>
                                            <div class="section-ticket-company-info-name">
                                                <p class="base_text"></p>
                                                <p class="base_text_1"></p>
                                            </div>
                                        </div>
                                        <div class="box-ticket-route-detail-container">
                                            <div class="section-route-info">
                                                <div class="area-point-detail-round-trip__container">
                                                    <div class="date-time-container">
                                                        <div class="date-time-container-pick-up time-pick-up">
                                                            <div class="base__Headline01"></div>
                                                        </div>
                                                    </div>
                                                    <div class="icon-container">
                                                        <div class="icon-container-top">
                                                            <img class="pickup-icon" src="/wp-content/uploads/assets/images/pickup_vv_blue_24dp.svg" alt="pickup-icon" width="12" height="12">
                                                        </div>
                                                        <div class="icon-container-divider">
                                                            <div class="icon-container-divider-border-right"></div>
                                                            <div class="icon-container-divider-border-left"></div>
                                                        </div>
                                                    </div>
                                                    <div class="section-area">
                                                        <div class="section-area-picker pickup-point-name">
                                                            <p class="base_text mb-5"></p>
                                                            <p class="base_text_2"></p>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="area-point-detail-round-trip__container">
                                                    <div class="date-time-container">
                                                        <div class="date-time-container-pick-up time-drop-off mb-0">
                                                            <div class="base__Headline01"></div>
                                                        </div>
                                                    </div>
                                                    <div class="icon-container">
                                                        <div class="icon-container-divider">
                                                            <div class="icon-container-divider-border-right"></div>
                                                            <div class="icon-container-divider-border-left"></div>
                                                        </div>
                                                        <div class="icon-container-bottom">
                                                            <img class="pickup-icon" src="/wp-content/uploads/assets/images/dropoff_semantic_negative_12dp.svg" alt="dropoff-icon" width="12" height="12">
                                                        </div>
                                                    </div>
                                                    <div class="section-area">
                                                        <div class="section-area-picker dropoff-point-name">
                                                            <p class="base_text mb-5"></p>
                                                            <p class="base_text_2"></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-footer-action">
                <div class="form-footer-prev">
                    <button type="button" class="prev-step"><i class="fas fa-chevron-left"></i>Quay lại</button>
                </div>
                <div class="form-footer-right">
                    <div class="footer-price-seat"></div>
                    <button type="button" class="submit-step" id="next-step-3">Đặt chỗ</button>
                </div>
            </div>
        </div>

        </div>
        </form>
        </div>';

    // fix stage_fares group_fares empty array -> object (giữ nguyên)
    if (!empty($data['data']['stage_fares']) && is_array($data['data']['stage_fares'])) {
        foreach ($data['data']['stage_fares'] as &$item) {
            if (isset($item['group_fares']) && empty($item['group_fares'])) {
                $item['group_fares'] = (object)[];
            }
        }
        unset($item);
    }

    $resp = [
        'html'  => $output,
        'seats' => $seatsList,
        'data'  => $data['data'],
    ];

    wp_send_json_success($resp);
    wp_die();
}

add_action('wp_ajax_choose_trip_ajax_booking', 'handle_choose_trip_ajax_booking');
add_action('wp_ajax_nopriv_choose_trip_ajax_booking', 'handle_choose_trip_ajax_booking');

function handle_choose_trip_ajax_booking_2()
{
    check_ajax_referer('ams_vexe', 'nonce');

    $partnerId  = isset($_POST['partnerId']) ? sanitize_text_field($_POST['partnerId']) : null;
    $tripCode   = isset($_POST['tripCode']) ? sanitize_text_field($_POST['tripCode']) : '';
    $departureTime = isset($_POST['departureTime']) ? sanitize_text_field($_POST['departureTime']) : '';
    $wayId      = isset($_POST['wayId']) ? sanitize_text_field($_POST['wayId']) : '';
    $bookingId  = isset($_POST['bookingId']) ? sanitize_text_field($_POST['bookingId']) : '';

    $response = null;
    $params   = [];

    if ($partnerId === 'vexere') {
        $params = array(
            "tripId"      => $tripCode,
        );
        $response  = call_api_v2('/trips/vexere/trip_detail', 'GET', $params);
    } elseif ($partnerId === 'goopay') {
        $params = array(
            "routeId"      => $tripCode,
            "departureTime"   => $departureTime ? str_replace(':', 'h', $departureTime) : '',
            "wayId"       => $wayId,
            "bookingId"  => $bookingId,
        );
        $response  = call_api_v2('/trips/goopay/trip_detail', 'GET', $params);
    }

    $output    = '';
    $seatsList = [];

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    } else {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data) && is_array($data)) {

            $templates = $data['data']['coach_seat_template'];
            $seat_group = [];
            $seat_group_2 = [];

            foreach ($templates as $template) {
                foreach ($template['seats'] as $seat) {

                    $temp_seat = [
                        'seat_group' => $seat['seat_group'],
                        'seat_group_code' => $seat['seat_group_code'],
                        'fare' => $seat['fare'],
                        'seat_color' => $seat['seat_color']
                    ];

                    $seat_group[] = $temp_seat;


                    if ($seat['is_available'] === true) {
                        $temp_seat['full_code'] = $seat['full_code'];
                        $seat_group_2[] = $temp_seat;
                    }
                }
            }

            $seat_group = array_map('unserialize', array_unique(array_map('serialize', $seat_group)));

            $counts = [];
            $index = 0;
            foreach ($seat_group_2 as $seat) {
                $code = $seat['seat_group_code'];
                $full_code = $seat['full_code'];
                if (!isset($counts[$code])) {
                    $counts[$code]['count'] = 1;
                } else {
                    $counts[$code]['count']++;
                }
                $counts[$code]['full_code'][] = $full_code;
            }

            $output = '<div>
                <img class="BookingDetail__IconClose" src="/wp-content/uploads/assets/images/iconCloseInfo.svg" alt="icon close" onClick="handleSeatClose();"/>
                <form id="multi-step-form" class="unchooseable-form">
                                    <div class="step-form-content">
                                        <div class="step active" id="step1">
                                            <div class="step-count">
                                                <div class="steps-item-count-icon">1</div>
                                                <h3>Chỗ mong muốn</h3>
                                            </div>
                                            <div class="trust-message-container trust">
                                                <i class="fas fa-shield-alt"></i>
                                                <p class="trust-message-content">Dailyve cam kết giữ đúng số lượng vé bạn đã chọn.</p>
                                            </div>
                                            <div class="steps-content" style="display: block;">
                                                <div class="unchooseable-content">
                                                    <div class="note-wrapper">
                                                        <div class="note-title">Lưu ý</div>
                                                        <p clas="note-desc">Chuyến này không hỗ trợ chọn chỗ trước</p>';

            $output .= '</div>
                        <div class="guest-count-form">
                            <div class="guest-count__title">Số lượng khách</div>';
            foreach ($seat_group as $type) {
                $index++;
                $full_code = implode(',', $counts[$type['seat_group_code']]['full_code']);
                $output .= '<div class="guest-count__item" data-fare="' . $type['fare'] . '" data-group="group-' . $index . '" data-full-code="' . $full_code . '" data-available-seat="' . $counts[$type['seat_group_code']]['count'] . '"><span class="guest-count__label" style="color:' . $type['seat_color'] . '">' . $type['seat_group'] . ' - ' . number_format($type['fare'], 0, ',', '.') . 'đ</span><div class="guest-count__input"><button type="button" class="guest-count__btn guest-count__minus">-</button><span class="guest-count__quantity">0</span><button type="button" class="guest-count__btn guest-count__plus">+</button></div></div>';
            }

            $output .= '</div></div>
                                        </div>
                                            <div class="form-footer-action" style="justify-content: end;">
                                                <div class="form-footer-left" style="display: none;"></div>
                                                <div class="form-footer-right">
                                                    <div class="footer-price-seat"></div>
                                                    <button type="button" class="next-step" id="next-step-1">Tiếp tục</button>
                                            </div>
                                        </div>
                                        </div>';
            $output .= '
        <div class="step" id="step2">
            <div class="step-count">
                <div class="steps-item-count-icon">2</div>
                <h3>Điểm đón trả</h3>
            </div>
            <div class="trust-message-container trust">
                <i class="fas fa-shield-alt"></i>
                <p class="trust-message-content">An tâm được đón đúng nơi, trả đúng chỗ đã chọn và dễ dàng thay đổi khi cần.</p>
            </div>
            <div class="section-tabs-point">
                <div class="point-tab point-tab-pickup active">Điểm đón</div>
                <div class="point-tab point-tab-dropoff">Điểm trả</div>
            </div>
            <div class="steps-content">
                <div class="area_point_selection__wrapper">

                    <div class="content-pickup-point">
                        <div class="topbar__content">
                            <p class="point-type">Điểm đón</p>
                            <div class="label-container">
                                <p class="hTYbup">Sắp xếp theo</p>
                                <div class="value-container">
                                    <div style="position: relative;">
                                        <select class="select-time" onChange="sortListPickUpPoint();">
                                            <option>Sớm nhất</option>
                                            <option>Muộn nhất</option>
                                        </select>
                                        <i class="fas fa-sort-down right-arrow-select"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="content-list-point" id="list-pickup-point">';

            // -------- PICKUP LIST (GỘP) --------
            $pickup_points = is_array($data['data']['pickup_points'] ?? null) ? $data['data']['pickup_points'] : [];

            $pickup_transfer_status = isset($data['data']['way_id']) ? 1 : (int)(($data['data']['transfer_enable']) ?? 0);
            $pickup_transfer_points = is_array($data['data']['transfer_points'] ?? null) ? $data['data']['transfer_points'] : [];

            $pickup_all_points = [];

            foreach ($pickup_points as $p) {
                $p['_is_transfer'] = 0;
                $p['_point_type']  = 'pickup-point';
                $p['_id_prefix']   = 'pickup-';
                $pickup_all_points[] = $p;
            }

            if ($pickup_transfer_status === 1 && !empty($pickup_transfer_points)) {
                foreach ($pickup_transfer_points as $p) {
                    $p['_is_transfer'] = 1;
                    $p['_point_type']  = 'transfer-point';
                    $p['_id_prefix']   = 'pickup-transfer-';
                    $pickup_all_points[] = $p;
                }
            }

            usort($pickup_all_points, 'compareByRealTime');

            foreach ($pickup_all_points as $point) {
                $pointData   = json_encode($point);
                $encodedData = base64_encode($pointData);

                $address = '';
                $note = $point['note'] != null ? $point['note'] : null;

                $minCustomer = $point['min_customer'] ?? null;
                $minCustomerTxt = "";
                $data_mincustomer = "data-min-customer=1";
                if ($minCustomer != null && $minCustomer > 1) {
                    $minCustomerTxt = "<div class='point-note-2'>Đặt từ $minCustomer ghế trở lên để chọn được điểm này</div>";
                    $data_mincustomer = "data-min-customer=$minCustomer";
                }

                $surcharge = $point['surcharge'] != null ? $point['surcharge'] : 0;
                $surcharge_tiers = $point['surcharge_tiers'] != null ? $point['surcharge_tiers'] : '[]';
                $surcharge_method = "";
                $surcharge_type = 0;
                if ($surcharge != null && $surcharge != 0) {
                    $surcharge_type = $point['surcharge_type'] != null ? $point['surcharge_type'] : 0;
                    if ($surcharge_type == 1) {
                        $surcharge_method = "Khách hàng thanh toán phụ thu sau với nhà xe";
                    } elseif ($surcharge_type == 2) {
                        $surcharge_method = "Khách hàng thanh toán phụ thu trước cùng với tiền vé";
                    }
                }

                $unfixed_point = $point['unfixed_point'] != null ? $point['unfixed_point'] : 0;
                $unfixed_point_input = "";
                if ($unfixed_point == 1) {
                    $unfixed_point_input = "<input disabled type='text' placeholder='Nhập địa chỉ' name='pickup_point_more_desc'>";
                }

                // disable time chỉ áp cho trung chuyển
                $transfer_attr = "";
                $transfer_txt  = "";
                if (!empty($point['_is_transfer'])) {
                    $transfer_real_time = $point['transfer_disabled_real_time'] != null ? $point['transfer_disabled_real_time'] : null;
                    if ($transfer_real_time != null) {
                        date_default_timezone_set('Asia/Ho_Chi_Minh');
                        $targetTime = DateTime::createFromFormat('H:i d-m-Y', $transfer_real_time);
                        $now = new DateTime();
                        if ($targetTime < $now) {
                            $transfer_attr = "data-transfer='disabled'";
                            $transfer_txt  = "Đã quá thời gian để chọn điểm này";
                        }
                    }
                }

                if (!empty($point['address'])) {
                    $address = esc_html($point['address']);
                } else {
                    if (!empty($point["areaDetail"]["ward_name"]))  $address .= esc_html($point["areaDetail"]["ward_name"]) . ', ';
                    if (!empty($point["areaDetail"]["city_name"]))  $address .= esc_html($point["areaDetail"]["city_name"]) . ', ';
                    if (!empty($point["areaDetail"]["state_name"])) $address .= esc_html($point["areaDetail"]["state_name"]) . ', ';
                }

                $transferNoteHtml = !empty($point['_is_transfer'])
                    ? "<div class='point-note-transfer'><i class='fas fa-car-side'></i> Điểm trung chuyển</div>"
                    : "";

                $input_id = $point['_id_prefix'] . esc_html($point["id"]);
                $data_point_type = esc_attr($point['_point_type']);

                $output .= '<div class="item-list-point" ' . $data_mincustomer . '>
            <label for="' . $input_id . '" class="point-title">
                <input ' . $transfer_attr . '
                    data-point-type="' . $data_point_type . '"
                    class="data-pickup-point"
                    type="radio"
                    id="' . $input_id . '"
                    name="pickup-point"
                    data-point="' . $encodedData . '"
                    onChange="handleChangePickUp(this);"
                    data-surcharge-type=' . $surcharge_type . '
                    data-surcharge=' . $surcharge . '
                    data-surcharge-tiers=' . $surcharge_tiers . '>
                <strong>' . getTime($point["real_time"]) . '</strong>
            </label>
            <div class="content">
                <div>
                    <strong>' . esc_html($point["name"]) . '</strong>
                    <div style="color: rgb(133, 133, 133);">' . $address . '</div>
                    <div class="point-note">' . $note . '</div>
                    ' . $minCustomerTxt . '
                    <div class="content-surcharge-price"></div>
                    <div class="content-surcharge-method">' . $surcharge_method . '</div>
                    <div class="pickup-point-more-desc">' . $unfixed_point_input . '</div>
                    <div class="point-note-3">' . $transfer_txt . '</div>
                    ' . $transferNoteHtml . '
                </div>
                <div>
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="viewmap-link"
                        data-name="' . esc_html($point["name"]) . '"
                        data-long="' . esc_html($point["areaDetail"]["longitude"] ?? '') . '"
                        data-lat="' . esc_html($point["areaDetail"]["latitude"] ?? '') . '"
                        onClick="viewMap(this);">Bản đồ</span>
                </div>
            </div>
        </div>';
            }

            $output .= '</div>
            </div>

            <div class="content-dropoff-point">
                <div class="topbar__content">
                    <p class="point-type">Điểm trả</p>
                    <div class="label-container">
                        <p class="hTYbup">Sắp xếp theo</p>
                        <div class="value-container">
                            <div style="position: relative;">
                                <select class="select-time" onChange="sortListDropOffPoint();">
                                    <option>Sớm nhất</option>
                                    <option>Muộn nhất</option>
                                </select>
                                <i class="fas fa-sort-down right-arrow-select"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="content-list-point" id="list-dropoff-point">';

            // -------- DROPOFF LIST --------
            $dropoff_points = is_array($data['data']['drop_off_points_at_arrive'] ?? null) ? $data['data']['drop_off_points_at_arrive'] : [];

            $dropoff_transfer_status = isset($data['data']['way_id']) ? 1 : (int)($data['data']['transfer_at_arrive_enable'] ?? 0);
            $dropoff_transfer_points = is_array($data['data']['transfer_points_at_arrive'] ?? null) ? $data['data']['transfer_points_at_arrive'] : [];

            $dropoff_all_points = [];

            foreach ($dropoff_points as $p) {
                $p['_is_transfer'] = 0;
                $p['_point_type']  = 'dropoff-point';
                $p['_id_prefix']   = 'dropoff-';
                $dropoff_all_points[] = $p;
            }

            if ($dropoff_transfer_status === 1 && !empty($dropoff_transfer_points)) {
                foreach ($dropoff_transfer_points as $p) {
                    $p['_is_transfer'] = 1;
                    $p['_point_type']  = 'dropoff-transfer-point';
                    $p['_id_prefix']   = 'dropoff-transfer-';
                    $dropoff_all_points[] = $p;
                }
            }

            usort($dropoff_all_points, 'compareByRealTime');

            foreach ($dropoff_all_points as $point) {
                $pointData   = json_encode($point);
                $encodedData = base64_encode($pointData);

                $address = '';
                $note = $point['note'] != null ? $point['note'] : null;

                $minCustomer = $point['min_customer'] ?? null;
                $minCustomerTxt = "";
                $data_mincustomer = "data-min-customer=1";
                if ($minCustomer != null && $minCustomer > 1) {
                    $minCustomerTxt = "<div class='point-note-2'>Đặt từ $minCustomer ghế trở lên để chọn được điểm này</div>";
                    $data_mincustomer = "data-min-customer=$minCustomer";
                }

                $surcharge = $point['surcharge'] != null ? $point['surcharge'] : 0;
                $surcharge_tiers = $point['surcharge_tiers'] != null ? $point['surcharge_tiers'] : '[]';
                $surcharge_method = "";
                $surcharge_type = 0;
                if ($surcharge != null && $surcharge != 0) {
                    $surcharge_type = $point['surcharge_type'] != null ? $point['surcharge_type'] : 0;
                    if ($surcharge_type == 1) {
                        $surcharge_method = "Khách hàng thanh toán phụ thu sau với nhà xe";
                    } elseif ($surcharge_type == 2) {
                        $surcharge_method = "Khách hàng thanh toán phụ thu trước cùng với tiền vé";
                    }
                }

                $unfixed_point = $point['unfixed_point'] != null ? $point['unfixed_point'] : 0;
                $unfixed_point_input = "";
                if ($unfixed_point == 1) {
                    $unfixed_point_input = "<input disabled type='text' placeholder='Nhập địa chỉ' name='dropoff_point_more_desc'>";
                }

                // disable time chỉ áp cho trung chuyển
                $transfer_attr = "";
                $transfer_txt  = "";
                if (!empty($point['_is_transfer'])) {
                    $transfer_real_time = $point['transfer_disabled_real_time'] != null ? $point['transfer_disabled_real_time'] : null;
                    if ($transfer_real_time != null) {
                        date_default_timezone_set('Asia/Ho_Chi_Minh');
                        $targetTime = DateTime::createFromFormat('H:i d-m-Y', $transfer_real_time);
                        $now = new DateTime();
                        if ($targetTime < $now) {
                            $transfer_attr = "data-transfer='disabled'";
                            $transfer_txt  = "Đã quá thời gian để chọn điểm này";
                        }
                    }
                }

                if (!empty($point['address'])) {
                    $address = esc_html($point['address']);
                } else {
                    if (!empty($point["areaDetail"]["ward_name"]))  $address .= esc_html($point["areaDetail"]["ward_name"]) . ', ';
                    if (!empty($point["areaDetail"]["city_name"]))  $address .= esc_html($point["areaDetail"]["city_name"]) . ', ';
                    if (!empty($point["areaDetail"]["state_name"])) $address .= esc_html($point["areaDetail"]["state_name"]) . ', ';
                }

                $transferNoteHtml = !empty($point['_is_transfer'])
                    ? "<div class='point-note-transfer'><i class='fas fa-car-side'></i> Điểm trung chuyển</div>"
                    : "";

                $input_id = $point['_id_prefix'] . esc_html($point["id"]);
                $data_point_type = esc_attr($point['_point_type']);

                $output .= '<div class="item-list-point" ' . $data_mincustomer . '>
            <label for="' . $input_id . '" class="point-title">
                <input ' . $transfer_attr . '
                    data-point-type="' . $data_point_type . '"
                    class="data-dropoff-point"
                    type="radio"
                    id="' . $input_id . '"
                    name="dropoff-point"
                    data-point="' . $encodedData . '"
                    onChange="handleChangeDropOff(this);"
                    data-surcharge-type=' . $surcharge_type . '
                    data-surcharge=' . $surcharge . '
                    data-surcharge-tiers=' . $surcharge_tiers . '>
                <strong>' . getTime($point["real_time"]) . '</strong>
            </label>
            <div class="content">
                <div>
                    <strong>' . esc_html($point["name"]) . '</strong>
                    <div style="color: rgb(133, 133, 133);">' . $address . '</div>
                    <div class="content-note">' . $note . '</div>
                    ' . $minCustomerTxt . '
                    <div class="content-surcharge-price"></div>
                    <div class="content-surcharge-method">' . $surcharge_method . '</div>
                    <div class="dropoff-point-more-desc">' . $unfixed_point_input . '</div>
                    <div class="point-note-3">' . $transfer_txt . '</div>
                    ' . $transferNoteHtml . '
                </div>
                <div>
                    <i class="fas fa-map-marker-alt"></i>
                    <span class="viewmap-link"
                        data-name="' . esc_html($point["name"]) . '"
                        data-long="' . esc_html($point["areaDetail"]["longitude"] ?? '') . '"
                        data-lat="' . esc_html($point["areaDetail"]["latitude"] ?? '') . '"
                        onClick="viewMap(this);">Bản đồ</span>
                </div>
            </div>
        </div>';
            }


            $output .= '</div>
                                                     </div>
                                                </div>
                                            </div>
                                            <div class="form-footer-action">
                                                <div class="form-footer-prev">
                                                    <button type="button" class="prev-step"><i class="fas fa-chevron-left"></i>Quay lại</button>
                                                </div>
                                                <div class="form-footer-right">
                                                    <div class="footer-price-seat"></div>
                                                    <button type="button" class="next-step" id="next-step-2">Tiếp tục</button>
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                </form>
                                </div>';
            foreach ($data['data']['stage_fares'] as &$item) {
                if (isset($item['group_fares']) && empty($item['group_fares'])) {
                    $item['group_fares'] = (object)[];
                }
            }
            $response = [
                'html' => $output,
                'seats' => $seatsList,
                'data' => $data['data'],
            ];
            wp_send_json_success($response);
        }
    }
    wp_die();
}
add_action('wp_ajax_choose_trip_ajax_booking_2', 'handle_choose_trip_ajax_booking_2');
add_action('wp_ajax_nopriv_choose_trip_ajax_booking_2', 'handle_choose_trip_ajax_booking_2');

function generate_dailyve_review_html($items)
{
    $output = '';
    foreach ($items as $item) {
        $dateString = isset($item['trip_date']) ? date('d-m-Y', strtotime($item['trip_date'])) : "";
        $widthStart = ((int) $item['rating'] / 5) * 100;
        $output .= '<div class="rating-tab__comments-list__item">
                        <div class="rating-tab__comments-list__item-personal__info">';
        if (!empty($item['social_avatar'])) {
            $output .= '<div class="rating-tab__comments-list__item-personal_social-avatar"><img src="' . $item['social_avatar'] . '" alt="' . $item['name'] . '"></div>';
        } else {
            $output .= '<div class="rating-tab__comments-list__item-personal__info-avatar">' . getInitialsNameToAvatar($item['name']) . '</div>';
        }
        $output .= '<div class="rating-tab__comments-list__item-personal__info-name">'
            . $item['name'] .
            '<div class="rating-tab__comments-list__item-personal__info-star"> 
                                    <div class="ratings">
                                        <div class="empty-stars" style="font-size: 12pt;"></div>
                                        <div class="full-stars" style="width: ' . $widthStart . '%; font-size: 12pt;"></div>
                                    </div>
                                 </div>
                            </div>
                        </div>';
        $output .= '<div class="rating-tab__comments-list__item-content">' . $item['comment'] . '</div>';
        if (isset($item['images']) && count($item['images']) > 0 && 1 > 2) {
            $output .= '<div class="rating-tab__comments-list__item-gallery">';
            foreach ($item['images'] as $key => $value) {
                $output .= '<div class="rating-tab__comments-list__item-gallery__img"> 
                                                <a data-fancybox="" href="' . $value . '" rel="nofollow">
                                                    <img
                                                        data-lazyloaded="1"
                                                        src="' . $value . '"
                                                        class="attachment-large size-large entered litespeed-loaded" alt="gallery">
                                                </a>
                                            </div>';
            }
            $output .= '</div>';
        }
        if (!empty($dateString)) {
            $output .= '<div class="rating-tab__comments-list__item-depart-date">
                            <p>Đi ngày ' . $dateString . '</p>
                            <div><i class="fas fa-check-circle"></i></div>
                            <p class="verified">Đã mua vé</p>
                    </div>';
        }
        if (isset($item['replies']) && 1 > 2) {
            $output .= '<div class="rating-tab__comments-list__comment-reply">';
            foreach ($item['replies'] as $reply) {
                $output .= '<div class="item-comment-reply">
                                <div class="comment-title"><p class="comment-reply-title">Phản hồi của nhà xe</p></div>
                                <div class="comment-content">
                                    <p class="comment-reply-content">' . $reply['content'] . '</p>
                                </div>
                            </div>';
            }
            $output .= '</div>';
        }
        $output .= '</div>';
    }
    return $output;
}

// Company AJAX functions have been moved to inc/api/ajax-company.php

// Amenities & Policy AJAX functions have been moved to inc/api/ajax-amenities-policy.php

function ams_extract_districts_from_raw($raw)
{
    if (empty($raw)) return [];
    $raw = trim((string)$raw);

    $decoded = base64_decode($raw, true);
    if ($decoded !== false) {
        $json = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return ams_extract_districts_from_payload($json);
        }
    }

    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        return ams_extract_districts_from_payload($json);
    }

    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, fn($x) => $x !== '');
    return array_values(array_unique($parts));
}

function ams_extract_districts_from_payload($payload)
{
    $out = [];

    foreach ((array)$payload as $item) {
        if (is_string($item) || is_numeric($item)) {
            $d = trim((string)$item);
            if ($d !== '') $out[] = $d;
            continue;
        }
        if (is_array($item)) {
            $d = isset($item['district']) ? trim((string)$item['district']) : '';
            if ($d === '' && isset($item['pointName'])) $d = trim((string)$item['pointName']);
            if ($d !== '') $out[] = $d;
        }
    }

    $out = array_values(array_unique($out));
    return $out;
}

function ams_extract_point_names_from_raw($raw)
{
    if (empty($raw)) return [];
    $raw = trim((string)$raw);
    $decoded = base64_decode($raw, true);
    if ($decoded !== false) {
        $json = json_decode($decoded, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return ams_extract_point_names_from_payload($json);
        }
    }
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        return ams_extract_point_names_from_payload($json);
    }
    $parts = array_map('trim', explode(',', $raw));
    $parts = array_filter($parts, fn($x) => $x !== '');
    return array_values(array_unique($parts));
}

function ams_extract_point_names_from_payload($payload)
{
    $out = [];
    foreach ((array)$payload as $item) {
        if (is_string($item)) {
            $d = trim($item);
            if ($d !== '') $out[] = $d;
            continue;
        }
        if (is_array($item)) {
            $d = isset($item['pointName']) ? trim((string)$item['pointName']) : '';
            if ($d !== '') $out[] = $d;
        }
    }
    $out = array_values(array_unique($out));
    return $out;
}

function handle_filter_route_trip()
{
    check_ajax_referer('ams_vexe', 'nonce');

    $page = isset($_POST['p']) ? intval($_POST['p']) : 1;
    $cursor = isset($_POST['cursor']) ? sanitize_text_field($_POST['cursor']) : '';
    $loadMore = isset($_POST['loadmore']) ? intval($_POST['loadmore']) : 0;
    $from = isset($_POST['from']) ? sanitize_text_field($_POST['from']) : '';
    $to = isset($_POST['to']) ? sanitize_text_field($_POST['to']) : '';
    $date = !empty($_POST['date']) ? date('Y-m-d', strtotime($_POST['date'])) : date('Y-m-d', strtotime('+1 day'));
    $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '00:00-23:59';
    $sort = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'time:asc';
    $companies = isset($_POST['companies']) ? sanitize_text_field($_POST['companies']) : '';
    $fromAreaRaw = isset($_POST['fromarea']) ? wp_unslash($_POST['fromarea']) : '';
    $toAreaRaw   = isset($_POST['toarea']) ? wp_unslash($_POST['toarea']) : '';
    $rating = isset($_POST['rating']) ? sanitize_text_field($_POST['rating']) : '1-5';
    $isLimousine = isset($_POST['islimousine']) ? intval($_POST['islimousine']) : null;
    $isCompanyPage = isset($_POST['iscompanypage']) ? intval($_POST['iscompanypage']) : 0;
    $seatType = isset($_POST['seatType']) ? intval($_POST['seatType']) : -1;

    $arrCompanies = [];
    $arrFromArea = [];
    $arrToArea = [];

    if ($seatType == 7) {
        $arrSeatType = [7];
    } elseif ($seatType == 1) {
        $seatType = 1;
        $arrSeatType = [1];
    } else {
        $arrSeatType = [];
    }

    if (!empty($companies)) {
        $arrCompanies = explode(",", $companies);
    }

    $arrFromArea = ams_extract_districts_from_raw($fromAreaRaw);
    $arrToArea   = ams_extract_districts_from_raw($toAreaRaw);
    $arrPickupNames = ams_extract_point_names_from_raw($fromAreaRaw);
    $arrDropoffNames = ams_extract_point_names_from_raw($toAreaRaw);

    $arrTime = explode("-", $time);
    $arrRating = explode("-", $rating);

    $params = array(
        "pageSize" => 20,
        "from" => $from,
        "to" => $to,
        "date" => $date,
        "onlineTicket" => 1,
        'timeMin'        => $arrTime[0] ?? '00:00',
        'timeMax'        => $arrTime[1] ?? '23:59',
        "sort" => $sort,
        // 'partner' => 'goopay'
    );
    if (!empty($companies)) {
        $params['companies'] = $companies;
    }
    if (!empty($arrFromArea)) {
        $params['pickupDistricts'] = implode(',', $arrFromArea);
    }
    if (!empty($arrToArea)) {
        $params['dropoffDistricts'] = implode(',', $arrToArea);
    }
    if (!empty($arrPickupNames)) {
        $params['pickupNames'] = implode(',', $arrPickupNames);
    }
    if (!empty($arrDropoffNames)) {
        $params['dropoffNames'] = implode(',', $arrDropoffNames);
    }
    if (!empty($isLimousine)) {
        $params['limousine'] = $isLimousine;
    }
    if (!empty($cursor)) {
        $params['cursor'] = $cursor;
        unset($params['page']);
    } else {
        $params['page'] = $page;
    }

    if (!empty($isLimousine)) {
        $params['isLimousine'] = ($isLimousine == 1) ? true : false;
    }

    $cache_key_trips = 'dv_ajax_trips_' . md5(json_encode([
        $cursor,
        $from,
        $to,
        $date,
        $time,
        $sort,
        $companies
    ]));

    $cached = get_transient($cache_key_trips);

    if ($cached === false) {
        $action_key = 'route_search_' . md5($from . '|' . $to . '|' . $date);

        if (!dv_rate_limit($action_key, 5, 5)) {
            wp_send_json_error([
                'message' => 'Bạn thao tác quá nhanh, vui lòng thử lại sau vài giây.',
                'code' => 429
            ], 429);
        }
    }
    // Gọi API có cache TTL 5 giây
    $response = dv_cached_api_call($cache_key_trips, 5, 'trips', 'GET', $params);

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    } else {
        $data = json_decode(wp_remote_retrieve_body($response), true); ?>
        <?php if (isset($data['items']) && is_array($data['items']) && count($data['items']) > 0) {
            $company_data_map = ams_get_bulk_company_data($data['items']);
        ?>
            <?php if ($loadMore == 0) {  ?>
                <ul class="online-booking-page__provider-list" total="<?= esc_attr($data['paging']['totalItems'] ?? 0); ?>">
                <?php } ?>
                <?php foreach ($data['items'] as $key => $item) {
                    $cid = trim($item['company_id']);
                    $company_info = $company_data_map[$cid] ?? ['thumbnail' => '', 'gallery' => []];
                    $thumbnail_url = $company_info['thumbnail'];
                    $gallery = $company_info['gallery'];
                ?>

                    <li class="online-booking-page__provider-list__item"
                        id="route-trip-<?= esc_attr($item['trip_id']); ?>">
                        <?php if (!empty($item['notification'])) { ?>
                            <div class="online-booking-page__provider-list__item_header_notify">
                                <div class="header_notify-note">
                                    <div class="notify-tag">
                                        <span>Thông báo</span>
                                    </div>
                                    <div class="tooltip notify-link">
                                        <?= $item['notification']['label'] ?>
                                        <span
                                            class="tooltiptext tooltip-top"><?= $item['notification']['content'] ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                        <div class="online-booking-page__provider-list__item__img">
                            <img
                                src="<?= !empty($thumbnail_url) ? esc_url($thumbnail_url) : home_url('/wp-content/uploads/assets/images/logo-icon-f2.png'); ?>"
                                fetchpriority="high" decoding="async" width="162" height="162"
                                class="attachment-post-thumbnail size-post-thumbnail wp-post-image entered"
                                alt="<?= esc_attr($item['company_name']); ?>">
                            <div class="instant-confirm">
                                <div><i class="fas fa-check-square"></i> Xác nhận tức thì</div>
                                <div class="point"></div>
                            </div>
                        </div>
                        <div class="online-booking-page__provider-list__item__info">
                            <div class="online-booking-page__provider-list__item__bus-name-info">
                                <p class="online-booking-page__provider-list__item__title">
                                    <?= esc_html($item['company_name']); ?>
                                </p>
                                <button type="button" class="ant-btn bus-rating-button">
                                    <div class="bus-rating">
                                        <i class="fas fa-star"></i>
                                        <span>
                                            <?= $item['ratings']['overall'] ?? 0 ?>
                                            (<?= $item['ratings']['comments'] ?? 0 ?>)
                                        </span>
                                    </div>
                                </button>
                            </div>
                            <div class="online-booking-page__provider-list__item__bus-type-info">
                                <p><?= esc_html($item['vehicle_type']); ?></p>
                            </div>
                            <div class="online-booking-page__provider-list__item__route-info">
                                <div class="online-booking-page__provider-list__item__route-info__item">
                                    <span
                                        class="online-booking-page__provider-list__item__route-info__item-time"><?= getTime($item['pickup_date']); ?>
                                        • </span>
                                    <span
                                        class="online-booking-page__provider-list__item__route-info__item-place"><?= esc_html($item['from_name']); ?></span>
                                </div>
                                <div class="online-booking-page__provider-list__item__route-info__travel-time">
                                    <?= totalTimeRoute($item['pickup_date'], $item['arrival_date']); ?>
                                </div>
                                <div
                                    class="online-booking-page__provider-list__item__route-info__item online-booking-page__provider-list__item__route-info__item--ct">
                                    <span
                                        class="online-booking-page__provider-list__item__route-info__item-time"><?= getTime($item['arrival_date']); ?>
                                        • </span>
                                    <span
                                        class="online-booking-page__provider-list__item__route-info__item-place"><?= esc_html($item['to_name']); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="online-booking-page__provider-list__item__handle">
                            <div class="online-booking-page__provider-list__item__price">
                                <div class="fare">
                                    <?= isset($item['fare']) && isset($item['fare_max']) && $item['fare_max'] > $item['fare_original'] ? 'Từ ' : '' ?> <?= number_format($item['fare'], 0, ",", "."); ?>đ
                                </div>
                                <?php if ($item['fare_discount'] > 0 && $item['fare_original'] > 0) { ?>
                                    <div class="fareSmall">
                                        <div class="small">
                                            <?= number_format($item['fare_original'], 0, ",", "."); ?>đ
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="online-booking-page__provider-list__item__available-seat">
                                <div
                                    class="seat-available <?= $item['available_seat'] <= 5 ? 'text-red' : '' ?>">
                                    Còn <?= $item['available_seat']; ?> chỗ trống
                                </div>
                            </div>
                            <div class="online-booking-page__provider-list__item__btns">
                                <div data-companyid="<?= $item['company_id']; ?>" data-load="0"
                                    data-tripid="<?= $item['trip_id']; ?>"
                                    data-seat-template-id="<?= $item['seat_template_id']; ?>"
                                    data-partner-id="<?= $item['partner']['partner_id']; ?>"
                                    data-partner-name="<?= $item['partner']['partner_name']; ?>"
                                    data-departure-date="<?= $item['departure_date'] ?? ''; ?>"
                                    data-pickup-date="<?= $item['pickup_date']; ?>"
                                    data-way-id="<?php echo isset($item['way_id']) ? $item['way_id'] : ''; ?>"
                                    data-booking-id="<?php echo isset($item['booking_id']) ? $item['booking_id'] : ''; ?>"
                                    data-fare="<?= $item['fare']; ?>"
                                    class="online-booking-page__provider-list__item__details-btn">
                                    Thông tin chi tiết
                                </div>

                                <button class="online-booking-page__provider-list__item__price-btn"
                                    data-trip="<?= $item['trip_id']; ?>"
                                    data-to="<?php echo (isset($item['toId']) ? $item['toId'] : (isset($item['to_name']) ? $item['to_name'] : '')); ?>"
                                    data-from="<?php echo (isset($item['fromId']) ? $item['fromId'] : (isset($item['from_name']) ? $item['from_name'] : '')); ?>"
                                    data-partner-id="<?= $item['partner']['partner_id']; ?>"
                                    data-departure-time="<?= $item['departure_time']; ?>"
                                    data-departure-date="<?= $item['departure_date'] ?? ''; ?>"
                                    data-way-id="<?php echo isset($item['way_id']) ? $item['way_id'] : ''; ?>"
                                    data-booking-id="<?php echo isset($item['booking_id']) ? $item['booking_id'] : ''; ?>"
                                    data-fare="<?= $item['fare']; ?>"
                                    data-unchoosable="<?php echo isset($item['unchoosable']) ? $item['unchoosable'] : 0; ?>"
                                    data-route-name="<?php echo isset($item['route_name']) ? $item['route_name'] : ''; ?>">
                                    Chọn chuyến
                                </button>
                            </div>
                        </div>
                        <div class="online-booking-page__provider-list__item__full-route">
                            <?php if (isset($item['route_name']) && !empty($item['route_name']) && isset($item['departure_date'])) { ?>
                                <div class="notify-trip">
                                    <div class="full-trip"><span>*</span>Vé chặng thuộc chuyến
                                        <?php echo formatDateISO($item['departure_date']); ?>
                                        <?php echo $item['route_name']; ?>
                                    </div>
                                </div>
                            <?php } ?>
                            <div style="width: 100%;"
                                id="ticket-loading-<?= $item['trip_id']; ?>">
                            </div>
                        </div>
                        <div class="online-booking-page__provider-list__seats-info"
                            id="seats-info-conetnt-<?= $item['trip_id']; ?>">

                        </div>
                        <div class="online-booking-page__provider-list__details-tab"
                            id="detail-tab-<?= $item['trip_id']; ?>">
                            <div class="provider-details">
                                <ul class="provider-details__nav">
                                    <li data-tab="images-tab-<?= $item['trip_id']; ?>"
                                        class="active">Hình ảnh</li>
                                    <li
                                        data-tab="convenience-tab-<?= $item['trip_id']; ?>">
                                        Tiện ích</li>
                                    <li
                                        data-tab="ratings-tab-<?= $item['trip_id']; ?>">
                                        Đánh giá</li>
                                    <li
                                        data-tab="pickup-dropoff-points-tab-<?= $item['trip_id']; ?>">
                                        Điểm đón, trả</li>
                                    <li
                                        data-tab="policy-tab-<?= $item['trip_id']; ?>">
                                        Chính sách</li>
                                </ul>
                                <div class="provider-details__tabs width-tab">
                                    <div id="images-tab-<?= $item['trip_id']; ?>"
                                        class="provider-details__tab images-tab">
                                        <div class="provider-details__gallery">
                                            <?php if (is_array($gallery) && count($gallery) > 0) { ?>
                                                <div class="provider-details__gallery-main">
                                                    <?php foreach ($gallery as $g) { ?>
                                                        <div class="provider-details__gallery-main__item">
                                                            <img src="<?= esc_url($g['url']); ?>" width="<?= esc_attr($g['width']); ?>" height="<?= esc_attr($g['height']); ?>" class="attachment-large size-large" alt="<?= esc_attr($g['title']); ?>">
                                                        </div>
                                                    <?php } ?>
                                                </div>
                                                <div class="provider-details__gallery-thumbnails">
                                                    <?php foreach ($gallery as $g) { ?>
                                                        <div class="provider-details__gallery-thumbnails__item">
                                                            <img src="<?= esc_url($g['sizes']['medium']); ?>" width="<?= esc_attr($g['sizes']['medium-width']); ?>" height="200" alt="<?= esc_attr($g['title']); ?>">
                                                        </div>
                                                    <?php } ?>
                                                </div>
                                            <?php } else { ?>
                                                <p style="text-align: center;"><strong>Dailyve</strong> sẽ sớm cập nhật thông tin nhà xe <?= esc_html($item['company_name']); ?> đến quý khách. <br> <a href="tel:19000155">Liên hệ hỗ trợ</a></p>
                                            <?php } ?>
                                        </div>
                                    </div>
                                    <div id="convenience-tab-<?= $item['trip_id']; ?>"
                                        class="provider-details__tab">
                                        <ul class="provider_details_convenience__list"></ul>
                                        <div class="provider_details_convenience__list_2"></div>
                                    </div>
                                    <div id="pickup-dropoff-points-tab-<?= $item['trip_id']; ?>"
                                        class="provider-details__tab">
                                        <div class="bus-type-title">Lưu ý</div>
                                        <div class="header-content">Các mốc thời gian đón, trả bên dưới là thời
                                            gian dự kiến.
                                            Lịch này có thể thay đổi tùy tình hình thực tế.</div>
                                        <div class="flex flex-wrap accordion-sub-item__wrapper">
                                            <div class="accordion-sub-item">
                                                <div class="accordion-sub-item__title">Điểm đón</div>
                                                <ul class="accordion-sub-item__list pickup-point-list">

                                                </ul>
                                            </div>
                                            <div class="accordion-sub-item">
                                                <div class="accordion-sub-item__title">Điểm trả</div>
                                                <ul class="accordion-sub-item__list dropoff-point-list">

                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="policy-tab-<?= $item['trip_id']; ?>"
                                        class="provider-details__tab">
                                        <p class="policy-title"><strong>Chính sách nhà xe</strong></p>
                                        <div class="content-policy-container">

                                        </div>
                                    </div>
                                    <div id="ratings-tab-<?= $item['trip_id']; ?>"
                                        class="provider-details__tab">
                                        <div class="ratings-tab__average">
                                            <span class="ratings-tab__average__point">
                                                <i class="fas fa-star"></i>
                                                <?= $item['ratings']['overall'] ?? 0 ?>
                                            </span>
                                            <span class="ratings-tab__average__total-ratings">
                                                <?= $item['ratings']['comments'] ?? 0 ?>
                                                đánh giá
                                            </span>
                                            <div class="ratings-tab__show-cmt__btn-wrap">
                                                <div class="ratings">
                                                    <div class="empty-stars" style="font-size: 16pt;"></div>
                                                    <div class="full-stars"
                                                        style="width: <?php echo !isset($item['ratings']['overall']) ? 0 : ($item['ratings']['overall'] / 5) * 100; ?>%; font-size: 16pt;">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="rating-tab__cats"
                                            id="list-rating-cats-<?= $item['trip_id']; ?>">

                                        </div>
                                        <div class="rating-tab__comments-list"
                                            id="comment-list-<?= $item['trip_id']; ?>">

                                        </div>
                                        <div class="rating-tab__comments-list-pagination"
                                            id="comment-pagination-<?= $item['trip_id']; ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($item['important_notification']['content'])) : ?>
                            <div class="notice-box">
                                <h3 class="notice-box__title"><?php echo $item['important_notification']['label']; ?></h3>
                                <div class="notice-box__desc"><?php echo $item['important_notification']['content']; ?></div>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php } ?>
                <?php if ($loadMore == 0) { ?>
                </ul>
            <?php } ?>
            <?php if ($loadMore == 1) { ?>
                <div class="load-more-response-meta" data-has-more="<?= !empty($data['paging']['hasMore']) ? '1' : '0'; ?>" data-next-cursor="<?= esc_attr($data['nextCursor']); ?>"></div>
            <?php } else { ?>
                <?php if (!empty($data['paging']['hasMore'])) { ?>
                    <div class="box-load-more">
                        <button type="button" data-current-page="1" data-cursor="<?php echo esc_attr($data['nextCursor']); ?>" data-total-page="0"
                            class="btn-load-more-route">Xem thêm chuyến</button>
                    </div>
                <?php } ?>
            <?php } ?>
        <?php } else { ?>
            <?php if ($loadMore == 0) { ?>
                <div class="not-fount-trip-container">
                    <div class="not__found__content">
                        <div class="label">Xin lỗi bạn vì sự bất tiện này. Dailyve sẽ cập nhật ngay khi có thông tin xe hoạt động trên
                            tuyến đường <br>
                            <!-- <b><span class="from-name-not">Huaphanh</span> đi <span class="to-name-not">Hải Phòng</span></b> ngày <b class="route-not-date">20/03/2024</b> -->
                        </div>
                        <div class="content">Xin bạn vui lòng thay đổi tuyến đường tìm kiếm</div>
                    </div>
                    <div style="text-align: center; height: 400px;">
                        <!-- <img src="/wp-content/uploads/assets/images/no-routes.png" alt="no route"> -->
                        <iframe style="border: none; width: 100%; height: 100%;"
                            src="https://lottie.host/embed/3c67b86e-7bff-4dac-8b6c-4cf8444beb75/VSTij16CGS.json"></iframe>
                    </div>
                </div>
            <?php } ?>
        <?php } ?>
<?php }
    wp_die();
}
add_action('wp_ajax_filter_route_trip', 'handle_filter_route_trip');
add_action('wp_ajax_nopriv_filter_route_trip', 'handle_filter_route_trip');
function handle_payment_pay_booking()
{
    $bookingCode = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
    $method = isset($_POST['method']) ? sanitize_text_field($_POST['method']) : '';
    if (!empty($bookingCode) && !empty($method)) {
        $codeArr = explode(" ", $bookingCode);
        $arrResponse = array();
        if ($method == 'online') {
            $status = 1;
            $paymentMethod = 'Chuyển khoản';
        } elseif ($method == 'offline') {
            $status = 2;
            $paymentMethod = 'Tại nhà xe';
        } elseif ($method == 'vnpayqr') {
            $status = 3;
            $paymentMethod = 'VNPAY QR';
        } else {
            $status = 0;
        }
        $arrResponse['status'] = $status;
        $args = array(
            'post_type' => 'book-ticket',
            'post_status' => 'publish',
            'title' => $bookingCode,
            'numberposts' => 1,
        );
        $post_id = null;
        $existing_post = get_posts($args);
        if (!empty($existing_post)) {
            $post_id = $existing_post[0]->ID;
            if ($post_id) {
                update_post_meta($post_id, 'payment_method', $paymentMethod);
                // update_post_meta($post_id, 'seat_code', join(",", $data['data'][0]['seat_codes']));
            }
        }

        if (count($codeArr) == 1 && $method == 'offline') {
            $url = endPoint . "/Api/Ticket/BookingSearch?bookingCode=" . $bookingCode;
            $response = call_api_with_token_agent($url, 'GET');
            $bookingData = wp_remote_retrieve_body($response);
            $data = json_decode($bookingData, true);
            if (is_array($data) && isset($data["data"])) {
                if ($method == 'online' || $method == 'offline' || $method == 'vnpayqr') {
                    $arrResponse['company'] = $data['data'][0]['company'];
                    $arrResponse['price'] = $data['data'][0]['bookingVexereFinalPrice'];
                    $arrResponse['code'] = $data['data'][0]['code'];
                    $arrResponse['expiredTime'] = $data['data'][0]['expiredTime'];
                }
                if ($post_id) {
                    $original_price = get_post_meta($post_id, 'original_price', true);
                    update_post_meta($post_id, 'discount_type', '');
                    update_post_meta($post_id, 'discount', 0);
                    update_post_meta($post_id, 'total_price', $original_price);
                }
                // $post = get_post($post_id);
                wp_send_json_success($arrResponse);
            } else {
                wp_send_json_error("Không tìm thấy vé!");
            }
        } else {
            if ($post_id) {
                confirm_coupon_usage($post_id);
            }
            wp_send_json_success($arrResponse);
        }
    } else {
        wp_send_json_error("Vui lòng điền đầy đủ thông tin!");
    }
    wp_die();
}
add_action('wp_ajax_payment_pay_booking', 'handle_payment_pay_booking');
add_action('wp_ajax_nopriv_payment_pay_booking', 'handle_payment_pay_booking');
// function update_custom_fields_on_edit()
// {
//     global $pagenow;
//     if ($pagenow == 'post.php' && isset($_GET['post'])) {
//         $post_id = intval($_GET['post']);
//         if (get_post_type($post_id) == 'book-ticket') {
//             $bookingCode = get_the_title($post_id);
//             $codeArr = explode(" ", $bookingCode);
//             if (count($codeArr) === 1) {
//                 $url = endPoint . "/Api/Ticket/BookingSearch?bookingCode=" . $bookingCode;
//                 $response = call_api_with_token_agent($url, 'GET');
//                 if (is_wp_error($response)) {
//                     return;
//                 }
//                 $body = wp_remote_retrieve_body($response);
//                 $data = json_decode($body, true);
//                 if (!empty($data)) {
//                     update_post_meta($post_id, 'payment_status', $data["data"][0]["ticket"]["status"]);
//                     update_post_meta($post_id, 'expired_time', $data['data'][0]['expiredTime']);
//                 }
//             }
//             return;
//         }
//     }
// }
// Hook vào admin_enqueue_scripts khi trang admin đang được tải
// add_action('admin_enqueue_scripts', 'update_custom_fields_on_edit');
//session
function start_session()
{
    if (!session_id()) {
        session_start();
    }
}
add_action('init', 'start_session');
// Đóng session khi WordPress hoàn thành xử lý
function end_session()
{
    if (session_id()) {
        session_write_close();
    }
}
add_action('shutdown', 'end_session');
add_action('wp_ajax_save_ticket', 'save_ticket_to_session');
add_action('wp_ajax_nopriv_save_ticket', 'save_ticket_to_session');
function save_ticket_to_session()
{
    error_log('save_ticket_to_session called');
    if (!check_ajax_referer('ams_vexe', 'nonce', false)) {
        error_log('Nonce check failed for ams_vexe');
        wp_send_json_error(['message' => 'Xác thực không hợp lệ (Nonce failed)']);
        wp_die();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $ticket = isset($_POST['ticket']) ? $_POST['ticket'] : null;
    $legIndex = isset($_POST['legIndex']) && $_POST['legIndex'] !== '' ? (int) $_POST['legIndex'] : null;
    if ($ticket && is_array($ticket)) {
        // Fix: Automatically decode JSON strings if they were sent as part of FormData
        // This ensures compatibility with React's JSON.stringify(payload) approach
        $json_fields = ['selectedSeats', 'pickupPoint', 'dropoffPoint', 'transferPickupPoint', 'transferDropoffPoint', 'seatsAndInfoData'];
        foreach ($json_fields as $field) {
            if (isset($ticket[$field]) && is_string($ticket[$field])) {
                $decoded = json_decode(stripslashes($ticket[$field]), true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $ticket[$field] = $decoded;
                }
            }
        }

        if (!isset($_SESSION['tickets'])) {
            $_SESSION['tickets'] = [];
        }
        if ($legIndex !== null) {
            $_SESSION['tickets'][$legIndex] = $ticket;
            ksort($_SESSION['tickets']);
            $_SESSION['tickets'] = array_values($_SESSION['tickets']);
        } else {
            $_SESSION['tickets'][] = $ticket;
        }
        wp_send_json_success(['message' => 'Vé đã được lưu!', 'tickets' => $_SESSION['tickets']]);
    } else {
        wp_send_json_error(['message' => 'Không có dữ liệu vé để lưu!']);
    }
}

add_action('wp_ajax_clear_tickets', 'clear_tickets_session');
add_action('wp_ajax_nopriv_clear_tickets', 'clear_tickets_session');
function clear_tickets_session()
{
    check_ajax_referer('ams_vexe', 'nonce');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['tickets'] = [];
    wp_send_json_success(['message' => 'Đã xóa giỏ hàng!']);
}
// function display_tickets_on_checkout() {
//     session_start();
//     if (!empty($_SESSION['tickets'])) {
//         $tickets = $_SESSION['tickets'];
//         echo '<script>var tickets = ' . json_encode($tickets) . ';</script>';
//     }
// }
// add_action('wp_head', 'display_tickets_on_checkout');
add_action('wp_ajax_get_company_limousine_route_data', 'handle_get_company_limousine_route_data');
add_action('wp_ajax_nopriv_get_company_limousine_route_data', 'handle_get_company_limousine_route_data');
function handle_get_company_limousine_route_data()
{
    check_ajax_referer('ams_nhaxe_limousine', 'nonce');
    $from = isset($_POST['from']) ? sanitize_text_field($_POST['from']) : '';
    $to = isset($_POST['to']) ? sanitize_text_field($_POST['to']) : '';
    $date = date('d-m-Y', strtotime('+1 day'));
    $cache_key = "limousine_route_data_{$from}_{$to}_{$date}";
    $cached_data = get_transient($cache_key);
    if ($cached_data !== false) {
        wp_send_json_success($cached_data);
        return;
    }
    $params = array(
        "page" => 1,
        "pagesize" => 350,
        "newKeyFrom" => $from,
        "newKeyTo" => $to,
        "date" => $date,
        "time" => [
            "min" => '00:00',
            "max" => '23:59'
        ],
        "sort" => "time:asc",
        "companies" => [],
        "rating" => [
            "min" => "1",
            "max" => "5"
        ],
        "isLimousine" => true,
        "fromArea" => [],
        "toArea" => [],
        "times" => []
    );
    $response = call_api_with_token_agent(endPoint . '/Api/Book/RouteAms', 'POST', $params);
    if (!is_wp_error($response)) {
        $data = json_decode($response['body'], true);
        $companies = [];
        foreach ($data['data']['data'] as $item) {
            $companyId = $item['company']['id'];
            if (!isset($companies[$companyId])) {
                $companies[$companyId]['company'] = $item['company'];
                $companies[$companyId]['route'] = $item['route'];
                $companies[$companyId]['to'] = $item['route']['to'];
                $companies[$companyId]['from'] = $item['route']['from'];
            }
        }
        $uniqueCompanies = array_values($companies);
        $result = [];
        foreach ($uniqueCompanies as $item) {
            $args = [
                'post_type' => 'post',
                'posts_per_page' => 1,
                'category__in' => [6],
                'meta_query' => [
                    [
                        'key' => 'company_id',
                        'value' => $item['company']['id'],
                        'compare' => '=',
                    ],
                ],
            ];
            $query = new WP_Query($args);
            $companyData = [];
            if ($query->have_posts()) {
                $post_id = $query->posts[0]->ID;
                $companyData['thumbnail_url'] = get_the_post_thumbnail_url($post_id, 'medium');
                $companyData['vehicle_type'] = get_field('vehicle_type', $post_id) ?? [];
                $companyData['gallery'] = get_field('company_gallery', $post_id) ?? [];
            }
            $result[] = [
                'company' => $item['company'],
                'route' => $item['route'],
                'to' => $item['to'],
                'from' => $item['from'],
                'meta' => $companyData,
            ];
        }
        set_transient($cache_key, $result, 2 * HOUR_IN_SECONDS);
        wp_send_json_success($result);
    }
    wp_die();
}

add_action('wp_ajax_check_add_coupon', 'handle_check_add_coupon');
add_action('wp_ajax_nopriv_check_add_coupon', 'handle_check_add_coupon');

function handle_check_add_coupon()
{
    global $wpdb;
    $journey_code = !empty($_POST['ticket_code']) ? sanitize_text_field($_POST['ticket_code']) : null;
    $coupon_code  = !empty($_POST['coupon']) ? sanitize_text_field($_POST['coupon']) : null;

    if ($journey_code === null || $coupon_code === null) {
        wp_send_json_error('Đã có lỗi xảy ra');
    }

    // 1. Tìm tất cả vé thuộc journey group
    $existing_post = get_posts([
        'post_type'      => 'book-ticket',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => 'journey_group_id',
                'value'   => $journey_code,
                'compare' => '=',
            ]
        ],
    ]);

    if (empty($existing_post)) {
        wp_send_json_error('Mã vé không tồn tại!');
    }

    // 2. Tìm coupon trong WP post type 'coupon'
    $coupon = get_posts([
        'post_type'   => 'coupon',
        'title'       => $coupon_code,
        'numberposts' => 1,
        'post_status' => 'publish',
    ]);

    if (empty($coupon)) {
        wp_send_json_error('Mã giảm giá không hợp lệ');
    }

    $coupon_id  = $coupon[0]->ID;
    $table_name = $wpdb->prefix . 'ticket_coupon';

    // 3. Kiểm tra không cộng dồn – vé đã áp dụng mã khác chưa
    foreach ($existing_post as $p) {
        $existing_coupon = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE ticket_id = %d AND (status = 'pending' OR status = 'completed')",
            $p->ID
        ));
        if ($existing_coupon) {
            wp_send_json_error('Đơn hàng này đã được áp dụng mã giảm giá khác');
        }
    }

    // 4. Lấy cấu hình coupon
    $coupon_routes          = get_field('coupon_route', $coupon_id);
    $coupon_date            = get_field('coupon_date', $coupon_id);
    $coupon_type            = get_field('coupon_type', $coupon_id);
    $coupon_value           = floatval(get_field('coupon_value', $coupon_id));
    $coupon_limit           = intval(get_field('coupon_limit', $coupon_id));
    $coupon_by_seat         = intval(get_field('coupon_by_seat', $coupon_id));
    $coupon_quantity        = intval(get_field('coupon_quantity', $coupon_id));
    $coupon_start_datetime  = strval(get_field('coupon_start_datetime', $coupon_id));
    $coupon_end_datetime    = strval(get_field('coupon_end_datetime', $coupon_id));
    $max_discount           = intval(get_field('max_discount', $coupon_id));

    // 5. Kiểm tra thời gian hiệu lực
    $current_datetime = current_time('Y-m-d H:i:s');
    if (!empty($coupon_start_datetime) && !empty($coupon_end_datetime)) {
        if ($current_datetime < $coupon_start_datetime || $current_datetime > $coupon_end_datetime) {
            $start_fmt = date('H:i d/m/Y', strtotime($coupon_start_datetime));
            $end_fmt   = date('H:i d/m/Y', strtotime($coupon_end_datetime));
            wp_send_json_error(sprintf('Mã giảm giá chỉ có hiệu lực từ %s đến %s', $start_fmt, $end_fmt));
        }
    }

    // 6. Kiểm tra số lượng mã còn lại trong ngày (Chỉ đếm completed)
    if (!empty($coupon_quantity) && intval($coupon_quantity) > 0) {
        $used_total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND status = 'completed' AND DATE(created_at) = CURDATE()",
            $coupon_id
        ));
        if (intval($used_total) >= intval($coupon_quantity)) {
            wp_send_json_error('Mã giảm giá đã hết lượt sử dụng trong ngày');
        }
    }

    // 7. Kiểm tra TỔNG số lượng mã còn lại (Chỉ đếm completed)
    $total_limit = intval(get_post_meta($coupon_id, 'coupon_total_quantity', true));
    if ($total_limit > 0) {
        $used_total_all = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND status = 'completed'",
            $coupon_id
        )));

        if ($used_total_all >= $total_limit) {
            wp_send_json_error('Mã giảm giá đã hết lượt sử dụng');
        }
    }

    // Lấy thông tin từ vé đầu tiên để validate
    $first_ticket   = $existing_post[0];
    $customer_phone = strval(get_post_meta($first_ticket->ID, 'phone', true));
    $from           = strval(get_post_meta($first_ticket->ID, 'search_from', true));
    $to             = strval(get_post_meta($first_ticket->ID, 'search_to', true));

    // 7. Kiểm tra danh sách số điện thoại được phép sử dụng (whitelist)
    $phone_whitelist_raw = get_field('coupon_phone_whitelist', $coupon_id);
    if (!empty($phone_whitelist_raw)) {
        // Tách textarea thành mảng, mỗi dòng 1 số điện thoại
        $phone_whitelist = array_filter(array_map('trim', preg_split('/[\r\n]+/', $phone_whitelist_raw)));
        // Chuẩn hóa: bỏ ký tự không phải số, giữ lại chỉ số
        $normalized_whitelist = array_map(function ($phone) {
            return preg_replace('/[^0-9]/', '', $phone);
        }, $phone_whitelist);
        $normalized_customer = preg_replace('/[^0-9]/', '', $customer_phone);

        // Kiểm tra SĐT có trong danh sách whitelist không
        if (!in_array($normalized_customer, $normalized_whitelist)) {
            wp_send_json_error('Số điện thoại của bạn không nằm trong danh sách được phép sử dụng mã giảm giá này');
        }

        // Kiểm tra SĐT này đã sử dụng mã này chưa (mỗi SĐT whitelist chỉ được dùng 1 lần)
        $whitelist_used = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND phone = %s AND status = 'completed'",
            $coupon_id,
            $customer_phone
        ));
        if (intval($whitelist_used) > 0) {
            wp_send_json_error('Số điện thoại này đã sử dụng mã giảm giá này rồi');
        }
    }

    // 8. Kiểm tra giới hạn sử dụng mỗi user (theo phone)
    if ($coupon_limit > 0) {
        $used_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND phone = %s AND status = 'completed'",
            $coupon_id,
            $customer_phone
        ));
        if ($used_count >= $coupon_limit) {
            wp_send_json_error('Bạn đã sử dụng mã giảm giá này quá số lần cho phép');
        }
    }

    // 8. Kiểm tra tuyến đường (map partner ID -> _id)
    if (!empty($coupon_routes)) {
        $partner_id = strval(get_post_meta($first_ticket->ID, 'partner_id', true));
        $response = wp_remote_get(rest_url('api/v1/state-city-new?api_key=' . API_KEY_CLIENT));
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['data']) && is_array($data['data'])) {
                $newFrom = $from;
                $newTo = $to;
                foreach ($data['data'] as $item) {
                    if (!empty($item['mappings']) && is_array($item['mappings'])) {
                        foreach ($item['mappings'] as $mapping) {
                            if ($mapping['system'] == $partner_id) {
                                if (strval($mapping['externalId']) === $from || strval($mapping['externalCode'] ?? '') === $from) {
                                    $newFrom = $item['_id'];
                                }
                                if (strval($mapping['externalId']) === $to || strval($mapping['externalCode'] ?? '') === $to) {
                                    $newTo = $item['_id'];
                                }
                            }
                        }
                    }
                }
                $from = $newFrom;
                $to = $newTo;
            }
        }

        $isValidRoute = false;
        foreach ($coupon_routes as $route) {
            if ($route['coupon_route_departure']['value'] == $from && $route['coupon_route_destination']['value'] == $to) {
                $isValidRoute = true;
                break;
            }
        }
        if (!$isValidRoute) {
            wp_send_json_error('Mã giảm giá không áp dụng cho tuyến đường này');
        }
    }

    // 9. Kiểm tra ngày trong tuần
    $current_day       = date('N');
    $coupon_date_array = is_array($coupon_date) ? $coupon_date : [];
    if (!empty($coupon_date_array) && !in_array($current_day, $coupon_date_array)) {
        wp_send_json_error('Mã giảm giá không áp dụng cho ngày này');
    }

    // 10. Kiểm tra số ghế tối thiểu
    if ($coupon_by_seat > 0) {
        $total_seats = 0;
        foreach ($existing_post as $p) {
            $seat = get_post_meta($p->ID, 'seat', true);
            if (!empty($seat)) {
                $total_seats += count(array_filter(explode(',', $seat)));
            }
        }
        if ($total_seats < $coupon_by_seat) {
            wp_send_json_error('Cần đặt tối thiểu ' . $coupon_by_seat . ' ghế để sử dụng mã giảm giá này');
        }
    }

    // 11. Tính tổng giá gốc và giảm giá
    $total_original = 0;
    foreach ($existing_post as $p) {
        $orig = floatval(get_post_meta($p->ID, 'original_price', true));
        if (empty($orig)) {
            $orig = floatval(get_post_meta($p->ID, 'total_price', true));
        }
        $total_original += $orig;
    }

    $discount_amount = 0;
    if ($coupon_type === 'percent') {
        $discount_amount = $total_original * ($coupon_value / 100);
    } else {
        $discount_amount = $coupon_value;
    }

    if ($max_discount > 0 && $discount_amount > $max_discount) {
        $discount_amount = $max_discount;
    }

    if ($discount_amount > $total_original) {
        $discount_amount = $total_original;
    }

    $new_total = $total_original - $discount_amount;

    // 12. Cập nhật vé và lưu ticket_coupon
    $payment_content = '';
    foreach ($existing_post as $p) {
        $pid  = $p->ID;
        $orig = floatval(get_post_meta($pid, 'original_price', true));
        if (empty($orig)) {
            $orig = floatval(get_post_meta($pid, 'total_price', true));
            update_post_meta($pid, 'original_price', $orig);
        }

        // Phân bổ giảm giá theo tỷ lệ giá mỗi vé
        $ticket_discount  = ($total_original > 0) ? ($orig / $total_original) * $discount_amount : 0;
        $ticket_new_price = $orig - $ticket_discount;

        update_post_meta($pid, 'total_price', $ticket_new_price);
        update_post_meta($pid, 'discount_type', $coupon_type);
        update_post_meta($pid, 'discount', $coupon_value);

        if (empty($payment_content)) {
            $payment_content = strval(get_post_meta($pid, 'payment_content', true));
        }

        // Xóa pending coupon cũ (nếu có) rồi insert mới
        $wpdb->delete($table_name, ['ticket_id' => $pid, 'status' => 'pending'], ['%d', '%s']);
        $wpdb->insert($table_name, [
            'coupon_id'  => $coupon_id,
            'phone'      => $customer_phone,
            'ticket_id'  => $pid,
            'status'     => 'pending',
            'code'       => $coupon_code,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['%d', '%s', '%d', '%s', '%s', '%s', '%s']);
    }

    wp_send_json_success([
        'total_price'     => $new_total,
        'payment_content' => $payment_content,
        'message'         => 'Áp dụng mã giảm giá thành công!',
        'discount_amount' => $discount_amount,
    ]);
}

function confirm_coupon_usage($ticket_id)
{
    if (isset($_SESSION['pending_coupon_record'])) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ticket_coupon';

        $wpdb->update(
            $table_name,
            array(
                'ticket_id' => $ticket_id,
                'status' => 'completed'
            ),
            array('id' => $_SESSION['pending_coupon_record']),
            array('%d', '%s'),
            array('%d')
        );

        // Xóa khỏi session sau khi đã cập nhật
        unset($_SESSION['pending_coupon_record']);
    }
}
function check_ticket_discount($request)
{
    $booking_code = $request->get_param('bookingCode');
    $booking_code = explode(" ", $booking_code);
    if (empty($booking_code) || strlen($booking_code[0]) < 7) {
        return new WP_Error('missing_code', 'Vui lòng cung cấp mã vé', array('status' => 400));
    }

    $args = array(
        'post_type' => 'book-ticket',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => $booking_code[0],
        'exact' => false
    );

    $ticket_query = get_posts($args);

    if (empty($ticket_query)) {
        // return new WP_Error('ticket_not_found', 'Không tìm thấy vé với mã này', array('status' => 404));
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                // 'bookingCode' => $booking_code[0],
                // 'hasDiscount' => !empty($discount),
                'discountAmount' => 0,
                // 'originalPrice' => $original_price ? $original_price : $total_price,
                // 'finalPrice' => $total_price
            )
        ), 200);
    }

    $ticket_id = $ticket_query[0]->ID;

    $discount = get_post_meta($ticket_id, 'discount', true);
    $total_price = get_post_meta($ticket_id, 'total_price', true);
    $original_price = get_post_meta($ticket_id, 'original_price', true);

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            // 'bookingCode' => $booking_code[0],
            // 'hasDiscount' => !empty($discount),
            'discountAmount' => $original_price ? $original_price - $total_price : 0,
            // 'originalPrice' => $original_price ? $original_price : $total_price,
            // 'finalPrice' => $total_price
        )
    ), 200);
}


function handle_booking_ams_without_session(WP_REST_Request $request)
{
    $params = $request->get_json_params();

    $required_fields = ['customer', 'tickets'];
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return new WP_Error('missing_field', "Missing required field: $field", ['status' => 400]);
        }
    }

    $customer = $params['customer'];
    $note = $params['note'];
    $tickets = $params['tickets'];

    if (empty($customer['name']) || empty($customer['phone'])) {
        return new WP_Error('invalid_customer', 'Vui lòng nhập đầy đủ thông tin khách hàng!', ['status' => 400]);
    }

    $dataArr = [];

    foreach ($tickets as $key => $ticket) {
        $seatDepart = '';
        $seatArrive = '';

        if ($key == 0) {
            $seatDepart = implode(',', array_map(function ($seat) {
                return $seat['seatCode'];
            }, $ticket['selectedSeats']));
        } else {
            $seatArrive = implode(',', array_map(function ($seat) {
                return $seat['seatCode'];
            }, $ticket['selectedSeats']));
        }

        $ticket['customer'] = $customer;
        $ticket['note'] = $note ?? '';
        $ticket['paymentMethod'] = 0;

        $response = call_api_with_token_agent(endPoint . '/Api/Book/BookingAms', 'POST', $ticket);
        // wp_send_json_success($response);
        // die();
        if (is_wp_error($response)) {
            $dataArr['errors'][$key] = $response->get_error_message();
        } else {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if ($data['successful'] != true) {
                $dataArr['errors'][$key] = $data['errorData'];
            } else {
                $dataArr['data'][$key] = $data['data'];
            }
        }
    }

    $from = $tickets[0]['seatsAndInfoData']['searchFrom'] ?? '';
    $to = $tickets[0]['seatsAndInfoData']['searchTo'] ?? '';
    $company = $tickets[0]['seatsAndInfoData']['companyName'] ?? '';
    $vehicleName = $tickets[0]['seatsAndInfoData']['name'] ?? '';
    $pickupDate = $tickets[0]['pickupPoint']['realTime'] ?? '';
    $arrivalDate = $tickets[0]['dropoffPoint']['realTime'] ?? '';

    if (isset($dataArr['data']) && count($dataArr['data']) > 0 && !isset($dataArr['error'])) {
        $new_post = array(
            'post_type' => 'book-ticket',
            'post_status' => 'publish',
        );

        $bookingCodeArr = [];
        $ticketCodeArr = [];

        foreach ($dataArr['data'] as $key => $item) {
            if (isset($item['bookingCode']) && !empty($item['bookingCode'])) {
                $bookingCodeArr[] = $item['bookingCode'];
            }
            if (isset($item['code']) && !empty($item['code'])) {
                $ticketCodeArr[] = $item['code'];
            }
        }

        $ticketCodes = implode(' ', $ticketCodeArr);
        $bookingCodes = implode(' ', $bookingCodeArr);
        $new_post['post_title'] = $bookingCodes;

        // Tính tổng giá
        $priceTotal = 0;
        foreach ($tickets as $ticket) {
            foreach ($ticket['selectedSeats'] as $item) {
                $priceTotal += $item['fare'];
            }
        }

        $paymentContent = $ticketCodes . ' ' . $customer['phone'];
        $post_id = wp_insert_post($new_post);

        if ($post_id) {
            update_post_meta($post_id, 'search_from', $from);
            update_post_meta($post_id, 'search_to', $to);
            update_post_meta($post_id, 'company_bus', $company);
            update_post_meta($post_id, 'vehicle_name', $vehicleName);
            update_post_meta($post_id, 'pickup_date', $pickupDate);
            update_post_meta($post_id, 'arrival_date', $arrivalDate);
            update_post_meta($post_id, 'ticket_codes', $ticketCodes);
            update_post_meta($post_id, 'booking_codes', $bookingCodes);
            update_post_meta($post_id, 'original_price', $priceTotal);
            update_post_meta($post_id, 'total_price', $priceTotal);
            update_post_meta($post_id, 'seat_depart', $seatDepart);
            update_post_meta($post_id, 'seat_arrive', $seatArrive);
            update_post_meta($post_id, 'payment_status', 1);
            update_post_meta($post_id, 'full_name', $customer['name']);
            update_post_meta($post_id, 'payment_content', $paymentContent);
            update_post_meta($post_id, 'phone', $customer['phone']);
            update_post_meta($post_id, 'email', $customer['email'] ?? '');
            update_post_meta($post_id, 'note', $note);

            $codeArr = explode(" ", $bookingCodes);
            if (count($codeArr) === 1) {
                $url = endPoint . "/Api/Ticket/BookingSearch?bookingCode=" . $bookingCodes;
                $response = call_api_with_token_agent($url, 'GET');
                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);
                    if (!empty($data)) {
                        update_post_meta($post_id, 'payment_status', $data["data"][0]["ticket"]["status"]);
                        update_post_meta($post_id, 'expired_time', $data['data'][0]['expiredTime']);
                    }
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'data' => $dataArr,
                'code' => $bookingCodes,
                'ticket' => [
                    'id' => $post_id,
                    'total_price' => $priceTotal,
                    'payment_content' => $paymentContent,
                    'company_name' => $company,
                    'expired_time' => get_post_meta($post_id, 'expired_time', true) ?? '',
                ]
            ], 200);
        } else {
            return new WP_Error('insert_failed', 'Không thể tạo đơn hàng', ['status' => 500]);
        }
    } else {
        return new WP_Error('booking_failed', $dataArr['errors'] ?? 'Đã xảy ra lỗi khi đặt vé', ['status' => 400]);
    }
}

function api_handle_delete_ticket($request)
{
    try {
        $code = $request->get_param('code');

        if (empty($code)) {
            return new WP_Error(
                'missing_parameter',
                'Thiếu mã đơn hàng',
                array('status' => 400)
            );
        }

        $args = array(
            'post_type' => 'book-ticket',
            'post_status' => 'publish',
            'title' => $code,
            'numberposts' => 1,
        );

        $existing_post = get_posts($args);

        if (empty($existing_post)) {
            return new WP_Error(
                'order_not_found',
                'Đơn hàng không tồn tại!',
                array('status' => 404)
            );
        }

        $post_id = $existing_post[0]->ID;
        $bookingCodes = get_field('booking_codes', $post_id);

        if (empty($bookingCodes)) {
            return new WP_Error(
                'booking_codes_not_found',
                'Không tìm thấy mã booking',
                array('status' => 400)
            );
        }

        $codeArr = explode(" ", $bookingCodes);
        $result = array(
            'status' => false,
            'deleted_codes' => array(),
            'failed_codes' => array()
        );

        foreach ($codeArr as $bookingCode) {
            $bookingCode = trim($bookingCode);
            if (empty($bookingCode))
                continue;

            $endpoint = endPoint . '/Api/Book/Delete?bookingCode=' . $bookingCode;
            $response = call_api_with_token_agent($endpoint, 'POST');

            if (is_wp_error($response)) {
                $result['failed_codes'][] = array(
                    'code' => $bookingCode,
                    'error' => $response->get_error_message()
                );
                continue;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($data['successful']) && $data['successful'] == true) {
                $result['deleted_codes'][] = $bookingCode;
                $result['status'] = true;
            } else {
                $result['failed_codes'][] = array(
                    'code' => $bookingCode,
                    'error' => isset($data['message']) ? $data['message'] : 'Unknown error'
                );
            }
        }

        if ($result['status']) {
            update_post_meta($post_id, 'payment_status', 3);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $result,
            'message' => $result['status'] ? 'Xóa vé thành công' : 'Không thể xóa vé'
        ), 200);
    } catch (Exception $e) {
        return new WP_Error(
            'internal_error',
            'Lỗi hệ thống: ' . $e->getMessage(),
            array('status' => 500)
        );
    }
}

function get_posts_by_category($request)
{
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $category = $request->get_param('category');
    $offset = ($page - 1) * $per_page;

    $args = [
        'post_type' => 'post',
        'posts_per_page' => $per_page,
        'offset' => $offset,
        'post_status' => 'publish'
    ];

    // Add category filter if specified
    if (!is_null($category)) {
        $args['category__in'] = [$category];
    }

    $query = new WP_Query($args);
    $total_items = $query->found_posts;
    $posts = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            $posts[] = [
                'id' => $post_id,
                'title' => get_the_title(),
                'excerpt' => get_the_excerpt(),
                'thumbnail' => get_the_post_thumbnail_url($post_id, 'full'),
                'date' => get_the_date('Y-m-d H:i:s'),
                'author' => get_the_author(),
                'categories' => wp_get_post_categories($post_id, ['fields' => 'names']),
                'url' => get_permalink($post_id)
            ];
        }
        wp_reset_postdata();
    }

    $total_pages = ceil($total_items / $per_page);

    return new WP_REST_Response([
        'data' => $posts,
        'meta' => [
            'current_page' => (int) $page,
            'per_page' => (int) $per_page,
            'total_items' => (int) $total_items,
            'total_pages' => $total_pages
        ]
    ], 200);
}


function get_valid_coupons($request)
{
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $use_coupon = $request->get_param('use_coupon');
    $coupon_id = $request->get_param('coupon_id');
    $offset = ($page - 1) * $per_page;

    $args = [
        'post_type' => 'coupon',
        'posts_per_page' => $per_page,
        'offset' => $offset,
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'is_for_new_users',
                'value' => true,
                'compare' => '='
            ],
        ]
    ];

    if (!is_null($coupon_id)) {
        $args['p'] = $coupon_id;
    }

    if (!is_null($use_coupon)) {
        $args['meta_query'][] = [
            'key' => 'use_coupon',
            'value' => $use_coupon,
            'compare' => 'LIKE'
        ];
    }

    // var_dump($args);

    $query = new WP_Query($args);
    $total_items = $query->found_posts;
    $coupons = [];

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();

            $coupon_routes = get_field('coupon_route', $post_id);
            $coupon_date = get_field('coupon_date', $post_id);
            $coupon_type = get_field('coupon_type', $post_id);
            $coupon_value = floatval(get_field('coupon_value', $post_id));
            $coupon_limit = intval(get_field('coupon_limit', $post_id));
            $coupon_by_seat = intval(get_field('coupon_by_seat', $post_id));
            $coupon_quantity = intval(get_field('coupon_quantity', $post_id));
            $coupon_total_quantity = intval(get_field('coupon_total_quantity', $post_id));
            $coupon_start_datetime = strval(get_field('coupon_start_datetime', $post_id));
            $coupon_end_datetime = strval(get_field('coupon_end_datetime', $post_id));

            $coupons[] = [
                'id' => $post_id,
                'title' => get_the_title(),
                'excerpt' => get_the_excerpt(),
                'is_for_new_users' => get_field('is_for_new_users', $post_id),
                'use_coupon' => get_field('use_coupon', $post_id),
                'max_discount' => intval(get_field('max_discount', $post_id)),
                'valid_days_after_signup' => intval(get_field('valid_days_after_signup', $post_id)),
                'coupon_routes' => $coupon_routes,
                'coupon_date' => $coupon_date,
                'coupon_type' => $coupon_type,
                'coupon_value' => $coupon_value,
                'coupon_limit' => $coupon_limit,
                'coupon_by_seat' => $coupon_by_seat,
                'coupon_quantity' => $coupon_quantity,
                'coupon_total_quantity' => $coupon_total_quantity,
                'coupon_start_datetime' => $coupon_start_datetime,
                'coupon_end_datetime' => $coupon_end_datetime,
                'created_at' => get_the_date('Y-m-d H:i:s'),
            ];
        }
        wp_reset_postdata();
    }

    $total_pages = ceil($total_items / $per_page);

    return new WP_REST_Response([
        'data' => $coupons,
        'meta' => [
            'current_page' => (int) $page,
            'per_page' => (int) $per_page,
            'total_items' => (int) $total_items,
            'total_pages' => $total_pages
        ]
    ], 200);
}

function api_check_add_coupon($request)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'ticket_coupon';
    $coupon_code = $request->get_param('coupon');
    $token = $request->get_param('token');
    $booking_codes = $request->get_param('booking_codes');
    $is_devices = '';
    $created_at = '';

    // Tìm coupon
    $args = array(
        'post_type' => 'coupon',
        'title' => $coupon_code,
        'numberposts' => 1,
        'post_status' => 'publish'
    );
    $coupon = get_posts($args);

    if (empty($coupon)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Mã giảm giá không hợp lệ'
        ), 400);
    }

    // Tìm ticket
    $args = array(
        'post_type' => 'book-ticket',
        'post_status' => 'publish',
        'title' => $booking_codes,
        'numberposts' => 1,
    );
    $ticket = get_posts($args);

    if (empty($ticket)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Không tìm thấy thông tin vé'
        ), 400);
    }

    $coupon_id = $coupon[0]->ID;
    $ticket_id = $ticket[0]->ID;

    $existing_coupon = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE ticket_id = %d AND (status = 'pending' OR status = 'completed')",
        $ticket_id
    ));

    if ($existing_coupon) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Vé này đã được áp dụng mã giảm giá khác'
        ), 400);
    }

    // Lấy thông tin coupon
    $coupon_routes = get_field('coupon_route', $coupon_id);
    $coupon_date = get_field('coupon_date', $coupon_id);
    $coupon_type = get_field('coupon_type', $coupon_id);
    $coupon_value = floatval(get_field('coupon_value', $coupon_id));
    $coupon_limit = intval(get_field('coupon_limit', $coupon_id));
    $coupon_by_seat = intval(get_field('coupon_by_seat', $coupon_id));
    $coupon_quantity = intval(get_field('coupon_quantity', $coupon_id));
    $coupon_total_quantity = intval(get_field('coupon_total_quantity', $coupon_id));
    $coupon_start_datetime = strval(get_field('coupon_start_datetime', $coupon_id));
    $coupon_end_datetime = strval(get_field('coupon_end_datetime', $coupon_id));
    $is_for_new_users = filter_var(get_field('is_for_new_users', $coupon_id), FILTER_VALIDATE_BOOLEAN);
    $valid_days_after_signup = intval(get_field('valid_days_after_signup', $coupon_id));
    $max_discount = intval(get_field('max_discount', $coupon_id));
    $use_coupon = get_field('use_coupon', $coupon_id);

    // Đã loại bỏ kiểm tra App và Token theo yêu cầu


    // Kiểm tra thời gian hiệu lực của mã giảm giá
    $current_datetime = current_time('Y-m-d H:i:s');
    if (!empty($coupon_start_datetime) && !empty($coupon_end_datetime)) {
        if ($current_datetime < $coupon_start_datetime || $current_datetime > $coupon_end_datetime) {
            $start_formatted = date('H:i d/m/Y', strtotime($coupon_start_datetime));
            $end_formatted = date('H:i d/m/Y', strtotime($coupon_end_datetime));
            return new WP_REST_Response(array(
                'success' => false,
                'message' => sprintf('Mã giảm giá chỉ có hiệu lực từ %s đến %s', $start_formatted, $end_formatted)
            ), 400);
        }
    }

    // Lấy thông tin ticket
    $ticket_total = floatval(get_field('total_price', $ticket_id));
    $original_price = floatval(get_field('original_price', $ticket_id) ?? $ticket_total);
    $payment_content = strval(get_field('payment_content', $ticket_id) ?? '');
    $customer_phone = strval(get_field('phone', $ticket_id) ?? '');
    $partner_id = strval(get_field('partner_id', $ticket_id) ?? '');
    $from = strval(get_field('search_from', $ticket_id));
    $to = strval(get_field('search_to', $ticket_id));
    $seat_depart = strval(get_field('seat_depart', $ticket_id) ?? '');
    $seat_arrive = strval(get_field('search_to', $ticket_id) ?? '');

    $response = wp_remote_get(rest_url('api/v1/state-city-new?api_key=' . API_KEY_CLIENT));

    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['data']) && is_array($data['data'])) {
            $newFrom = $from;
            $newTo = $to;
            foreach ($data['data'] as $item) {
                if (!empty($item['mappings']) && is_array($item['mappings'])) {
                    foreach ($item['mappings'] as $mapping) {
                        if ($mapping['system'] == $partner_id) {
                            if (strval($mapping['externalId']) === $from || strval($mapping['externalCode'] ?? '') === $from) {
                                $newFrom = $item['_id'];
                            }
                            if (strval($mapping['externalId']) === $to || strval($mapping['externalCode'] ?? '') === $to) {
                                $newTo = $item['_id'];
                            }
                        }
                    }
                }
            }
            $from = $newFrom;
            $to = $newTo;
        }
    } else {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Có lỗi xảy ra khi lấy thông tin địa điểm!'
        ), 500);
    }

    // Kiểm tra số lượng mã còn lại (Chỉ đếm completed)
    if (!empty($coupon_quantity) && intval($coupon_quantity) > 0) {
        $used_total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND status = 'completed' AND DATE(created_at) = CURDATE()",
            $coupon_id
        ));

        if (intval($used_total) >= intval($coupon_quantity)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Mã giảm giá đã hết lượt sử dụng trong ngày'
            ), 400);
        }
    }

    // Kiểm tra TỔNG số lượng mã còn lại (Chỉ đếm completed)
    $total_limit = $coupon_total_quantity;

    if ($total_limit > 0) {
        $used_total_all = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND status = 'completed'",
            $coupon_id
        )));

        if ($used_total_all >= $total_limit) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Mã giảm giá đã hết lượt sử dụng (ID:' . $coupon_id . ' - Đã dùng: ' . $used_total_all . '/' . $total_limit . ')'
            ), 400);
        }
    }

    // Kiểm tra các điều kiện
    $current_day = date('N');
    $arr_seat_depart = !empty($seat_depart) ? explode(",", $seat_depart) : array();
    $arr_seat_arrive = !empty($seat_arrive) ? explode(",", $seat_arrive) : array();

    // 1. Kiểm tra tuyến đường
    $isValidRoute = true;
    if (!empty($coupon_routes)) {
        $isValidRoute = false;
        foreach ($coupon_routes as $route) {
            if ($route['coupon_route_departure']['value'] == $from && $route['coupon_route_destination']['value'] == $to) {
                $isValidRoute = true;
                break;
            }
        }
    }

    if (!$isValidRoute) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Mã giảm giá không áp dụng cho tuyến đường này'
        ), 400);
    }

    // 2. Kiểm tra ngày
    $coupon_date_array = is_array($coupon_date) ? $coupon_date : array();
    if (!empty($coupon_date_array) && !in_array($current_day, $coupon_date_array)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Mã giảm giá không áp dụng cho ngày này'
        ), 400);
    }

    // 3. Kiểm tra số ghế tối thiểu
    if ($coupon_by_seat > 0) {
        if (count($arr_seat_depart) < $coupon_by_seat && count($arr_seat_arrive) < $coupon_by_seat) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Cần đặt tối thiểu ' . $coupon_by_seat . ' ghế để sử dụng mã giảm giá này'
            ), 400);
        }
    }

    // 4. Kiểm tra danh sách số điện thoại được phép sử dụng (whitelist)
    $phone_whitelist_raw = get_field('coupon_phone_whitelist', $coupon_id);
    if (!empty($phone_whitelist_raw)) {
        $phone_whitelist = array_filter(array_map('trim', preg_split('/[\r\n]+/', $phone_whitelist_raw)));
        $normalized_whitelist = array_map(function ($phone) {
            return preg_replace('/[^0-9]/', '', $phone);
        }, $phone_whitelist);
        $normalized_customer = preg_replace('/[^0-9]/', '', $customer_phone);

        if (!in_array($normalized_customer, $normalized_whitelist)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Số điện thoại của bạn không nằm trong danh sách được phép sử dụng mã giảm giá này'
            ), 400);
        }

        $whitelist_used = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND phone = %s AND status = 'completed'",
            $coupon_id,
            $customer_phone
        ));
        if (intval($whitelist_used) > 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Số điện thoại này đã sử dụng mã giảm giá này rồi'
            ), 400);
        }
    }

    // 5. Kiểm tra giới hạn sử dụng
    if ($coupon_limit > 0) {
        $used_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE coupon_id = %d AND phone = %s AND status = 'completed' AND DATE(created_at) = CURDATE()",
            $coupon_id,
            $customer_phone
        ));

        if ($used_count >= $coupon_limit) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Bạn đã sử dụng mã giảm giá này quá số lần cho phép trong ngày'
            ), 400);
        }
    }

    // Tính toán giảm giá
    $discount_amount = 0;
    if ($coupon_type == 'percent') {
        $discount_amount = $original_price * ($coupon_value / 100);
    } else {
        $discount_amount = $coupon_value;
    }

    if ($max_discount > 0 && !empty($max_discount)) {
        if ($discount_amount > $max_discount) {
            $discount_amount = $max_discount;
        }
    }

    $new_total = $original_price - $discount_amount;

    // Cập nhật thông tin giảm giá
    update_post_meta($ticket_id, 'discount_type', $coupon_type);
    update_post_meta($ticket_id, 'discount', $coupon_value);
    update_post_meta($ticket_id, 'total_price', $new_total);

    $wpdb->delete(
        $table_name,
        array('ticket_id' => $ticket_id, 'status' => 'pending'),
        array('%d', '%s')
    );

    // Lưu thông tin sử dụng mã giảm giá mới
    $insert_result = $wpdb->insert(
        $table_name,
        array(
            'coupon_id' => $coupon_id,
            'phone' => $customer_phone,
            'ticket_id' => $ticket_id,
            'status' => 'pending',
            'code' => $coupon_code,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ),
        array('%d', '%s', '%d', '%s', '%s', '%s')
    );

    if ($insert_result === false) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Có lỗi xảy ra khi lưu thông tin mã giảm giá'
        ), 500);
    }

    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'coupon_type' => $coupon_type,
            'coupon_value' => $coupon_value,
            'discount_amount' => $discount_amount,
            'original_price' => $original_price,
            'total_price' => $new_total,
            'payment_content' => $payment_content,
            'ticket_id' => $ticket_id,
            'id' => $wpdb->insert_id
        ),
        'message' => 'Áp dụng mã giảm giá thành công'
    ), 200);
}

function api_remove_pending_coupon($request)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'ticket_coupon';
    $ticket_id = $request->get_param('ticket_id');
    $record_id = $request->get_param('id');

    // Kiểm tra tham số đầu vào
    if (empty($ticket_id) && empty($record_id)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Vui lòng cung cấp ticket_id hoặc record_id'
        ), 400);
    }

    $where_conditions = array('status' => 'pending');
    $where_formats = array('%s');

    if (!empty($record_id)) {
        $where_conditions['id'] = intval($record_id);
        $where_formats[] = '%d';
    } elseif (!empty($ticket_id)) {
        $where_conditions['ticket_id'] = intval($ticket_id);
        $where_formats[] = '%d';
    }

    // Kiểm tra xem bản ghi có tồn tại không
    $existing_record = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE " .
            (!empty($record_id) ? "id = %d" : "ticket_id = %d") .
            " AND status = %s",
        !empty($record_id) ? $record_id : $ticket_id,
        'pending'
    ));

    if (!$existing_record) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Không tìm thấy đơn hàng sử dụng mã giảm giá để xóa'
        ), 404);
    }

    $delete_result = $wpdb->delete(
        $table_name,
        $where_conditions,
        $where_formats
    );

    if ($delete_result === false) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Có lỗi xảy ra khi xóa mã giảm giá'
        ), 500);
    }

    if ($delete_result === 0) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Không có bản ghi nào được xóa'
        ), 404);
    }

    if (!empty($ticket_id)) {
        $ticket_id_to_update = !empty($record_id) ? $existing_record->ticket_id : $ticket_id;

        // Lấy giá gốc để khôi phục
        $original_price = floatval(get_field('original_price', $ticket_id_to_update));
        if (empty($original_price)) {
            $original_price = floatval(get_field('total_price', $ticket_id_to_update));
        }

        // Khôi phục lại giá gốc và xóa thông tin discount
        update_post_meta($ticket_id_to_update, 'total_price', $original_price);
        delete_post_meta($ticket_id_to_update, 'discount_type');
        delete_post_meta($ticket_id_to_update, 'discount');
    }

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Xóa mã giảm giá thành công',
        'data' => array(
            'deleted_records' => $delete_result,
            'deleted_record_info' => array(
                'id' => $existing_record->id,
                'coupon_id' => $existing_record->coupon_id,
                'ticket_id' => $existing_record->ticket_id,
                'phone' => $existing_record->phone
            )
        )
    ), 200);
}

function handle_vexere_refund(WP_REST_Request $request)
{
    $code = $request->get_param('code');
    $transaction_id = $request->get_param('transaction_id');

    // 1. Tìm post tương ứng
    $args = array(
        'post_type' => 'book-ticket',
        'post_status' => 'publish',
        'title' => $code,
        'numberposts' => 1,
    );
    $existing_posts = get_posts($args);

    if (empty($existing_posts)) {
        return new WP_Error('order_not_found', 'Đơn hàng không tồn tại!', array('status' => 404));
    }

    $post_id = $existing_posts[0]->ID;

    // 2. Gọi API Vexere để thực hiện refund
    $body = [
        'code' => $code,
        'transaction_id' => $transaction_id
    ];

    $response = call_api_v2('booking/vexere/refund', 'POST', $body);

    if (is_wp_error($response)) {
        return new WP_Error('api_error', $response->get_error_message(), array('status' => 500));
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body_raw = wp_remote_retrieve_body($response);
    $data = json_decode($body_raw, true);

    if ($http_code !== 200 || empty($data)) {
        $error_msg = isset($data['message']) ? $data['message'] : 'Không thể thực hiện hoàn tiền Vexere.';
        return new WP_Error('refund_failed', $error_msg, array('status' => $http_code ?: 500));
    }

    // 3. Cập nhật trạng thái post nếu thành công
    // Trạng thái 5 là REFUNDED theo yêu cầu
    update_post_meta($post_id, 'payment_status', 5);
    update_post_meta($post_id, 'vexere_refund_transaction_id', $transaction_id);
    if (isset($data['data']['refund'])) {
        update_post_meta($post_id, 'refund_amount', $data['data']['refund']);
    }
    if (isset($data['data']['cancel_fee'])) {
        update_post_meta($post_id, 'cancel_fee', $data['data']['cancel_fee']);
    }

    // 4. Trả về response theo cấu trúc yêu cầu
    return new WP_REST_Response($data, 200);
}

/**
 * Lấy dữ liệu nhà xe (thumbnail, gallery) theo lô để tối ưu hiệu năng.
 */
function ams_get_bulk_company_data($items)
{
    if (!is_array($items)) return [];

    $company_data_map = [];
    $company_ids_to_fetch = [];

    foreach ($items as $item) {
        $cid = trim($item['company_id'] ?? '');
        if (!$cid) continue;

        $cache_key = 'company_' . $cid . '_v3';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            $company_data_map[$cid] = $cached;
        } else {
            $company_ids_to_fetch[] = $cid;
        }
    }

    if (!empty($company_ids_to_fetch)) {
        $company_ids_to_fetch = array_unique($company_ids_to_fetch);
        $bulk_query = new WP_Query([
            'post_type'        => 'page',
            'posts_per_page'   => -1,
            'post_parent'      => 15764,
            'suppress_filters' => true,
            'meta_query'       => [
                [
                    'key'     => 'company_id',
                    'value'   => $company_ids_to_fetch,
                    'compare' => 'IN',
                ],
            ],
        ]);

        if ($bulk_query->have_posts()) {
            foreach ($bulk_query->posts as $post) {
                $cid = get_post_meta($post->ID, 'company_id', true);
                if ($cid) {
                    $thumb = get_the_post_thumbnail_url($post->ID, 'medium') ?: get_the_post_thumbnail_url($post->ID, 'full');
                    $gallery = get_field('company_gallery', $post->ID) ?? [];

                    $data_to_store = [
                        'thumbnail' => $thumb,
                        'gallery'   => $gallery,
                        'url'       => get_permalink($post->ID),
                    ];

                    $company_data_map[$cid] = $data_to_store;
                    set_transient('company_' . $cid . '_v3', $data_to_store, DAY_IN_SECONDS);
                }
            }
        }

        // Đánh dấu các ID không tìm thấy để tránh query lại nhiều lần
        foreach ($company_ids_to_fetch as $cid) {
            if (!isset($company_data_map[$cid])) {
                $empty_data = ['thumbnail' => '', 'gallery' => [], 'url' => ''];
                $company_data_map[$cid] = $empty_data;
                set_transient('company_' . $cid . '_v3', $empty_data, DAY_IN_SECONDS);
            }
        }
    }

    return $company_data_map;
}

// Thêm Meta Box vào trang edit book-ticket để xem thông tin coupon
add_action('add_meta_boxes', 'dailyve_add_coupon_info_meta_box');
function dailyve_add_coupon_info_meta_box()
{
    add_meta_box(
        'dailyve_coupon_info',           // ID của meta box
        'Thông tin Mã giảm giá',          // Tiêu đề
        'dailyve_display_coupon_info',    // Hàm hiển thị nội dung
        'book-ticket',                   // Post type áp dụng
        'side',                          // Vị trí (cột bên phải)
        'high'                           // Độ ưu tiên
    );
}

function dailyve_display_coupon_info($post)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'ticket_coupon';

    // Truy vấn thông tin coupon từ bảng custom
    $coupon_info = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE ticket_id = %d ORDER BY created_at DESC LIMIT 1",
        $post->ID
    ));

    if ($coupon_info) {
        echo '<div style="padding: 10px 0;">';
        echo '<p><strong>Mã code:</strong> <span style="color: #43a72f; font-weight: bold; font-size: 16px;">' . esc_html($coupon_info->code) . '</span></p>';
        echo '<p><strong>Trạng thái:</strong> ' . ($coupon_info->status === 'completed' ? '<span style="color: blue;">Đã hoàn thành</span>' : '<span style="color: orange;">Đang chờ (Pending)</span>') . '</p>';
        echo '<p><strong>Ngày áp dụng:</strong> ' . esc_html(date('d/m/Y H:i', strtotime($coupon_info->created_at))) . '</p>';
        echo '<hr>';
        echo '<p><a href="' . get_edit_post_link($coupon_info->coupon_id) . '" target="_blank">Xem chi tiết cấu hình mã →</a></p>';
        echo '</div>';
    } else {
        echo '<p>Vé này chưa áp dụng mã giảm giá nào.</p>';
    }
}

/**
 * Tự động chuyển trạng thái Coupon sang Completed khi vé được chuyển sang trạng thái Đã thanh toán (status = 2)
 */
add_action('updated_post_meta', 'dailyve_sync_coupon_status_on_payment', 10, 4);
add_action('added_post_meta', 'dailyve_sync_coupon_status_on_payment', 10, 4);

function dailyve_sync_coupon_status_on_payment($meta_id, $object_id, $meta_key, $meta_value)
{
    // Nếu key là payment_status và giá trị mới là 2 (Đã thanh toán)
    if ($meta_key === 'payment_status' && (int)$meta_value === 2) {
        if (get_post_type($object_id) === 'book-ticket') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'ticket_coupon';

            // Cập nhật trạng thái từ pending sang completed cho vé này
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'completed',
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'ticket_id' => $object_id,
                    'status' => 'pending'
                ),
                array('%s', '%s'),
                array('%d', '%s')
            );
        }
    }
}
