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
 * Privacy subsystem implementation for local_aireader.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aireader\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_aireader.
 *
 * Several tables hold per-user data:
 *
 *   - `local_aireader_position(userid, assetid, position)`: per-learner resume
 *     position. Joined to `local_aireader_asset.contextid` to determine which
 *     module context the data belongs to.
 *   - `local_aireader_listen(userid, assetid, startms, endms)`: per-learner
 *     listened ranges used for activity completion.
 *   - `local_aireader_override(cmid, chapterid, enabled, usermodified)`:
 *     authorship metadata recording which manager toggled narration on/off.
 *   - `local_aireader_completion(cmid, enabled, threshold, usermodified)`:
 *     activity-completion settings and the manager who last changed them.
 *
 * @package local_aireader
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe what user data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_aireader_position',
            [
                'userid'       => 'privacy:metadata:position:userid',
                'assetid'      => 'privacy:metadata:position:assetid',
                'position'     => 'privacy:metadata:position:position',
                'timemodified' => 'privacy:metadata:position:timemodified',
            ],
            'privacy:metadata:position'
        );

        $collection->add_database_table(
            'local_aireader_listen',
            [
                'userid'       => 'privacy:metadata:listen:userid',
                'assetid'      => 'privacy:metadata:listen:assetid',
                'startms'      => 'privacy:metadata:listen:startms',
                'endms'        => 'privacy:metadata:listen:endms',
                'timemodified' => 'privacy:metadata:listen:timemodified',
            ],
            'privacy:metadata:listen'
        );

        $collection->add_database_table(
            'local_aireader_override',
            [
                'cmid'         => 'privacy:metadata:override:cmid',
                'chapterid'    => 'privacy:metadata:override:chapterid',
                'enabled'      => 'privacy:metadata:override:enabled',
                'usermodified' => 'privacy:metadata:override:usermodified',
                'timemodified' => 'privacy:metadata:override:timemodified',
            ],
            'privacy:metadata:override'
        );

        $collection->add_database_table(
            'local_aireader_completion',
            [
                'cmid'         => 'privacy:metadata:completion:cmid',
                'enabled'      => 'privacy:metadata:completion:enabled',
                'threshold'    => 'privacy:metadata:completion:threshold',
                'usermodified' => 'privacy:metadata:completion:usermodified',
                'timemodified' => 'privacy:metadata:completion:timemodified',
            ],
            'privacy:metadata:completion'
        );

        $collection->add_external_location_link(
            'openai',
            [
                'text' => 'privacy:metadata:openai:text',
                'lang' => 'privacy:metadata:openai:lang',
            ],
            'privacy:metadata:openai'
        );

        return $collection;
    }

    /**
     * Find the contexts in which the given user has any data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Positions: contexts of assets the user has saved a position on.
        $sql = "SELECT DISTINCT a.contextid
                  FROM {local_aireader_position} p
                  JOIN {local_aireader_asset} a ON a.id = p.assetid
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        // Listened ranges: contexts of assets the user has listened to.
        $sql = "SELECT DISTINCT a.contextid
                  FROM {local_aireader_listen} l
                  JOIN {local_aireader_asset} a ON a.id = l.assetid
                 WHERE l.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        // Overrides: contexts where this user last toggled narration on/off.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_aireader_override} o
                  JOIN {course_modules} cm ON cm.id = o.cmid
                  JOIN {context} ctx ON ctx.contextlevel = :modulelevel AND ctx.instanceid = cm.id
                 WHERE o.usermodified = :userid";
        $contextlist->add_from_sql($sql, [
            'userid'      => $userid,
            'modulelevel' => CONTEXT_MODULE,
        ]);

        // Completion settings: contexts where this user last changed the rule.
        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_aireader_completion} c
                  JOIN {course_modules} cm ON cm.id = c.cmid
                  JOIN {context} ctx ON ctx.contextlevel = :modulelevel AND ctx.instanceid = cm.id
                 WHERE c.usermodified = :userid";
        $contextlist->add_from_sql($sql, [
            'userid'      => $userid,
            'modulelevel' => CONTEXT_MODULE,
        ]);

        return $contextlist;
    }

    /**
     * Find users in a given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "SELECT p.userid
               FROM {local_aireader_position} p
               JOIN {local_aireader_asset} a ON a.id = p.assetid
              WHERE a.contextid = :contextid",
            ['contextid' => $context->id]
        );

        $userlist->add_from_sql(
            'userid',
            "SELECT l.userid
               FROM {local_aireader_listen} l
               JOIN {local_aireader_asset} a ON a.id = l.assetid
              WHERE a.contextid = :contextid",
            ['contextid' => $context->id]
        );

        $userlist->add_from_sql(
            'usermodified',
            "SELECT o.usermodified
               FROM {local_aireader_override} o
              WHERE o.cmid = :cmid AND o.usermodified > 0",
            ['cmid' => $context->instanceid]
        );

        $userlist->add_from_sql(
            'usermodified',
            "SELECT c.usermodified
               FROM {local_aireader_completion} c
              WHERE c.cmid = :cmid AND c.usermodified > 0",
            ['cmid' => $context->instanceid]
        );
    }

    /**
     * Export all user data for the given user/contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $positions = $DB->get_records_sql(
                "SELECT p.id, p.position, p.timemodified, a.module, a.cmid, a.chapterid, a.lang
                   FROM {local_aireader_position} p
                   JOIN {local_aireader_asset} a ON a.id = p.assetid
                  WHERE p.userid = :userid AND a.contextid = :contextid",
                ['userid' => $userid, 'contextid' => $context->id]
            );
            $rows = [];
            foreach ($positions as $p) {
                $rows[] = [
                    'module'    => $p->module,
                    'cmid'      => (int)$p->cmid,
                    'chapterid' => (int)$p->chapterid,
                    'lang'      => $p->lang,
                    'position'  => (int)$p->position,
                    'updated'   => \core_privacy\local\request\transform::datetime((int)$p->timemodified),
                ];
            }
            if ($rows) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aireader'), 'positions'],
                    (object)['rows' => $rows]
                );
            }

            $listens = $DB->get_records_sql(
                "SELECT l.id, l.startms, l.endms, l.timemodified, a.module, a.cmid, a.chapterid, a.lang
                   FROM {local_aireader_listen} l
                   JOIN {local_aireader_asset} a ON a.id = l.assetid
                  WHERE l.userid = :userid AND a.contextid = :contextid
                  ORDER BY a.id ASC, l.startms ASC",
                ['userid' => $userid, 'contextid' => $context->id]
            );
            $lrows = [];
            foreach ($listens as $listen) {
                $lrows[] = [
                    'module'    => $listen->module,
                    'cmid'      => (int)$listen->cmid,
                    'chapterid' => (int)$listen->chapterid,
                    'lang'      => $listen->lang,
                    'startms'   => (int)$listen->startms,
                    'endms'     => (int)$listen->endms,
                    'updated'   => \core_privacy\local\request\transform::datetime((int)$listen->timemodified),
                ];
            }
            if ($lrows) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aireader'), 'listened_ranges'],
                    (object)['rows' => $lrows]
                );
            }

            $overrides = $DB->get_records('local_aireader_override', [
                'cmid'         => $context->instanceid,
                'usermodified' => $userid,
            ]);
            $orows = [];
            foreach ($overrides as $o) {
                $orows[] = [
                    'chapterid' => (int)$o->chapterid,
                    'enabled'   => (bool)$o->enabled,
                    'updated'   => \core_privacy\local\request\transform::datetime((int)$o->timemodified),
                ];
            }
            if ($orows) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aireader'), 'overrides_set'],
                    (object)['rows' => $orows]
                );
            }

            $completions = $DB->get_records('local_aireader_completion', [
                'cmid'         => $context->instanceid,
                'usermodified' => $userid,
            ]);
            $crows = [];
            foreach ($completions as $completion) {
                $crows[] = [
                    'enabled'   => (bool)$completion->enabled,
                    'threshold' => (int)$completion->threshold,
                    'updated'   => \core_privacy\local\request\transform::datetime((int)$completion->timemodified),
                ];
            }
            if ($crows) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aireader'), 'completion_settings_changed'],
                    (object)['rows' => $crows]
                );
            }
        }
    }

    /**
     * Delete all data for all users in this context (e.g. a course module
     * being deleted by the GDPR delete-context route).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        // Positions for any asset in this context.
        $DB->delete_records_select(
            'local_aireader_position',
            'assetid IN (SELECT id FROM {local_aireader_asset} WHERE contextid = :contextid)',
            ['contextid' => $context->id]
        );
        $DB->delete_records_select(
            'local_aireader_listen',
            'assetid IN (SELECT id FROM {local_aireader_asset} WHERE contextid = :contextid)',
            ['contextid' => $context->id]
        );

        // Override usermodified -> anonymise (override row itself is org data;
        // we just strip the user link rather than delete the row).
        $DB->set_field('local_aireader_override', 'usermodified', 0, ['cmid' => $context->instanceid]);
        $DB->set_field('local_aireader_completion', 'usermodified', 0, ['cmid' => $context->instanceid]);
    }

    /**
     * Delete a user's data in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }
            $DB->delete_records_select(
                'local_aireader_position',
                'userid = :userid AND assetid IN (SELECT id FROM {local_aireader_asset} WHERE contextid = :contextid)',
                ['userid' => $userid, 'contextid' => $context->id]
            );
            $DB->delete_records_select(
                'local_aireader_listen',
                'userid = :userid AND assetid IN (SELECT id FROM {local_aireader_asset} WHERE contextid = :contextid)',
                ['userid' => $userid, 'contextid' => $context->id]
            );
            $DB->set_field_select(
                'local_aireader_override',
                'usermodified',
                0,
                'cmid = :cmid AND usermodified = :userid',
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );
            $DB->set_field_select(
                'local_aireader_completion',
                'usermodified',
                0,
                'cmid = :cmid AND usermodified = :userid',
                ['cmid' => $context->instanceid, 'userid' => $userid]
            );
        }
    }

    /**
     * Bulk delete data for multiple users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $DB->delete_records_select(
            'local_aireader_position',
            "userid {$insql} AND assetid IN (SELECT id FROM {local_aireader_asset} WHERE contextid = :contextid)",
            $params
        );
        $DB->delete_records_select(
            'local_aireader_listen',
            "userid {$insql} AND assetid IN (SELECT id FROM {local_aireader_asset} WHERE contextid = :contextid)",
            $params
        );
        $params['cmid'] = $context->instanceid;
        $DB->set_field_select(
            'local_aireader_override',
            'usermodified',
            0,
            "cmid = :cmid AND usermodified {$insql}",
            $params
        );
        $DB->set_field_select(
            'local_aireader_completion',
            'usermodified',
            0,
            "cmid = :cmid AND usermodified {$insql}",
            $params
        );
    }
}
