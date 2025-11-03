<?php
/**
 * Manual Scheduler Trigger Endpoint
 * Can be called via AJAX from the frontend to check and post due tweets
 * This is useful when cron job isn't set up yet or for testing
 */

// Include the scheduler logic
require_once 'scheduler.php';
