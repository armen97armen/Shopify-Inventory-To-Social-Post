<?php
/**
 * Cancel Scheduled Tweet Endpoint
 * Cancels a scheduled tweet
 */

require_once 'database.php';

header('Content-Type: application/json');

try {
    $id = $_POST['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        throw new Exception('Invalid tweet ID');
    }
    
    $db = getDatabase();
    
    // Check if tweet exists and is pending
    $stmt = $db->prepare("
        SELECT id, status FROM scheduled_tweets WHERE id = ?
    ");
    $stmt->execute([$id]);
    $tweet = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tweet) {
        throw new Exception('Scheduled tweet not found');
    }
    
    if ($tweet['status'] !== 'pending') {
        throw new Exception('Only pending tweets can be cancelled');
    }
    
    // Delete the scheduled tweet
    $stmt = $db->prepare("DELETE FROM scheduled_tweets WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Scheduled tweet cancelled successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
