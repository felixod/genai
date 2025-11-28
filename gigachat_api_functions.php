<?php
/**
 * API functions for GigaChat integration
 * 
 * Contains functions for:
 * - Authentication
 * - Chat completions
 * - File handling (upload, list, info, delete)
 * - Audio transcription
 * 
 * Authors: Original authors + Gorbatov Sergey s.gorbatov@tu-ugmk.com
 * Year: 2025
 */

class gigachat_api_functions {

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
    public function getGigaChatAccessToken() {
        $clientSecret = get_config('qtype_essay_gigachat', 'gigachat_token');
        if (!$clientSecret) {
            throw new moodle_exception('gigachat_token_not_configured', 'qtype_essay_gigachat');
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
            throw new moodle_exception('gigachat_oauth_error', 'qtype_essay_gigachat', null, $httpCode);
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new moodle_exception('gigachat_no_access_token', 'qtype_essay_gigachat');
        }

        return $data['access_token'];
    }

    /**
     * Uploads a file to GigaChat storage.
     *
     * @param string $filepath Path to the local file to upload
     * @param string $filename Name of the file
     * @param string $purpose Purpose of the file (default: "general")
     * @return array File information from the API response
     * @throws moodle_exception If upload fails
     */
    public function uploadFile($filepath, $filename, $purpose = "general") {
        if (!file_exists($filepath)) {
            throw new moodle_exception('gigachat_file_not_found', 'qtype_essay_gigachat');
        }

        $accessToken = $this->getGigaChatAccessToken();
        $timeout = (int)get_config('qtype_essay_gigachat', 'gigachat_timeout', 30);

        // Create cURL file object
        if (function_exists('curl_file_create')) {
            $cfile = curl_file_create($filepath, mime_content_type($filepath), $filename);
        } else {
            $cfile = '@' . $filepath;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/files',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => $cfile,
                'purpose' => $purpose
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new moodle_exception('gigachat_file_upload_error', 'qtype_essay_gigachat', null, $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['id'])) {
            throw new moodle_exception('gigachat_file_upload_invalid_response', 'qtype_essay_gigachat');
        }

        return $data;
    }

    /**
     * Gets a list of available files in GigaChat storage.
     *
     * @return array List of file objects
     * @throws moodle_exception If request fails
     */
    public function listFiles() {
        $accessToken = $this->getGigaChatAccessToken();
        $timeout = (int)get_config('qtype_essay_gigachat', 'gigachat_timeout', 30);

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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new moodle_exception('gigachat_list_files_error', 'qtype_essay_gigachat', null, $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['data'])) {
            throw new moodle_exception('gigachat_list_files_invalid_response', 'qtype_essay_gigachat');
        }

        return $data['data'];
    }

    /**
     * Gets information about a specific file.
     *
     * @param string $fileId ID of the file to get info for
     * @return array File information
     * @throws moodle_exception If request fails
     */
    public function getFileInfo($fileId) {
        $accessToken = $this->getGigaChatAccessToken();
        $timeout = (int)get_config('qtype_essay_gigachat', 'gigachat_timeout', 30);

        $url = 'https://gigachat.devices.sberbank.ru/api/v1/files/' . urlencode($fileId);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new moodle_exception('gigachat_file_info_error', 'qtype_essay_gigachat', null, $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['id'])) {
            throw new moodle_exception('gigachat_file_info_invalid_response', 'qtype_essay_gigachat');
        }

        return $data;
    }

    /**
     * Deletes a file from GigaChat storage.
     *
     * @param string $fileId ID of the file to delete
     * @return array Response from the API
     * @throws moodle_exception If deletion fails
     */
    public function deleteFile($fileId) {
        $accessToken = $this->getGigaChatAccessToken();
        $timeout = (int)get_config('qtype_essay_gigachat', 'gigachat_timeout', 30);

        $url = 'https://gigachat.devices.sberbank.ru/api/v1/files/' . urlencode($fileId) . '/delete';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,  // Method is POST for deletion according to API docs
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new moodle_exception('gigachat_file_delete_error', 'qtype_essay_gigachat', null, $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['deleted'])) {
            throw new moodle_exception('gigachat_file_delete_invalid_response', 'qtype_essay_gigachat');
        }

        return $data;
    }

    /**
     * Performs audio transcription using GigaChat API.
     *
     * @param string $fileId ID of the uploaded audio file
     * @param string $model Model to use for transcription (default: standard model)
     * @param string $language Language of the audio (optional)
     * @param string $responseFormat Format of the response (text or json)
     * @return array Transcription result
     * @throws moodle_exception If transcription fails
     */
    public function transcribeAudio($fileId, $model = null, $language = null, $responseFormat = 'json') {
        $accessToken = $this->getGigaChatAccessToken();
        $timeout = (int)get_config('qtype_essay_gigachat', 'gigachat_timeout', 60); // Longer timeout for audio processing

        $payload = [
            'file' => $fileId,
            'response_format' => $responseFormat
        ];

        if ($model) {
            $payload['model'] = $model;
        }
        if ($language) {
            $payload['language'] = $language;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://gigachat.devices.sberbank.ru/api/v1/transcriptions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new moodle_exception('gigachat_transcription_error', 'qtype_essay_gigachat', null, $httpCode);
        }

        $data = json_decode($response, true);
        if (!$data) {
            throw new moodle_exception('gigachat_transcription_invalid_response', 'qtype_essay_gigachat');
        }

        return $data;
    }

    /**
     * Makes a chat completion request to GigaChat API with file attachments.
     *
     * @param string $message The message to send to the model
     * @param array $attachments Array of file IDs to attach to the request
     * @param string $model Model to use (default: GigaChat-Pro)
     * @return string Raw response from GigaChat
     * @throws moodle_exception On API error
     */
    public function callGigaChatWithFiles($message, $attachments = [], $model = 'GigaChat-Pro') {
        $accessToken = $this->getGigaChatAccessToken();
        $timeout = (int)get_config('qtype_essay_gigachat', 'gigachat_timeout', 30);

        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $message]],
            'temperature' => 0.0,
        ];

        // Add attachments if provided
        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
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
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => false, // Should be true in production
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            throw new moodle_exception('gigachat_api_error', 'qtype_essay_gigachat', null, $code);
        }

        $data = json_decode($resp, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }
}