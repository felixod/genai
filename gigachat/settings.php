<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings file.
 *
 * @package   local_gigachat
 * @copyright 2025 Gorbatov Sergey s.gorbatov@tu-ugmk.com, Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {

    global $CFG, $PAGE, $ADMIN;

    $settings = new admin_settingpage("local_gigachat", get_string("pluginname", "local_gigachat"));

    $ADMIN->add("localplugins", $settings);

    $models = [
        "none" => get_string("mode_name_none", "local_gigachat"),
        "gigachat" => get_string("mode_name_gigachat", "local_gigachat"),
    ];
    $setting = new admin_setting_configselect(
        "local_gigachat/mode",
        get_string("mode", "local_gigachat"),
        get_string("mode_desc", "local_gigachat"),
        "none", $models
    );
    $settings->add($setting);

    // Tutor name.
    $gigachatname = get_config("local_gigachat", "gigachatname");
    if (!isset($gigachatname[2])) {
        $gigachatname = "GigaChat Assistant";
    }
    $setting = new admin_setting_configtext(
        "local_gigachat/gigachatname",
        get_string("gigachatname", "local_gigachat"),
        get_string("gigachatname_desc", "local_gigachat"),
        $gigachatname);
    $settings->add($setting);

    // Photo agent.
    $setting = new admin_setting_configstoredfile("local_gigachat/agentphoto",
        get_string("agentphoto", "local_gigachat"),
        get_string("agentphoto_desc", "local_gigachat"),
        "agentphoto", 0, ["maxfiles" => 1, "accepted_types" => [".jpeg .jpg .png .svg .tif .tiff .webm"]]);
    $settings->add($setting);

    $apikey = get_config("local_gigachat", "apikey");
    if (isset($apikey[12])) {
        $setting = new admin_setting_configpasswordunmask(
            "local_gigachat/apikey",
            get_string("apikey", "local_gigachat"),
            get_string("apikey_desc", "local_gigachat"),
            "");
        $settings->add($setting);
    } else {
        $setting = new admin_setting_configtext(
            "local_gigachat/apikey",
            get_string("apikey", "local_gigachat"),
            get_string("apikey_desc", "local_gigachat"),
            "");
        $settings->add($setting);
    }

    $models = [
        "gigachat:latest" => "GigaChat (latest)",
        "gigachat-pro" => "GigaChat Pro",
        "gigachat-max" => "GigaChat Max",
        "gigachat-plus" => "GigaChat Plus",
    ];
    $setting = new admin_setting_configselect(
        "local_gigachat/model",
        get_string("model", "local_gigachat"),
        get_string("model_desc", "local_gigachat"),
        "gigachat:latest", $models
    );
    $settings->add($setting);

    $cases = [
        "chatbot" => get_string("caseuse_chatbot", "local_gigachat"),
        "creative" => get_string("caseuse_creative", "local_gigachat"),
        "balanced" => get_string("caseuse_balanced", "local_gigachat"),
        "precise" => get_string("caseuse_precise", "local_gigachat"),
        "exploration" => get_string("caseuse_exploration", "local_gigachat"),
        "formal" => get_string("caseuse_formal", "local_gigachat"),
        "informal" => get_string("caseuse_informal", "local_gigachat"),
    ];
    $casedesc = $OUTPUT->render_from_template("local_gigachat/settings_casedesc", []);
    $settings->add(new admin_setting_configselect(
        "local_gigachat/case",
        get_string("case", "local_gigachat"),
        $casedesc,
        "chatbot",
        $cases
    ));

    $modules = [];
    $records = $DB->get_records("modules", ["visible" => 1], "name", "name");
    foreach ($records as $record) {
        if (file_exists("{$CFG->dirroot}/mod/{$record->name}/lib.php")) {
            if (!(plugin_supports("mod", $record->name, FEATURE_MOD_ARCHETYPE) === MOD_ARCHETYPE_SYSTEM)) {
                $modules[$record->name] = get_string("pluginname", $record->name);
            }
        }
    }
    $settings->add(new admin_setting_configmultiselect(
        "local_gigachat/modules",
        get_string("modules", "local_gigachat", $gigachatname),
        get_string("modules_desc", "local_gigachat", $gigachatname),
        ["glossary", "lesson", "forum", "scorm", "feedback", "survey", "quiz", "assign", "wiki", "lti", "workshop"],
        $modules
    ));

    $setting = new admin_setting_configtext(
        "local_gigachat/max_tokens",
        get_string("max_tokens", "local_gigachat"),
        get_string("max_tokens_desc", "local_gigachat"),
        200, PARAM_INT);
    $settings->add($setting);

    $penalty = [
        "-2.0" => "-2.0",
        "-1.9" => "-1.9",
        "-1.8" => "-1.8",
        "-1.7" => "-1.7",
        "-1.6" => "-1.6",
        "-1.5" => "-1.5",
        "-1.4" => "-1.4",
        "-1.3" => "-1.3",
        "-1.2" => "-1.2",
        "-1.1" => "-1.1",
        "-1.0" => "-1.0",
        "-0.9" => "-0.9",
        "-0.8" => "-0.8",
        "-0.7" => "-0.7",
        "-0.6" => "-0.6",
        "-0.5" => "-0.5",
        "-0.4" => "-0.4",
        "-0.3" => "-0.3",
        "-0.2" => "-0.2",
        "-0.1" => "-0.1",
        "0.0" => "0.0",
        "0.1" => "0.1",
        "0.2" => "0.2",
        "0.3" => "0.3",
        "0.4" => "0.4",
        "0.5" => "0.5",
        "0.6" => "0.6",
        "0.7" => "0.7",
        "0.8" => "0.8",
        "0.9" => "0.9",
        "1.0" => "1.0",
        "1.1" => "1.1",
        "1.2" => "1.2",
        "1.3" => "1.3",
        "1.4" => "1.4",
        "1.5" => "1.5",
        "1.6" => "1.6",
        "1.7" => "1.7",
        "1.8" => "1.8",
        "1.9" => "1.9",
        "2.0" => "2.0",
    ];
    $setting = new admin_setting_configselect(
        "local_gigachat/frequency_penalty",
        get_string("frequency_penalty", "local_gigachat"),
        get_string("frequency_penalty_desc", "local_gigachat"),
        "0.0", $penalty);
    $settings->add($setting);

    $setting = new admin_setting_configselect(
        "local_gigachat/presence_penalty",
        get_string("presence_penalty", "local_gigachat"),
        get_string("presence_penalty_desc", "local_gigachat"),
        "0.0", $penalty);
    $settings->add($setting);
}
