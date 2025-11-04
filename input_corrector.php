<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't output errors as HTML - use error_log instead
ini_set('log_errors', 1);

// Start output buffering to prevent any accidental output before JSON
ob_start();

header('Content-Type: application/json');

// Load default settings from JSON config file
function loadDefaultSettings() {
    $settingsFile = __DIR__ . '/config/settings.default.json';
    if (file_exists($settingsFile)) {
        $json = file_get_contents($settingsFile);
        $settings = json_decode($json, true);
        if ($settings) {
            return [
                'replicate' => [
                    'apiToken' => $settings['replicate']['apiToken'] ?? ''
                ]
            ];
        }
    }
    return [];
}

try {
    $userInput = $_POST['input'] ?? '';
    $productNamesJson = $_POST['productNames'] ?? '';
    
    if (empty($userInput)) {
        throw new Exception('Input is required');
    }
    
    $defaultSettings = loadDefaultSettings();
    $replicateToken = $defaultSettings['replicate']['apiToken'] ?? '';
    
    if (empty($replicateToken)) {
        throw new Exception('Replicate API token is required in config/settings.default.json');
    }
    
    // Extract product names from JSON if provided
    $productNames = [];
    if (!empty($productNamesJson)) {
        $namesArray = json_decode($productNamesJson, true);
        if (is_array($namesArray)) {
            $productNames = array_unique(array_filter($namesArray));
            // Limit to first 500 product names to avoid token limits
            $productNames = array_slice($productNames, 0, 500);
        }
    }
    
    // Call GPT-5 Nano via Replicate to fix/correct typos only, NOT to suggest full product names
    $prompt = "You are a spelling and typo correction assistant. Fix ONLY spelling mistakes and typos in the user's input. DO NOT suggest or replace with full product names.\n\n";
    $prompt .= "USER INPUT: {$userInput}\n\n";
    
    if (!empty($productNames)) {
        // Extract unique words from product names for spelling reference only
        $allWords = [];
        foreach ($productNames as $name) {
            $words = preg_split('/\s+/', strtolower($name));
            foreach ($words as $word) {
                // Remove special characters and keep only words 3+ characters
                $cleanWord = preg_replace('/[^a-z0-9]/', '', $word);
                if (strlen($cleanWord) >= 3) {
                    $allWords[] = $cleanWord;
                }
            }
        }
        $uniqueWords = array_unique($allWords);
        $wordsList = implode(', ', array_slice($uniqueWords, 0, 200)); // Limit to 200 words
        
        $prompt .= "REFERENCE WORDS FROM PRODUCT NAMES (for spelling correction only):\n{$wordsList}\n\n";
    }
    
    $prompt .= "TASK:\n";
    $prompt .= "1. Fix ONLY spelling mistakes and typos in the user input\n";
    $prompt .= "2. Use the reference words above ONLY to check if a word is misspelled (e.g., 'vapre' → 'vape', 'blu' → 'blue')\n";
    $prompt .= "3. DO NOT replace the user's input with a full product name\n";
    $prompt .= "4. Keep the user's original input length and structure as much as possible\n";
    $prompt .= "5. If user types 'Blue Razz', return 'Blue Razz' (not 'Blue Razz Sharks HHC Gummies - 1000mg')\n";
    $prompt .= "6. If user types 'vapre', return 'vape' (not 'Vape Pen' or full product name)\n";
    $prompt .= "7. If user types 'blu raz', return 'blue raz' or 'blue razz' (fix typos only, keep short)\n";
    $prompt .= "8. If input has no typos, return it exactly as-is\n";
    $prompt .= "9. Preserve the user's search intent - they want to search for what they typed, not a full product name\n\n";
    $prompt .= "IMPORTANT RULES:\n";
    $prompt .= "- Return ONLY the corrected text (typos fixed), keep it short like the original input\n";
    $prompt .= "- DO NOT return full product names\n";
    $prompt .= "- DO NOT add extra words or product details\n";
    $prompt .= "- DO NOT use quotes or explanations\n";
    $prompt .= "- If input is 'Blue Razz', return 'Blue Razz' (not 'Blue Razz Sharks HHC Gummies')\n\n";
    $prompt .= "Return ONLY the corrected text (typos fixed, same length/structure):";
    
    $input = [
        'prompt' => $prompt,
        'max_tokens' => 256
    ];
    
    $postData = json_encode(['input' => $input]);
    
    $ch = curl_init('https://api.replicate.com/v1/models/openai/gpt-5/predictions');
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $replicateToken,
            'Content-Type: application/json',
            'Prefer: wait'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if (!empty($curlError)) {
        throw new Exception('cURL Error: ' . $curlError);
    }
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception('Replicate API Error: HTTP ' . $httpCode . ' - ' . $response);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['output'])) {
        throw new Exception('Invalid response from Replicate API: ' . $response);
    }
    
    $output = $data['output'];
    
    // Handle output if it's an array (streaming response)
    if (is_array($output)) {
        $output = implode('', $output);
    }
    
    // Store raw output for debugging
    $rawOutput = $output;
    
    // Clean up the output
    $correctedInput = trim($output);
    
    // Remove quotes if present
    $correctedInput = trim($correctedInput, '"\'');
    
    // If output is empty or same as input, return original (fallback)
    if (empty($correctedInput)) {
        $correctedInput = $userInput;
    }
    
    // Clear any accidental output before JSON
    ob_clean();
    
    // Return corrected input with raw output for debugging
    echo json_encode([
        'success' => true,
        'original' => $userInput,
        'corrected' => $correctedInput,
        'rawOutput' => $rawOutput,
        'productNamesCount' => count($productNames)
    ]);
    
} catch (Exception $e) {
    // Clear any accidental output before JSON
    ob_clean();
    
    // On error, return original input so filtering can continue
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'original' => $userInput ?? '',
        'corrected' => $userInput ?? '',
        'error' => $e->getMessage(),
        'rawOutput' => null
    ]);
}

