<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_aireader_get_status' => [
        'classname'   => 'local_aireader\external\get_status',
        'methodname'  => 'execute',
        'description' => 'Return audio status and URL for a Page or Book chapter.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'local/aireader:listen',
        'loginrequired' => true,
    ],
    'local_aireader_request_regen' => [
        'classname'   => 'local_aireader\external\request_regen',
        'methodname'  => 'execute',
        'description' => 'Mark the asset stale and queue a fresh generation.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'local/aireader:manage',
        'loginrequired' => true,
    ],
];
