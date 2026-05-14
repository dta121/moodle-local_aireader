<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => [\local_aireader\hook_callbacks::class, 'inject_player'],
        'priority' => 0,
    ],
];
