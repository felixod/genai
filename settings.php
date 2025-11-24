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
 * Plugin administration pages are defined here.
 *
 * @package     qbank_gigaai
 * @category    admin
 * @copyright   2023 Christian Grévisse <christian.grevisse@uni.lu>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('qbank_gigaai_settings', new lang_string('pluginname', 'qbank_gigaai'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configpasswordunmask(
            'qbank_gigaai/apikey',
            get_string('apikey', 'qbank_gigaai'),
            get_string('apikey_help', 'qbank_gigaai'),
            '',
        ));

        $settings->add(new admin_setting_configtext(
            'qbank_gigaai/model',
            new lang_string('model', 'qbank_gigaai'),
            new lang_string('model_help', 'qbank_gigaai'),
            'gigachat:latest',
        ));
    }
}
