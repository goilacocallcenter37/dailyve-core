<?php

function is_customer_logged_in()
{
    // if (session_status() === PHP_SESSION_NONE) {
    //     session_start();
    // }

    // Kiểm tra session
    if (isset($_SESSION['customer_login']) && $_SESSION['customer_login'] === true) {
        return true;
    }

    // Nếu không có session, kiểm tra cookie và gọi API
    if (isset($_COOKIE['auth_token']) && !empty($_COOKIE['auth_token'])) {
        $token = $_COOKIE['auth_token'];
        $response = wp_remote_get(BMS_URL . '/v1/customer/check-token', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ]);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $_SESSION['customer_login'] = true;
            $_SESSION['customer_data'] = $body['data'];
            return true;
        }
    }
    return false;
}

// Đăng xuất
function custom_logout()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $phone = isset($_SESSION['customer_data']['phone']) ? $_SESSION['customer_data']['phone'] : '';
    unset($_SESSION['customer_login']);
    unset($_SESSION['customer_data']);

    if (isset($_COOKIE['auth_token'])) {
        $token = $_COOKIE['auth_token'];
        wp_remote_post(BMS_URL . '/v1/logout', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    setcookie('auth_token', '', time() - 3600, '/');
    if (!empty($phone)) {
        delete_transient('auth_menu_logged_in_' . md5($phone));
    }
    delete_transient('auth_menu_logged_out');

    wp_redirect(home_url());
    exit;
}

add_action('init', function () {
    if (isset($_GET['action']) && $_GET['action'] === 'customer_logout') {
        if (is_user_logged_in()) {
            wp_logout();
        }
        custom_logout();
    }
});

add_shortcode('auth_menu', 'render_auth_menu');

function render_auth_menu($atts)
{
    // nocache_headers();
    // Buffer output
    ob_start();
    if (is_customer_logged_in() === true || is_user_logged_in()) { ?>

        <div class="action-drp">
            <div class="user-drp" onclick="menuToggle();">
                <div class="profile">
                    <img src="<?= !empty($_SESSION['customer_data']['avatar']) ? $_SESSION['customer_data']['avatar'] : '/wp-content/uploads/images/user.png'; ?>"
                        alt="Profile avatar">
                </div>
                <span><?php echo $_SESSION['customer_data']['phone']; ?></span>
                <i id="icon-drp" class='fas fa-caret-down'></i>
            </div>
            <div class="menu-drp">
                <!-- <h3>Takeda Rena<br><span>Japanese Actress</span></h3> -->
                <ul>
                    <?php
                    $user = wp_get_current_user();
                    if (is_user_logged_in() && in_array('contributor', (array) $user->roles)) { ?>
                        <li><img
                                src="data:image/png;base64,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"><a
                                href="/danh-sach-ve">Danh sách vé</a></li>
                    <?php } else { ?>
                        <li><img
                                src="data:image/png;base64,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"><a
                                href="/tai-khoan">Tài khoản</a></li>
                    <?php } ?>
                    <li><img
                            src="data:image/png;base64,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"><a
                            href="<?php echo esc_url(add_query_arg('action', 'customer_logout', home_url())); ?>">Đăng xuất</a>
                    </li>
                </ul>
            </div>
        </div>
    <?php } else { ?>
        <button class="btn-login trigger">Đăng nhập</button>
    <?php
    }
    return ob_get_clean();
}

add_shortcode('auth_form', 'render_auth_form');

add_action('wp_ajax_get_auth_menu', 'ajax_get_auth_menu');
add_action('wp_ajax_nopriv_get_auth_menu', 'ajax_get_auth_menu');
function ajax_get_auth_menu()
{
    $user_id = '';
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
    }
    $cache_key = 'auth_menu_' . (is_customer_logged_in() ? 'logged_in_' . md5($_SESSION['customer_data']['phone']) . '_' . $user_id : 'logged_out');
    $cache_time = 86400 * 5;

    $cached_output = get_transient($cache_key);
    if ($cached_output !== false) {
        echo $cached_output;
        wp_die();
    }

    $output = render_auth_menu([]);
    set_transient($cache_key, $output, $cache_time);

    echo $output;
    wp_die();
}

function render_auth_form($atts)
{
    // Buffer output
    ob_start();

    // Check if user is logged in
    if (is_customer_logged_in() === true) {
        return ob_get_clean();
    }
    ?>
    <div class="modal">
        <div class="modal-content">
            <div class="modal-content-wrapper">
                <span class="back-button"><i class="fas fa-chevron-left"></i></span>
                <div class="modal-container">
                    <div class="modal-header">
                        <img width="300" height="104"
                            src="https://object.dailyve.com/dailyve/wp-content/uploads/2024/10/DailyVe-12-300x104.png"
                            alt="Dailyve.com" class="logo">
                    </div>
                    <div class="modal-body">
                        <div class="banner-login">
                            <img src="/wp-content/uploads/images/login-banner2.png" alt="Login">
                        </div>

                        <div class="content-method-login">
                            <div class="text-center" style="margin: 16px 0px;">
                                <div class="mb-0" style="font-size: 15px; color:#1a4c85; font-weight: 700;">ĐĂNG NHẬP HOẶC
                                    TẠO TÀI KHOẢN MỚI</div>
                                <p style="font-size: 13px; color: #555555;">Đặt vé dễ dàng và thuận tiện trong việc lên kế
                                    hoạch và quản lý lịch trình cá nhân khi sử dụng tài khoản Dailyve.</p>
                            </div>
                            <form action="/wp-login.php" method="post">
                                <a class="button-login-social" href="#" id="btn-sms-login"
                                    style="display: flex; align-items: center; justify-content: center; gap: 16px;">
                                    <div class="google-btn">
                                        <div class="google-icon-wrapper">
                                            <img class="facebook-icon" src="/wp-content/uploads/images/sms2.png"
                                                alt="SMS" />
                                        </div>
                                        <p class="btn-text">Tiếp tục với SMS</p>
                                    </div>
                                </a>

                                <div class="or-text">
                                    <span></span>
                                    <span style="font-size: 80%;">hoặc</span>
                                    <span></span>
                                </div>

                                <div class="modal-bottom-social">
                                    <style>
                                        .g_id_signin {
                                            width: 100%;
                                        }

                                        .g_id_signin>div {
                                            display: flex;
                                            justify-content: center;
                                        }
                                    </style>
                                    <!-- <a class="button-login-social" href="#"> -->
                                    <!-- <div class="google-btn">
                                            <div class="google-icon-wrapper">
                                                <img class="google-icon" src="/wp-content/uploads/images/google.png" alt="Google" />
                                            </div>
                                            <p>Google</p>
                                        </div> -->
                                    <!-- <div id="g_id_onload"
                                        data-client_id="217007742906-a8tfms2d3tv0scf2bmtl8utiqgda9m8s.apps.googleusercontent.com"
                                        data-callback="handleCredentialResponse" data-auto_prompt="false">
                                    </div>

                                    <div class="g_id_signin" data-type="standard"></div> -->
                                    <!-- </a> -->


                                    <!-- <a class="button-login-social" href="#">
                                        <div class="google-btn">
                                            <div class="google-icon-wrapper">
                                                <img class="facebook-icon" src="/wp-content/uploads/images/facebook.png" alt="Facebook" />
                                            </div>
                                            <p>Facebook</p>
                                        </div>
                                    </a> -->
                                    <!-- <button id="googleLoginBtn" style="
                                        display: flex;
                                        align-items: center;
                                        gap: 8px;
                                        border: 1px solid #ddd;
                                        padding: 10px 15px;
                                        border-radius: 5px;
                                        background: white;
                                        cursor: pointer;
                                    ">
                                        <img src="https://developers.google.com/identity/images/g-logo.png" width="18"
                                            height="18" alt="Google Logo">
                                        <span>Đăng nhập bằng Google</span>
                                    </button> -->

                                </div>
                            </form>
                            <p class="text-center"
                                style="line-height: 1.4; font-size: 12px; margin-top: 16px; color: #555555;">Bằng cách tạo
                                và/hoặc sử dụng tài khoản của mình, bạn đồng ý với Điều khoản sử dụng và Chính sách quyền
                                riêng tư của chúng tôi.</p>
                        </div>
                        <div class="content-sms-login">
                            <label>Số điện thoại của bạn là gì?</label>
                            <div style="margin-top: 10px;">
                                <input type="tel" name="customer-phone" id="customer-phone" placeholder="Exp: 090.xxx.xxxx"
                                    required>
                                <div class="error-msg" id="msg-phone-error"></div>
                                <button class="btn-next" id="btn-sms-verify" type="button" disabled>Tiếp tục</button>
                            </div>
                        </div>
                        <div class="content-sms-verify">
                            <div class="text-center mb-0" style="font-size: 15px; font-weight: 700;">NHẬP MÃ XÁC THỰC</div>
                            <p class="text-center mb-0" style="font-size: 13px; color: #555555;">Vui lòng nhập mã 6 chữ số
                                được gửi đến số điện thoại của bạn.
                                Nếu số điện thoại đã đăng ký Zalo, hãy mở Zalo để nhận và xác nhận mã OTP.</p>
                            <div class="text-center" style="font-size: 13px; margin-bottom: 10px;">(Hiệu lực: <span
                                    id="time-expired"></span>)</div>
                            <div class="input-field">
                                <input type="number" />
                                <input type="number" disabled />
                                <input type="number" disabled />
                                <input type="number" disabled />
                                <input type="number" disabled />
                                <input type="number" disabled />
                            </div>
                            <div class="error-msg" id="msg-verify"></div>
                            <button class="btn-next" type="button" id="btn-verify-otp" disabled>Xác nhận</button>
                            <div class="resend-otp">
                                <a href="#" id="btn-resend-otp">Gửi lại mã</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}

add_action('wp_ajax_customer_send_otp', 'customer_send_otp_callback');
add_action('wp_ajax_nopriv_customer_send_otp', 'customer_send_otp_callback');

function customer_send_otp_callback()
{
    // check_ajax_referer('customer_send_otp_nonce', 'nonce');
    $phone = sanitize_text_field($_POST['phone']);
    $payload = json_encode([
        'phone' => $phone,
        'registered_from' => 'web',
    ]);
    $signature = hash_hmac('sha256', $payload, WEBHOOK_SECRET_TOKEN);
    $response = wp_remote_post(BMS_URL . '/v1/customer/send-otp', [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => $signature,
        ],
        'body' => $payload,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Lỗi kết nối đến máy chủ']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200 && isset($body['status']) && $body['status'] == true) {
        wp_send_json_success($body);
    } else {
        wp_send_json_error([
            'message' => $body['message'] ?? 'Gửi OTP thất bại',
            'error' => $body['error'] ?? null
        ]);
    }
}

add_action('wp_ajax_customer_verify_otp', 'customer_verify_otp_callback');
add_action('wp_ajax_nopriv_customer_verify_otp', 'customer_verify_otp_callback');

function customer_verify_otp_callback()
{
    // check_ajax_referer('customer_verify_otp_nonce', 'nonce');
    $phone = sanitize_text_field($_POST['phone']);
    $otp = sanitize_text_field($_POST['otp']);

    $payload = json_encode([
        'phone' => $phone,
        'otp' => $otp,
    ]);
    $signature = hash_hmac('sha256', $payload, WEBHOOK_SECRET_TOKEN);

    $response = wp_remote_post(BMS_URL . '/v1/customer/verify-otp', [
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Webhook-Signature' => $signature,
        ],
        'body' => $payload,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Lỗi kết nối đến máy chủ']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200 && isset($body['data']['token'])) {
        setcookie('auth_token', $body['data']['token'], time() + (86400 * 365), '/', '', false, true); // 1 year expiry, HttpOnly
        wp_send_json_success(['message' => $body['message'] ?? 'OTP đã được xác thực']);
    } else {
        wp_send_json_error(['message' => $body['message'] ?? 'Đăng nhập thất bại']);
    }
}

add_action('wp_ajax_customer_login_google', 'customer_login_google_callback');
add_action('wp_ajax_nopriv_customer_login_google', 'customer_login_google_callback');

function customer_login_google_callback()
{
    // check_ajax_referer('customer_verify_otp_nonce', 'nonce');
    $google_token = sanitize_text_field($_POST['google_token']);

    $payload = json_encode([
        'google_token' => $google_token,
    ]);

    $response = wp_remote_post(BMS_URL . '/v1/customer/login', [
        'headers' => [
            'Content-Type' => 'application/json',
        ],
        'body' => $payload,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Lỗi kết nối đến máy chủ']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200 && isset($body['data']['token'])) {
        setcookie('auth_token', $body['data']['token'], time() + (86400 * 365), '/', '', false, true); // 1 year expiry, HttpOnly
        wp_send_json_success(['message' => $body['message'] ?? 'OTP đã được xác thực']);
    } else {
        wp_send_json_error(['message' => $body['message'] ?? 'Đăng nhập thất bại']);
    }
}

function render_profile_shortcode()
{
    ob_start();
    if (is_customer_logged_in() === true || is_user_logged_in()) {
        $customer_data = isset($_SESSION['customer_data']) ? $_SESSION['customer_data'] : array();
    ?>
        <div class="profile-container">
            <form id="profile-form" class="profile-form" method="post">
                <div class="profile-details">
                    <div class="form-group">
                        <label for="name">Họ và tên:</label>
                        <input type="text" id="name" name="name"
                            value="<?php echo isset($customer_data['name']) ? esc_attr($customer_data['name']) : ''; ?>"
                            placeholder="Nhập họ và tên">
                    </div>

                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email"
                            value="<?php echo isset($customer_data['email']) ? esc_attr($customer_data['email']) : ''; ?>"
                            placeholder="Nhập địa chỉ email">
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone:</label>
                        <input type="tel" id="phone" name="phone"
                            value="<?php echo isset($customer_data['phone']) ? esc_attr($customer_data['phone']) : ''; ?>"
                            placeholder="Nhập số điện thoại" <?php echo isset($customer_data['phone']) ? 'disabled' : ''; ?>
                            style="cursor: not-allowed; background-color: #f5f5f5;">
                    </div>
                    <div class="form-group">
                        <label for="birth_date">Ngày sinh:</label>
                        <input type="date" id="birth_date" name="birth_date" value="<?php
                                                                                    if (isset($customer_data['birth_date'])) {
                                                                                        $date = new DateTime($customer_data['birth_date']);
                                                                                        echo $date->format('Y-m-d');
                                                                                    }
                                                                                    ?>">
                    </div>
                    <div class="form-group">
                        <label for="gender">Giới tính:</label>
                        <div class="gender-options">
                            <label>
                                <input type="radio" name="gender" value="male" <?php echo (isset($_SESSION['customer_data']['gender']) && $_SESSION['customer_data']['gender'] == 'male') ? 'checked' : ''; ?>>
                                Nam
                            </label>
                            <label>
                                <input type="radio" name="gender" value="female" <?php echo (isset($_SESSION['customer_data']['gender']) && $_SESSION['customer_data']['gender'] == 'female') ? 'checked' : ''; ?>>
                                Nữ
                            </label>
                            <label>
                                <input type="radio" name="gender" value="other" <?php echo (isset($_SESSION['customer_data']['gender']) && $_SESSION['customer_data']['gender'] == 'other') ? 'checked' : ''; ?>>
                                Khác
                            </label>
                        </div>
                    </div>
                </div>

                <div class="profile-actions">
                    <button type="submit" class="save-profile-btn">
                        <span class="btn-text">Cập nhật</span>
                        <span class="btn-loading" style="display: none;">Đang xử lý...</span>
                    </button>
                    <a href="<?php echo esc_url(add_query_arg('action', 'customer_logout', home_url())); ?>"
                        class="logout-btn">Đăng xuất</a>
                </div>
            </form>
        </div>
    <?php
    } else {
    ?>
        <div class="login-required">
            <p>Vui lòng đăng nhập để sử dụng chức năng này</p>
            <button class="btn-login trigger">Login</button>
        </div>
    <?php
    }

    return ob_get_clean();
}

add_shortcode('profile', 'render_profile_shortcode');

function render_profile_sidebar()
{
    ob_start();

    if (is_customer_logged_in() === true || is_user_logged_in()) { ?>
        <div class="profile-sidebar">
            <div class="profile-header">
                <div class="profile-avatar">
                    <img src="<?= !empty($_SESSION['customer_data']['avatar']) ? $_SESSION['customer_data']['avatar'] : '/wp-content/uploads/images/user.png'; ?>"
                        alt="Profile Avatar">
                </div>
                <div class="profile-info">
                    <h3 class="profile-name"><?php echo $_SESSION['customer_data']['phone']; ?></h3>
                </div>
            </div>

            <ul class="profile-menu">
                <li class="<?php echo is_page('tai-khoan') ? 'active' : ''; ?>">
                    <a href="/tai-khoan">
                        <i class="fas fa-user"></i>
                        <span>Thông tin tài khoản</span>
                    </a>
                </li>

                <li class="<?php echo is_page('don-hang-cua-toi') ? 'active' : ''; ?>">
                    <a href="/don-hang-cua-toi">
                        <i class="fas fa-ticket-alt"></i>
                        <span>Đơn hàng của tôi</span>
                    </a>
                </li>

                <li class="<?php echo is_page('uu-dai') ? 'active' : ''; ?>">
                    <a href="/uu-dai">
                        <i class="fas fa-gift"></i>
                        <span>Ưu đãi</span>
                    </a>
                </li>

                <li class="<?php echo is_page('quan-ly-the') ? 'active' : ''; ?>">
                    <a href="/quan-ly-the">
                        <i class="fas fa-credit-card"></i>
                        <span>Quản lý thẻ</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo esc_url(add_query_arg('action', 'customer_logout', home_url())); ?>">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Đăng xuất</span>
                    </a>
                </li>
            </ul>
        </div>
    <?php }
    return ob_get_clean();
}

add_shortcode('profile_sidebar', 'render_profile_sidebar');


function render_profile_add_card_shortcode()
{
    ob_start();
    if (is_customer_logged_in() === true || is_user_logged_in()) {
        $customer_data = isset($_SESSION['customer_data']) ? $_SESSION['customer_data'] : array();
    ?>
        <style>
            .profile-card {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .modalCard {
                display: none;
                position: fixed;
                z-index: 1;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.6);
                overflow-y: auto;
                padding-top: 60px;
            }

            .modal-content-card {
                background-color: #fff;
                margin: 5% auto;
                padding: 30px;
                width: 80%;
                max-width: 660px;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            }

            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
            }

            .modal-header h3 {
                /* font-size: 1.6em; */
                margin: 0;
            }

            .modal-header button {
                background-color: transparent;
                border: none;
                font-size: 2em;
                cursor: pointer;
            }

            .modal-body {
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                gap: 15px;
                /* height: 270px; */
                max-height: 270px;
                overflow-y: auto;
            }

            .bank-logo {
                display: flex;
                justify-content: center;
                align-items: center;
                /* padding: 15px; */
                background-color: #f8f8f8;
                /* border-radius: 10px; */
                transition: transform 0.3s ease;
                cursor: pointer;
                border: 0.5px solid #e3e3e3;
                max-height: 90px;
                transition: all 0.3s ease;
            }

            .bank-logo.active {
                border: 1px solid #007bff;
            }

            .bank-logo:hover {
                transform: scale(1);
                background-color: #e3e3e3;
            }

            .bank-logo img {
                width: 100%;
            }

            .modal-footer {
                display: flex;
                justify-content: flex-end;
                margin-top: 16px;
                gap: 20px;
            }

            .modal-footer button {
                margin: 0;
                padding: 0px 20px;
                background-color: #1a4c85;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 1em;
                font-weight: 500;
                transition: background-color 0.3s ease;
            }

            .modal-footer button.cancel {
                color: #787878;
                background-color: #ffffff;
                border: 1px solid #787878;
            }

            .modal-footer button:hover {
                background-color: #007bff;
            }

            .modal-footer button.cancel:hover {
                color: #000000;
                border: 1px solid #000000;
                background-color: #ffffff;
            }

            .closeModalBtn {
                margin: 0;
                padding-right: 0;
            }

            @media screen and (max-width: 768px) {
                .profile-card .cell-1 {
                    flex: 0 0 50%;
                    max-width: 50%;
                }

                .profile-card .cell-2 {
                    text-align: right;
                    flex: 0 0 48%;
                    max-width: 48%;
                }

                .modal-body {
                    grid-template-columns: repeat(3, 1fr);
                }
            }

            @media screen and (max-width: 520px) {
                .modal-body {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>
        <div class="profile-container">
            <div class="profile-form" style="margin-bottom: 20px;">
                <div class="profile-card">
                    <div class="cell-1">
                        <h3 class="profile-card-title">Thẻ ATM nội địa</h3>
                        <div>Đảm bảo thẻ đã đăng ký Internet Banking</div>
                    </div>
                    <div class="cell-2">
                        <button class="save-profile-btn" id="openModalBtn"
                            style="background: #1a4c85; font-weight: bold; padding: 2px 14px;">
                            <span><i class="fas fa-plus"></i></span>
                            <span class="btn-text">Thêm thẻ mới</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="profile-form">
                <div class="profile-card">
                    <div class="cell-1">
                        <h3 class="profile-card-title">Thẻ thanh toán quốc tế</h3>
                        <div>VISA, MasterCard, JCB</div>
                    </div>
                    <div class="cell-2">
                        <button class="save-profile-btn" style="background: #1a4c85; font-weight: bold; padding: 2px 14px;">
                            <span><i class="fas fa-plus"></i></span>
                            <span class="btn-text">Thêm thẻ mới</span>
                        </button>
                    </div>
                </div>
                <div class="trust-message-container trust" style="width: 100%;">
                    <i class="fas fa-shield-alt"></i>
                    <p class="trust-message-content">Thẻ được lưu bởi đối tác đạt chuẩn quốc tế PCI DSS cấp độ cao nhất về Bảo
                        mật thanh toán. Một khoản phí nhỏ sẽ được khấu trừ để xác minh thông tin thẻ.</p>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div id="modalCard" class="modalCard">
            <div class="modal-content-card">
                <div class="modal-header">
                    <h3>Thẻ ATM nội địa/ Internet Banking</h3>
                    <div class="closeModalBtn">
                        <img src="https://229a2c9fe669f7b.cmccloud.com.vn/svgIcon/close_black.svg" alt="close">
                    </div>
                </div>
                <div class="search-card">
                    <input type="text" id="searchBank" placeholder="Tìm kiếm ngân hàng">
                </div>
                <div class="modal-body" id="listBank">
                    <!-- Add more logos here -->
                </div>
                <div class="trust-message-container trust" style="width: 100%;">
                    <i class="fas fa-shield-alt"></i>
                    <p class="trust-message-content">Thẻ được lưu bởi đối tác đạt chuẩn quốc tế PCI DSS cấp độ cao nhất về Bảo
                        mật thanh toán. Một khoản phí nhỏ sẽ được khấu trừ để xác minh thông tin thẻ.</p>
                </div>
                <div class="modal-footer">
                    <button class="cancel closeModalBtn">Đóng</button>
                    <button id="continueBtn">Tiếp tục</button>
                </div>
            </div>
        </div>
    <?php } else { ?>
        <div class="login-required">
            <p>Vui lòng đăng nhập để sử dụng chức năng này</p>
            <button class="btn-login trigger">Login</button>
        </div>
    <?php }

    return ob_get_clean();
}

add_shortcode('profile_add_card', 'render_profile_add_card_shortcode');

add_action('wp_ajax_update_customer_profile', 'handle_update_customer_profile');
add_action('wp_ajax_nopriv_update_customer_profile', 'handle_update_customer_profile');

function handle_update_customer_profile()
{
    // if (!wp_verify_nonce($_POST['profile_nonce'], 'update_profile_action')) {
    //     wp_die(json_encode(array(
    //         'success' => false,
    //         'data' => array('message' => 'Security check failed')
    //     )));
    // }

    if (!is_customer_logged_in() && !is_user_logged_in()) {
        wp_die(json_encode(array(
            'success' => false,
            'data' => array('message' => 'Unauthorized access')
        )));
    }

    $name = sanitize_text_field($_POST['name']);
    $email = sanitize_email($_POST['email']);
    $birth_date = sanitize_text_field($_POST['birth_date']);
    $gender = sanitize_text_field($_POST['gender']);

    if (empty($name) || empty($email)) {
        wp_send_json_error([
            'data' => [
                'message' => 'Vui lòng điền đầy đủ thông tin bắt buộc'
            ]
        ]);
    }

    if (!is_email($email)) {
        wp_send_json_error([
            'data' => [
                'message' => 'Email không đúng định dạng'
            ]
        ]);
    }

    $api_data = array(
        'name' => $name,
        'email' => $email,
        'birth_date' => $birth_date,
        'gender' => $gender,
        'phone' => isset($_SESSION['customer_data']['phone']) ? $_SESSION['customer_data']['phone'] : null
    );

    $api_response = send_to_external_api($api_data);

    if ($api_response['success']) {
        $_SESSION['customer_data'] = array_merge(
            isset($_SESSION['customer_data']) ? $_SESSION['customer_data'] : array(),
            $api_data
        );

        wp_send_json_success([
            'data' => [
                'message' => 'Cập nhật thông tin thành công!',
                'updated_data' => $api_data
            ]
        ]);
    } else {
        wp_send_json_error([
            'data' => [
                'message' => $api_response['message']
            ]
        ]);
    }
}

function send_to_external_api($data)
{
    $api_url = BMS_URL . '/v1/customer/update-profile';
    $access_token = isset($_COOKIE['auth_token']) && !empty($_COOKIE['auth_token']) ? $_COOKIE['auth_token'] : '';

    $headers = array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $access_token,
    );

    $body = json_encode($data);

    $response = wp_remote_post($api_url, array(
        'method' => 'POST',
        'headers' => $headers,
        'body' => $body,
    ));

    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'message' => 'Lỗi kết nối API: ' . $response->get_error_message()
        );
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    $parsed_response = json_decode($response_body, true);

    if ($response_code === 200 || $response_code === 201) {
        return array(
            'success' => true,
            'message' => 'Cập nhật thành công',
            'data' => $parsed_response
        );
    } else {
        $error_message = 'Lỗi API';

        if ($parsed_response && isset($parsed_response['message'])) {
            $error_message = $parsed_response['message'];
        } elseif ($parsed_response && isset($parsed_response['error'])) {
            $error_message = $parsed_response['error'];
        }

        return array(
            'success' => false,
            'message' => $error_message . ' (HTTP ' . $response_code . ')',
            'response' => array(
                'status' => $response_code,
                'body' => wp_remote_retrieve_body($response),
            )
        );
    }
}

function render_ticket_lookup_shortcode($atts)
{
    if (is_customer_logged_in() === true || is_user_logged_in()) {
        ob_start();
        $atts = shortcode_atts(array(
            'per_page' => 10,
            'show_search' => 'true',
            'show_pagination' => 'true',
            'status' => '1'
        ), $atts);

        $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;

        $status_query_value = $atts['status'];
        $status_query_compare = '=';

        if ($atts['status'] === '3' || $atts['status'] === '3,5') {
            $status_query_value = array('3', '5');
            $status_query_compare = 'IN';
        }

        $args = [
            'post_type' => 'book-ticket',
            'posts_per_page' => $atts['per_page'],
            'paged' => $paged,
            'meta_query' => [
                [
                    'key' => 'phone',
                    'value' => $_SESSION['customer_data']['phone'],
                    'compare' => '='
                ],
                [
                    'key' => 'payment_status',
                    'value' => $status_query_value,
                    'compare' => $status_query_compare
                ]
            ]
        ];

        $query = new WP_Query($args);
    ?>
        <div class="ticket-lookup-container" data-per-page="<?php echo $atts['per_page']; ?>"
            data-status="<?php echo $atts['status']; ?>" data-phone="<?php echo $_SESSION['customer_data']['phone']; ?>">

            <div class="ticket-list" id="ticket-list">
                <?php echo render_ticket_cards($query); ?>
            </div>

            <?php if ($atts['show_pagination'] === 'true' && $query->max_num_pages > 1): ?>
                <div class="ajax-pagination" id="ajax-pagination">
                    <?php echo render_ajax_pagination($query, $paged); ?>
                </div>
            <?php endif; ?>

            <div class="loading-overlay" id="loading-overlay" style="display: none;">
                <div class="spinner"></div>
            </div>
        </div>

    <?php
        wp_reset_postdata();
    } else {
    ?>
        <div class="login-required">
            <p>Please login to view your profile</p>
            <button class="btn-login trigger">Login</button>
        </div>
    <?php
    }
    return ob_get_clean();
}


function render_ticket_cards($query)
{
    ob_start();

    if ($query->have_posts()): ?>
        <div class="ticket-cards">
            <?php while ($query->have_posts()):
                $query->the_post(); ?>
                <div class="ticket-card">
                    <div class="ticket-card-header">
                        <h3>Mã vé: <?php echo get_post_meta(get_the_ID(), 'booking_codes', true); ?></h3>
                    </div>
                    <div class="ticket-card-body">
                        <div class="ticket-info">
                            <div class="info-item">
                                <span class="label">Ngày đặt:</span>
                                <span class="value"><?php echo get_the_date(); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="label">Trạng thái:</span>
                                <?php
                                $status = get_post_meta(get_the_ID(), 'payment_status', true);
                                $status_label = '';
                                $status_class = '';

                                switch ($status) {
                                    case '1':
                                        $status_label = 'Chưa thanh toán';
                                        $status_class = 'processing';
                                        break;
                                    case '2':
                                        $status_label = 'Đã thanh toán';
                                        $status_class = 'paid';
                                        break;
                                    case '3':
                                        $status_label = 'Đã hủy';
                                        $status_class = 'cancelled';
                                        break;
                                    case '5':
                                        $status_label = 'Đã hủy hoàn tiền';
                                        $status_class = 'cancelled';
                                        break;
                                    default:
                                        $status_label = 'Không xác định';
                                        $status_class = 'unknown';
                                        break;
                                }
                                echo '<span class="status-label ' . $status_class . '">' . $status_label . '</span>';

                                // $id_from = get_post_meta(get_the_ID(), 'search_from', true);
                                // $id_to = get_post_meta(get_the_ID(), 'search_to', true);
                                // $name_from = timTuyenDuongID($id_from);
                                // $name_to = timTuyenDuongID($id_to);
                                // $slug_from = vietnamese_string_to_slug($name_from);
                                // $slug_to = vietnamese_string_to_slug($name_to);
                                ?>
                            </div>
                            <div class="info-item">
                                <span class="label" style="margin-right: 5px;">Nhà xe:</span>
                                <span class="value"><?php echo get_post_meta(get_the_ID(), 'company_bus', true); ?></span>
                            </div>
                            <!-- <div class="info-item">
                                <span class="label" style="margin-right: 5px;">Tuyến:</span>
                                <span class="value"
                                    style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">
                                    <?php // echo $name_from . ' đến ' . $name_to; 
                                    ?>
                                </span>
                            </div> -->
                            <?php if ((string)$status === '5'): ?>
                                <div class="info-item">
                                    <span class="label" style="margin-right: 5px;">Phí hủy:</span>
                                    <span class="value" style="color: #d9534f; font-weight: bold;">
                                        <?php echo number_format((float)get_post_meta(get_the_ID(), 'refund_fee', true), 0, ',', '.'); ?>đ
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <input type="text" class="dailyve_calendar" name="reorder_booking_<?php // echo get_the_ID(); 
                                                                                            ?>">
                        <div class="flex">
                            <a data-datepicker="reorder_booking_<?php // echo get_the_ID(); 
                                                                ?>" href="/"
                                data-link="<?php // echo "/ve-xe-khach-tu-" . $slug_from . "-di-" . $slug_to . "-{$id_from}t{$id_to}.html?date="; 
                                            ?>"
                                title="Đặt lại" class="view-ticket-btn booking-dailyve-route">Đặt lại</a>
                            <?php
                            $status = (string)get_post_meta(get_the_ID(), 'payment_status', true);
                            $departure_date_raw = get_post_meta(get_the_ID(), 'departure_date', true);
                            if (empty($departure_date_raw)) {
                                $departure_date_raw = get_post_meta(get_the_ID(), 'pickup_date', true);
                            }
                            $show_refund = false;

                            if ($status === '2' && !empty($departure_date_raw)) {
                                // departure_date format: "19-03-2026 19h30" or "19h30 19-03-2026"
                                $clean_date = str_replace('h', ':', (string)$departure_date_raw);
                                try {
                                    $departure_time = new DateTime($clean_date, new DateTimeZone('Asia/Ho_Chi_Minh'));
                                    $now = new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh'));

                                    if ($now < $departure_time) {
                                        $show_refund = true;
                                    }
                                } catch (Exception $e) {
                                    // Fallback if parsing fails
                                    $show_refund = true;
                                }
                            }

                            if ($show_refund): ?>
                                <button
                                    type="button"
                                    class="view-ticket-btn btn-refund-ticket"
                                    data-post-id="<?php echo esc_attr(get_the_ID()); ?>"
                                    data-booking-code="<?php echo esc_attr(get_post_meta(get_the_ID(), 'booking_codes', true)); ?>"
                                    data-journey-group-id="<?php echo esc_attr(get_post_meta(get_the_ID(), 'journey_group_id', true)); ?>"
                                    data-partner-id="<?php echo esc_attr(get_post_meta(get_the_ID(), 'partner_id', true)); ?>"
                                    style="margin-left: 8px;">
                                    Hủy vé hoàn tiền
                                </button>
                            <?php endif; ?>


                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <p style="text-align: center; margin-top: 16px;">Không tìm thấy vé nào.</p>
<?php endif;

    return ob_get_clean();
}

function render_ajax_pagination($query, $current_page)
{
    $total_pages = $query->max_num_pages;

    if ($total_pages <= 1)
        return '';

    $pagination = '<div class="pagination-wrapper">';

    // Previous button
    if ($current_page > 1) {
        $pagination .= '<a href="#" class="pagination-link" data-page="' . ($current_page - 1) . '">&laquo; Trước</a>';
    }

    // Page numbers
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == $current_page) {
            $pagination .= '<span class="current">' . $i . '</span>';
        } else {
            $pagination .= '<a href="#" class="pagination-link" data-page="' . $i . '">' . $i . '</a>';
        }
    }

    // Next button
    if ($current_page < $total_pages) {
        $pagination .= '<a href="#" class="pagination-link" data-page="' . ($current_page + 1) . '">Sau &raquo;</a>';
    }

    $pagination .= '</div>';

    return $pagination;
}

function handle_ticket_pagination_ajax()
{
    if (!wp_verify_nonce($_POST['nonce'], 'auth_nonce')) {
        wp_die('Security check failed');
    }

    $page = intval($_POST['page']);
    $per_page = intval($_POST['per_page']);
    $status = sanitize_text_field($_POST['status']);
    $phone = sanitize_text_field($_POST['phone']);

    $status_query_value = $status;
    $status_query_compare = '=';

    if ($status === '3' || $status === '3,5') {
        $status_query_value = array('3', '5');
        $status_query_compare = 'IN';
    }

    $args = [
        'post_type' => 'book-ticket',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'meta_query' => [
            [
                'key' => 'phone',
                'value' => $phone,
                'compare' => '='
            ],
            [
                'key' => 'payment_status',
                'value' => $status_query_value,
                'compare' => $status_query_compare
            ]
        ]
    ];

    $query = new WP_Query($args);

    $response = array(
        'tickets' => render_ticket_cards($query),
        'pagination' => render_ajax_pagination($query, $page),
        'success' => true
    );

    wp_reset_postdata();
    wp_send_json($response);
}

add_action('wp_ajax_ticket_pagination', 'handle_ticket_pagination_ajax');
add_action('wp_ajax_nopriv_ticket_pagination', 'handle_ticket_pagination_ajax');

function auth_extract_booking_code($booking_codes_raw)
{
    $booking_codes_raw = trim((string)$booking_codes_raw);
    if ($booking_codes_raw === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $booking_codes_raw);
    $parts = is_array($parts) ? array_values(array_filter($parts)) : [];
    return (string)($parts[0] ?? '');
}

function auth_extract_seat_ids_from_booking_info($data)
{
    $seatIds = [];

    if (isset($data['seatIDs']) && is_array($data['seatIDs'])) {
        $seatIds = $data['seatIDs'];
    } elseif (isset($data['seatIds']) && is_array($data['seatIds'])) {
        $seatIds = $data['seatIds'];
    } elseif (isset($data['seat_ids']) && is_array($data['seat_ids'])) {
        $seatIds = $data['seat_ids'];
    } elseif (isset($data['seats']) && is_array($data['seats'])) {
        foreach ($data['seats'] as $s) {
            if (is_array($s)) {
                $seatIds[] = (string)($s['seatID'] ?? $s['seatId'] ?? $s['id'] ?? $s['seat_code'] ?? '');
            } else {
                $seatIds[] = (string)$s;
            }
        }
    }

    return array_values(array_filter(array_map('strval', $seatIds)));
}

function auth_extract_seat_ids_from_post($post_id)
{
    $seat_ids_raw = (string)get_post_meta($post_id, 'seat_ids', true);
    $seat_ids = [];

    if ($seat_ids_raw !== '') {
        $decoded = json_decode($seat_ids_raw, true);
        if (is_array($decoded)) {
            $seat_ids = $decoded;
        } else {
            $seat_ids = array_values(array_filter(array_map('trim', explode(',', $seat_ids_raw))));
        }
    }

    if (empty($seat_ids)) {
        $seat_meta = (string)get_post_meta($post_id, 'seat', true);
        $seat_ids = array_values(array_filter(array_map('trim', explode(',', $seat_meta))));
    }

    return array_values(array_filter(array_map('strval', $seat_ids)));
}

function auth_parse_goopay_booking_info(array $payload, $is_holiday = null)
{
    $tickets = (isset($payload['tickets']) && is_array($payload['tickets'])) ? $payload['tickets'] : [];
    $total_tickets = count($tickets);
    $total_amount = (float)($payload['finalAmount'] ?? $payload['totalAmount'] ?? 0);

    if ($total_amount <= 0) {
        foreach ($tickets as $t) {
            $total_amount += (float)($t['fare'] ?? 0);
        }
    }

    $departure_time_raw = (string)($payload['departureTime'] ?? '');
    // Handle duplicated format "Y-m-d H:i:s Y-m-d H:i:s"
    $parts = explode(' ', trim($departure_time_raw));
    $clean_departure = trim(($parts[0] ?? '') . ' ' . ($parts[1] ?? ''));
    $departure_ts = strtotime($clean_departure);

    // relies on is_holiday meta passed from handler
    if ($is_holiday === null) {
        $is_holiday = false;
    }

    $now_ts = time();
    $diff_seconds = $departure_ts - $now_ts;
    $diff_hours = $diff_seconds / 3600;
    $diff_minutes = $diff_seconds / 60;

    $allow_cancel = false;
    $fee_percent = 0;
    $allow_message = '';

    // Kiểm tra thêm điều kiện acceptRefund từ API (bao gồm cả trong từng vé)
    $has_non_refundable = false;
    foreach ($tickets as $t) {
        if (isset($t['acceptRefund']) && ($t['acceptRefund'] === false || $t['acceptRefund'] === 'false')) {
            $has_non_refundable = true;
            break;
        }
    }

    if ($has_non_refundable || (isset($payload['acceptRefund']) && ($payload['acceptRefund'] === false || $payload['acceptRefund'] === 'false'))) {
        return [
            'allow_cancel'       => false,
            'allow_message'      => 'Quý khách vui lòng ra quầy vé xe Phương Trang để được hỗ trợ',
            'cancel_fee'         => 0,
            'refund_amount'      => 0,
            'seat_ids'           => [],
            'status'             => (string)($payload['status'] ?? ''),
            'status_description' => (string)($payload['statusDescription'] ?? ''),
            'refund_before'      => '',
        ];
    }

    if ($diff_minutes < 30) {
        return [
            'allow_cancel'       => false,
            'allow_message'      => 'Không cho phép hủy vé dưới 30 phút trước giờ khởi hành.',
            'cancel_fee'         => 0,
            'refund_amount'      => 0,
            'seat_ids'           => [],
            'status'             => (string)($payload['status'] ?? ''),
            'status_description' => (string)($payload['statusDescription'] ?? ''),
            'refund_before'      => '',
        ];
    }

    if ($is_holiday) {
        if ($total_tickets <= 3) {
            if ($diff_hours >= 24) {
                $allow_cancel = true;
                $fee_percent = 0.3;
            } else {
                $allow_message = 'Hủy vé Lễ/Tết (1-3 vé) phải trước 24h.';
            }
        } elseif ($total_tickets <= 5) {
            if ($diff_hours >= 48) {
                $allow_cancel = true;
                $fee_percent = 0.3;
            } else {
                $allow_message = 'Hủy vé Lễ/Tết (trên 4 vé) phải trước 48h.';
            }
        } else {
            if ($diff_hours >= 72) {
                $allow_cancel = true;
                $fee_percent = 0.3;
            } else {
                $allow_message = 'Hủy vé Lễ/Tết (>10 vé) phải trước 72h.';
            }
        }
    } else {
        // Ngày thường
        if ($total_tickets <= 3) {
            if ($diff_minutes >= 30 && $diff_hours < 4) {
                $allow_cancel = true;
                $fee_percent = 0.3;
            } elseif ($diff_hours >= 4) {
                $allow_cancel = true;
                $fee_percent = 0.1;
            } else {
                $allow_message = 'Hủy vé phải trước giờ khởi hành ít nhất 30 phút.';
            }
        } elseif ($total_tickets <= 5) {
            if ($diff_hours >= 24) {
                $allow_cancel = true;
                $fee_percent = 0.1;
            } else {
                $allow_message = 'Hủy vé đoàn trên 4 vé phải trước 24h.';
            }
        } else {
            // > 10 vé
            if ($diff_hours >= 48) {
                $allow_cancel = true;
                $fee_percent = 0.1;
            } else {
                $allow_message = 'Hủy vé đoàn trên 10 vé phải trước 48h. Vui lòng ra quầy vé xe Phương Trang để hủy trực tiếp, phí hủy 30%.';
            }
        }
    }

    if ($allow_cancel && empty($allow_message)) {
        $allow_message = 'Vé được phép hủy hoàn tiền.';
    }

    $cancel_fee = $total_amount * $fee_percent;
    $refund_amount = (float)max(0, $total_amount - $cancel_fee);

    $seat_ids = [];
    foreach ($tickets as $t) {
        $seat_ids[] = (string)($t['id'] ?? $t['seatId'] ?? $t['seatID'] ?? $t['seatCode'] ?? '');
    }

    $refund_before_ts = null;
    foreach ($tickets as $t) {
        $rb = isset($t['refundBefore']) ? trim((string)$t['refundBefore']) : '';
        if ($rb !== '' && is_numeric($rb)) {
            $rb_int = (int)$rb;
            if ($rb_int > 1000000000000) $rb_int = (int)floor($rb_int / 1000);
            if ($rb_int > 0 && ($refund_before_ts === null || $rb_int < $refund_before_ts)) {
                $refund_before_ts = $rb_int;
            }
        }
    }
    $refund_before = $refund_before_ts ? wp_date('H:i d/m/Y', $refund_before_ts) : '';

    return [
        'allow_cancel'       => $allow_cancel,
        'allow_message'      => $allow_message,
        'cancel_fee'         => $cancel_fee,
        'refund_amount'      => $refund_amount,
        'seat_ids'           => array_values(array_filter(array_map('strval', $seat_ids))),
        'status'             => (string)($payload['status'] ?? ''),
        'status_description' => (string)($payload['statusDescription'] ?? ''),
        'refund_before'      => $refund_before,
    ];
}


function auth_get_ticket_post_for_refund($post_id)
{
    if (!is_customer_logged_in() && !is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'Vui lòng đăng nhập.');
    }

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'book-ticket') {
        return new WP_Error('not_found', 'Không tìm thấy vé.');
    }

    $phone_meta = (string)get_post_meta($post_id, 'phone', true);
    $session_phone = (string)($_SESSION['customer_data']['phone'] ?? '');

    if ($session_phone !== '' && $phone_meta !== '' && $phone_meta !== $session_phone && !is_user_logged_in()) {
        return new WP_Error('forbidden', 'Bạn không có quyền thao tác vé này.');
    }

    return $post;
}

function handle_preview_refund_ticket_ajax()
{
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'auth_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if ($post_id <= 0) {
        wp_send_json_error(['message' => 'Thiếu post_id']);
        return;
    }

    $post = auth_get_ticket_post_for_refund($post_id);
    if (is_wp_error($post)) {
        wp_send_json_error(['message' => $post->get_error_message()]);
        return;
    }

    $status = (string)get_post_meta($post_id, 'payment_status', true);
    if ($status !== '2') {
        wp_send_json_error(['message' => 'Chỉ hỗ trợ hoàn tiền cho vé đã thanh toán.']);
        return;
    }

    $partner = strtolower((string)get_post_meta($post_id, 'partner_id', true));
    if ($partner === '' && function_exists('get_field')) {
        $partner = strtolower((string)(get_field('partner_id', $post_id) ?? ''));
    }

    $booking_codes_raw = (string)get_post_meta($post_id, 'booking_codes', true);
    if ($booking_codes_raw === '' && function_exists('get_field')) {
        $booking_codes_raw = (string)(get_field('booking_codes', $post_id) ?? '');
    }
    $booking_code = auth_extract_booking_code($booking_codes_raw);

    if ($booking_code === '' || ($partner !== 'vexere' && $partner !== 'goopay')) {
        wp_send_json_error(['message' => 'Thiếu dữ liệu booking hoặc partner không hợp lệ.']);
        return;
    }

    $info_resp = call_api_v2('booking/' . rawurlencode($booking_code), 'GET', ['partner' => $partner]);
    if (is_wp_error($info_resp)) {
        wp_send_json_error(['message' => $info_resp->get_error_message()]);
        return;
    }

    $info_data = json_decode(wp_remote_retrieve_body($info_resp), true);
    $payload = $info_data['data'] ?? $info_data;
    if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
        $payload = $payload[0];
    }
    if (!is_array($payload)) {
        $payload = [];
    }

    if ($partner === 'goopay') {
        $is_holiday_meta = get_post_meta($post_id, 'is_holiday', true);
        $is_holiday = $is_holiday_meta === '' ? null : (bool)$is_holiday_meta;
        $goopay_preview = auth_parse_goopay_booking_info($payload, $is_holiday);
        $cancel_fee = (float)$goopay_preview['cancel_fee'];
        $refund_amount = (float)$goopay_preview['refund_amount'];
        $seat_ids = auth_extract_seat_ids_from_post($post_id);
        $allow_cancel = (bool)$goopay_preview['allow_cancel'];
        $allow_message = (string)$goopay_preview['allow_message'];
        if (empty($seat_ids)) {
            $allow_cancel = false;
            $allow_message = 'Không tìm thấy seat_ids trong dữ liệu vé đã lưu.';
        }
        $status_description = (string)$goopay_preview['status_description'];
        $refund_before = (string)$goopay_preview['refund_before'];
    } else {
        $cancel_fee = (float)($payload['cancel_fee'] ?? $payload['cancelFee'] ?? $payload['refundFee'] ?? $payload['fee'] ?? 0);
        $refund_amount = (float)($payload['refund'] ?? $payload['refundAmount'] ?? $payload['refund_money'] ?? $payload['refundMoney'] ?? 0);
        $seat_ids = auth_extract_seat_ids_from_booking_info($payload);
        $allow_cancel = true;
        $allow_message = 'Vé được phép hủy hoàn tiền.';
        $status_description = (string)($payload['statusDescription'] ?? '');
        $refund_before = '';
    }

    // Kiểm tra thêm điều kiện acceptRefund từ API (áp dụng cho tất cả partner, bao gồm cả trong từng vé)
    $has_non_refundable = false;
    $tickets_payload = $payload['tickets'] ?? [];
    if (is_array($tickets_payload)) {
        foreach ($tickets_payload as $t) {
            if (isset($t['acceptRefund']) && ($t['acceptRefund'] === false || $t['acceptRefund'] === 'false')) {
                $has_non_refundable = true;
                break;
            }
        }
    }

    if ($has_non_refundable || (isset($payload['acceptRefund']) && ($payload['acceptRefund'] === false || $payload['acceptRefund'] === 'false'))) {
        $allow_cancel = false;
        $allow_message = 'Quý khách vui lòng ra quầy vé xe Phương Trang để được hỗ trợ';
    }

    wp_send_json_success([
        'post_id'       => $post_id,
        'partner'       => $partner,
        'booking_code'  => $booking_code,
        'cancel_fee'    => $cancel_fee,
        'refund_amount' => $refund_amount,
        'allow_cancel'  => $allow_cancel,
        'allow_message' => $allow_message,
        'status_description' => $status_description,
        'refund_before' => $refund_before,
        'seat_ids'      => $seat_ids,
        'raw'           => $payload,
    ]);
}

function handle_confirm_refund_ticket_ajax()
{
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'auth_nonce')) {
        wp_send_json_error(['message' => 'Security check failed']);
        return;
    }

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : 'Khách hàng yêu cầu hủy vé';

    if ($post_id <= 0) {
        wp_send_json_error(['message' => 'Thiếu post_id']);
        return;
    }

    $post = auth_get_ticket_post_for_refund($post_id);
    if (is_wp_error($post)) {
        wp_send_json_error(['message' => $post->get_error_message()]);
        return;
    }

    $status = (string)get_post_meta($post_id, 'payment_status', true);
    if ($status !== '2') {
        wp_send_json_error(['message' => 'Chỉ hỗ trợ hoàn tiền cho vé đã thanh toán.']);
        return;
    }

    $partner = strtolower((string)get_post_meta($post_id, 'partner_id', true));
    if ($partner === '' && function_exists('get_field')) {
        $partner = strtolower((string)(get_field('partner_id', $post_id) ?? ''));
    }

    $booking_codes_raw = (string)get_post_meta($post_id, 'booking_codes', true);
    if ($booking_codes_raw === '' && function_exists('get_field')) {
        $booking_codes_raw = (string)(get_field('booking_codes', $post_id) ?? '');
    }
    $booking_code = auth_extract_booking_code($booking_codes_raw);

    if ($booking_code === '' || ($partner !== 'vexere' && $partner !== 'goopay')) {
        wp_send_json_error(['message' => 'Thiếu dữ liệu booking hoặc partner không hợp lệ.']);
        return;
    }

    $cancel_fee = 0;
    $refund_amount = 0;

    if ($partner === 'goopay') {
        $info_resp = call_api_v2('booking/' . rawurlencode($booking_code), 'GET', ['partner' => 'goopay']);
        if (is_wp_error($info_resp)) {
            wp_send_json_error(['message' => $info_resp->get_error_message()]);
            return;
        }

        $info_data = json_decode(wp_remote_retrieve_body($info_resp), true);
        $payload = $info_data['data'] ?? $info_data;
        if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $is_holiday_meta = get_post_meta($post_id, 'is_holiday', true);
        $is_holiday = $is_holiday_meta === '' ? null : (bool)$is_holiday_meta;
        $goopay_preview = auth_parse_goopay_booking_info($payload, $is_holiday);

        if (empty($goopay_preview['allow_cancel'])) {
            wp_send_json_error([
                'message' => (string)$goopay_preview['allow_message'],
                'raw'     => $payload,
            ]);
            return;
        }

        $seat_ids = auth_extract_seat_ids_from_post($post_id);
        if (empty($seat_ids)) {
            wp_send_json_error([
                'message' => 'Không tìm thấy seat_ids trong dữ liệu vé đã lưu.',
                'raw'     => $payload,
            ]);
            return;
        }

        $refund_resp = call_api_v2('booking/goopay/refund', 'POST', [
            'bookingList' => [
                [
                    'bookingNo' => $booking_code,
                    'reason'    => $reason,
                    'seatIds'   => array_values(array_map('strval', $seat_ids)),
                ]
            ],
        ]);

        if (is_wp_error($refund_resp)) {
            wp_send_json_error(['message' => $refund_resp->get_error_message()]);
            return;
        }

        $refund_data = json_decode(wp_remote_retrieve_body($refund_resp), true);
        $ok = ((string)($refund_data['code'] ?? '') === '0') || (strtolower((string)($refund_data['message'] ?? '')) === 'success');
        if (!$ok) {
            wp_send_json_error([
                'message' => (string)($refund_data['message'] ?? 'Hoàn tiền Goopay thất bại'),
                'raw'     => $refund_data,
            ]);
            return;
        }

        $cancel_fee    = (float)$goopay_preview['cancel_fee'];
        $refund_amount = (float)$goopay_preview['refund_amount'];
    } else {
        $refund_resp = call_api_v2('booking/vexere/refund', 'POST', [
            'code'           => $booking_code,
            'transaction_id' => '',
        ]);

        if (is_wp_error($refund_resp)) {
            wp_send_json_error(['message' => $refund_resp->get_error_message()]);
            return;
        }

        $refund_data = json_decode(wp_remote_retrieve_body($refund_resp), true);
        $payload = $refund_data['data'] ?? $refund_data;
        if (!is_array($payload)) {
            $payload = [];
        }
        $ok = strtolower((string)($payload['message'] ?? $refund_data['message'] ?? '')) === 'success';
        if (!$ok) {
            wp_send_json_error([
                'message' => (string)($payload['message'] ?? $refund_data['message'] ?? 'Hoàn tiền Vexere thất bại'),
                'raw'     => $refund_data,
            ]);
            return;
        }
        $cancel_fee    = (float)($payload['cancel_fee'] ?? $payload['cancelFee'] ?? 0);
        $refund_amount = (float)($payload['refund'] ?? $payload['refundAmount'] ?? 0);
    }

    update_post_meta($post_id, 'payment_status', 5);
    update_post_meta($post_id, 'refund_reason', $reason);
    update_post_meta($post_id, 'refund_amount', $refund_amount);
    update_post_meta($post_id, 'refund_fee', $cancel_fee);

    wp_send_json_success([
        'post_id'       => $post_id,
        'booking_code'  => $booking_code,
        'partner'       => $partner,
        'cancel_fee'    => $cancel_fee,
        'refund_amount' => $refund_amount,
        'message'       => 'Hủy vé hoàn tiền thành công.',
    ]);
}

add_action('wp_ajax_preview_refund_ticket', 'handle_preview_refund_ticket_ajax');
add_action('wp_ajax_nopriv_preview_refund_ticket', 'handle_preview_refund_ticket_ajax');
add_action('wp_ajax_confirm_refund_ticket', 'handle_confirm_refund_ticket_ajax');
add_action('wp_ajax_nopriv_confirm_refund_ticket', 'handle_confirm_refund_ticket_ajax');

add_shortcode('ticket_lookup', 'render_ticket_lookup_shortcode');
