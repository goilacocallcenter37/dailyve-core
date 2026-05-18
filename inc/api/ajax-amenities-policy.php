<?php
function handle_get_bus_amenities()
{
    $trip_code        = isset($_GET['trip_code']) ? sanitize_text_field($_GET['trip_code']) : '';
    $partner_id       = isset($_GET['partnerId']) ? sanitize_text_field($_GET['partnerId']) : '';
    $seat_template_id = isset($_GET['seat_template_id']) ? intval($_GET['seat_template_id']) : 0;
    $company_id       = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

    if (empty($seat_template_id) || empty($company_id)) {
        wp_send_json_error('Thiếu tham số seat_template_id / company_id');
    }

    $url  = "/companies/vexere/" . $company_id . "/utility?seat_template_id=" . $seat_template_id;

    $response = call_api_v2($url, 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (
        empty($data) ||
        !isset($data['data']) ||
        !is_array($data['data'])
    ) {
        wp_send_json_error('Không có dữ liệu tiện ích từ API. Raw: ' . json_encode($data));
    }

    $amenities = $data['data'];

    $result = array_map(function ($item) {
        $iconUrl = isset($item['icon_url']) ? $item['icon_url'] : '';

        if (!empty($iconUrl) && !preg_match('/^https?:\/\//', $iconUrl)) {
            $iconUrl = 'https://' . ltrim($iconUrl, '/');
        }

        return [
            'id'          => isset($item['id']) ? $item['id'] : '',
            'name'        => isset($item['name']) ? $item['name'] : '',
            'description' => isset($item['description']) ? $item['description'] : '',
            'icon_url'    => $iconUrl,
        ];
    }, $amenities);

    wp_send_json_success($result);
}
add_action('wp_ajax_get_bus_amenities', 'handle_get_bus_amenities');
add_action('wp_ajax_nopriv_get_bus_amenities', 'handle_get_bus_amenities');


function mapData($originalData, $newStructure)
{
    foreach ($newStructure['data'][0]['groups'] as $group) {
        foreach ($originalData['data'] as &$dataGroup) {
            if ($dataGroup['id'] == $group['id']) {
                foreach ($group['details'] as $detail) {
                    foreach ($dataGroup['details'] as &$originalDetail) {
                        if ($originalDetail['id'] == $detail['id']) {
                            if (strpos($originalDetail['title'], '{{time}}') != false && isset($detail['value']['time'])) {
                                $originalDetail['title'] = str_replace('{{time}}', $detail['value']['time'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{time1}}') != false && isset($detail['value']['time1'])) {
                                $originalDetail['title'] = str_replace('{{time1}}', $detail['value']['time1'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{time2}}') != false && isset($detail['value']['time2'])) {
                                $originalDetail['title'] = str_replace('{{time2}}', $detail['value']['time2'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{weight}}') != false && isset($detail['value']['weight'])) {
                                $originalDetail['title'] = str_replace('{{weight}}', $detail['value']['weight'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{age}}') != false && isset($detail['value']['age'])) {
                                $originalDetail['title'] = str_replace('{{age}}', $detail['value']['age'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{amount}}') != false && isset($detail['value']['amount'])) {
                                $originalDetail['title'] = str_replace('{{amount}}', $detail['value']['amount'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{height}}') != false && isset($detail['value']['height'])) {
                                $originalDetail['title'] = str_replace('{{height}}', $detail['value']['height'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{percentage}}') != false && isset($detail['value']['percentage'])) {
                                $originalDetail['title'] = str_replace('{{percentage}}', $detail['value']['percentage'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], '{{number_of}}') != false && isset($detail['value']['number_of'])) {
                                $originalDetail['title'] = str_replace('{{number_of}}', $detail['value']['number_of'], $originalDetail['title']);
                            }
                            if (strpos($originalDetail['title'], 'VeXeRe') != false) {
                                $originalDetail['title'] = str_replace('VeXeRe', 'Dailyve <a href="tel:19000155" title="Hotline" style="font-weight: 500;">1900 0155</a>', $originalDetail['title']);
                            }
                            $originalDetail['status'] = $detail['status'];
                        }
                    }
                }
            }
        }
    }
    return $originalData;
}
function handle_get_policy_mapping()
{
    $tripCode         = isset($_GET['tripCode'])         ? sanitize_text_field($_GET['tripCode']) : '';
    $seat_template_id = isset($_GET['seat_template_id']) ? sanitize_text_field($_GET['seat_template_id']) : '';
    $partner_id       = isset($_GET['partnerId'])        ? sanitize_text_field($_GET['partnerId'])        : '';
    $company_id       = isset($_GET['company_id'])       ? sanitize_text_field($_GET['company_id'])       : '';

    if ($partner_id === 'vexere') {
        $url = "companies/vexere/policy?seat_template_id=" . $seat_template_id . "&trip_code=" . $tripCode;
    } else {
        $url = "companies/futa/policy";
    }


    $response = call_api_v2($url, 'GET');
    $output = '';

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    } else {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $policy) {
                $output .= '<div class="policy-group-container">';

                $policy_name = isset($policy['name']) ? $policy['name'] : '';
                $output .= '<p class="policy-group-title"><strong>' . esc_html($policy_name) . '</strong></p>';
                $output .= '<ul>';

                foreach ($policy['details'] as $item) {
                    if (empty($item['title'])) {
                        continue;
                    }

                    $output .= '<li>
                        <p class="policy-option">' . esc_html($item['title']) . '</p>
                    </li>';
                }

                $output .= '</ul></div>';
            }
        }

        echo $output;
    }

    wp_die();
}

add_action('wp_ajax_get_policy_mapping', 'handle_get_policy_mapping');
add_action('wp_ajax_nopriv_get_policy_mapping', 'handle_get_policy_mapping');

function handle_get_cancellation_policy()
{
    $partnerId     = isset($_GET['partnerId']) ? sanitize_text_field($_GET['partnerId']) : '';
    $trip_code     = isset($_GET['tripCode']) ? sanitize_text_field($_GET['tripCode']) : '';
    $searchKeyword = isset($_GET['searchKeyword']) ? sanitize_text_field($_GET['searchKeyword']) : '';
    $companyId     = isset($_GET['companyId']) ? sanitize_text_field($_GET['companyId']) : '';
    $departureDate = isset($_GET['departureDate']) ? sanitize_text_field($_GET['departureDate']) : '';

    if (empty($partnerId) || empty($trip_code)) {
        wp_send_json_error('Thiếu partnerId hoặc trip_code');
    }

    $url  = "companies/vexere/cancel-policy?trip_code=" . $trip_code;

    $response = call_api_v2($url, 'GET');

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }

    $raw = json_decode(wp_remote_retrieve_body($response), true);

    if (
        empty($raw['data'])
    ) {
        echo '';
        wp_die();
    }

    $policy  = $raw['data'];
    $details = !empty($policy['detail']) && is_array($policy['detail']) ? $policy['detail'] : [];
    $note    = isset($policy['note']) ? $policy['note'] : '';

    if (empty($details)) {
        echo '';
        wp_die();
    }

    $tripTs = 0;

    if (!empty($departureDate)) {
        $tripTs = strtotime($departureDate);
        if ($tripTs === false) {
            $tripTs = 0;
        }
    }

    // fallback sang trip_date trong API nếu departureDate rỗng / lỗi
    if (!$tripTs) {
        $tripDateStr = isset($policy['trip_date']) ? $policy['trip_date'] : '';
        if (!empty($tripDateStr)) {
            $tripTs = strtotime($tripDateStr);
            if ($tripTs === false) {
                $tripTs = 0;
            }
        }
    }

    // sort theo from_minutes tăng dần (gần giờ chạy hơn sẽ ở dưới)
    usort($details, function ($a, $b) {
        $aFrom = isset($a['from_minutes']) ? (int) $a['from_minutes'] : 0;
        $bFrom = isset($b['from_minutes']) ? (int) $b['from_minutes'] : 0;
        return $aFrom <=> $bFrom;
    });

    $format_fee_text = function ($detail) {
        $fee        = isset($detail['fee']) ? (float) $detail['fee'] : 0;
        $currency   = isset($detail['currency']) ? (string) $detail['currency'] : '1';
        // $cancelable = isset($detail['cancelable']) ? (bool) $detail['cancelable'] : true;
        // $disable    = isset($detail['disable_cancel']) ? (bool) $detail['disable_cancel'] : false;

        // if ($disable || !$cancelable) {
        //     return 'Không được huỷ';
        // }

        if ($fee <= 0) {
            return 'Miễn phí';
        }

        if ($currency === '1') {
            return $fee . '%';
        }

        return number_format($fee, 0, ',', '.') . 'đ';
    };

    $get_status_class = function ($detail) {
        $fee        = isset($detail['fee']) ? (float) $detail['fee'] : 0;
        $cancelable = isset($detail['cancelable']) ? (bool) $detail['cancelable'] : true;
        $disable    = isset($detail['disable_cancel']) ? (bool) $detail['disable_cancel'] : false;

        if ($disable || !$cancelable || $fee >= 100) {
            return 'is-red';
        }

        if ($fee <= 0) {
            return 'is-green';
        }

        return 'is-yellow';
    };

    $currentFeeText = '';
    $currentClass   = 'is-green';

    if ($tripTs) {
        $nowTs = current_time('timestamp', 7);
        $diffMinutes = floor(($tripTs - $nowTs) / 60);

        if ($diffMinutes < 0) {
            $diffMinutes = 0;
        }

        foreach ($details as $detail) {
            $fromMin = isset($detail['from_minutes']) ? (int) $detail['from_minutes'] : 0;
            $toMin   = isset($detail['to_minutes']) ? (int) $detail['to_minutes'] : 0;

            // inclusive range: from <= diff <= to
            // nếu to = 0 hoặc null => không giới hạn trên
            $inRange = false;

            if ($toMin > 0) {
                $inRange = ($diffMinutes >= $fromMin && $diffMinutes <= $toMin);
            } else {
                $inRange = ($diffMinutes >= $fromMin);
            }

            if ($inRange) {
                $currentFeeText = $format_fee_text($detail);
                $currentClass   = $get_status_class($detail);
                break;
            }
        }
    }

    if ($currentFeeText === '' && !empty($details)) {
        $currentFeeText = $format_fee_text($details[0]);
        $currentClass   = $get_status_class($details[0]);
    }

    $output  = '<div class="cancellation-policy-card">';
    $output .= '  <div class="cancellation-policy-card__header">';
    $output .= '      <span class="cancellation-policy-card__header-time">Thời gian hủy</span>';
    $output .= '      <span class="cancellation-policy-card__header-fee">Phí hủy</span>';
    $output .= '  </div>';

    if ($currentFeeText !== '') {
        $output .= '<div class="cancellation-policy-card__row cancellation-policy-card__row--current ' . esc_attr($currentClass) . '">';
        $output .= '  <div class="cancellation-policy-card__row-time">';
        $output .= '      <span class="cancellation-policy-card__row-dot"></span>';
        $output .= '      <span class="cancellation-policy-card__row-time-text">Hiện tại</span>';
        $output .= '  </div>';
        $output .= '  <div class="cancellation-policy-card__row-fee">' . esc_html($currentFeeText) . '</div>';
        $output .= '</div>';
    }

    foreach ($details as $index => $detail) {
        $feeText     = $format_fee_text($detail);
        $statusClass = $get_status_class($detail);

        $fromMin = isset($detail['from_minutes']) ? (int) $detail['from_minutes'] : 0;
        $toMin   = isset($detail['to_minutes']) ? (int) $detail['to_minutes'] : 0;

        $boundaryTs   = ($tripTs && $fromMin > 0) ? $tripTs - $fromMin * 60 : 0;
        $toBoundaryTs = ($tripTs && $toMin > 0)   ? $tripTs - $toMin * 60   : 0;

        $timeLabel = '';

        if ($boundaryTs) {
            $timeLabel = date('H:i • d/m/Y', $boundaryTs);
        } elseif ($toBoundaryTs) {
            $timeLabel = date('H:i • d/m/Y', $toBoundaryTs);
        }

        if (!empty($detail['min_tickets']) && !empty($detail['max_tickets']) && ($boundaryTs || $toBoundaryTs)) {
            $timeLabel .= ' áp dụng cho đặt vé từ ' . $detail['min_tickets'] . ' đến ' . $detail['max_tickets'] . ' vé';
        }

        if ($fromMin > 0 && $toMin == 0 && $timeLabel !== '') {
            $timeLabel = 'Trước ' . $timeLabel;
        }

        if ($fromMin == 0 && $toMin > 0 && $timeLabel !== '') {
            $timeLabel = 'Từ ' . $timeLabel;
        }

        $output .= '<div class="cancellation-policy-card__row ' . esc_attr($statusClass) . '">';
        $output .= '  <div class="cancellation-policy-card__row-time">';
        $output .= '      <span class="cancellation-policy-card__row-dot"></span>';
        $output .= '      <span class="cancellation-policy-card__row-time-text">' . esc_html($timeLabel) . '</span>';
        $output .= '  </div>';
        $output .= '  <div class="cancellation-policy-card__row-fee">' . esc_html($feeText) . '</div>';
        $output .= '</div>';
    }

    $output .= '</div>';

    if (!empty($note)) {
        $output .= '<div class="cancellation-policy-card__note">';
        $output .= '  <i class="fas fa-exclamation-triangle"></i>';
        $output .= '  <span>' . esc_html($note) . '</span>';
        $output .= '</div>';
    }

    echo $output;
    wp_die();
}

add_action('wp_ajax_get_cancellation_policy', 'handle_get_cancellation_policy');
add_action('wp_ajax_nopriv_get_cancellation_policy', 'handle_get_cancellation_policy');
