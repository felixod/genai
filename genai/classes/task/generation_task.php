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

namespace qbank_genai\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/bank/genai/lib.php');
require_once($CFG->dirroot . '/question/bank/genai/vendor/autoload.php');

/**
 * Class generation_task
 *
 * @package    qbank_genai
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

        $gigachat_token = qbank_genai_get_gigachat_token($data->courseid);
        if (empty($gigachat_token)) {
            throw new \Exception(get_string('nogigachat_token', 'qbank_genai'));
        }

        $model = get_config('qbank_genai', 'gigachat_model', 'GigaChat-Max');

        $category = qbank_genai_create_question_category($data->contextid, qbank_genai_get_resource_names_string($data->resources));
        mtrace("Category created: " . $category->name);

        foreach ($data->resources as $resource) {
            $file = qbank_genai_get_fileinfo_for_resource($resource->id);

            mtrace("Processing file:");
            mtrace($file->path);

            // Get file content based on extension
            $content = '';
            $fileid = null;
            
            // Check if file is a text-based format that can be read directly
            $text_extensions = ['txt', 'pdf', 'doc', 'docx', 'rtf', 'odt', 'html', 'htm', 'xml'];
            if (in_array(strtolower($file->extension), $text_extensions)) {
                // For text-based files, we can read the content directly
                $tempfolder = make_temp_directory('qbank_genai');
                $copypath = $tempfolder . "/" . basename($file->path) . "." . $file->extension;
                $file->file->copy_content_to($copypath);
                
                // Extract text content from the file based on its type
                switch (strtolower($file->extension)) {
                    case 'txt':
                        $content = file_get_contents($copypath);
                        break;
                    case 'pdf':
                        // For PDF, we would use a PDF library if available
                        // For now, just read as much as possible
                        $content = file_get_contents($copypath);
                        break;
                    case 'docx':
                        // For DOCX, we could use PHPWord library if available
                        $content = file_get_contents($copypath);
                        break;
                    default:
                        $content = file_get_contents($copypath);
                        break;
                }
                
                unlink($copypath);
                
                // Limit content size to avoid exceeding API limits
                $content = substr($content, 0, 10000); // Limit to 10k characters
                
            } else {
                // For non-text files (images, etc.), upload to GigaChat
                $tempfolder = make_temp_directory('qbank_genai');
                $copypath = $tempfolder . "/" . basename($file->path) . "." . $file->extension;
                $file->file->copy_content_to($copypath);

                try {
                    mtrace("Uploading file to GigaChat:");
                    mtrace($copypath);

                    $fileid = qbank_genai_upload_file_to_gigachat($copypath, $file->name);
                    mtrace("File uploaded with ID: $fileid");
                } catch (\Exception $e) {
                    mtrace("Error uploading file to GigaChat: " . $e->getMessage());
                    unlink($copypath);
                    continue; // Skip this file and continue with the next
                }

                unlink($copypath);
            }

            // Generate questions based on whether we have content or a file ID
            $response = '';
            if ($content) {
                // Use content directly
                $response = qbank_genai_call_gigachat_generator($content, $model);
            } elseif ($fileid) {
                // Use file ID
                $response = qbank_genai_call_gigachat_generator_with_file($fileid, $model);
            } else {
                mtrace("No content or file available for processing");
                continue;
            }

            // Parse the response with retry logic
            $max_retries = 5;
            $parsed_data = null;
            $retry_count = 0;
            
            do {
                $parsed_result = qbank_genai_parse_gigachat_response($response, $max_retries - $retry_count);
                
                if (isset($parsed_result['error'])) {
                    if (!empty($parsed_result['retry_needed'])) {
                        mtrace("Retrying GigaChat response parsing (attempt " . ($retry_count + 1) . ")...");
                        // Retry by calling the API again with the same content/file
                        if ($content) {
                            $response = qbank_genai_call_gigachat_generator($content, $model);
                        } elseif ($fileid) {
                            $response = qbank_genai_call_gigachat_generator_with_file($fileid, $model);
                        }
                        $retry_count++;
                    } else {
                        mtrace("Failed to parse GigaChat response after $retry_count retries: " . $parsed_result['error']);
                        qbank_genai_add_description("Issue during question generation", $parsed_result['error'], $category);
                        break; // Exit the retry loop
                    }
                } else {
                    $parsed_data = $parsed_result['questions'];
                    break; // Success, exit the retry loop
                }
            } while ($retry_count < $max_retries && !$parsed_data);

            if ($parsed_data && is_array($parsed_data)) {
                $i = 0;

                foreach ($parsed_data as $data_item) {
                    mtrace(var_export($data_item, true));

                    $question = new \stdClass();
                    $question->stem = $data_item->stem;
                    $question->answers = [];

                    foreach ($data_item->answers as $answer) {
                        $question->answers[] = (object) ["text" => $answer, "weight" => 0.0];
                    }

                    $question->answers[$data_item->correctAnswerIndex]->weight = 1.0;

                    $questionname = str_pad(strval(++$i), 3, "0", STR_PAD_LEFT);

                    qbank_genai_create_question($questionname, $question, $category);

                    mtrace("Question created: $questionname");
                }
            } else {
                qbank_genai_add_description("Issue during question generation", "Failed to generate questions from content", $category);
            }
        }
    }
}
