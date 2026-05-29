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
 * Backup support for local_aireader narration overrides.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Attaches local_aireader per-resource enable/disable overrides to each
 * supported activity (mod_page, mod_book) when it is backed up.
 *
 * The overrides are the custom settings added to the activity edit form via
 * {@see local_aireader_coursemodule_standard_elements()} (activity-level,
 * chapterid 0) and the in-player manager toggle (chapter-level, chapterid > 0),
 * so they must travel with the activity through backup/restore.
 *
 * @package    local_aireader
 * @copyright  2026 Saylor Academy
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_aireader_plugin extends backup_local_plugin {
    /**
     * Define the plugin structure attached at the module connection point.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();

        // Wrapper named after the plugin, as required for plugin structures.
        $pluginwrapper = new backup_nested_element($this->get_recommended_name());

        $overrides = new backup_nested_element('aireader_overrides');
        $override = new backup_nested_element('aireader_override', ['id'], [
            'chapterid', 'enabled', 'timemodified', 'usermodified',
        ]);

        $plugin->add_child($pluginwrapper);
        $pluginwrapper->add_child($overrides);
        $overrides->add_child($override);

        // Overrides are activity configuration (not per-user data), so they are
        // always backed up, regardless of the "include user data" setting.
        $override->set_source_table('local_aireader_override', ['cmid' => backup::VAR_MODID]);

        // The usermodified column points at a real user; annotate it so it can
        // be remapped on restore when users are part of the backup.
        $override->annotate_ids('user', 'usermodified');

        return $plugin;
    }
}
