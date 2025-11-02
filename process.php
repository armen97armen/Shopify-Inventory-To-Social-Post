<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    // Get input
    $shopifyUrl = $_POST['shopify_url'] ?? '';
    $xProfileLink = $_POST['x_profile_link'] ?? $defaultSettings['xProfileLink'];
    $replicateToken = $_POST['replicate_token'] ?? $defaultSettings['replicateToken'];
    
    if (empty($shopifyUrl)) {
        throw new Exception('Missing required parameters');
    }
    
    // Use token from POST if provided, otherwise use default from config
    $activeReplicateToken = !empty($replicateToken) ? $replicateToken : $defaultSettings['replicateToken'];
    
    // Step 1: Analyze writing style from X profile using Grok-4
    $styleAnalysis = analyzeWritingStyle($xProfileLink, $activeReplicateToken);
    
    // Step 2: Scrape Shopify page to get product image
    $productData = scrapeShopify($shopifyUrl);
    
    // Step 3: Generate tweet with comprehensive prompt using GPT-5-Nano
    // Calculate dynamic character limit based on link length
    // Total limit is 270, minus link length, minus 2 for double newline
    $linkLength = mb_strlen($shopifyUrl);
    $maxTweetLength = 270 - $linkLength - 2; // 270 total limit - link - double newline
    
    $tweetPrompt = trim(file_get_contents('prompts/gpt_generation_prompt.txt'));
    
    // Replace the X profile link variable in the prompt template with the user's setting
    $tweetPrompt = str_replace('{X_PROFILE_LINK}', $xProfileLink, $tweetPrompt);
    
    // Replace the extracted tweets variable with the style analysis
    $tweetPrompt = str_replace('{EXTRACTED_TWEETS}', $styleAnalysis, $tweetPrompt);
    
    // Replace the dynamic max length variable with calculated value
    $tweetPrompt = str_replace('{MAX_TWEET_LENGTH}', (string)$maxTweetLength, $tweetPrompt);
    
    $rawAIOutput = callGPTNanoAPI($tweetPrompt, $productData['image'], $activeReplicateToken);
    
    // Clean up the tweet - extract only the tweet text
    $generatedTweet = trim($rawAIOutput);
    
    // Clean up spacing - remove ALL extra whitespace including newlines
    $generatedTweet = preg_replace('/\s+/u', ' ', $generatedTweet);
    $generatedTweet = trim($generatedTweet);
    
    // Step 4: Capitalize naturally using GPT-5-Nano
    $capitalizedTweet = capitalizeNaturally($generatedTweet, $activeReplicateToken);
    
    // Always append product link at the end with spacing (double newline for separation)
    // Remove link if AI accidentally included it, then add it properly at the end
    $capitalizedTweet = preg_replace('/\s*' . preg_quote($shopifyUrl, '/') . '\s*/i', '', $capitalizedTweet);
    $capitalizedTweet = trim($capitalizedTweet) . "\n\n" . $shopifyUrl;
    
    $generatedTweet = $capitalizedTweet;
    
    // Return JSON response
    echo json_encode([
        'tweet' => $generatedTweet,
        'image_url' => $productData['image'],
        'extracted_tweets' => $styleAnalysis // Now contains style analysis instead of raw tweets
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

function callGrokAPI($prompt, $token) {
    // Grok-4 API format for style analysis
    $input = [
        'prompt' => $prompt,
        'top_p' => 1,
        'max_tokens' => 2048,
        'temperature' => 0.1,
        'presence_penalty' => 0,
        'frequency_penalty' => 0
    ];
    
    $postData = json_encode(['input' => $input]);
    
    $ch = curl_init('https://api.replicate.com/v1/models/xai/grok-4/predictions');
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Prefer: wait'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception('Grok-4 API Error: HTTP ' . $httpCode . ' - ' . $response);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['output'])) {
        // Try different output formats
        if (isset($data['text'])) {
            return $data['text'];
        }
        if (isset($data['result'])) {
            return $data['result'];
        }
        if (isset($data['outputs']) && is_array($data['outputs'])) {
            return implode('', $data['outputs']);
        }
        throw new Exception('Invalid response from Grok-4 API. Response: ' . substr(json_encode($data), 0, 500));
    }
    
    // Handle output if it's an array (multiple outputs)
    if (is_array($data['output'])) {
        // Check if it's an array of strings or objects
        if (is_string($data['output'][0] ?? null)) {
            return implode('', $data['output']);
        }
        // If it's an array of objects, try to extract text
        $textParts = [];
        foreach ($data['output'] as $item) {
            if (is_string($item)) {
                $textParts[] = $item;
            } elseif (is_array($item) && isset($item['text'])) {
                $textParts[] = $item['text'];
            }
        }
        if (!empty($textParts)) {
            return implode('', $textParts);
        }
        return json_encode($data['output']);
    }
    
    return $data['output'];
}

function callGPTNanoAPI($prompt, $imageUrl = null, $token = null) {
    // Use provided token or fallback to constant
    if ($token === null) {
        $token = defined('REPLICATE_TOKEN') ? REPLICATE_TOKEN : '';
    }
    
    // GPT-5-Nano API format for tweet generation
    $input = [
        'prompt' => $prompt,
        'messages' => [],
        'verbosity' => 'medium',
        'image_input' => [],
        'reasoning_effort' => 'minimal'
    ];
    
    // Add image if provided
    if ($imageUrl) {
        $input['image_input'] = [$imageUrl];
    }
    
    $postData = json_encode(['input' => $input]);
    
    $ch = curl_init('https://api.replicate.com/v1/models/openai/gpt-5-nano/predictions');
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Prefer: wait'
        ]
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception('GPT-5-Nano API Error: HTTP ' . $httpCode . ' - ' . $response);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['output'])) {
        // Try different output formats
        if (isset($data['text'])) {
            return $data['text'];
        }
        if (isset($data['result'])) {
            return $data['result'];
        }
        throw new Exception('Invalid response from GPT-5-Nano API: ' . $response);
    }
    
    // Handle output if it's an array (multiple outputs)
    if (is_array($data['output'])) {
        return implode('', $data['output']);
    }
    
    return $data['output'];
}

function scrapeShopify($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Failed to fetch Shopify page');
    }
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Extract product image
    $image = '';
    $imageNodes = $xpath->query('//meta[@property="og:image"]');
    if ($imageNodes->length > 0) {
        $image = $imageNodes->item(0)->getAttribute('content');
    } else {
        // Try other image selectors
        $imageNodes = $xpath->query('//img[@class="product-image"] | //img[@id="product-image"] | //img[contains(@class, "product")]');
        if ($imageNodes->length > 0) {
            $image = $imageNodes->item(0)->getAttribute('src');
        }
    }
    
    // Fallback if nothing found
    if (empty($image)) {
        $image = 'https://via.placeholder.com/800x600?text=Product';
    }
    
    return ['image' => $image];
}

function capitalizeNaturally($tweet, $token = null) {
    try {
        // Load capitalization prompt template from file
        $capPrompt = trim(file_get_contents('prompts/capitalization_prompt.txt'));
        
        // Replace the tweet text variable in the prompt template
        $capPrompt = str_replace('{TWEET_TEXT}', $tweet, $capPrompt);
        
        // Use GPT-5-Nano to capitalize naturally
        $capitalizedTweet = callGPTNanoAPI($capPrompt, null, $token);
        
        // Clean up the capitalized tweet
        $capitalizedTweet = trim($capitalizedTweet);
        
        // If capitalization failed or returned empty, return original
        if (empty($capitalizedTweet) || strlen($capitalizedTweet) < 10) {
            return $tweet;
        }
        
        // Clean up spacing
        $capitalizedTweet = preg_replace('/\s+/u', ' ', $capitalizedTweet);
        $capitalizedTweet = trim($capitalizedTweet);
        
        return $capitalizedTweet;
    } catch (Exception $e) {
        // If capitalization fails, return original tweet
        return $tweet;
    }
}

function analyzeWritingStyle($profileUrl, $token) {
    try {
        // Use Grok-4 to analyze writing style directly from the handle/URL
        // Load style analysis prompt template from file
        $stylePrompt = trim(file_get_contents('prompts/style_analysis_prompt.txt'));
        
        // Replace the X profile link variable in the prompt template
        $stylePrompt = str_replace('{X_PROFILE_LINK}', $profileUrl, $stylePrompt);
        
        // Call Grok-4 API for style analysis
        $rawAIOutput = callGrokAPI($stylePrompt, $token);
        
        // Clean up the style analysis
        $styleAnalysis = trim($rawAIOutput);
        
        // Check for failure message
        if (empty($styleAnalysis) || strlen($styleAnalysis) < 50) {
            return 'No writing style could be analyzed from the profile: ' . $profileUrl;
        }
        
        return $styleAnalysis;
    } catch (Exception $e) {
        // Return error message so user can see what happened
        return 'Error analyzing writing style: ' . $e->getMessage();
    }
}


