<?php
/**
 * Get Scheduled Tweets Endpoint
 * Returns list of scheduled tweets
 */

require_once 'database.php';

header('Content-Type: application/json');

try {
    $db = getDatabase();
    
    // Get all scheduled tweets (pending and posted for history)
    $stmt = $db->prepare("
        SELECT 
            id,
            tweet_text,
            image_url,
            shopify_url,
            scheduled_time,
            status,
            created_at,
            posted_at,
            error_message
        FROM scheduled_tweets
        ORDER BY scheduled_time DESC
        LIMIT 50
    ");
    
    $stmt->execute();
    $tweets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert UTC times to ISO 8601 format with timezone for proper JavaScript parsing
    // The database stores times in UTC, we'll return them with explicit UTC indicator
    foreach ($tweets as &$tweet) {
        // Parse UTC datetime and convert to ISO 8601 format (with Z for UTC)
        $scheduledDateTime = new DateTime($tweet['scheduled_time'], new DateTimeZone('UTC'));
        $tweet['scheduled_time_iso'] = $scheduledDateTime->format('c'); // ISO 8601 format
        
        $createdDateTime = new DateTime($tweet['created_at'], new DateTimeZone('UTC'));
        $tweet['created_at_iso'] = $createdDateTime->format('c');
        
        if ($tweet['posted_at']) {
            $postedDateTime = new DateTime($tweet['posted_at'], new DateTimeZone('UTC'));
            $tweet['posted_at_iso'] = $postedDateTime->format('c');
        }
    }
    
    echo json_encode([
        'success' => true,
        'tweets' => $tweets
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
