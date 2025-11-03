<?php
/**
 * Schedule Tweet Endpoint
 * Saves a scheduled tweet to the database
 */

require_once 'database.php';

header('Content-Type: application/json');

try {
    $tweet = $_POST['tweet'] ?? '';
    $imageUrl = $_POST['image_url'] ?? '';
    $shopifyUrl = $_POST['shopify_url'] ?? '';
    $scheduledTime = $_POST['scheduled_time'] ?? '';
    $twitterApiKey = $_POST['api_key'] ?? '';
    $twitterApiSecret = $_POST['api_secret'] ?? '';
    $twitterAccessToken = $_POST['access_token'] ?? '';
    $twitterAccessSecret = $_POST['access_secret'] ?? '';
    
    // Validation
    if (empty($tweet)) {
        throw new Exception('No tweet text provided');
    }
    
    if (empty($imageUrl)) {
        throw new Exception('No image URL provided');
    }
    
    if (empty($scheduledTime)) {
        throw new Exception('No scheduled time provided');
    }
    
    // Validate and parse scheduled time
    // Frontend sends ISO 8601 format (e.g., "2024-01-15T14:35:00.000Z") with timezone info
    // This allows PHP to parse it correctly regardless of server timezone
    
    // Create DateTime from ISO string (will include timezone info)
    $scheduledDateTime = new DateTime($scheduledTime);
    
    // Get current time in UTC for comparison
    $now = new DateTime('now', new DateTimeZone('UTC'));
    
    // Ensure scheduled time is also in UTC for comparison
    $scheduledDateTime->setTimezone(new DateTimeZone('UTC'));
    
    // Add a small buffer (10 seconds) to account for any processing delays
    $bufferSeconds = 10;
    $nowWithBuffer = clone $now;
    $nowWithBuffer->modify("+{$bufferSeconds} seconds");
    
    // Compare timestamps to ensure scheduled time is in the future
    if ($scheduledDateTime->getTimestamp() <= $nowWithBuffer->getTimestamp()) {
        $secondsDiff = $scheduledDateTime->getTimestamp() - $now->getTimestamp();
        throw new Exception('Scheduled time must be at least 10 seconds in the future. Please select a later time.');
    }
    
    // Scheduled time is already in UTC, ready to store
    
    // Get database connection
    $db = getDatabase();
    
    // Insert scheduled tweet
    $stmt = $db->prepare("
        INSERT INTO scheduled_tweets 
        (tweet_text, image_url, shopify_url, scheduled_time, twitter_api_key, twitter_api_secret, twitter_access_token, twitter_access_secret, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $stmt->execute([
        $tweet,
        $imageUrl,
        $shopifyUrl,
        $scheduledDateTime->format('Y-m-d H:i:s'),
        $twitterApiKey,
        $twitterApiSecret,
        $twitterAccessToken,
        $twitterAccessSecret
    ]);
    
    $id = $db->lastInsertId();
    
    // Return success with scheduled tweet info
    echo json_encode([
        'success' => true,
        'id' => $id,
        'scheduled_time' => $scheduledDateTime->format('Y-m-d H:i:s'),
        'message' => 'Tweet scheduled successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
