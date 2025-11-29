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
 * Callback implementations for Generative AI Question Bank
 *
 * @package    qbank_genai
 * @copyright  2023 Christian Grévisse <christian.grevisse@uni.lu>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php'); // Needed for get_next_version().

/**
 * Defines the necessary capabilities for this plugin.
 *
 * @return array The necessary capabilities
 */
function qbank_genai_required_capabilities() {
    return ['moodle/question:add', 'moodle/course:viewhiddenactivities'];
}

/**
 * Insert a link to the secondary navigation of a course.
 *
 * @param navigation_node $navigation The settings navigation object
 * @param stdClass $course The course
 * @param context $context Course context
 */
function qbank_genai_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context) {
    if (!\core\plugininfo\qbank::is_plugin_enabled('qbank_genai')) {
        return;
    }

    if (!isloggedin() || isguestuser() || !has_all_capabilities(qbank_genai_required_capabilities(), $context)) {
        return;
    }

    $navigation->add(
        get_string('title', 'qbank_genai'),
        new moodle_url('/question/bank/genai/index.php', ['courseid' => $course->id]),
        navigation_node::COURSE_INDEX_PAGE,
    );

    $navigation->add(
        get_string('settings', 'qbank_genai'),
        new moodle_url('/question/bank/genai/genai_settings.php', ['courseid' => $course->id]),
        navigation_node::COURSE_INDEX_PAGE,
    );
}

/**
 * Get all resources of a course.
 *
 * @param stdClass $course The course
 * @return cm_info[] The resources
 */
function qbank_genai_get_course_resources(stdClass $course) {
    $info = get_fast_modinfo($course);
    return $info->instances['resource'] ?? [];
}

/**
 * Get the name, extension and path on the file storage for the first file associated to a resource (if any).
 *
 * @param int $resourceid The ID of the resource
 * @return stdClass The file info
 */
function qbank_genai_get_fileinfo_for_resource(int $resourceid) {
    $fs = get_file_storage();
    $cmid = context_module::instance($resourceid)->id;
    $files = $fs->get_area_files($cmid, 'mod_resource', 'content', 0, 'filename', false);
    $file = reset($files);

    if ($file) {
        $filename = $file->get_filename();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $path = $fs->get_file_system()->get_remote_path_from_storedfile($file);

        return (object) [
            "file" => $file,
            "name" => $filename,
            "extension" => strtolower($extension),
            "path" => $path,
        ];
    } else {
        return null;
    }
}

/**
 * Retrieves the GigaChat token, if any. It first checks the course-specific settings, then the site-wide settings.
 *
 * @param int $courseid ID of the course.
 * @return string|null The GigaChat token or null.
 */
function qbank_genai_get_gigachat_token(int $courseid) {
    global $DB, $USER;

    // Note: We're using the same table for GigaChat tokens since the plugin has been converted
    $coursesettings = $DB->get_record('qbank_genai_openai_settings', ["courseid" => $courseid, "userid" => $USER->id]);

    if ($coursesettings && !empty($coursesettings->openaiapikey)) {
        return $coursesettings->openaiapikey;  // This field now stores the GigaChat token
    }

    $gigachat_token = get_config('qbank_genai', 'gigachat_token');

    if (!empty($gigachat_token)) {
        return $gigachat_token;
    }

    return null;
}

/**
 * Retrieves and - if necessary - creates an OpenAI Assistant for the same level
 * (course-specific/site-wide) as the OpenAI API key.
 *
 * @param int $courseid ID of the course in which questions will be created.
 * @param int $userid ID of the user executing the task.
 * @return string ID of the assistant.
 */
function qbank_genai_get_or_create_openai_assistant(int $courseid, int $userid) {
    global $DB;

    $assistantid = null;

    $coursesettings = $DB->get_record('qbank_genai_openai_settings', ["courseid" => $courseid, "userid" => $userid]);

    if ($coursesettings && !empty($coursesettings->openaiapikey)) {
        // Course-specific.
        $assistantid = $coursesettings->assistantid;

        if (empty($assistantid)) {
            $assistantid = _qbank_genai_create_openai_assistant($coursesettings->openaiapikey);
            $DB->update_record('qbank_genai_openai_settings', ["id" => $coursesettings->id, "assistantid" => $assistantid]);
        }
    } else {
        // Site-wide.
        $assistantid = get_config('qbank_genai', 'assistantid');

        if (empty($assistantid)) {
            $assistantid = _qbank_genai_create_openai_assistant(get_config('qbank_genai', 'openaiapikey'));
            set_config("assistantid", $assistantid, "qbank_genai");
        }
    }

    return $assistantid;
}

/**
 * Creates an OpenAI Assistant.
 *
 * @param string $apikey The OpenAI API key.
 * @return string ID of the created assistant.
 */
function _qbank_genai_create_openai_assistant(string $apikey) {
    $client = OpenAI::client($apikey);

    $response = $client->assistants()->create([
        'name' => 'MCQ Generator',
        'instructions' => 'You create multiple-choice questions about the files that you will receive.',
        'model' => 'gpt-4o',
        'tools' => [['type' => 'file_search']],
    ]);

    return $response->id;
}

/**
 * Creates a new question category in the question bank of the given course. Adapted from /question/tests/generator/lib.php.
 *
 * @param int $contextid The ID of the context
 * @param string $resourcedescription The description about the resources for which questions are generated
 * @return stdClass Record of the new question category
 */
function qbank_genai_create_question_category(int $contextid, string $resourcedescription) {
    global $DB;

    $record = [
        'name'       => 'GenAI (' . date('d/m/Y H:i:s') . ')',
        'info'       => 'Generative AI-based questions on: ' . format_string($resourcedescription),
        'infoformat' => FORMAT_HTML,
        'stamp'      => make_unique_id_code(),
        'sortorder'  => 999,
        'idnumber'   => null,
        'contextid'  => $contextid,
        'parent'     => question_get_top_category($contextid, true)->id,
    ];

    $record['id'] = $DB->insert_record('question_categories', $record);
    return (object) $record;
}

/**
 * Returns a comma-separated string representation of all resource names.
 *
 * @param stdClass[] $resources Array of resources
 * @return string The description string
 */
function qbank_genai_get_resource_names_string($resources) {
    return implode(", ", array_map(fn($r): string => $r->name, $resources));
}

/**
 * Programmatically create an MCQ question.
 *
 * Based on code from save_question() in /question/type/questiontypebase.php
 * and save_question_options() /question/type/multichoice/questiontype.php.
 *
 * @param string $name The question name
 * @param stdClass $question The MCQ data
 * @param stdClass $category Information about the category and context
 */
function qbank_genai_create_question(string $name, stdClass $question, stdClass $category) {
    global $USER, $DB;

    $transaction = $DB->start_delegated_transaction();

    $qdata = new stdClass();

    $qdata->category = $category->id;
    $qdata->qtype = 'multichoice';
    $qdata->name = $name;
    $qdata->parent = 0;
    $qdata->length = 1;
    $qdata->defaultmark = 1;
    $qdata->penalty = 0;
    $qdata->questiontext = $question->stem; // FIXME: Cleanse.
    $qdata->questiontextformat = FORMAT_HTML;
    $qdata->generalfeedback = '';
    $qdata->generalfeedbackformat = FORMAT_HTML;

    $qdata->stamp = make_unique_id_code();
    $qdata->createdby = $USER->id;
    $qdata->modifiedby = $USER->id;
    $t = time();
    $qdata->timecreated = $t;
    $qdata->timemodified = $t;
    $qdata->idnumber = null;

    // Create a record for the question.
    $qdata->id = $DB->insert_record('question', $qdata);

    // Create a record for the question bank entry.
    $questionbankentry = new stdClass();
    $questionbankentry->questioncategoryid = $category->id;
    $questionbankentry->idnumber = $qdata->idnumber;
    $questionbankentry->ownerid = $qdata->createdby;
    $questionbankentry->id = $DB->insert_record('question_bank_entries', $questionbankentry);

    // Create a record for the question versions.
    $questionversion = new stdClass();
    $questionversion->questionbankentryid = $questionbankentry->id;
    $questionversion->questionid = $qdata->id;
    $questionversion->version = get_next_version($questionbankentry->id);
    $questionversion->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
    $questionversion->id = $DB->insert_record('question_versions', $questionversion);

    // Create answer records.
    foreach ($question->answers as $a) {
        $answer = new stdClass();
        $answer->question = $qdata->id;
        $answer->answer = $a->text; // FIXME: Cleanse.
        $answer->answerformat = FORMAT_HTML;
        $answer->feedback = '';
        $answer->feedbackformat = FORMAT_HTML;
        $answer->fraction = $a->weight;
        $answer->id = $DB->insert_record('question_answers', $answer);
    }

    // Question a record for the question's options.
    $options = new stdClass();
    $options->questionid = $qdata->id;
    $options->correctfeedback = '';
    $options->correctfeedbackformat = FORMAT_HTML;
    $options->partiallycorrectfeedback = '';
    $options->partiallycorrectfeedbackformat = FORMAT_HTML;
    $options->incorrectfeedback = '';
    $options->incorrectfeedbackformat = FORMAT_HTML;
    $options->single = 1;
    $options->shuffleanswers = 1;
    $options->answernumbering = 'abc';
    $options->showstandardinstruction = 1;
    $options->shownumcorrect = 1;
    $options->id = $DB->insert_record('qtype_multichoice_options', $options);

    // Log the creation of this question.
    $context = \context::instance_by_id($category->contextid, IGNORE_MISSING);
    $event = \core\event\question_created::create_from_question_instance($qdata, $context);
    $event->trigger();

    // Commit the transaction.
    $transaction->allow_commit();
}

/**
 * Programmatically creates a Description question.
 *
 * Based on code from /question/type/description/
 *
 * @param string $name The question name
 * @param string $text The question text
 * @param stdClass $category Information about the category and context
 */
function qbank_genai_add_description(string $name, string $text, stdClass $category) {
    global $USER, $DB;

    $transaction = $DB->start_delegated_transaction();

    $qdata = new stdClass();
    $qdata->category = $category->id;
    $qdata->qtype = 'description';
    $qdata->name = $name;
    $qdata->questiontext = $text;
    $qdata->questiontextformat = FORMAT_HTML;
    $qdata->parent = 0;
    $qdata->defaultmark = 0;
    $qdata->length = 0;
    $qdata->penalty = 0;
    $qdata->generalfeedback = '';
    $qdata->generalfeedbackformat = FORMAT_HTML;
    $qdata->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
    $qdata->timecreated = time();
    $qdata->timemodified = time();
    $qdata->createdby = $USER->id;
    $qdata->modifiedby = $USER->id;
    $qdata->stamp = make_unique_id_code();
    $qdata->idnumber = null;
    $qdata->contextid = 0;
    $qdata->hints = [];
    $qdata->options = new stdClass();
    $qdata->options->answers = [];

    // Create a record for the question.
    $qdata->id = $DB->insert_record('question', $qdata);

    // Create a record for the question bank entry.
    $questionbankentry = new stdClass();
    $questionbankentry->questioncategoryid = $category->id;
    $questionbankentry->idnumber = $qdata->idnumber;
    $questionbankentry->ownerid = $qdata->createdby;
    $questionbankentry->id = $DB->insert_record('question_bank_entries', $questionbankentry);

    // Create a record for the question versions.
    $questionversion = new stdClass();
    $questionversion->questionbankentryid = $questionbankentry->id;
    $questionversion->questionid = $qdata->id;
    $questionversion->version = get_next_version($questionbankentry->id);
    $questionversion->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
    $questionversion->id = $DB->insert_record('question_versions', $questionversion);

    // Log the creation of this question.
    $context = \context::instance_by_id($category->contextid, IGNORE_MISSING);
    $event = \core\event\question_created::create_from_question_instance($qdata, $context);
    $event->trigger();

    // Commit the transaction.
    $transaction->allow_commit();
}

/**
 * Generates a random UUID v4 string.
 *
 * @return string UUID v4.
 */
function qbank_genai_generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return sprintf('%s-%s-%s-%s-%s',
        bin2hex(substr($data, 0, 4)),
        bin2hex(substr($data, 4, 2)),
        bin2hex(substr($data, 6, 2)),
        bin2hex(substr($data, 8, 2)),
        bin2hex(substr($data, 10, 6))
    );
}

/**
 * Obtains an OAuth 2.0 access token from GigaChat.
 *
 * @return string Valid access token.
 * @throws moodle_exception If token retrieval fails.
 */
function qbank_genai_get_gigachat_access_token() {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $clientSecret = get_config('qbank_genai', 'gigachat_token');
    if (!$clientSecret) {
        throw new moodle_exception('gigachat_token_not_configured', 'qbank_genai');
    }

    $requestId = qbank_genai_generate_uuid_v4();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'scope=GIGACHAT_API_PERS',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'RqUID: ' . $requestId,
            'Authorization: Basic ' . $clientSecret,
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Note: should be true in production
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new moodle_exception('gigachat_oauth_error', 'qbank_genai', null, $httpCode);
    }

    $data = json_decode($response, true);
    if (!isset($data['access_token'])) {
        throw new moodle_exception('gigachat_no_access_token', 'qbank_genai');
    }

    return $data['access_token'];
}

/**
 * Sends a question generation request to the GigaChat API.
 *
 * @param string $content The content for question generation.
 * @param string $model The model to use
 * @param array $attachments Optional file IDs to attach to the request.
 * @return string Raw JSON response from GigaChat.
 * @throws moodle_exception On API error.
 */
function qbank_genai_call_gigachat_generator(string $content, string $model = 'GigaChat-Max', array $attachments = []): string {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $accessToken = qbank_genai_get_gigachat_access_token();
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 60);

    $prompt = "Ты — генератор тестовых заданий. Создай 10 вопросов с множественным выбором по следующему контенту. ";
    $prompt .= "Каждый вопрос должен иметь 4 варианта ответа и только 1 правильный ответ. ";
    $prompt .= "Вопросы должны быть на русском языке. ";
    $prompt .= "Вывод должен быть в формате JSON, то есть массив объектов, где каждый объект содержит стебель (stem), ";
    $prompt .= "массив для ответов и индекс правильного ответа. Назови ключи \"stem\", \"answers\", \"correctAnswerIndex\". ";
    $prompt .= "Вывод должен содержать только JSON, ничего больше. ";
    $prompt .= "Контент: {$content}";

    $request_body = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
    ];
    
    // Add attachments if provided
    if (!empty($attachments)) {
        $request_body['attachments'] = $attachments;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($request_body),
        CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new moodle_exception('gigachat_api_error', 'qbank_genai', null, $code);
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['choices']) || !isset($data['choices'][0]['message']['content'])) {
        throw new moodle_exception('gigachat_invalid_response', 'qbank_genai');
    }
    
    return $data['choices'][0]['message']['content'];
}

/**
 * Sends a question generation request to the GigaChat API with file.
 *
 * @param string $fileid The file ID for question generation.
 * @param string $model The model to use
 * @return string Raw JSON response from GigaChat.
 * @throws moodle_exception On API error.
 */
function qbank_genai_call_gigachat_generator_with_file(string $fileid, string $model = 'GigaChat-Max'): string {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $accessToken = qbank_genai_get_gigachat_access_token();
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 60);

    $prompt = "Создай 10 вопросов с множественным выбором по содержанию предоставленного файла. ";
    $prompt .= "Каждый вопрос должен иметь 4 варианта ответа и только 1 правильный ответ. ";
    $prompt .= "Вопросы должны быть на русском языке. ";
    $prompt .= "Вывод должен быть в формате JSON, то есть массив объектов, где каждый объект содержит стебель (stem), ";
    $prompt .= "массив для ответов и индекс правильного ответа. Назови ключи \"stem\", \"answers\", \"correctAnswerIndex\". ";
    $prompt .= "Вывод должен содержать только JSON, ничего больше.";

    $request_body = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.7,
        'attachments' => [$fileid],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($request_body),
        CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new moodle_exception('gigachat_api_error', 'qbank_genai', null, $code);
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['choices']) || !isset($data['choices'][0]['message']['content'])) {
        throw new moodle_exception('gigachat_invalid_response', 'qbank_genai');
    }
    
    return $data['choices'][0]['message']['content'];
}

/**
 * Uploads a file to GigaChat storage.
 *
 * @param string $filepath Path to the file to upload
 * @param string $filename Name of the file
 * @param string $purpose Purpose of the file (default: "general")
 * @return string File ID
 * @throws moodle_exception On upload error
 */
function qbank_genai_upload_file_to_gigachat(string $filepath, string $filename, string $purpose = "general"): string {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $accessToken = qbank_genai_get_gigachat_access_token();
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 60);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/files',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filepath, mime_content_type($filepath), $filename),
            'purpose' => $purpose,
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new moodle_exception('gigachat_file_upload_error', 'qbank_genai', null, $code);
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['id'])) {
        throw new moodle_exception('gigachat_file_upload_invalid_response', 'qbank_genai');
    }

    return $data['id'];
}

/**
 * Parses GigaChat's JSON response into question data.
 *
 * @param string $raw Raw response content.
 * @param int $retry_count Number of retries remaining (for error reporting)
 * @return array Parsed question data
 */
function qbank_genai_parse_gigachat_response(string $raw, int $retry_count = 0): array {
    $json = trim(preg_replace('/^```json\s*|\s*```$/i', '', $raw));
    $data = json_decode($json, true);
    
    if (!$data) {
        // Try to extract JSON from text if direct parsing fails
        $pattern = '/\{(?:[^{}]|(?R))*\}/';
        preg_match($pattern, $json, $matches);
        if (!empty($matches)) {
            $data = json_decode($matches[0], true);
        }
    }
    
    if ($data && isset($data[0]['stem']) && isset($data[0]['answers']) && isset($data[0]['correctAnswerIndex'])) {
        return ['questions' => $data];
    } else {
        // Return error with retry information
        return [
            'error' => 'Failed to parse GigaChat response into expected question format',
            'retry_needed' => $retry_count > 0
        ];
    }
}
