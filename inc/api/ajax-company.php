<?php
// Tách các AJAX functions liên quan đến thông tin nhà xe từ api-functions.php

function handle_get_review_ajax_company()
{

    $partnerName  = isset($_GET['partnerName']) ? sanitize_text_field($_GET['partnerName']) : "";
    $companyId  = isset($_GET['companyId']) ? sanitize_text_field($_GET['companyId']) : "";
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $url = "companies/vexere/$companyId/reviews?page=$page&limit=10";

    if (function_exists('call_api_v2')) {
        $response = call_api_v2($url, 'GET');
        $output = '';
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        } else {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data) && is_array($data) && isset($data['data']['items'])) {
                if (function_exists('generate_dailyve_review_html')) {
                    $output = generate_dailyve_review_html($data['data']['items']);
                }
                $response = [
                    'html' => $output,
                    'total' => $data['data']['total_pages'] ?? 1,
                ];
                echo json_encode($response);
            }
        }
    }
    wp_die();
}
add_action('wp_ajax_get_review_ajax_company', 'handle_get_review_ajax_company');
add_action('wp_ajax_nopriv_get_review_ajax_company', 'handle_get_review_ajax_company');

function handle_get_info_ajax_company()
{
    $partnerId  = isset($_POST['partnerId']) ? sanitize_text_field($_POST['partnerId']) : null;
    $companyId  = isset($_POST['companyId']) ? sanitize_text_field($_POST['companyId']) : null;
    $tripCode   = isset($_POST['tripCode']) ? sanitize_text_field($_POST['tripCode']) : '';
    $from       = isset($_POST['from']) ? sanitize_text_field($_POST['from']) : '';
    $to         = isset($_POST['to']) ? sanitize_text_field($_POST['to']) : '';
    $fare       = isset($_POST['fare']) ? intval($_POST['fare']) : 0;
    $pickupDate = isset($_POST['pickupDate']) ? sanitize_text_field($_POST['pickupDate']) : '';
    $departureTime = isset($_POST['departureTime']) ? sanitize_text_field($_POST['departureTime']) : '';
    $wayId      = isset($_POST['wayId']) ? sanitize_text_field($_POST['wayId']) : '';
    $bookingId  = isset($_POST['bookingId']) ? sanitize_text_field($_POST['bookingId']) : '';

    $responseSeatInfo = null;
    $params   = [];

    if (function_exists('call_api_v2')) {
        if ($partnerId === 'vexere') {
            $params = array(
                "tripId"      => $tripCode,
            );
            $responseSeatInfo  = call_api_v2('/trips/vexere/trip_detail', 'GET', $params);
        } elseif ($partnerId === 'goopay') {
            $params = array(
                "routeId"      => $tripCode,
                "departureTime"   => $departureTime ? str_replace(':', 'h', $departureTime) : '',
                "wayId"       => $wayId,
                "bookingId"  => $bookingId,
            );
            $responseSeatInfo  = call_api_v2('/trips/goopay/trip_detail', 'GET', $params);
        }
    }

    $outputPickUp = '';
    $outputDropOff = '';
    $output = '';

    if (function_exists('call_api_v2')) {
        $response = call_api_v2("companies/vexere/" . $companyId . "/rating", "GET");
        if (is_wp_error($response) || is_wp_error($responseSeatInfo)) {
            wp_send_json_error(is_wp_error($response) ? $response->get_error_message() : $responseSeatInfo->get_error_message());
        } else {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $dataSeatInfo = json_decode(wp_remote_retrieve_body($responseSeatInfo), true);

            if (!empty($data) && is_array($data)) {
                foreach ($data['data']['rating'] as $item) {
                    $width = ((float) $item['rv_main_value'] / 5) * 100;
                    $output .= '<div class="rating-tab__cat">
                                    <div class="rating-tab__cat-name">' . $item['label'] . '</div>
                                    <div class="rating-tab__progress__wrap">
                                        <div class="rating-tab__progress__bar">
                                            <div style="width: ' . $width . '%;" class="rating-tab__progress__bar-fill"></div>
                                        </div>
                                        <div class="rating-tab__progress__txt">' . $item['rv_main_value'] . '</div>
                                    </div>
                                </div>';
                }
                $response = [
                    'listCats' => $output,
                    'data' => $data['data']['overall'],
                ];
            }
            if (!empty($dataSeatInfo) && is_array($dataSeatInfo) && isset($dataSeatInfo['data'])) {
                // -------- PICKUP LIST (GỘP) --------
                $pickup_points = is_array($dataSeatInfo['data']['pickup_points'] ?? null) ? $dataSeatInfo['data']['pickup_points'] : [];
                $pickup_transfer_status = isset($dataSeatInfo['data']['way_id']) ? 1 : (int)(($dataSeatInfo['data']['transfer_enable']) ?? 0);
                $pickup_transfer_points = is_array($dataSeatInfo['data']['transfer_points'] ?? null) ? $dataSeatInfo['data']['transfer_points'] : [];

                $pickup_all_points = [];
                foreach ($pickup_points as $p) {
                    $pickup_all_points[] = $p;
                }

                if ($pickup_transfer_status === 1 && !empty($pickup_transfer_points)) {
                    foreach ($pickup_transfer_points as $p) {
                        $pickup_all_points[] = $p;
                    }
                }

                if (function_exists('compareByRealTime')) {
                    usort($pickup_all_points, 'compareByRealTime');
                }

                foreach ($pickup_all_points as $item) {
                    $time_str = function_exists('getTime') ? getTime($item["real_time"]) : $item["real_time"];
                    $outputPickUp .= '<li> 
                                        <span class="accordion-sub-item__list__time">' . $time_str . '</span>
                                        <span class="accordion-sub-item__list__place">' . $item['name'] . '</span>
                                    </li>';
                }

                // -------- DROPOFF LIST (GỘP) --------
                $dropoff_points = is_array($dataSeatInfo['data']['drop_off_points_at_arrive'] ?? null) ? $dataSeatInfo['data']['drop_off_points_at_arrive'] : [];
                $dropoff_transfer_status = isset($dataSeatInfo['data']['way_id']) ? 1 : (int)($dataSeatInfo['data']['transfer_at_arrive_enable'] ?? 0);
                $dropoff_transfer_points = is_array($dataSeatInfo['data']['transfer_points_at_arrive'] ?? null) ? $dataSeatInfo['data']['transfer_points_at_arrive'] : [];

                $dropoff_all_points = [];
                foreach ($dropoff_points as $p) {
                    $dropoff_all_points[] = $p;
                }

                if ($dropoff_transfer_status === 1 && !empty($dropoff_transfer_points)) {
                    foreach ($dropoff_transfer_points as $p) {
                        $dropoff_all_points[] = $p;
                    }
                }

                if (function_exists('compareByRealTime')) {
                    usort($dropoff_all_points, 'compareByRealTime');
                }

                foreach ($dropoff_all_points as $item) {
                    $time_str = function_exists('getTime') ? getTime($item["real_time"]) : $item["real_time"];
                    $outputDropOff .= '<li> 
                                        <span class="accordion-sub-item__list__time">' . $time_str . '</span>
                                        <span class="accordion-sub-item__list__place">' . $item['name'] . '</span>
                                    </li>';
                }

                $response['pickUpHtml'] = $outputPickUp;
                $response['dropOffHtml'] = $outputDropOff;
            }

            echo json_encode($response);
        }
    }
    wp_die();
}
add_action('wp_ajax_get_info_ajax_company', 'handle_get_info_ajax_company');
add_action('wp_ajax_nopriv_get_info_ajax_company', 'handle_get_info_ajax_company');

function handle_get_images_ajax_company()
{
    $companyId = isset($_GET['companyId']) ? sanitize_text_field($_GET['companyId']) : 0;
    $url = defined('endPoint') ? endPoint . "/api/Raw/Company/Images?companyId=$companyId" : "https://api.vexe.vn/api/Raw/Company/Images?companyId=$companyId";
    $response = wp_remote_get($url);
    $images = [];
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    } else {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data) && is_array($data)) {
            foreach ($data['data'] as $key => $item) {
                $images[$key] = $item['files']['1000x600'];
                if ($key > 10) {
                    break;
                }
            }
            $response = [
                'data' => $images,
            ];
            echo json_encode($response);
        }
    }
    wp_die();
}
add_action('wp_ajax_get_images_ajax_company', 'handle_get_images_ajax_company');
add_action('wp_ajax_nopriv_get_images_ajax_company', 'handle_get_images_ajax_company');
