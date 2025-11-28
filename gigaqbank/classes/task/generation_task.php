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

namespace qbank_gigaqbank\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/bank/gigaqbank/lib.php');
require_once($CFG->dirroot . '/gigachat_api_functions.php');

/**
 * Class generation_task
 *
 * @package    qbank_gigaqbank
 * @copyright  2025 Gorbatov Sergey <s.gorbatov@tu-ugmk.com>
 * @copyright  2023 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_task extends \core\task\adhoc_task {
    /**
     * Factory method for this class
     *
     * @param array $resources The selected resources for which questions shall be generated
     * @param int $userid The user who started the task
     * @param int $contextid The context ID (needed for the question bank category)
     * @param int $courseid The course ID (needed for retrieving any course-specific API key)
     *
     * @return static the singleton instance
     */
    public static function instance(array $resources, int $userid, int $contextid, int $courseid) {
        $task = new self();
        $task->set_custom_data((object) [
            'resources' => $resources,
            'contextid' => $contextid,
            'courseid' => $courseid,
        ]);
        $task->set_userid($userid);

        return $task;
    }

    /**
     * This method implements the question generation by 1) extracting text from the selected resources; 2) generating
     * questions via an LLM; and 3) programmatically add the questions in a newly created question bank category.
     */
    public function execute() {
        $data = $this->get_custom_data();

        $gigachatapikey = qbank_gigaqbank_get_gigachat_apikey($data->courseid);
        if (empty($gigachatapikey)) {
            throw new \Exception('No GigaChat API key provided.');
        }

        // Initialize GigaChat API functions
        $gigachat = new gigachat_api_functions();

        $category = qbank_gigaqbank_create_question_category($data->contextid, qbank_gigaqbank_get_resource_names_string($data->resources));
        mtrace("Category created: " . $category->name);

        foreach ($data->resources as $resource) {
            $file = qbank_gigaqbank_get_fileinfo_for_resource($resource->id);

            mtrace("Processing file:");
            mtrace($file->path);

            // For GigaChat, we'll use the file upload functionality if needed
            // For now, we'll read the content directly and send it as a message
            $filecontent = file_get_contents($file->path);
            $filename = $file->name;

            // Create a prompt to generate multiple choice questions from the file content
            $message = "Create 10 multiple choice questions on the content of the following document: '$filename'. ";
            $message .= "Document content: $filecontent ";
            $message .= "Each question shall have 4 answers and only 1 correct answer. ";
            $message .= "Questions should be in the same language as the file content. ";
            $message .= "The output shall be in JSON format, i.e., an array of objects where each object contains the stem, ";
            $message .= "an array for the answers and the index of the correct answer. Name the keys \"stem\", \"answers\", ";
            $message .= "\"correctAnswerIndex\". The output shall only contain the JSON, nothing else.";

            try {
                // Call GigaChat API with the message
                $response = $gigachat->callGigaChatWithFiles($message, [], 'GigaChat-Pro');
                
                mtrace("GigaChat response received");

                // Process the response
                $questiondata = json_decode(trim($response, '`json'), true);
                
                if (is_array($questiondata)) {
                    $i = 0;

                    foreach ($questiondata as $data) {
                        mtrace(var_export($data, true));

                        $question = new \stdClass();
                        $question->stem = $data['stem'];
                        $question->answers = [];

                        foreach ($data['answers'] as $answer) {
                            $question->answers[] = (object) ["text" => $answer, "weight" => 0.0];
                        }

                        $question->answers[$data['correctAnswerIndex']]->weight = 1.0;

                        $questionname = str_pad(strval(++$i), 3, "0", STR_PAD_LEFT);

                        qbank_gigaqbank_create_question($questionname, $question, $category);

                        mtrace("Question created: $questionname");
                    }
                } else {
                    qbank_gigaqbank_add_description("Issue during question generation", $response, $category);
                }
            } catch (\Exception $e) {
                mtrace("Error during GigaChat processing: " . $e->getMessage());
                qbank_gigaqbank_add_description("Error during question generation", "Error: " . $e->getMessage(), $category);
            }
        }
    }
}
