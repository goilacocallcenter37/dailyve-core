<?php
function bus_booking_shortcode($atts)
{
  $atts = shortcode_atts(array(
    'auto_load' => 'false',
    'show_loader' => 'true',
    'fromId' => '',
    'toId' => ''
  ), $atts);

  $html_output = '';
  $fromId = $atts['fromId'];
  $toId = $atts['toId'];

  if (empty($fromId) || empty($toId)) {
    $departure = get_field('routes_departure_point');
    $destination = get_field('routes_destination_point');
    if ($departure && isset($departure['value'])) $fromId = $departure['value'];
    if ($destination && isset($destination['value'])) $toId = $destination['value'];
  }

  $html_output = '';
  if ($atts['auto_load'] === 'true') {
    $view_data = dailyve_get_bus_booking_view_data([
      'url_params' => $_GET,
      'current_url' => home_url(add_query_arg([], $GLOBALS['wp']->request)),
      'fromId' => $fromId,
      'toId' => $toId
    ]);
    if ($view_data && isset($view_data['html'])) {
      $html_output = $view_data['html'];
      $atts['show_loader'] = 'false'; // Hide loader if we already have data
    }
  }

  ob_start();
?>
  <div id="bus-booking-container" class="bus-booking-wrapper">
    <?php if ($atts['show_loader'] === 'true'): ?>
      <div id="bus-booking-loader" class="bus-booking-loader">
        <div class="loader-content">
          <div class="spinner"></div>
          <p>Đang tải thông tin chuyến xe...</p>
        </div>
      </div>
    <?php endif; ?>

    <div id="bus-booking-content" <?php echo !empty($html_output) ? '' : 'style="display: none;"'; ?>>
      <?php if (!empty($html_output)) echo $html_output; ?>
    </div>

    <?php if ($atts['auto_load'] === 'false'): ?>
      <div id="bus-booking-trigger" class="text-center">
        <button id="load-bus-booking" class="btn btn-primary">
          Xem thông tin chuyến xe
        </button>
      </div>
    <?php endif; ?>
  </div>

  <style>
    .bus-booking-loader {
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 200px;
      padding: 40px;
    }

    .loader-content {
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .bus-booking-wrapper {
      padding-bottom: 30px;
      position: relative;
    }
  </style>

  <script>
    jQuery(document).ready(function($) {
      var autoLoad = <?php echo $atts['auto_load'] === 'true' ? 'true' : 'false'; ?>;
      var searchParams = new URLSearchParams(url.search);
      var startMinutes = 0;
      var endMinutes = 1440;
      var returnDate = searchParams.get('returnDate') ?? null;

      if (autoLoad && $('#bus-booking-content').is(':empty')) {
        loadBusBookingContent();
      }

      function formatTimes(values) {
        const toHHMM = (m) => {
          let h = Math.floor(m / 60);
          let mm = m - h * 60;
          if (h.toString().length === 1) h = '0' + h;
          if (mm.toString().length === 1) mm = '0' + mm;
          if (mm == 0) mm = '00';

          if (h >= 24) {
            h = 23;
            mm = '59';
          }
          return `${h}:${mm}`;
        };
        return [toHHMM(values[0]), toHHMM(values[1])];
      }

      function initSlider() {
        $("#slider-range").slider({
          range: true,
          min: 0,
          max: 1440,
          step: 15,
          values: [startMinutes, endMinutes],

          slide: function(e, ui) {
            const [timeMin, timeMax] = formatTimes(ui.values);
            $('.slider-time').html(timeMin);
            $('.slider-time2').html(timeMax);
          },

          stop: function(e, ui) {
            const [timeMin, timeMax] = formatTimes(ui.values);

            $('.list-search').find('span[data-type="time"]').remove();
            $('.list-search').append(
              '<span data-type="time" data-id="' + timeMin + '-' + timeMax + '">' +
              '<p>' + timeMin + ' - ' + timeMax + '</p>' +
              '<div class="close-item-filter"><i class="fas fa-times"></i></div>' +
              '</span>'
            );

            searchParams.set('time', `${timeMin}-${timeMax}`);
            url.search = searchParams.toString();
            window.history.pushState({}, '', url);

            const data = returnDate ?
              getParamsFilter(searchParams, 1, 0, returnDate) :
              getParamsFilter(searchParams);

            filterRouteAjax(data);
          },

        });
      }

      $('#load-bus-booking').on('click', function() {
        $(this).parent().hide();
        $('#bus-booking-loader').show();
        loadBusBookingContent();
      });

      function loadBusBookingContent() {
        var urlParams = new URLSearchParams(window.location.search);
        var returnDate = urlParams.get("returnDate");
        // console.log(searchData);
        $.ajax({
          url: ajax_object.ajax_url,
          type: 'POST',
          data: {
            action: 'load_bus_booking_content',
            //nonce: ajax_object.nonce,

            url_params: Object.fromEntries(urlParams),
            current_url: window.location.href,
            fromId: searchData?.fromId?.value,
            toId: searchData?.toId?.value
          },
          success: function(response) {
            if (response.success) {
              $('#bus-booking-loader').hide();
              $('#bus-booking-content').html(response.data.html).fadeIn();

              initBusBookingEvents();
            } else {
              $('#bus-booking-loader').hide();
              $('#bus-booking-content').html('<div class="alert alert-danger">Có lỗi xảy ra khi tải dữ liệu.</div>').show();
            }

            if (returnDate) {
              $('#add-return-date .add-return').addClass('hidden');
              $('#add-return-date .date-return').removeClass('hidden');
            }
          },
          error: function() {
            $('#bus-booking-loader').hide();
            $('#bus-booking-content').html('<div class="alert alert-danger">Không thể tải dữ liệu. Vui lòng thử lại.</div>').show();
          }
        });
      }

      function initBusBookingEvents() {
        $("#route-exchange").on("click", function(e) {
          e.preventDefault();
          const fromVal = $("#inputFrom").val();
          const fromId = $("#from").val();
          const fromName = $("#nameFrom").val();
          const toVal = $("#inputTo").val();
          const toId = $("#to").val();
          const toName = $("#nameTo").val();

          $("#inputFrom").val(toVal);
          $("#from").val(toId);
          $("#nameFrom").val(toName);
          $("#inputTo").val(fromVal);
          $("#to").val(fromId);
          $("#nameTo").val(fromName);
        });

        initSlider()

        $(window).scroll(function() {
          var scroll_top = $(window).scrollTop();
          var section_offset = $('.main-vexe-content').offset();
          if (section_offset) {
            var section_height = $('.main-vexe-content').innerHeight();

            if (scroll_top > section_offset.top && scroll_top < (section_height - section_offset.top)) {
              $('.route-fixed-left').addClass('fixed-top');
            } else {
              $('.route-fixed-left').removeClass('fixed-top');
            }
          }
        });

      }
    });
  </script>
<?php

  return ob_get_clean();
}
add_shortcode('bus_booking', 'bus_booking_shortcode');

function dailyve_has_bus_search_query($url_params)
{
  $search_keys = [
    'from',
    'to',
    'date',
    'returnDate',
    'companies',
    'rating',
    'time',
    'sort',
    'islimousine',
    'fa',
    'ta',
    'p',
    'cursor',
  ];

  foreach ($search_keys as $key) {
    if (!isset($url_params[$key])) {
      continue;
    }

    $value = $url_params[$key];
    if (is_array($value)) {
      $value = array_filter($value, static function ($item) {
        return $item !== '' && $item !== null;
      });

      if (!empty($value)) {
        return true;
      }

      continue;
    }

    if ($value !== '' && $value !== null) {
      return true;
    }
  }

  return false;
}

function dailyve_build_route_company_booking_url($from_value, $to_value, $company_value = 0)
{
  $query_args = [
    'from' => $from_value,
    'to' => $to_value,
  ];

  if (!empty($company_value)) {
    $query_args['companies'] = $company_value;
  }

  return home_url('/dat-ve-truc-tuyen/?' . http_build_query($query_args));
}

function dailyve_get_route_companies_data($post_id = null)
{
  if (!$post_id) {
    $post_id = get_the_ID();
  }

  if (!$post_id) {
    return [];
  }

  $bus_companies = get_field('bus_company', $post_id);
  if (empty($bus_companies) || !is_array($bus_companies)) {
    return [];
  }

  $companies_data = [];

  foreach ($bus_companies as $index => $item) {
    $company_post_id = isset($item['company_name']) ? (int) $item['company_name'] : 0;
    $company_title = $company_post_id ? get_the_title($company_post_id) : '';
    $company_permalink = $company_post_id ? get_permalink($company_post_id) : '';
    $company_thumbnail_id = $company_post_id ? get_post_thumbnail_id($company_post_id) : 0;
    $logo_url = $company_thumbnail_id ? wp_get_attachment_url($company_thumbnail_id) : '';

    $departure_point = $item['schedule_departure_point'] ?? [];
    $destination_point = $item['schedule_destination_point'] ?? [];

    $from_value = $departure_point['value'] ?? '';
    $to_value = $destination_point['value'] ?? '';
    $company_field = $company_post_id && function_exists('get_field')
      ? get_field('company_id', $company_post_id)
      : null;
    $company_value = is_array($company_field)
      ? ($company_field['value'] ?? 0)
      : $company_field;

    $route_label_parts = array_filter([
      $departure_point['label'] ?? '',
      $destination_point['label'] ?? '',
    ]);

    $companies_data[] = [
      'index' => $index,
      'company_post_id' => $company_post_id,
      'company_name' => $company_title,
      'company_url' => $company_permalink,
      'logo_url' => $logo_url,
      'route_label' => count($route_label_parts) === 2 ? $route_label_parts[0] . ' đi ' . $route_label_parts[1] : '',
      'route_total' => $item['route_total'] ?? '',
      'schedule_content' => $item['schedule_content'] ?? '',
      'price' => $item['price'] ?? '',
      'phone' => $item['phone'] ?? '1900 0155',
      'booking_url' => ($from_value && $to_value) ? dailyve_build_route_company_booking_url($from_value, $to_value, $company_value) : '',
    ];
  }

  return $companies_data;
}

function dailyve_build_route_statistic_params($args = [])
{
  return array(
    "from" => $args['from'] ?? '',
    "to" => $args['to'] ?? '',
    "date" => $args['date'] ?? date('Y-m-d', strtotime('+1 day')),
    "onlineTicket" => 1,
    "sort" => $args['sort'] ?? 'time:asc',
    "timeMin" => $args['timeMin'] ?? '00:00',
    "timeMax" => $args['timeMax'] ?? '23:59',
  );
}

function dailyve_get_route_statistics_data($paramsStatistic)
{
  $cache_key = 'dailyve_route_statistic_' . md5(serialize($paramsStatistic));
  $cached = get_transient($cache_key);

  if ($cached !== false) {
    return $cached;
  }

  $responseStatistic = call_api_v2('route/filter_statistic', 'GET', $paramsStatistic);
  $dataStatictis = [];

  if (!is_wp_error($responseStatistic) && isset($responseStatistic['body'])) {
    $dataStatictis = json_decode($responseStatistic['body'], true);
    set_transient($cache_key, $dataStatictis, 10 * MINUTE_IN_SECONDS);
  }

  return $dataStatictis;
}

function dailyve_get_bus_booking_view_data($params_input)
{
  $fromId = $params_input['fromId'] ?? '';
  $toId = $params_input['toId'] ?? '';
  $current_url = $params_input['current_url'] ?? '';
  $url_params = $params_input['url_params'] ?? [];

  $from = '';
  $to = '';

  if ($fromId && $toId) {
    $from = $fromId;
    $to = $toId;
  } else {
    $dulieu = parse_url($current_url);
    $path = $dulieu['path'] ?? (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

    // Lấy các tham số từ URL
    $pattern = '/(\d+)t(\d+)/';
    if (preg_match($pattern, $path, $matches)) {
      $from = $matches[1];
      $to = $matches[2];
    }
  }

  $patternId = '/-(\d+)-\d+t\d+\.html/';
  $companyId = 0;
  if (isset($path) && preg_match($patternId, $path, $matches)) {
    $companyId = $matches[1];
  }

  $page = isset($url_params['p']) ? (int) $url_params['p'] : 1;
  $cursor = isset($url_params['cursor']) ? $url_params['cursor'] : '';
  $date = isset($url_params['date']) && !empty($url_params['date'])
    ? $url_params['date']
    : date('d-m-Y', strtotime('+1 day'));

  $companies = isset($url_params['companies']) ? $url_params['companies'] : '';
  $rating = isset($url_params['rating']) ? $url_params['rating'] : '1-5';
  $time = isset($url_params['time']) ? $url_params['time'] : '00:00-23:59';
  $sort = isset($url_params['sort']) ? $url_params['sort'] : 'time:asc';
  $isLimousine = isset($url_params['islimousine']) ? intval($url_params['islimousine']) : '';
  $fromArea = isset($url_params['fa']) ? sanitize_text_field($url_params['fa']) : '';
  $toArea = isset($url_params['ta']) ? sanitize_text_field($url_params['ta']) : '';
  $returnDate = isset($url_params['returnDate']) && !empty($url_params['returnDate']) ? $url_params['returnDate'] : '';
  $has_search_query = dailyve_has_bus_search_query($url_params);
  $arrTime = explode("-", $time);
  $newDateString = date('Y-m-d', strtotime($date));

  if (!$has_search_query) {
    $route_companies = dailyve_get_route_companies_data();
    $paramsStatistic = dailyve_build_route_statistic_params([
      'from' => $from,
      'to' => $to,
      'date' => $newDateString,
      'sort' => $sort,
      'timeMin' => $arrTime[0] ?? '00:00',
      'timeMax' => $arrTime[1] ?? '23:59',
    ]);

    if (!empty($isLimousine)) {
      $paramsStatistic['limousine'] = $isLimousine;
    }

    $dataStatictis = dailyve_get_route_statistics_data($paramsStatistic);

    ob_start();
    include get_stylesheet_directory() . '/template-parts/bus-booking-company-list.php';
    $html_content = ob_get_clean();

    return [
      'html' => $html_content,
      'mode' => 'companies',
      'companies' => $route_companies,
      'statistics' => $dataStatictis,
    ];
  }

  // Generate cache key based on request parameters
  $cache_key = 'bus_booking_' . md5(serialize([
    $from,
    $to,
    $date,
    $cursor,
    $companies,
    $rating,
    $time,
    $sort,
    $fromArea,
    $toArea
  ]));

  $cached_data = get_transient($cache_key);

  if ($cached_data !== false) {
    return $cached_data;
  }

  $arrCompanies = [];
  $arrFromArea = [];
  $arrToArea = [];
  $inputArrFrom = [];
  $inputArrTo = [];

  if (!empty($companies)) {
    $arrCompanies = explode(",", $companies);
  }

  if (!empty($fromArea)) {
    $inputArrFrom = explode(",", $fromArea);
    $arrFromArea = array_map(function ($item) {
      return ["name" => $item];
    }, $inputArrFrom);
  }

  if (!empty($toArea)) {
    $inputArrTo = explode(",", $toArea);
    $arrToArea = array_map(function ($item) {
      return ["name" => $item];
    }, $inputArrTo);
  }

  $arrRating = explode("-", $rating);

  // Gọi API
  $params = array(
    // "cursor" => $cursor,
    // "page" => $page,
    "pageSize" => 20,
    "from" => $from,
    "to" => $to,
    "date" => $newDateString,
    "onlineTicket" => 1,
    'timeMin'        => $arrTime[0] ?? '00:00',
    'timeMax'        => $arrTime[1] ?? '23:59',
    "sort" => $sort,
    //"partner" => "goopay",
  );

  $paramsStatistic = dailyve_build_route_statistic_params([
    'from' => $from,
    'to' => $to,
    'date' => $newDateString,
    'sort' => $sort,
    'timeMin' => $arrTime[0] ?? '00:00',
    'timeMax' => $arrTime[1] ?? '23:59',
  ]);

  if (!empty($companies)) {
    $params['companies'] = $companies;
    $paramsStatistic['companies'] = $companies;
  }
  if (!empty($inputArrFrom)) {
    $params['pickupDistricts'] = implode(',', $inputArrFrom);
    $paramsStatistic['pickupDistricts'] = implode(',', $inputArrFrom);
  }
  if (!empty($inputArrTo)) {
    $params['dropoffDistricts'] = implode(',', $inputArrTo);
    $paramsStatistic['dropoffDistricts'] = implode(',', $inputArrTo);
  }

  if (!empty($isLimousine)) {
    $params['limousine'] = $isLimousine;
    $paramsStatistic['limousine'] = $isLimousine;
  }

  if (!empty($cursor)) {
    $params['cursor'] = $cursor;
    unset($params['page']);
  } else {
    $params['page'] = $page;
  }

  $response = call_api_v2('trips', 'GET', $params);
  $dataStatictis = dailyve_get_route_statistics_data($paramsStatistic);

  if (!is_wp_error($response)) {
    $data = json_decode($response['body'], true);

    ob_start();
    include get_stylesheet_directory() . '/template-parts/bus-booking-content.php';
    $html_content = ob_get_clean();

    $response_data = array(
      'html' => $html_content,
      'data' => $data,
      'statistics' => $dataStatictis
    );

    // Cache the response for 1 hour
    set_transient($cache_key, $response_data, HOUR_IN_SECONDS);

    return $response_data;
  } else {
    return false;
  }
}

function ajax_load_bus_booking_content()
{
  $response_data = dailyve_get_bus_booking_view_data([
    'url_params' => $_POST['url_params'] ?? [],
    'current_url' => $_POST['current_url'] ?? '',
    'fromId' => $_POST['fromId'] ?? '',
    'toId' => $_POST['toId'] ?? ''
  ]);

  if ($response_data) {
    wp_send_json_success($response_data);
  } else {
    wp_send_json_error('Không thể tải dữ liệu từ API');
  }
}
add_action('wp_ajax_load_bus_booking_content', 'ajax_load_bus_booking_content');
add_action('wp_ajax_nopriv_load_bus_booking_content', 'ajax_load_bus_booking_content');

add_action('rest_api_init', function () {
  register_rest_route('api/v1', '/trips', [
    'methods' => WP_REST_Server::READABLE,
    'callback' => 'dailyve_rest_get_trips',
    'permission_callback' => '__return_true',
    'args' => [
      'from' => [
        'required' => true,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'to' => [
        'required' => true,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'date' => [
        'required' => false,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'companies' => [
        'required' => false,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'time' => [
        'required' => false,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'sort' => [
        'required' => false,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'islimousine' => [
        'required' => false,
        'sanitize_callback' => 'sanitize_text_field',
      ],
      'p' => [
        'required' => false,
        'sanitize_callback' => 'absint',
      ],
      'cursor' => [
        'required' => false,
        'sanitize_callback' => 'sanitize_text_field',
      ],
    ],
  ]);
});

function dailyve_rest_normalize_trip_date($date)
{
  $date = trim((string) $date);
  $timestamp = $date !== '' ? strtotime($date) : false;

  if (!$timestamp) {
    $timestamp = current_time('timestamp') + DAY_IN_SECONDS;
  }

  return date('Y-m-d', $timestamp);
}

function dailyve_rest_normalize_time_range($time)
{
  $time = trim((string) $time);
  if (!preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $time)) {
    return ['00:00-23:59', '00:00', '23:59'];
  }

  $parts = explode('-', $time);
  return [$time, $parts[0] ?: '00:00', $parts[1] ?: '23:59'];
}

function dailyve_rest_get_point_filters($raw)
{
  if (function_exists('ams_extract_districts_from_raw')) {
    $districts = ams_extract_districts_from_raw($raw);
  } else {
    $districts = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
  }

  if (function_exists('ams_extract_point_names_from_raw')) {
    $names = ams_extract_point_names_from_raw($raw);
  } else {
    $names = [];
  }

  return [
    'districts' => $districts,
    'names' => $names,
  ];
}

function dailyve_rest_simplify_gallery($gallery)
{
  if (!is_array($gallery)) {
    return [];
  }

  $items = [];
  foreach (array_slice($gallery, 0, 6) as $image) {
    if (!is_array($image)) {
      continue;
    }

    $items[] = [
      'url' => $image['url'] ?? '',
      'title' => $image['title'] ?? '',
      'width' => $image['width'] ?? '',
      'height' => $image['height'] ?? '',
      'medium' => $image['sizes']['medium'] ?? ($image['url'] ?? ''),
    ];
  }

  return $items;
}

function dailyve_rest_enrich_trip_items($items)
{
  if (!is_array($items)) {
    return [];
  }

  $company_data_map = function_exists('ams_get_bulk_company_data') ? ams_get_bulk_company_data($items) : [];
  $fallback_logo = home_url('/wp-content/uploads/assets/images/logo-icon-f2.png');

  foreach ($items as &$item) {
    $company_id = trim((string) ($item['company_id'] ?? ''));
    $company_info = $company_data_map[$company_id] ?? ['thumbnail' => '', 'gallery' => []];

    $item['company_logo'] = !empty($company_info['thumbnail']) ? $company_info['thumbnail'] : $fallback_logo;
    $item['company_gallery'] = dailyve_rest_simplify_gallery($company_info['gallery'] ?? []);
  }
  unset($item);

  return array_values($items);
}

function dailyve_rest_get_trips(WP_REST_Request $request)
{
  $from = sanitize_text_field((string) $request->get_param('from'));
  $to = sanitize_text_field((string) $request->get_param('to'));

  if ($from === '' || $to === '') {
    return new WP_Error('dailyve_missing_route', 'Missing from or to route id.', ['status' => 400]);
  }

  $date = dailyve_rest_normalize_trip_date($request->get_param('date'));
  [$time, $timeMin, $timeMax] = dailyve_rest_normalize_time_range($request->get_param('time') ?: '00:00-23:59');

  $sort = sanitize_text_field((string) ($request->get_param('sort') ?: 'time:asc'));
  $companies = sanitize_text_field((string) ($request->get_param('companies') ?: ''));
  $isLimousine = $request->get_param('islimousine');
  $page = max(1, absint($request->get_param('p') ?: 1));
  $cursor = sanitize_text_field((string) ($request->get_param('cursor') ?: ''));
  $fromAreaRaw = (string) ($request->get_param('fa') ?: $request->get_param('fromarea') ?: '');
  $toAreaRaw = (string) ($request->get_param('ta') ?: $request->get_param('toarea') ?: '');
  $fromArea = dailyve_rest_get_point_filters($fromAreaRaw);
  $toArea = dailyve_rest_get_point_filters($toAreaRaw);

  $params = [
    'pageSize' => 20,
    'from' => $from,
    'to' => $to,
    'date' => $date,
    'onlineTicket' => 1,
    'timeMin' => $timeMin,
    'timeMax' => $timeMax,
    'sort' => $sort,
  ];

  if ($companies !== '') {
    $params['companies'] = $companies;
  }

  if (!empty($fromArea['districts'])) {
    $params['pickupDistricts'] = implode(',', $fromArea['districts']);
  }

  if (!empty($toArea['districts'])) {
    $params['dropoffDistricts'] = implode(',', $toArea['districts']);
  }

  if (!empty($fromArea['names'])) {
    $params['pickupNames'] = implode(',', $fromArea['names']);
  }

  if (!empty($toArea['names'])) {
    $params['dropoffNames'] = implode(',', $toArea['names']);
  }

  if ($isLimousine !== null && $isLimousine !== '') {
    $limousine = absint($isLimousine);
    $params['limousine'] = $limousine;
    $params['isLimousine'] = $limousine === 1;
  }

  if ($cursor !== '') {
    $params['cursor'] = $cursor;
  } else {
    $params['page'] = $page;
  }

  $stats_params = $params;
  unset($stats_params['pageSize'], $stats_params['page'], $stats_params['cursor'], $stats_params['isLimousine']);

  $cache_key = 'dailyve_rest_trips_' . md5(wp_json_encode($params));
  $cached_response = get_transient($cache_key);

  if ($cached_response === false && function_exists('dv_rate_limit')) {
    $action_key = 'dailyve_rest_trips_' . md5($from . '|' . $to . '|' . $date);
    if (!dv_rate_limit($action_key, 5, 8)) {
      return new WP_Error('dailyve_rate_limited', 'Too many requests. Please try again shortly.', ['status' => 429]);
    }
  }

  if ($cached_response !== false) {
    $data = $cached_response;
  } else {
    $response = call_api_v2('trips', 'GET', $params);

    if (is_wp_error($response)) {
      return $response;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
      return new WP_Error('dailyve_invalid_trips_response', 'Invalid trips response.', ['status' => 502]);
    }

    set_transient($cache_key, $data, 15);
  }

  $statistics = dailyve_get_route_statistics_data($stats_params);
  $items = dailyve_rest_enrich_trip_items($data['items'] ?? []);

  return rest_ensure_response([
    'success' => true,
    'data' => [
      'items' => $items,
      'paging' => $data['paging'] ?? [],
      'nextCursor' => $data['nextCursor'] ?? ($data['paging']['nextCursor'] ?? ''),
      'statistics' => $statistics['data'] ?? $statistics,
      'query' => [
        'from' => $from,
        'to' => $to,
        'date' => $date,
        'time' => $time,
        'sort' => $sort,
        'companies' => $companies,
        'islimousine' => $isLimousine,
        'p' => $page,
        'cursor' => $cursor,
      ],
    ],
  ]);
}

// function enqueue_bus_booking_scripts()
// {
//   wp_enqueue_script('jquery');
//   wp_localize_script('jquery', 'ajax_object', array(
//     'ajax_url' => admin_url('admin-ajax.php'),
//     'nonce' => wp_create_nonce('ajax_nonce')
//   ));
// }
// add_action('wp_enqueue_scripts', 'enqueue_bus_booking_scripts');

/**
 * Custom section sitemaps with automatic index + pagination.
 * Examples:
 *  - /ve-xe-khach/tuyen-duong.xml      -> <sitemapindex> if > per_page, else <urlset>
 *  - /ve-xe-khach/tuyen-duong/2.xml    -> <urlset> page 2
 *  - /ve-xe-khach/nha-xe.xml           -> <urlset> (no pagination by default)
 */

add_filter('query_vars', function ($vars) {
  $vars[] = 'dv_section_xml';
  $vars[] = 'dv_section_path';
  $vars[] = 'dv_section_page';
  return $vars;
});

add_action('init', function () {
  add_rewrite_rule(
    '^(.+?)/([0-9]+)\.xml$',
    'index.php?dv_section_xml=1&dv_section_path=$matches[1]&dv_section_page=$matches[2]',
    'top'
  );
  add_rewrite_rule(
    '^(.+?)\.xml$',
    'index.php?dv_section_xml=1&dv_section_path=$matches[1]',
    'top'
  );
});

function dv_sitemap_sections_config()
{
  return [
    've-xe-khach' => [
      'type' => 'index',
      'children_sitemaps' => [
        've-xe-khach/ve-chung-toi',
        've-xe-khach/tuyen-duong',
        've-xe-khach/nha-xe',
        've-xe-khach/ben-xe',
      ],
    ],
    // Ít URL: liệt kê tĩnh
    've-xe-khach/ve-chung-toi' => [
      'type' => 'static',
      'urls' => [
        home_url('/'),
        home_url('/ve-xe-khach/'),
      ],
      'per_page'     => -1,          // không phân trang
      'index_mode'   => false,       // không cần sitemapindex
    ],

    // RẤT NHIỀU URL: bật index_mode + phân trang 5.000
    've-xe-khach/tuyen-duong' => [
      'type'         => 'children',
      'parent'       => 've-xe-khach/tuyen-duong',
      'direct'       => true,        // true: chỉ con trực tiếp; false: mọi cấp
      'per_page'     => 5000,
      'include_parent' => false,
      'index_mode'   => true,        // /tuyen-duong.xml sẽ là <sitemapindex> nếu > per_page
      'priority'     => '0.6',
    ],

    've-xe-khach/nha-xe' => [
      'type'         => 'children',
      'parent'       => 've-xe-khach/nha-xe',
      'direct'       => true,
      'per_page'     => -1,
      'include_parent' => false,
      'index_mode'   => false,
      'priority'     => '0.6',
    ],

    've-xe-khach/ben-xe' => [
      'type'         => 'children',
      'parent'       => 've-xe-khach/ben-xe',
      'direct'       => true,
      'per_page'     => -1,
      'include_parent' => false,
      'index_mode'   => false,
      'priority'     => '0.6',
    ],

    've-may-bay' => [
      'type'         => 'children',
      'parent'       => 've-may-bay',
      'direct'       => true,
      'per_page'     => -1,
      'include_parent' => false,
      'index_mode'   => false,
      'priority'     => '0.5',
    ],

    've-tau-hoa' => [
      'type'         => 'children',
      'parent'       => 've-tau-hoa',
      'direct'       => true,
      'per_page'     => -1,
      'include_parent' => false,
      'index_mode'   => false,
      'priority'     => '0.5',
    ],
  ];
}

/** Helpers */
function dv_get_page_by_path_deep($path)
{
  $path = trim($path, '/');
  $parts = explode('/', $path);
  $slug  = array_pop($parts);

  $parent_id = 0;
  if (!empty($parts)) {
    $parent = get_page_by_path(implode('/', $parts), OBJECT, 'page');
    if ($parent) $parent_id = $parent->ID;
  }
  $pages = get_pages([
    'post_type'   => 'page',
    'post_status' => 'publish',
    'parent'      => $parent_id,
    'hierarchical' => 0,
  ]);
  foreach ($pages as $p) {
    if ($p->post_name === $slug) return $p;
  }
  return get_page_by_path($path, OBJECT, 'page');
}

function dv_get_children_ids($parent_id, $direct = true)
{
  if ($direct) {
    return get_posts([
      'post_type'      => 'page',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'post_parent'    => $parent_id,
      'orderby'        => 'menu_order',
      'order'          => 'ASC',
      'fields'         => 'ids',
    ]);
  }
  $pages = get_pages([
    'post_type'   => 'page',
    'post_status' => 'publish',
    'child_of'    => $parent_id,
    'sort_column' => 'menu_order,post_title',
    'sort_order'  => 'ASC',
  ]);
  return wp_list_pluck($pages, 'ID');
}

function dv_build_page_url($path, $page)
{
  $path = '/' . trim($path, '/');
  return home_url($path . '/' . $page . '.xml');
}


function dv_build_xml_url($path)
{
  $path = '/' . trim($path, '/');
  return home_url($path . '.xml');
}

add_action('template_redirect', function () {
  if (intval(get_query_var('dv_section_xml')) !== 1) return;

  $path     = trim((string) get_query_var('dv_section_path'), '/');
  $page_num = max(0, intval(get_query_var('dv_section_page')));
  $config   = dv_sitemap_sections_config();

  if (!$path || !isset($config[$path])) {
    status_header(404);
    exit;
  }
  $section = $config[$path];

  // Thu thập URL
  $urls = [];

  if ($section['type'] === 'index') {
    nocache_headers();
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $children = isset($section['children_sitemaps']) ? (array) $section['children_sitemaps'] : [];
    foreach ($children as $child_path) {
      $loc = function_exists('dv_build_xml_url')
        ? dv_build_xml_url($child_path)
        : home_url('/' . trim($child_path, '/') . '.xml');

      echo "  <sitemap>\n";
      echo "    <loc>{$loc}</loc>\n";
      echo "    <lastmod>" . date('c') . "</lastmod>\n";
      echo "  </sitemap>\n";
    }

    echo "</sitemapindex>";
    exit;
  }

  if ($section['type'] === 'static') {
    foreach ($section['urls'] as $u) {
      $urls[] = [
        'loc'     => esc_url($u),
        'lastmod' => date('c'),
        'priority' => '0.5',
      ];
    }
  } elseif ($section['type'] === 'children') {
    $parent_page = dv_get_page_by_path_deep($section['parent']);
    if ($parent_page) {
      if (!empty($section['include_parent'])) {
        $urls[] = [
          'loc'     => get_permalink($parent_page),
          'lastmod' => get_post_modified_time('c', true, $parent_page),
          'priority' => '0.8',
        ];
      }
      $ids = dv_get_children_ids($parent_page->ID, !empty($section['direct']));
      foreach ($ids as $id) {
        $urls[] = [
          'loc'     => get_permalink($id),
          'lastmod' => get_post_modified_time('c', true, $id),
          'priority' => isset($section['priority']) ? $section['priority'] : '0.6',
        ];
      }
    }
  }

  $per_page    = isset($section['per_page']) ? intval($section['per_page']) : -1;
  $index_mode  = !empty($section['index_mode']);
  $total       = count($urls);
  $total_pages = ($per_page > 0) ? max(1, (int)ceil($total / $per_page)) : 1;

  nocache_headers();
  header('Content-Type: application/xml; charset=UTF-8');
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

  // Trường hợp cần INDEX: /section.xml → <sitemapindex>, còn /section/{n}.xml → <urlset>
  if ($index_mode && $per_page > 0 && $total_pages > 1 && $page_num === 0) {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    for ($i = 1; $i <= $total_pages; $i++) {
      $loc = dv_build_page_url($path, $i);
      echo "  <sitemap>\n";
      echo "    <loc>{$loc}</loc>\n";
      echo "    <lastmod>" . date('c') . "</lastmod>\n";
      echo "  </sitemap>\n";
    }
    echo "</sitemapindex>";
    exit;
  }

  // Còn lại: xuất URLSET (1 trang hoặc không bật index_mode)
  $page_num = ($page_num === 0) ? 1 : $page_num;
  if ($per_page > 0) {
    $offset = ($page_num - 1) * $per_page;
    $urls   = array_slice($urls, $offset, $per_page);
  }

  echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
  foreach ($urls as $u) {
    echo "  <url>\n";
    echo "    <loc>{$u['loc']}</loc>\n";
    if (!empty($u['lastmod']))   echo "    <lastmod>{$u['lastmod']}</lastmod>\n";
    if (!empty($u['priority']))  echo "    <priority>{$u['priority']}</priority>\n";
    echo "  </url>\n";
  }
  echo "</urlset>";
  exit;
});

// add_filter( 'rank_math/sitemap/enable_caching', '__return_false' );

// Chèn sitemap tùy biến vào sitemap_index.xml của Rank Math
// add_filter( 'rank_math/sitemap/index', function( $xml ) {
//     $custom = [
//         home_url( '/ve-xe-khach.xml' ),
//         home_url( '/ve-tau-hoa.xml' ),
//         home_url( '/ve-may-bay.xml' ),
//     ];

//     foreach ( $custom as $loc ) {
//         $xml .= "\n<sitemap>\n" .
//                 '  <loc>' . esc_url( $loc ) . '</loc>' . "\n" .
//                 '  <lastmod>' . esc_html( date( 'c' ) ) . '</lastmod>' . "\n" .
//                 "</sitemap>\n";
//     }

//     return $xml;
// }, 999 );



/**
 * Cronjob check ticket status
 * Tự động kiểm tra trạng thái vé chưa thanh toán (status = 1) mỗi 5 phút.
 */
add_filter('cron_schedules', 'ams_add_cron_intervals');
function ams_add_cron_intervals($schedules)
{
  if (!isset($schedules['every_five_minutes'])) {
    $schedules['every_five_minutes'] = array(
      'interval' => 300,
      'display'  => __('Every 5 Minutes')
    );
  }
  return $schedules;
}

if (!wp_next_scheduled('ams_cron_check_ticket_status')) {
  wp_schedule_event(time(), 'every_five_minutes', 'ams_cron_check_ticket_status');
}

add_action('ams_cron_check_ticket_status', 'ams_execute_ticket_status_check');
function ams_execute_ticket_status_check()
{
  $args = array(
    'post_type'      => 'book-ticket',
    'posts_per_page' => 50, // Giới hạn số lượng mỗi lần chạy để tránh quá tải
    'meta_query'     => array(
      array(
        'key'     => 'payment_status',
        'value'   => 1,
        'compare' => '='
      ),
      array(
        'key'     => 'partner_id',
        'value'   => 'vexere',
        'compare' => '='
      )
    ),
    'date_query'     => array(
      array(
        'after' => '24 hours ago', // Chỉ kiểm tra các vé được tạo trong vòng 24h qua
      ),
    ),
  );

  $query = new WP_Query($args);
  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $post_id = get_the_ID();
      $partner = get_post_meta($post_id, 'partner_id', true);
      $booking_code = get_the_title($post_id);

      if (empty($booking_code) || empty($partner)) continue;

      // Lấy mã booking đầu tiên nếu có nhiều mã cách nhau bằng dấu cách
      $code_arr = explode(' ', trim($booking_code));
      $first_code = $code_arr[0];

      /**
       * Đồng nhất sử dụng API v2 cho tất cả đối tác (Vexere)
       * Trạng thái từ Vexere API sẽ được ánh xạ: PAID -> 2, REFUNDED -> 5, CANCELED -> 3, PENDING -> 1.
       */
      $response = call_api_v2("booking/{$first_code}?partner={$partner}", 'GET');
      if (!is_wp_error($response)) {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data) && isset($data['status'])) {
          $remote_status = strtoupper($data['status']);
          $statusDescription = strtoupper($data['statusDescription']);

          if ($remote_status === 'PAID') {
            update_post_meta($post_id, 'payment_status', 2);
            if (isset($data['departureTime'])) {
              update_post_meta($post_id, 'pickup_date', $data['departureTime']);
            }
          } elseif ($remote_status === 'REFUNDED' && $statusDescription != 'CANCELED') {
            update_post_meta($post_id, 'payment_status', 5);
          } elseif ($remote_status === 'REFUNDED' && $statusDescription === 'CANCELED') {
            update_post_meta($post_id, 'payment_status', 3);
          } elseif ($remote_status === 'PENDING') {
            update_post_meta($post_id, 'payment_status', 1);
          }
        }
      }
    }
    wp_reset_postdata();
  }
}
?>
