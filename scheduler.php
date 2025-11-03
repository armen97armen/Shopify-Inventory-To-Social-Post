<?php
/**
 * Scheduler Cron Script
 * This script should be run every minute via cron job or Windows Task Scheduler
 * Checks for due tweets and posts them
 */

require_once 'database.php';
require_once 'vendor/autoload.php';

use Abraham\TwitterOAuth\TwitterOAuth;

// Set time limit for long-running script
set_time_limit(300); // 5 minutes max

try {
    $db = getDatabase();
    
    // Get current time in UTC
    $now = new DateTime('now', new DateTimeZone('UTC'));
    
    // Find all pending tweets that are due (scheduled_time <= now)
    $stmt = $db->prepare("
        SELECT 
            id,
            tweet_text,
            image_url,
            scheduled_time,
            twitter_api_key,
            twitter_api_secret,
            twitter_access_token,
            twitter_access_secret
        FROM scheduled_tweets
        WHERE status = 'pending' 
        AND scheduled_time <= ?
        ORDER BY scheduled_time ASC
    ");
    
    $stmt->execute([$now->format('Y-m-d H:i:s')]);
    $dueTweets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($dueTweets)) {
        // No tweets to post
        // If called via HTTP (not cron), return JSON response
        if (php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'No due tweets found',
                'checked_at' => $now->format('Y-m-d H:i:s UTC')
            ]);
            exit(0);
        }
        exit(0);
    }
    
    $postedCount = 0;
    $failedCount = 0;
    
    foreach ($dueTweets as $tweet) {
        try {
            // Mark as processing
            $updateStmt = $db->prepare("
                UPDATE scheduled_tweets 
                SET status = 'processing' 
                WHERE id = ?
            ");
            $updateStmt->execute([$tweet['id']]);
            
            // Post the tweet using Twitter API
            $connection = new TwitterOAuth(
                $tweet['twitter_api_key'],
                $tweet['twitter_api_secret'],
                $tweet['twitter_access_token'],
                $tweet['twitter_access_secret']
            );
            $connection->setApiVersion('1.1');
            
            // Download image to temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'twitter_img_' . $tweet['id']);
            $imageData = file_get_contents($tweet['image_url']);
            
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
                'text' => $tweet['tweet_text'],
                'media' => ['media_ids' => [$mediaId]]
            ]);
            
            if (isset($result->errors)) {
                $error = $result->errors[0]->detail ?? $result->errors[0]->message ?? 'Failed to post tweet';
                throw new Exception('Tweet post error: ' . $error);
            }
            
            // Mark as posted
            $updateStmt = $db->prepare("
                UPDATE scheduled_tweets 
                SET status = 'posted', 
                    posted_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $updateStmt->execute([$tweet['id']]);
            
            // Log success (optional)
            error_log("Scheduled tweet #{$tweet['id']} posted successfully");
            
            // Track posted count for HTTP response
            $postedCount++;
            
        } catch (Exception $e) {
            // Mark as failed
            $updateStmt = $db->prepare("
                UPDATE scheduled_tweets 
                SET status = 'failed', 
                    error_message = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$e->getMessage(), $tweet['id']]);
            
            // Log error
            error_log("Failed to post scheduled tweet #{$tweet['id']}: " . $e->getMessage());
            
            // Track failed count
            $failedCount++;
        }
    }
    
    // If called via HTTP, return JSON response
    if (php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Scheduler completed',
            'tweets_processed' => count($dueTweets),
            'posted' => $postedCount,
            'failed' => $failedCount,
            'checked_at' => $now->format('Y-m-d H:i:s UTC')
        ]);
        exit(0);
    }
    
} catch (Exception $e) {
    error_log("Scheduler error: " . $e->getMessage());
    
    // If called via HTTP, return error as JSON
    if (php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit(1);
    }
    
    exit(1);
}
