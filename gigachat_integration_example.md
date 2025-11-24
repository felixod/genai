# GigaChat Integration Example for Moodle

This document extracts the correct implementation of GigaChat integration from the Moodle AI provider plugin.

## Key Implementation Details

### Authentication Flow
- Uses OAuth 2.0 for authentication with GigaChat API
- Token caching mechanism to avoid repeated authentication requests
- Basic authentication with API key for token acquisition

### API Endpoint
- OAuth endpoint: `https://ngw.devices.sberbank.ru:9443/api/v2/oauth`
- Scope required: `GIGACHAT_API_PERS`

### Token Management
```php
private function get_access_token(): string {
    $cache = \cache::make('aiprovider_gigachat', 'token');
    $token = $cache->get('access_token');

    if (!$token) {
        $token = $this->fetch_new_access_token();
        $cache->set('access_token', $token, 1500); // Cache for 25 minutes
    }
    return $token;
}
```

### OAuth Token Fetching
```php
private function fetch_new_access_token(): string {
    $apikey = $this->config['apikey'];
    if (!$apikey) {
        throw new \moodle_exception('error_missing_apikey', 'aiprovider_gigachat');
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth ',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'scope=GIGACHAT_API_PERS',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'RqUID: ' . $this->generate_uuid(),
            'Authorization: Basic ' . trim($apikey),
        ],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpcode !== 200) {
        throw new \moodle_exception('error_oauth_failed', 'aiprovider_gigachat', '', $httpcode);
    }

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        throw new \moodle_exception('error_no_access_token', 'aiprovider_gigachat');
    }

    return $data['access_token'];
}
```

### UUID Generation
```php
private function generate_uuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
```

### Authorization Header Addition
```php
#[\Override]
public function add_authentication_headers(RequestInterface $request): RequestInterface {
    $token = $this->get_access_token();
    return $request->withHeader('Authorization', "Bearer {$token}");
}
```

### Supported AI Actions
- `generate_text`
- `summarise_text`
- `explain_text`

### Configuration Check
```php
#[\Override]
public function is_provider_configured(): bool {
    return !empty($this->config['apikey']);
}
```

### Token Invalidation
```php
public static function invalidate_cached_token(): void {
    invalidation::trigger('aiprovider_gigachat/tokeninvalidated');
}
```

## Key Features

1. **Secure Token Management**: Implements proper caching and refresh mechanisms
2. **Error Handling**: Comprehensive error handling for authentication failures
3. **Proper HTTP Headers**: Correctly formatted headers for GigaChat API requests
4. **UUID Generation**: Required for OAuth requests to GigaChat
5. **SSL Verification**: Option to disable SSL peer verification (though this should be used carefully in production)
6. **Moodle Integration**: Follows Moodle's AI provider interface standards

This implementation provides a complete example of how to integrate GigaChat with Moodle's AI subsystem, replacing other LLM providers like ChatGPT.