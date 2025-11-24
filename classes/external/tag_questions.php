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

namespace qbank_gigaai\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/bank/gigaai/lib.php');

/**
 * Class tag_questions
 *
 * @package    qbank_gigaai
 * @copyright  2025 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tag_questions extends external_api {
    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'questionlist' => new external_multiple_structure(
                new external_value(PARAM_INT, 'ID of question')
            ),
        ]);
    }

    /**
     * Returns description of method result value
     * @return external_value
     */
    public static function execute_returns() {
        return new external_value(PARAM_TEXT, 'Result of the autotagging');
    }

    /**
     * Tag questions
     * @param object $questionlist The list of question IDs to tag
     * @return string The result
     */
    public static function execute($questionlist) {
        $params = self::validate_parameters(self::execute_parameters(), ['questionlist' => $questionlist]);

        $numbersuccessfullytagged = 0;

        // Get API key from plugin settings.
        $apikey = get_config('qbank_gigaai', 'apikey');

        if (empty($apikey)) {
            throw new \Exception(get_string('noapikey', 'qbank_gigaai'));
        }

        // Get model from plugin settings
        $model = get_config('qbank_gigaai', 'model');
        if (empty($model)) {
            $model = 'gigachat:latest'; // Default model
        }

        foreach ($questionlist as $qid) {
            $question = \question_bank::load_question($qid);

            $context = \context::instance_by_id($question->contextid);
            self::validate_context($context);
            question_require_capability_on($qid, 'tag');

            // Check if question has already been tagged, if so, skip it.
            $questiontags = \core_tag_tag::get_item_tags('core_question', 'question', $qid);

            if (!empty($questiontags)) {
                continue;
            }

            $questiontext = strip_tags($question->questiontext);

            foreach ($question->answers as $a) {
                $questiontext .= '\n- ' . strip_tags($a->answer);
            }

            // Call GigaChat to get tags.
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a tagging assistant. Your task is to extract a list of the most
                                    important tags for the given content. All tags shall be given in English.',
                ],
                [
                    'role' => 'user',
                    'content' => $questiontext,
                ],
            ];

            $response = qbank_gigaai_call_gigachat_api($apikey, $model, $messages, [
                'temperature' => 0.0,
            ]);

            $tags = [];

            // Parse the response to get the tags.
            try {
                $content = $response->choices[0]->message->content;
                // Extract JSON from the response if needed
                $json_start = strpos($content, '{');
                $json_end = strrpos($content, '}');
                if ($json_start !== false && $json_end !== false) {
                    $json_str = substr($content, $json_start, $json_end - $json_start + 1);
                    $parsed = json_decode($json_str, true);
                    if (isset($parsed['tags']) && is_array($parsed['tags'])) {
                        $tags = $parsed['tags'];
                    } else {
                        // If tags are not in a JSON object, try to extract them from the content
                        $tags = explode(',', $content);
                        $tags = array_map('trim', $tags);
                    }
                } else {
                    // If no JSON found, try to parse as a simple list
                    $tags = explode(',', $content);
                    $tags = array_map('trim', $tags);
                }
            } catch (\Exception $e) {
                throw new \Exception(get_string('autotagparsingerror', 'qbank_gigaai') . ' Error: ' . $e->getMessage());
            }

            \core_tag_tag::set_item_tags('core_question', 'question', $qid, \context::instance_by_id($question->contextid), $tags);

            $numbersuccessfullytagged++;
        }

        return get_string('autotagsuccess', 'qbank_gigaai', $numbersuccessfullytagged);
    }
}
