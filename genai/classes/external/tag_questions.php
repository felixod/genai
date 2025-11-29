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

namespace qbank_genai\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/bank/genai/lib.php');

/**
 * Class tag_questions
 *
 * @package    qbank_genai
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

        // Get GigaChat token from plugin settings.
        $gigachat_token = get_config('qbank_genai', 'gigachat_token');

        if (empty($gigachat_token)) {
            throw new \Exception(get_string('nogigachat_token', 'qbank_genai'));
        }

        $model = get_config('qbank_genai', 'gigachat_model', 'GigaChat-Max');

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
            $prompt = "Ты - ассистент для тегирования. Твоя задача - извлечь список наиболее важных тегов для следующего содержимого. Все теги должны быть на английском языке. ";
            $prompt .= "Содержимое: {$questiontext}. ";
            $prompt .= "Ответь в формате JSON: {\"tags\": [\"tag1\", \"tag2\", ...]}";

            try {
                $response = qbank_genai_call_gigachat_generator($prompt, $model);
                
                // Parse the response to get the tags
                $json = trim(preg_replace('/^```json\s*|\s*```$/i', '', $response));
                $data = json_decode($json, true);
                
                if (!$data || !isset($data['tags'])) {
                    // If direct parsing fails, try to extract JSON from response
                    if (preg_match('/(\[.*\])/', $json, $matches)) {
                        $json = $matches[1];
                        $data = json_decode($json, true);
                    }
                }

                if (!$data || !isset($data['tags'])) {
                    throw new \Exception(get_string('autotagparsingerror', 'qbank_genai'));
                }

                $tags = $data['tags'];
            } catch (\Exception $e) {
                throw new \Exception(get_string('autotagparsingerror', 'qbank_genai'));
            }

            \core_tag_tag::set_item_tags('core_question', 'question', $qid, \context::instance_by_id($question->contextid), $tags);

            $numbersuccessfullytagged++;
        }

        return get_string('autotagsuccess', 'qbank_genai', $numbersuccessfullytagged);
    }
}
