<?php
/**
 * Database initialization and connection helper
 * Uses SQLite for simplicity
 */

function getDatabase() {
    $dbPath = __DIR__ . '/data/scheduled_tweets.db';
    $dbDir = dirname($dbPath);
    
    // Create data directory if it doesn't exist
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS scheduled_tweets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tweet_text TEXT NOT NULL,
                image_url TEXT NOT NULL,
                shopify_url TEXT,
                scheduled_time DATETIME NOT NULL,
                status TEXT DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                posted_at DATETIME,
                error_message TEXT,
                twitter_api_key TEXT,
                twitter_api_secret TEXT,
                twitter_access_token TEXT,
                twitter_access_secret TEXT
            )
        ");
        
        // Create index on scheduled_time for faster queries
        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_scheduled_time_status 
            ON scheduled_tweets(scheduled_time, status)
        ");
        
        return $db;
    } catch (PDOException $e) {
        throw new Exception('Database error: ' . $e->getMessage());
    }
}
