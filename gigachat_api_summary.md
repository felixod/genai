# GigaChat API Integration Summary

## Required Functions for GigaChat Integration

### 1. Authentication Functions
- `generateUuidV4()` - Generate UUID v4 for requests
- `getGigaChatAccessToken()` - Obtain OAuth 2.0 access token from GigaChat

### 2. Core API Functions
- `call_gigachat_generator()` - Send requests to GigaChat API for question generation
- `parse_gigachat_response()` - Parse JSON responses from GigaChat

### 3. File Handling Functions
- `upload_file_to_gigachat()` - Upload text documents, images, or audio files to GigaChat storage
- `list_gigachat_files()` - Get list of available files in GigaChat storage
- `get_gigachat_file_info()` - Get information about specific file
- `delete_gigachat_file()` - Delete file from GigaChat storage (marks as deleted)

### 4. Question Generation Functions
- `generate_questions_with_gigachat()` - Generate questions based on text content
- `generate_questions_from_file_with_gigachat()` - Generate questions from uploaded file

## Required GigaChat APIs

### 1. Authentication API
- **Endpoint**: `POST https://ngw.devices.sberbank.ru:9443/api/v2/oauth`
- **Purpose**: Obtain OAuth 2.0 access token
- **Required Parameters**: 
  - `scope=GIGACHAT_API_PERS`
  - `Authorization: Basic <client_secret>`
  - `RqUID: <uuid>`

### 2. Chat Completions API
- **Endpoint**: `POST https://gigachat.devices.sberbank.ru/api/v1/chat/completions`
- **Purpose**: Generate responses from GigaChat
- **Required Headers**:
  - `Authorization: Bearer <access_token>`
  - `Content-Type: application/json`
- **Request Body**:
  - `model`: GigaChat, GigaChat-Pro, or GigaChat-Max
  - `messages`: Array of message objects
  - `attachments`: Array of file IDs (optional)

### 3. File Management APIs

#### Upload File
- **Endpoint**: `POST https://gigachat.devices.sberbank.ru/api/v1/files`
- **Purpose**: Upload files to GigaChat storage
- **Method**: multipart/form-data
- **Parameters**:
  - `file`: Binary file content
  - `purpose`: "general" (default)
- **Supported Formats**:
  - **Text**: txt, doc, docx, pdf, epub, ppt, pptx
  - **Images**: jpeg, png, tiff, bmp
  - **Audio**: mp4, mp3, m4a, wav, weba, ogg, opus
- **Size Limits**:
  - Audio: up to 35 MB
  - Images: up to 15 MB
  - Text: up to 40 MB
  - Total audio/image: up to 80 MB

#### List Files
- **Endpoint**: `GET https://gigachat.devices.sberbank.ru/api/v1/files`
- **Purpose**: Get list of available files

#### Get File Info
- **Endpoint**: `GET https://gigachat.devices.sberbank.ru/api/v1/files/{file_id}`
- **Purpose**: Get information about specific file

#### Delete File
- **Endpoint**: `POST https://gigachat.devices.sberbank.ru/api/v1/files/{file_id}/delete`
- **Purpose**: Mark file as deleted

## Configuration Requirements

### Settings to Add
1. GigaChat Token (replaces OpenAI API key)
2. GigaChat Model Selection (GigaChat, GigaChat-Pro, GigaChat-Max) - default: GigaChat-Max
3. Request Timeout (default: 60 seconds)
4. Number of retry attempts for API calls (default: 5)

### Database Changes
- Update table `qbank_genai_openai_settings` to `qbank_genai_gigachat_settings`
- Change `openaiapikey` column to `gigachat_token`
- Remove `assistantid` column (not needed for GigaChat)
- Update table comment to reflect GigaChat usage

## Additional Considerations

### Audio Processing
- GigaChat supports audio files up to 35 MB
- Supported formats: mp4, mp3, m4a, wav, weba, ogg, opus
- Audio transcription functionality is built into the API when files are attached

### Error Handling
- Implement retry logic with configurable attempts (default: 5)
- Proper logging with debugging() function
- Error messages through get_string() for localization
- Handle HTTP error codes (200 vs others)
- Validate JSON responses before processing

### Security
- Ensure no personal data is sent to GigaChat
- Update privacy metadata to reflect data sent to external service
- Use SSL verification in production (currently disabled for development)

### Compatibility
- Maintain compatibility with Moodle 5.1
- Follow Moodle coding standards (PSR-12)
- Preserve existing interfaces and APIs
- Support both course-specific and site-wide configurations