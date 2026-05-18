<?php

// include 'province-list.php';

add_shortcode('bmd_search', 'bmd_search_function');

function bmd_search_function()
{

    $content = null;

    ob_start(); ?>

    <div class="bmd-search-form-wrap">

        <div id="Info" class="w-100" style="color: #1867aa; display: none;">
            <div class="pb3 tc-l tl f3 w-100 ttn"><b id="fromName"></b> Đến <b id="toName"></b></div>
        </div>

        <form class="bmd-search-form <?php //if(isset($_SESSION['collaborator']) && !is_page('danh-sach-yeu-cau-dat-ve')) : echo 'collab-hidden'; endif; 
                                        ?>" action="<?php bloginfo('wpurl'); ?>/dat-ve-truc-tuyen" autocomplete="off">
            <?php if (isset($_SESSION['collaborator'])) : ?>
                <input name="bookingrequestid" type="hidden">
                <input name="collab-guest-name" type="hidden">
                <input name="collab-guest-phone" type="hidden">
            <?php endif; ?>
            <div class="bmd-search-item bmd-search-point bmd-search-depart-point">
                <div class="bmd-search-item__label">Điểm Khởi Hành</div>
                <input id="inputFrom" type="text" name="inputFrom" placeholder="Chọn Điểm Khởi Hành">
                <input id="from" style="margin-top: 20px;" name="from" type="hidden" value="" placeholder="Country" />
                <input id="nameFrom" name="nameFrom" type="hidden" placeholder="Country">
            </div>
            <div class="bmd-search-item bmd-search-swap-location">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <div class="bmd-search-item bmd-search-point">
                <div class="bmd-search-item__label">Điểm Đến</div>
                <input id="inputTo" type="text" name="inputTo" placeholder="Chọn Điểm Đến">
                <input id="to" name="to" type="hidden" value="" placeholder="Country" />
                <input id="nameTo" name="nameTo" type="hidden" placeholder="Country">
            </div>
            <div class="bmd-search-item bmd-search-date bmd-search-depart-date">
                <div class="bmd-search-item__label">Ngày Khởi Hành</div>
                <input id="datepicker" type="text" name="date" value="<?php echo date('d-m-Y'); ?>" placeholder="Chọn Ngày Đi">
            </div>
            <div class="bmd-search-item bmd-search-date bmd-search-return-date">
                <div class="bmd-search-item__label">Ngày Về</div>
                <input id="datepickerReturn" type="text" name="returnDate" placeholder="Tuỳ Chọn">
            </div>
            <div class="bmd-search-item bmd-search-btn-wrap">
                <button class="bmd-search-btn"><i class="icon-search"></i> Tìm Chuyến Xe</button>
            </div>
        </form>

    </div>

<?php $content = ob_get_clean();
    return $content;
}


add_shortcode('bmd_old_search_form', 'bmd_old_search_form');

function bmd_old_search_form()
{
    $content = null;
    ob_start(); ?>

    <script>
        jQuery(document).ready(function($) {
            $('#today').on('click', function() {
                $('#datepicker').datepicker({
                    dateFormat: 'dd-mm-yy',
                    showOtherMonths: true,
                    selectOtherMonths: true,
                    minDate: dateToday
                }).datepicker('setDate', '0');
                if (document.getElementById('from').value == '') {
                    document.getElementById('inputFrom').focus();
                } else if (document.getElementById('from').value != '' && document.getElementById('to').value == '') {
                    document.getElementById('inputTo').focus();
                } else if (document.getElementById('from').value != '' && document.getElementById('to').value != '') {
                    window.location.href = `/dat-ve-truc-tuyen/?from=${document.getElementById('from').value}&to=${document.getElementById('to').value}&date=${document.getElementById('datepicker').value}`;
                }
            });
            $('#tomorrow').on('click', function() {
                $('#datepicker').datepicker({
                    dateFormat: 'dd-mm-yy',
                    showOtherMonths: true,
                    selectOtherMonths: true,
                    minDate: dateToday
                }).datepicker('setDate', '1');
                if (document.getElementById('from').value == '') {
                    document.getElementById('inputFrom').focus();
                } else if (document.getElementById('from').value != '' && document.getElementById('to').value == '') {
                    document.getElementById('inputTo').focus();
                } else if (document.getElementById('from').value != '' && document.getElementById('to').value != '') {
                    window.location.href = `/dat-ve-truc-tuyen/?from=${document.getElementById('from').value}&to=${document.getElementById('to').value}&date=${document.getElementById('datepicker').value}`;
                }
            });
            <?php if (wp_get_post_parent_id(get_the_ID()) == 15738) { ?>
                var fromId = searchData ? searchData?.fromId?.value : null;
                var toId = searchData ? searchData?.toId?.value : null;

                if (fromId) {
                    document.getElementById('from').value = fromId;
                    // Find the name corresponding to fromId from the data array
                    var fromItem = data.find(item => item.id === fromId);

                    if (fromItem) {
                        document.getElementById('inputFrom').value = fromItem.name;
                        document.getElementById('nameFrom').value = fromItem.name;
                        document.getElementById('fromName').innerHTML = fromItem.name;
                    }
                }
                if (toId) {
                    document.getElementById('to').value = toId;
                    // Find the name corresponding to toId from the data array
                    var toItem = data.find(item => item.id === toId);
                    if (toItem) {
                        document.getElementById('inputTo').value = toItem.name;
                        document.getElementById('nameTo').value = toItem.name;
                        document.getElementById('toName').innerHTML = toItem.name;
                    }
                }
            <?php } ?>
        });
    </script>

    <style>
        .autocomplete {
            display: flex;
        }

        .autocomplete-items-from {
            border: none !important;
        }

        /* #ui-datepicker-div {
            display: none !important;
        } */
    </style>

    <div id="Info" class="w-100" style="color: #1867aa; display: none;">
        <div class="pb3 tc-l tl f3 w-100 ttn"><b id="fromName"></b> Đến <b id="toName"></b></div>
    </div>

    <div class="w-100 tl">
        <form id="searchForm" class=" w-100" action="/dat-ve-truc-tuyen" autocomplete="off">
            <div class="vxr-widget__wrapper autocomplete cf w-100 flex flex-wrap justify-center items-center" style="border-radius: 6px;">
                <div class="w-100 w-20 relative item-search">
                    <div class="relative row-search">
                        <img class="img-form-search" src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/assets/images/circle.png" alt="Điểm Khởi Hành" />
                        <div class="col-search">
                            <label>Điểm Khởi Hành</label>
                            <input id="inputFrom" class="input-search-form w-100" type="text" placeholder="Chọn Điểm Khởi Hành" />
                        </div>
                    </div>
                    <input id="from" style="margin-top: 20px;" name="from" type="hidden" value="" placeholder="Country" />
                    <input id="nameFrom" name="nameFrom" type="hidden" placeholder="Country" />
                    <div id="route-exchange-wrapper">
                        <div id="route-exchange">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                    </div>
                </div>
                <div class="w-100 w-20 relative item-search">
                    <div class="relative row-search">
                        <img class="img-form-search" src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/assets/images/circle2.png" alt="Điểm Đến" />
                        <div class="col-search">
                            <label>Điểm Đến</label>
                            <input id="inputTo" class="input-search-form w-100" type="text" placeholder="Chọn Điểm Đến" />
                        </div>
                    </div>
                    <input id="nameTo" name="nameTo" type="hidden" placeholder="Country" />
                    <input id="to" name="to" type="hidden" value="" placeholder="Country" />
                </div>
                <div class="w-100 w-20 relative item-search">
                    <div class="relative row-search">
                        <img class="img-form-search" src="https://object.dailyve.com/dailyve/wp-content/uploads/2025/08/calendar.png" style="width: 32px; height: 32px;" alt="Ngày Khởi Hành" />
                        <div class="col-search">
                            <label>Ngày Khởi Hành</label>
                            <input id="datepicker" class="input-search-form w-100" name="date" type="text" placeholder="Chọn ngày đi" />
                        </div>
                    </div>
                </div>
                <div class="w-100 w-20 relative item-search" id="add-return-date">
                    <label for="datepickerReturn" class="relative row-search add-return" style="margin-bottom: 0;">
                        <div>
                            <i class="fas fa-plus"></i>
                        </div>
                        <p style="margin-bottom: 0;">Thêm ngày về</p>
                    </label>
                    <div class="relative row-search date-return hidden">
                        <img class="img-form-search" src="https://object.dailyve.com/dailyve/wp-content/uploads/2025/08/calendar.png" style="width: 32px; height: 32px;" alt="Ngày Về" />
                        <div class="col-search">
                            <label>Ngày Về</label>
                            <input id="datepickerReturn" class="input-search-form w-100" name="returnDateTemp" type="text" placeholder="Chọn ngày về" />
                        </div>
                        <div class="close-add-return">
                            <i class="fas fa-times-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="relative dim vxr-search-button item-search">
                    <button class="w-100 pl3 mb0-l flex items-center vxr-widget__child vxr-widget__button vxr-widget__button–search" type="submit" value="Tìm Kiếm Vé">
                        <i class="vxr-widget__indicator vxr-widget__indicator--bus icon-search" style="font-size: 1.4rem;"></i>
                        <span style="padding-left: 0.125em;">TÌM CHUYẾN XE</span></button>
                </div>
            </div>
        </form>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $("#route-exchange").on("click", function(a) {
                a.preventDefault();
                const o = $("#inputFrom").val(),
                    l = $("#from").val(),
                    n = $("#nameFrom").val(),
                    v = $("#inputTo").val(),
                    t = $("#to").val(),
                    e = $("#nameTo").val();
                $("#inputFrom").val(v), $("#from").val(t), $("#nameFrom").val(e), $("#inputTo").val(o), $("#to").val(l), $("#nameTo").val(n);
            });
        });
    </script>

<?php $content = ob_get_clean();

    return $content;
}


add_shortcode('bmd_featured', 'bmd_featured_function');

function bmd_featured_function()
{

    $content = null;

    ob_start(); ?>

    <div class="bmd-featured">
        <div class="bmd-featured-item">
            <div class="bmd-featured-item__icon">
                <img src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/images/profit-growth.png" alt="Đã bán hơn 1 triệu vé">
            </div>
            <div class="bmd-featured-item__txt">
                Đã bán hơn 1 triệu vé
            </div>
        </div>
        <div class="bmd-featured-item">
            <div class="bmd-featured-item__icon">
                <img src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/images/24-hours-service.png" alt="Luôn luôn hỗ trợ 24/7">
            </div>
            <div class="bmd-featured-item__txt">
                Luôn luôn hỗ trợ 24/7
            </div>
        </div>
        <div class="bmd-featured-item">
            <div class="bmd-featured-item__icon">
                <img src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/images/quality.png" alt="Hơn 93% khách hàng hài lòng">
            </div>
            <div class="bmd-featured-item__txt">
                Hơn 93% khách hàng hài lòng
            </div>
        </div>
    </div>

    <?php $content = ob_get_clean();
    return $content;
}


add_action('wp_ajax_bmd_get_overall_reviews', 'bmd_get_overall_reviews');
add_action('wp_ajax_nopriv_bmd_get_overall_reviews', 'bmd_get_overall_reviews');

function bmd_get_overall_reviews()
{

    $company_id = $_GET['company_id'];
    $response = call_api_with_token_agent(endPoint . '/Api/Company/Info?companyId=' . $company_id, 'GET');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $count = 1;
    $content = null;

    ob_start();

    foreach ($data['data']['rating'] as $item) :
        if ($count <= 4) : ?>

            <div class="bmd-review-ticket__rating-overall-item">
                <span class="bmd-review-ticket__rating-overall-item-label"><?php echo $item['label']; ?></span>
                <span class="bmd-review-ticket__rating-overall-item-val"><?php echo $item['rvMainValue']; ?></span>
            </div>

    <?php else :
            break;
        endif;
        $count++;
    endforeach;

    $content = ob_get_clean();

    wp_send_json_success($content);
}

add_action('wp_ajax_bmd_get_reviews', 'bmd_get_reviews');
add_action('wp_ajax_nopriv_bmd_get_reviews', 'bmd_get_reviews');

function bmd_get_reviews()
{

    $company_id = $_GET['company_id'];
    $page = $_GET['page'];

    $url = endPoint . "/Api/Company/Reviews?companyId=$company_id&page=$page&pageSize=3&ratingMin=1&ratingMax=5";
    $response = call_api_with_token_agent($url, 'GET');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = null;

    ob_start(); ?>

    <div class="bmd-review-ticket__comments">

        <?php foreach ($data['data']['items'] as $item) :
            $dateString = !empty($item['tripDate']) ? date('d-m-Y', strtotime($item['tripDate'])) : "";
            $widthStart = ((int) $item['rating'] / 5) * 100; ?>

            <div class="bmd-review-ticket__comment">
                <?php if (!empty($item['comment'])) : ?>
                    <div class="bmd-review-ticket__comment-content"><?php echo $item['comment']; ?></div>
                <?php endif; ?>

                <div class="bmd-review-ticket__comment-rating">
                    <div class="ratings">
                        <div class="empty-stars" style="font-size: 12pt;"></div>
                        <div class="full-stars" style="width: <?php echo $widthStart . '%'; ?>; font-size: 12pt;"></div>
                    </div>
                </div>
                <div class="bmd-review-ticket__comment-name"><?php echo $item['name']; ?></div>
                <div class="bmd-review-ticket__comment-date"><?php echo $dateString; ?></div>
            </div>

        <?php endforeach; ?>

    </div>

    <?php if ($data['data']['totalPages'] > 1 && $page < $data['data']['totalPages']) : ?>
        <div class="bmd-comment-readmore-wrap">
            <button
                data-company="<?php echo $company_id; ?>"
                data-total="<?php echo $data['data']['totalPages']; ?>"
                data-page="<?php echo $page + 1; ?>"
                id="bmd-comment-readmore" class="bmd-comment-readmore">Đánh Giá Khác</button>
        </div>
    <?php endif;

    $content = ob_get_clean();

    wp_send_json_success($content);
}


add_action('wp_ajax_bmd_get_utilities', 'bmd_get_utilities');
add_action('wp_ajax_nopriv_bmd_get_utilities', 'bmd_get_utilities');
function bmd_get_utilities()
{

    $company_id = $_GET['company_id'];

    $url = endPoint . "/Api/Company/Utilities?companyId=$company_id";
    $response = call_api_with_token_agent($url, 'GET');
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = null;

    ob_start();

    foreach ($data['data'] as $item) : ?>
        <div class="bmd-review-ticket__amenity">
            <?php echo $item['name']; ?>
        </div>
        <?php endforeach;

    $content = ob_get_clean();

    wp_send_json_success($content);
}



add_action('wp_ajax_bmd_get_policies', 'bmd_get_policies');
add_action('wp_ajax_nopriv_bmd_get_policies', 'bmd_get_policies');
function bmd_get_policies()
{

    $tripCode = isset($_GET['trip_code']) ? sanitize_text_field($_GET['trip_code']) : '';
    $company_policy_url = endPoint . "/Api/Company/PolicyMapping";
    $trip_policy_url = endPoint . "/Api/Company/TripPolicy?tripCode=$tripCode";
    $response_1 = call_api_with_token_agent($company_policy_url, 'GET');
    $response_2 = call_api_with_token_agent($trip_policy_url, 'GET');
    $content = null;

    $data_1 = json_decode(wp_remote_retrieve_body($response_1), true);
    $data_2 = json_decode(wp_remote_retrieve_body($response_2), true);

    if (!empty($data_1) && is_array($data_1) || !empty($data_2) && is_array($data_2)) :
        $mapped_data = mapData($data_1, $data_2['data']);
        ob_start();
        foreach ($mapped_data['data'] as $policy) :

            if ($policy['id'] != '1') : ?>
                <div class="bmd-review-ticket__policy-item">
                    <div class="bmd-review-ticket__policy-title"><?php echo $policy['name']; ?></div>
                    <ul class="bmd-review-ticket__policy-sublist">
                        <?php foreach ($policy['details'] as $item) :

                            if ($item['status'] == 1) : ?>

                                <li><?php echo $item['title']; ?></li>

                        <?php endif;

                        endforeach; ?>
                    </ul>
                </div>
    <?php endif;

        endforeach;
        $content = ob_get_clean();
    endif;

    wp_send_json_success($content);
}



add_shortcode('bmd_partners', 'bmd_partners_function');

function bmd_partners_function()
{
    $content = null;
    ob_start(); ?>

    <div class="bmd-partners">
        <div class="bmd-partner">
            <a class="bmd-partner__link" href="https://anvui.vn" target="_blank">
                <img src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/images/an-vui.png" alt="an-vui">
            </a>
        </div>
        <div class="bmd-partner">
            <a class="bmd-partner__link" href="https://vexere.com" target="_blank">
                <img src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/images/vexere.png" alt="vexere">
            </a>
        </div>
        <div class="bmd-partner">
            <a class="bmd-partner__link" href="https://www.redbus.vn" target="_blank">
                <img src="<?php bloginfo('wpurl'); ?>/wp-content/uploads/images/rdc-redbus-logo.png" alt="redbus">
            </a>
        </div>
    </div>

    <?php $content = ob_get_clean();
    return $content;
}



//Collaborator Login
add_action('wp_ajax_bmd_collab_login', 'bmd_collab_login');
add_action('wp_ajax_nopriv_bmd_collab_login', 'bmd_collab_login');
function bmd_collab_login()
{

    check_ajax_referer('ams_vexe');

    $return = null;

    $email = $_POST['email'];
    $password = urlencode($_POST['password']);

    $response_encoded_password = wp_remote_post(endPoint . "/Api/Auth/EncryptPassword?password=$password");

    $body_encoded_password = json_decode(wp_remote_retrieve_body($response_encoded_password), true);

    $encoded_password = $body_encoded_password['message'];

    $data = [
        'email' => $email,
        'password' => $encoded_password,
        'audience' => 'dailyve.com'
    ];

    $response_login = wp_remote_post(endPoint . "/Api/Auth/Login", [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => json_encode($data)
    ]);

    $response_login_body = json_decode(wp_remote_retrieve_body($response_login), true);

    if ($response_login_body['statusCode'] == 200) {
        $_SESSION['collaborator'] = $response_login_body['data'];
        $return['content'] = $_SESSION['collaborator'];
    } else {
        $return['content'] = null;
    }

    wp_send_json_success($return);
}

//Collaborator Logout
add_action('wp_ajax_bmd_collab_logout', 'bmd_collab_logout');
add_action('wp_ajax_nopriv_bmd_collab_logout', 'bmd_collab_logout');


function bmd_collab_logout()
{

    check_ajax_referer('ams_vexe');
    session_unset();

    wp_send_json_success('logout');
}

//Collaborator Get Request List

add_action('wp_ajax_bmd_collab_request_list', 'bmd_collab_request_list');
add_action('wp_ajax_nopriv_bmd_collab_request_list', 'bmd_collab_request_list');

function bmd_collab_request_list()
{

    $content = null;

    if (isset($_SESSION['collaborator'])) :

        $page = (isset($_GET['page'])) ? $_GET['page'] : 1;
        $ajax = (isset($_GET['ajax'])) ? $_GET['ajax'] : 0;

        $collab = $_SESSION['collaborator'];
        $token = $collab['token'];
        $response = wp_remote_get(endPoint . "/Api/BookingRequest?pageSize=10&Page=$page", [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ]
        ]);

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_body['statusCode'] == 200) :

            $data = $response_body['data'];
            $pagination = $response_body['pagination'];

            if (!empty($data)) :

                ob_start(); ?>

                <div class="bmd-table">

                    <table>

                        <tr>
                            <th>Khách Hàng</th>
                            <th>SĐT</th>
                            <th>Nơi đi</th>
                            <th>Nơi đến</th>
                            <th>Nhà xe</th>
                            <th>Ghi chú</th>
                            <th>Ngày đặt</th>
                            <th>Trạng thái</th>
                            <th>Thao Tác</th>
                        </tr>

                        <?php foreach ($data as $item) : ?>
                            <tr>
                                <td><?php echo $item['customerName']; ?></td>
                                <td><?php echo $item['phone']; ?></td>
                                <td><?php echo $item['start']; ?></td>
                                <td><?php echo $item['destination']; ?></td>
                                <td><?php echo $item['busOperator']; ?></td>
                                <td><?php echo $item['note']; ?></td>
                                <td><?php $date = new DateTime($item['date']);
                                    echo $date->format('d-m-Y'); ?></td>
                                <td><?php if ($item['status'] == 1) : echo '<strong>Cần Đặt</strong>';
                                    else: echo '<strong>Đã Đặt</strong>';
                                    endif; ?></td>
                                <td>
                                    <?php if ($item['status'] == 1) : ?>
                                        <button
                                            data-request-id="<?php echo base64_encode($item['id']); ?>"
                                            data-request-name="<?php echo base64_encode($item['customerName']); ?>"
                                            data-request-phone="<?php echo base64_encode($item['phone']); ?>"
                                            class="bmd-collab-booking-btn">
                                            Đặt Vé
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    </table>

                </div>

                <?php if ($pagination['pageCount'] > 1) :

                    $first = 1;
                    $last = $pagination['pageCount'];
                    $current = $pagination['pageNumber'];
                    $next_extends = 0;
                    $prev_extends = 0;

                    $next_1 = $current + 1;
                    if ($next_1 < $last) {
                        $next_extends++;
                    }
                    $next_2 = $current + 2;
                    if ($next_2 < $last) {
                        $next_extends++;
                    }
                    $next_3 = $current + 3;
                    if ($next_3 < $last && $last - $next_3 > 1) {
                        $next_extends++;
                    }

                    $prev_1 = $current - 1;
                    if ($prev_1 > $first) {
                        $prev_extends++;
                    }
                    $prev_2 = $current - 2;
                    if ($prev_2 > $first) {
                        $prev_extends++;
                    }
                    $prev_3 = $current - 3;
                    if ($prev_3 > $first && $prev_3 - $first > 1) {
                        $prev_extends++;
                    } ?>

                    <div class="bmd-pagination">

                        <div data-page="<?php echo $first; ?>" class="bmd-pagination-item <?php if ($current == $first) : echo 'active';
                                                                                            else: echo 'selectable';
                                                                                            endif; ?>">
                            <?php echo $first; ?>
                        </div>

                        <?php if ($prev_extends == 3) : ?>
                            <div class="bmd-pagination-item">
                                ...
                            </div>
                        <?php endif; ?>

                        <?php if ($prev_3 > $first) : ?>
                            <div data-page="<?php echo $prev_3; ?>" class="bmd-pagination-item selectable">
                                <?php echo $prev_3; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($prev_2 > $first) : ?>
                            <div data-page="<?php echo $prev_2; ?>" class="bmd-pagination-item selectable">
                                <?php echo $prev_2; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($prev_1 > $first) : ?>
                            <div data-page="<?php echo $prev_1; ?>" class="bmd-pagination-item selectable">
                                <?php echo $prev_1; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($current != $first && $current != $last) : ?>
                            <div data-page="<?php echo $current; ?>" class="bmd-pagination-item active">
                                <?php echo $current; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($next_1 < $last) : ?>
                            <div data-page="<?php echo $next_1; ?>" class="bmd-pagination-item selectable">
                                <?php echo $next_1; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($next_2 < $last) : ?>
                            <div data-page="<?php echo $next_2; ?>" class="bmd-pagination-item selectable">
                                <?php echo $next_2; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($next_3 < $last) : ?>
                            <div data-page="<?php echo $next_3; ?>" class="bmd-pagination-item selectable">
                                <?php echo $next_3; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($next_extends == 3) : ?>
                            <div class="bmd-pagination-item">
                                ...
                            </div>
                        <?php endif; ?>

                        <div data-page="<?php echo $last; ?>" class="bmd-pagination-item <?php if ($current == $last) : echo 'active';
                                                                                            else: echo 'selectable';
                                                                                            endif; ?>">
                            <?php echo $last; ?>
                        </div>

                    </div>

                <?php endif; ?>

        <?php $content = ob_get_clean();

            endif;

        endif;

    endif;

    if ($ajax == 1) {
        wp_send_json_success($content);
    } else {
        return $content;
    }
}


//Collaborator Get Route Name
function bmd_collab_get_route_name($route_id)
{

    global $province_list;

    $route_name = null;

    foreach ($province_list as $key => $val) {

        if ($val['id'] == $route_id) {
            $route_name = $val['name'];
            break;
        }
    }

    return $route_name;
}

//Collaborator Get Route Filter Name
function bmd_collab_get_route_filter_name($route_id)
{

    global $province_list;

    $route_filter_name = null;

    foreach ($province_list as $key => $val) {

        if ($val['id'] == $route_id) {
            $route_filter_name = $val['name_filter'];
            break;
        }
    }

    return $route_filter_name;
}


//Collaborator Permission
// function bmd_collab_checking($from, $to, $date) {

//     if(isset($_SESSION['collaborator'])) : 

//         $collab = $_SESSION['collaborator'];
//         $token = $collab['token'];
//         $response = wp_remote_get(endPoint . "/Api/Collaborator", [
//             'headers' => [
//                 'Content-Type' => 'application/json',
//                 'Authorization' => 'Bearer ' . $token
//             ]
//         ]);

//         $response_body = json_decode(wp_remote_retrieve_body($response), true);
//         $flag = 0;

//         if($response_body['statusCode'] == 200) :

//             $data = $response_body['data'];

//             if(!empty($data)) :

//                 foreach($data as $item) :

//                     $item_date = new DateTime($item['date']);

//                     if($from == $item['routeFrom'] && $to == $item['routeTo'] && $date == $item_date->format('d-m-Y') && $item['status'] == 1) :

//                         $flag = 1;

//                         break;

//                     endif;

//                 endforeach;

//             endif;

//         endif;

//         return $flag;

//     endif;

// }


//Collaborator Login Form
add_shortcode('bmd_collab_login_form', 'bmd_collab_login_form');

function bmd_collab_login_form()
{

    if (isset($_SESSION['collaborator'])) :
        $collab = $_SESSION['collaborator']; ?>

        <div class="collab-message">
            <div class="collab-message__greeting">
                Xin chào, <?php echo ($collab['user']['displayName']) ? $collab['user']['displayName'] : $collab['user']['email']; ?>
            </div>
            <div class="collab-message__dropdown">
                <div class="collab-message__dropdown-item">
                    <a href="<?php bloginfo('wpurl'); ?>/danh-sach-yeu-cau-dat-ve">Danh Sách Yêu Cầu</a>
                </div>
                <div class="collab-message__dropdown-item">
                    <a href="<?php bloginfo('wpurl'); ?>/handle-logout">Đăng Xuất</a>
                </div>
            </div>
        </div>

    <?php else : ?>

        <!-- <div class="bmd-collab-login-form-wrap">

            <button data-fancybox data-src="#bmd-collab-login-form" class="bmd-collab-btn">
                <img src="<?php //bloginfo('wpurl'); 
                            ?>/wp-content/uploads/images/deal.png" alt="collaborator-icon">
            </button>
            <div class="bmd-collab-login-form" id="bmd-collab-login-form">
                <div class="bmd-collab-login-form-banner">
                    <img src="<?php //bloginfo('wpurl'); 
                                ?>/wp-content/uploads/images/login-banner2.png" alt="form banner">
                </div>
                <div class="bmd-collab-login-form-item">
                    <input type="email" name="email" placeholder="Địa chỉ Email">
                    <div class="bmd-collab-login-form-notice"></div>
                </div>
                <div class="bmd-collab-login-form-item">
                    <input type="password" name="password" placeholder="Mật khẩu">
                    <div class="bmd-collab-login-form-notice"></div>
                </div>
                <div class="bmd-collab-login-form-item">
                    <input class="bmd-collab-login-form-submit" type="submit" value="Đăng Nhập">
                    <div class="bmd-collab-login-form-notice bmd-collab-login-form-notice--ct-style"></div>
                </div>
            </div>
        
        </div> -->


<?php endif;
}




add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/update_post', array(
        'methods'  => 'POST',
        'callback' => 'update_post_function',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('custom/v1', '/create_route_post', array(
        'methods'  => 'POST',
        'callback' => 'create_route_post_function',
        'permission_callback' => '__return_true',
    ));

    register_rest_route('custom/v1', '/create_route_page', array(
        'methods'  => 'POST',
        'callback' => 'create_route_page_function',
        'permission_callback' => '__return_true',
    ));

});

//Update Post
function update_post_function(WP_REST_Request $request)
{

    $params = $request->get_json_params();
    $post_id = $params['post_id'];
    $post_content = $params['post_content'];

    if (empty($post_id) || empty($post_content)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Thiếu id hoặc content'
        ], 400);
    }

    $post_title = get_the_title($post_id);

    $args = array(
        'ID' => $post_id,
        'post_title' => $post_title,
        'post_status'   => 'publish',
        'post_author' => 8,
        'post_content' => $post_content
    );
    wp_insert_post($args);

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Dữ liệu đã được xử lý'
    ], 200);
}


//Creating Route Post
function create_route_post_function(WP_REST_Request $request)
{

    $params = $request->get_json_params();
    $secret = $params['secret'];
    $title = $params['title'];
    $content = $params['content'];

    if ($secret == 'dailyve.com' && !empty($title) && !empty($content)) {

        $args = array(
            'post_title' => $title,
            'post_type' => 'tuyen-duong',
            'post_status'   => 'publish',
            'post_author' => 8,
            'post_content' => $content
        );
        wp_insert_post($args);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Dữ liệu đã được xử lý'
        ], 200);
    }
}


//Creating Route Page
function create_route_page_function(WP_REST_Request $request){

    $params = $request->get_json_params();
    $secret = $params['secret'];

    $sapo = $params['sapo'];
    $title = $params['title'];
    $route_info_desc = $params['route_info_desc'];
    $route_info_tb = $params['route_info_tb'];
    $data_tb = $params['data_tb'];
    $noi_dung_gt_dong_xe = $params['noi_dung_gt_dong_xe'];
    $top_bus_company_title = $params['top_bus_company_title'];
    $top_bus_company_desc = $params['top_bus_company_desc'];
    $bus_company_intro = $params['bus_company_intro'];
    $booking_guide = $params['booking_guide'];
    $fag = $params['faq'];
    $depart = $params['depart'];
    $arrival = $params['arrival'];

    if ($secret == 'dailyve.com' && !empty($depart) && !empty($arrival)) {

        $content = $sapo . "<h2>$title</h2>" . $route_info_desc . $route_info_tb . $data_tb . $noi_dung_gt_dong_xe . "<h2>$top_bus_company_title</h2>" . $top_bus_company_desc . $bus_company_intro . $booking_guide . $fag;

        $args = array(
            'post_title' => "Vé xe khách từ $depart đi $arrival",
            'post_type' => 'page',
            'post_status'   => 'publish',
            'post_author' => 8,
            'post_content' => $content
        );
        wp_insert_post($args);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Dữ liệu đã được xử lý'
        ], 200);
    } else {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Thiếu dữ liệu'
        ], 200);
    }

}