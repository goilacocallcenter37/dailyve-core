<?php
// Register Bus Utility Taxonomy
add_action('init', 'dailyve_register_bus_utility_taxonomy');
function dailyve_register_bus_utility_taxonomy()
{
    $labels = array(
        'name'              => 'Tiện ích nhà xe',
        'singular_name'     => 'Tiện ích',
        'search_items'      => 'Tìm tiện ích',
        'all_items'         => 'Tất cả tiện ích',
        'parent_item'       => 'Tiện ích cha',
        'parent_item_colon' => 'Tiện ích cha:',
        'edit_item'         => 'Sửa tiện ích',
        'update_item'       => 'Cập nhật tiện ích',
        'add_new_item'      => 'Thêm tiện ích mới',
        'new_item_name'     => 'Tên tiện ích mới',
        'menu_name'         => 'Tiện ích nhà xe',
    );

    $args = array(
        'hierarchical'      => true,
        'labels'            => $labels,
        'public'            => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'bus-utility'),
    );

    register_taxonomy('bus_utility', array('page'), $args);
}
