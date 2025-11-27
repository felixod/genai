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
 * Class injector
 *
 * @package   local_gigachat
 * @copyright 2025 Gorbatov Sergey s.gorbatov@tu-ugmk.com, Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gigachat;

defined('MOODLE_INTERNAL') || die;
require_once(__DIR__ . "/../lib.php");

use context_system;
use Exception;
use local_gigachat\util\release;
use moodle_url;

/**
 * Class core_hook_output
 *
 * @package local_gigachat
 */
class core_hook_output {
    /**
     * Function before_footer_html_generation
     *
     */
    public static function before_footer_html_generation() {
        self::local_gigachat_addchat();
        self::local_gigachat_addh5p();
    }

    /**
     * Function local_gigachat_addchat
     *
     * @return void
     * @throws Exception
     */
    private static function local_gigachat_addchat() {
        global $OUTPUT, $PAGE, $COURSE, $SITE, $USER, $CFG;

        if (get_config("local_gigachat", "mode") == "none") {
            return;
        } else if (!isset(get_config("local_gigachat", "apikey")[5])) {
            return;
        } else if ($COURSE->id < 2) {
            return;
        } else if ($USER->id < 2) {
            return;
        } else if (strpos($_SERVER["REQUEST_URI"], "mod/gigachat/") >= 1) {
            return;
        } else if (!$PAGE->get_popup_notification_allowed()) {
            return;
        }

        $context = context_system::instance();
        $capability = has_capability("local/gigachat:manage", $context);
        if (!$capability) {
            $modules = explode(",", get_config("local_gigachat", "modules"));
            foreach ($modules as $module) {
                if (strpos($_SERVER["REQUEST_URI"], "mod/{$module}/") >= 1) {
                    return;
                }
            }
        }

        $agentphotourl = $OUTPUT->image_url("chat/tutor", "local_gigachat");
        if ($filepath = get_config("local_gigachat", "agentphoto")) {
            $syscontext = context_system::instance();
            $agentphotourl = moodle_url::make_file_url(
                "$CFG->wwwroot/pluginfile.php",
                "/{$syscontext->id}/local_gigachat/agentphoto/0/{$filepath}"
            );
        }

        $a = [
            "coursename" => $COURSE->fullname,
            "gigachatname" => get_config("local_gigachat", "gigachatname"),
            "moodlename" => $SITE->fullname,
        ];
        $data = [
            "message_01" => get_string("message_01", "local_gigachat", fullname($USER)),
            "message_02" => get_string("message_02", "local_gigachat", $a),
            "manage_capability" => $capability,
            "gigachatname" => get_config("local_gigachat", "gigachatname"),
            "talk_gigachat" => get_string("talk_gigachat", "local_gigachat", get_config("local_gigachat", "gigachatname")),
            "agentphotourl" => $agentphotourl,
        ];

        echo $OUTPUT->render_from_template("local_gigachat/chat", $data);
        $PAGE->requires->js_call_amd("local_gigachat/chat", "init", [$COURSE->id, release::version()]);
    }

    /**
     * Function local_gigachat_addh5p
     */
    private static function local_gigachat_addh5p() {
        global $PAGE, $COURSE;

        if (isset($_SERVER["REQUEST_URI"])) {
            if (strpos($_SERVER["REQUEST_URI"], "contentbank/") || strpos($_SERVER["REQUEST_URI"], "course/modedit.php")) {
                $contextid = \context_course::instance($COURSE->id)->id;
                $PAGE->requires->strings_for_js(["h5p-manager", "h5p-manager-scorm"], "local_gigachat");
                $PAGE->requires->js_call_amd("local_gigachat/h5p", "init", [$contextid]);
            }
        }
    }
}
