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
 * AutoTag feature.
 *
 * @package    qbank_gigaai
 * @copyright  2025 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../config.php');
require_once($CFG->dirroot . '/question/bank/gigaai/lib.php');

$courseid = required_param('courseid', PARAM_INT);

$url = new moodle_url('/question/bank/gigaai/autotag.php', ['courseid' => $courseid]);
$PAGE->set_url($url);

require_login($courseid);
core_question\local\bank\helper::require_plugin_enabled('qbank_gigaai');

$context = context_course::instance($courseid);
$PAGE->set_context($context);

$returnurl = optional_param('returnurl', 0, PARAM_LOCALURL);

if ($returnurl) {
    $returnurl = new moodle_url($returnurl);
}

$questionlist = [];

// Single question.
$questionid = optional_param('questionid', 0, PARAM_INT);

// Bulk action (inspired by question/bank/deletequestion/delete.php).
if (!$questionid) {
    $rawquestions = $_REQUEST;

    foreach ($rawquestions as $key => $value) {
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $questionlist[] = intval($matches[1]);
        }
    }
} else {
    $questionlist[] = $questionid;
}

// Check if any question was selected.
if (empty($questionlist)) {
    throw new moodle_exception('noquestionselected', 'qbank_gigaai');
}

// Check that the user has the capability to tag questions.
foreach ($questionlist as $questionid) {
    question_require_capability_on($questionid, 'tag');
}

// Check for GigaChat API key.
$gigachatapikey = qbank_gigaai_get_apikey($courseid);

if (empty($gigachatapikey)) {
    throw new moodle_exception('noapikey', 'qbank_gigaai');
}

$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('autotag', 'qbank_gigaai'));
$PAGE->set_heading(get_string('autotag', 'qbank_gigaai'));
echo $OUTPUT->header();

echo html_writer::tag('p', get_string('autotagintro', 'qbank_gigaai'));
echo html_writer::start_tag('ul');

foreach ($questionlist as $questionid) {
    $question = question_bank::load_question($questionid);
    echo html_writer::tag('li', $question->name);
}

echo html_writer::end_tag('ul');

echo html_writer::tag('button', get_string('autotag', 'qbank_gigaai'), ["class" => "btn btn-primary", "id" => "id_autotagbutton"]);

echo html_writer::tag('div', '', ["class" => "mt-3 alert", "id" => "id_autotagresult"]);

echo html_writer::tag('a', get_string('return', 'qbank_gigaai'), ["href" => $returnurl, "class" => "d-block mt-4"]);

// Add Javascript module.
global $PAGE;
$PAGE->requires->js_call_amd('qbank_gigaai/autotag', 'init', [$questionlist]);

echo $OUTPUT->footer();
