<?php
// Add ACF Fields for Bus Utility Taxonomy
add_action('acf/init', 'dailyve_register_bus_utility_acf_fields');
function dailyve_register_bus_utility_acf_fields()
{
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group(array(
        'key' => 'group_bus_utility_fields',
        'title' => 'Cài đặt Tiện ích',
        'fields' => array(
            array(
                'key' => 'field_utility_icon',
                'label' => 'Icon tiện ích',
                'name' => 'utility_icon',
                'type' => 'image',
                'return_format' => 'url',
                'preview_size' => 'thumbnail',
                'library' => 'all',
            ),
            array(
                'key' => 'field_utility_description',
                'label' => 'Mô tả ngắn',
                'name' => 'utility_description',
                'type' => 'textarea',
                'rows' => 2,
            ),
        ),
        'location' => array(
            array(
                array(
                    'param' => 'taxonomy',
                    'operator' => '==',
                    'value' => 'bus_utility',
                ),
            ),
        ),
    ));
}
