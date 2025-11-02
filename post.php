<?php

require_once 'vendor/autoload.php';

use Abraham\TwitterOAuth\TwitterOAuth;

// Load default settings from JSON config file
function loadDefaultSettings() {
    $settingsFile = __DIR__ . '/config/settings.default.json';
    if (file_exists($settingsFile)) {
        $json = file_get_contents($settingsFile);
        $settings = json_decode($json, true);
        if ($settings) {
            return [
                'xProfileLink' => $settings['contentGeneration']['xProfileLink'] ?? 'https://x.com/dope_as_yola',
                'replicateToken' => $settings['replicate']['apiToken'] ?? '',
                'twitterApiKey' => $settings['twitter']['apiKey'] ?? '',
                'twitterApiSecret' => $settings['twitter']['apiSecret'] ?? '',
                'twitterAccessToken' => $settings['twitter']['accessToken'] ?? '',
                'twitterAccessSecret' => $settings['twitter']['accessSecret'] ?? ''
            ];
        }
    }
    // Fallback defaults if JSON file doesn't exist or can't be parsed (empty - must be provided via POST or config)
    return [
        'xProfileLink' => 'https://x.com/dope_as_yola',
        'replicateToken' => '',
        'twitterApiKey' => '',
        'twitterApiSecret' => '',
        'twitterAccessToken' => '',
        'twitterAccessSecret' => ''
    ];
}

$defaultSettings = loadDefaultSettings();

header('Content-Type: application/json');

try {
    $tweet = $_POST['tweet'] ?? '';
    $imageUrl = $_POST['image_url'] ?? '';
    
    // Get Twitter credentials from POST if provided, otherwise use defaults from config
    $apiKey = $_POST['api_key'] ?? $defaultSettings['twitterApiKey'];
    $apiSecret = $_POST['api_secret'] ?? $defaultSettings['twitterApiSecret'];
    $accessToken = $_POST['access_token'] ?? $defaultSettings['twitterAccessToken'];
    $accessSecret = $_POST['access_secret'] ?? $defaultSettings['twitterAccessSecret'];
    
    error_log("POST data: tweet=" . substr($tweet, 0, 50) . "... imageUrl=" . $imageUrl);
    
    if (empty($tweet)) {
        throw new Exception('No tweet text provided');
    }
    
    if (empty($imageUrl)) {
        throw new Exception('No image URL provided');
    }
    
    // Initialize Twitter connection
    $connection = new TwitterOAuth($apiKey, $apiSecret, $accessToken, $accessSecret);
    $connection->setApiVersion('1.1');
    
    // Download image to temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'twitter_img');
    $imageData = file_get_contents($imageUrl);
    
    if ($imageData === false) {
        throw new Exception('Failed to download image');
    }
    
    file_put_contents($tempFile, $imageData);
    
    // Upload media to Twitter
    $media = $connection->upload('media/upload', [
        'media' => $tempFile
    ]);
    
    // Clean up temp file
    unlink($tempFile);
    
    if (isset($media->errors)) {
        $error = $media->errors[0]->message ?? 'Failed to upload media';
        throw new Exception('Media upload error: ' . $error);
    }
    
    $mediaId = $media->media_id_string;
    
    // Post tweet with media using v2 API
    $connection->setApiVersion('2');
    $result = $connection->post('tweets', [
        'text' => $tweet,
        'media' => ['media_ids' => [$mediaId]]
    ]);
    
    if (isset($result->errors)) {
        $error = $result->errors[0]->detail ?? $result->errors[0]->message ?? 'Failed to post tweet';
        throw new Exception('Tweet post error: ' . $error);
    }
    
    // Success
    echo json_encode([
        'success' => true,
        'tweet_id' => $result->data->id ?? '',
        'tweet_url' => isset($result->data->id) 
            ? 'https://twitter.com/i/web/status/' . $result->data->id
            : ''
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}


