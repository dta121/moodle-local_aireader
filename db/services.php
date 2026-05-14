<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * External web service function definitions for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_aireader_get_status' => [
        'classname'     => 'local_aireader\external\get_status',
        'methodname'    => 'execute',
        'description'   => 'Return audio status and URL for a Page or Book chapter.',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/aireader:listen',
        'loginrequired' => true,
    ],
    'local_aireader_request_regen' => [
        'classname'     => 'local_aireader\external\request_regen',
        'methodname'    => 'execute',
        'description'   => 'Mark the asset stale and queue a fresh generation.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aireader:manage',
        'loginrequired' => true,
    ],
    'local_aireader_set_override' => [
        'classname'     => 'local_aireader\external\set_override',
        'methodname'    => 'execute',
        'description'   => 'Enable or disable narration for a specific page or book chapter.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aireader:manage',
        'loginrequired' => true,
    ],
];
