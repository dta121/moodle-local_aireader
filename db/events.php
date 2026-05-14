<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_updated',
        'callback'  => '\local_aireader\observer::course_module_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_created',
        'callback'  => '\local_aireader\observer::course_module_created',
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback'  => '\local_aireader\observer::course_module_deleted',
        'internal'  => false,
    ],
    [
        'eventname' => '\mod_book\event\chapter_updated',
        'callback'  => '\local_aireader\observer::book_chapter_updated',
        'internal'  => false,
    ],
    [
        'eventname' => '\mod_book\event\chapter_deleted',
        'callback'  => '\local_aireader\observer::book_chapter_deleted',
        'internal'  => false,
    ],
];
