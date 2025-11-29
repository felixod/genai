<?php
/**
 * GigaChat API Integration Functions
 * 
 * This file contains all necessary functions to connect to GigaChat API,
 * including authentication, file handling, and question generation.
 */

/**
 * Generates a random UUID v4 string.
 *
 * @return string UUID v4.
 */
private function generateUuidV4() {
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
private function getGigaChatAccessToken() {
    $clientSecret = get_config('qbank_genai', 'gigachat_token');
    if (!$clientSecret) {
        throw new moodle_exception('gigachat_token_not_configured', 'qbank_genai');
    }

    $requestId = $this->generateUuidV4();

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
 * @param string $prompt The prompt for question generation.
 * @param array $attachments Optional file IDs to attach to the request.
 * @return string Raw JSON response from GigaChat.
 * @throws moodle_exception On API error.
 */
private function call_gigachat_generator(string $prompt, array $attachments = []): string {
    $accessToken = $this->getGigaChatAccessToken();
    $model = get_config('qbank_genai', 'gigachat_model', 'GigaChat-Max'); // Default to GigaChat-Max
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 60);

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
 * Uploads a file to GigaChat storage.
 *
 * @param string $filepath Path to the file to upload
 * @param string $filename Name of the file
 * @param string $purpose Purpose of the file (default: "general")
 * @return array File information including ID
 * @throws moodle_exception On upload error
 */
private function upload_file_to_gigachat(string $filepath, string $filename, string $purpose = "general"): array {
    $accessToken = $this->getGigaChatAccessToken();
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

    return $data;
}

/**
 * Gets list of files from GigaChat storage.
 *
 * @return array List of file objects
 * @throws moodle_exception On API error
 */
private function list_gigachat_files(): array {
    $accessToken = $this->getGigaChatAccessToken();
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 30);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/files',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new moodle_exception('gigachat_list_files_error', 'qbank_genai', null, $code);
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['data'])) {
        throw new moodle_exception('gigachat_list_files_invalid_response', 'qbank_genai');
    }

    return $data['data'];
}

/**
 * Gets information about a specific file from GigaChat storage.
 *
 * @param string $fileid ID of the file to get info for
 * @return array File information
 * @throws moodle_exception On API error
 */
private function get_gigachat_file_info(string $fileid): array {
    $accessToken = $this->getGigaChatAccessToken();
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 30);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/files/' . $fileid,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new moodle_exception('gigachat_get_file_error', 'qbank_genai', null, $code);
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['id'])) {
        throw new moodle_exception('gigachat_get_file_invalid_response', 'qbank_genai');
    }

    return $data;
}

/**
 * Deletes a file from GigaChat storage.
 *
 * @param string $fileid ID of the file to delete
 * @return array Response from API
 * @throws moodle_exception On API error
 */
private function delete_gigachat_file(string $fileid): array {
    $accessToken = $this->getGigaChatAccessToken();
    $timeout = (int)get_config('qbank_genai', 'gigachat_timeout', 30);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/files/' . $fileid . '/delete',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,  // Using POST as per API documentation
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
    ]);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new moodle_exception('gigachat_delete_file_error', 'qbank_genai', null, $code);
    }

    $data = json_decode($resp, true);
    if (!$data || !isset($data['id'])) {
        throw new moodle_exception('gigachat_delete_file_invalid_response', 'qbank_genai');
    }

    return $data;
}

/**
 * Parses GigaChat's JSON response into question data.
 *
 * @param string $raw Raw response content.
 * @return array Parsed question data
 */
private function parse_gigachat_response(string $raw): array {
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
    
    if (!$data) {
        return ['success' => false, 'error' => get_string('gigachat_invalid_response', 'qbank_genai')];
    }
    
    return ['success' => true, 'data' => $data];
}

/**
 * Generates multiple choice questions using GigaChat based on provided content.
 *
 * @param string $content Content to generate questions from
 * @param int $numquestions Number of questions to generate
 * @param string $language Language for questions
 * @return array Generated questions data
 * @throws moodle_exception On API error
 */
public function generate_questions_with_gigachat(string $content, int $numquestions = 10, string $language = 'ru'): array {
    $prompt = "Ты — генератор тестовых заданий. Создай {$numquestions} вопросов с множественным выбором по следующему контенту. ";
    $prompt .= "Каждый вопрос должен иметь 4 варианта ответа и только 1 правильный ответ. ";
    $prompt .= "Вопросы должны быть на языке: {$language}. ";
    $prompt .= "Вывод должен быть в формате JSON, то есть массив объектов, где каждый объект содержит стебель (stem), ";
    $prompt .= "массив для ответов и индекс правильного ответа. Назови ключи \"stem\", \"answers\", \"correctAnswerIndex\". ";
    $prompt .= "Вывод должен содержать только JSON, ничего больше. ";
    $prompt .= "Контент: {$content}";

    $max_attempts = 5;
    $attempt = 0;
    
    do {
        $attempt++;
        try {
            $response = $this->call_gigachat_generator($prompt);
            $parsed = $this->parse_gigachat_response($response);
            
            if ($parsed['success'] && is_array($parsed['data'])) {
                return $parsed['data'];
            }
        } catch (Exception $e) {
            debugging('GigaChat API error on attempt ' . $attempt . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            if ($attempt >= $max_attempts) {
                throw $e;
            }
            // Wait a bit before retrying
            sleep(1);
        }
    } while ($attempt < $max_attempts);
    
    throw new moodle_exception('gigachat_generation_failed_after_attempts', 'qbank_genai', null, $max_attempts);
}

/**
 * Generates multiple choice questions from a file using GigaChat.
 *
 * @param string $filepath Path to the file to analyze
 * @param string $filename Name of the file
 * @param int $numquestions Number of questions to generate
 * @return array Generated questions data
 * @throws moodle_exception On API error
 */
public function generate_questions_from_file_with_gigachat(string $filepath, string $filename, int $numquestions = 10): array {
    // First, upload the file to GigaChat
    $fileinfo = $this->upload_file_to_gigachat($filepath, $filename);
    $fileid = $fileinfo['id'];
    
    try {
        // Create a prompt that references the uploaded file
        $prompt = "Создай {$numquestions} вопросов с множественным выбором по содержанию предоставленного файла. ";
        $prompt .= "Каждый вопрос должен иметь 4 варианта ответа и только 1 правильный ответ. ";
        $prompt .= "Вопросы должны быть на русском языке. ";
        $prompt .= "Вывод должен быть в формате JSON, то есть массив объектов, где каждый объект содержит стебель (stem), ";
        $prompt .= "массив для ответов и индекс правильного ответа. Назови ключи \"stem\", \"answers\", \"correctAnswerIndex\". ";
        $prompt .= "Вывод должен содержать только JSON, ничего больше.";
        
        // Call GigaChat with the file attachment
        $response = $this->call_gigachat_generator($prompt, [$fileid]);
        $parsed = $this->parse_gigachat_response($response);
        
        if ($parsed['success'] && is_array($parsed['data'])) {
            return $parsed['data'];
        } else {
            throw new moodle_exception('gigachat_invalid_response_structure', 'qbank_genai');
        }
    } finally {
        // Always try to delete the uploaded file after processing
        try {
            $this->delete_gigachat_file($fileid);
        } catch (Exception $e) {
            debugging('Could not delete GigaChat file: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}