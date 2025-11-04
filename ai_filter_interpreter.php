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
    $query = $_POST['query'] ?? '';
    
    if (empty($query)) {
        throw new Exception('Query is required');
    }
    
    $defaultSettings = loadDefaultSettings();
    $replicateToken = $defaultSettings['replicate']['apiToken'] ?? '';
    
    if (empty($replicateToken)) {
        throw new Exception('Replicate API token is required in config/settings.default.json');
    }
    
    // Call GPT-5 via Replicate to interpret the query and return filter parameters
    $prompt = "You are a filter parameter generator. Convert the user's natural language query into structured filter parameters.\n\n";
    $prompt .= "USER QUERY: {$query}\n\n";
    $prompt .= "Return ONLY a JSON object with these fields (use null for fields not specified):\n";
    $prompt .= "{\n";
    $prompt .= "  \"quantityMin\": number or null,\n";
    $prompt .= "  \"quantityMax\": number or null,\n";
    $prompt .= "  \"priceMin\": number or null,\n";
    $prompt .= "  \"priceMax\": number or null,\n";
    $prompt .= "  \"status\": \"in_stock\" | \"low_stock\" | \"out_of_stock\" | null,\n";
    $prompt .= "  \"productName\": string or null\n";
    $prompt .= "}\n\n";
    $prompt .= "RULES:\n";
    $prompt .= "- IMPORTANT: If you see a range pattern like 'X-Y', 'X–Y', 'X to Y', 'X - Y', or 'X – Y' (with dash or em-dash), ALWAYS treat it as a range → quantityMin: X, quantityMax: Y (or priceMin: X, priceMax: Y for price)\n";
    $prompt .= "- For quantity ranges: 'X-Y', 'X–Y', 'X to Y', 'X - Y', 'X – Y', 'X-Y qty', 'X–Y qty', 'X-Y quantity', 'X–Y quantity', 'qty X-Y', 'qty X–Y' → quantityMin: X, quantityMax: Y\n";
    $prompt .= "- For quantity: 'under X', 'less than X', 'below X' (NOT a range) → quantityMax: X\n";
    $prompt .= "- For quantity: 'over X', 'more than X', 'above X', 'greater than X' (NOT a range) → quantityMin: X\n";
    $prompt .= "- For quantity: 'between X and Y' → quantityMin: X, quantityMax: Y\n";
    $prompt .= "- For price: 'under $X', 'less than $X', 'below $X' → priceMax: X\n";
    $prompt .= "- For price: 'over $X', 'more than $X', 'above $X' → priceMin: X\n";
    $prompt .= "- For price: 'between $X and $Y', '$X-$Y', '$X–$Y', '$X to $Y' → priceMin: X, priceMax: Y\n";
    $prompt .= "- For status: 'in stock', 'available' → status: \"in_stock\"\n";
    $prompt .= "- For status: 'low stock' → status: \"low_stock\"\n";
    $prompt .= "- For status: 'out of stock', 'unavailable' → status: \"out_of_stock\"\n";
    $prompt .= "- For productName: Extract ONLY specific product names, brands, or categories (e.g., 'iPhone', 'Nike', 'shoes', 'electronics', 'flower')\n";
    $prompt .= "- DO NOT extract generic words like 'product', 'products', 'item', 'items', 'show', 'find', 'get', 'list'\n";
    $prompt .= "- If query only mentions price/quantity without specific product terms, set productName to null\n";
    $prompt .= "\n";
    $prompt .= "EXAMPLES:\n";
    $prompt .= "- 'flower under 50–200 qty' → {quantityMin: 50, quantityMax: 200, productName: 'flower'} (the '50–200' range takes priority over 'under')\n";
    $prompt .= "- 'flower 50–200 qty' → {quantityMin: 50, quantityMax: 200, productName: 'flower'}\n";
    $prompt .= "- 'products over $100' → {priceMin: 100, productName: null}\n";
    $prompt .= "- 'Nike shoes under $50' → {priceMax: 50, productName: 'Nike shoes'}\n";
    $prompt .= "- 'vape 10-50 qty' → {quantityMin: 10, quantityMax: 50, productName: 'vape'}\n";
    $prompt .= "- 'items between 20 and 100 quantity' → {quantityMin: 20, quantityMax: 100, productName: null}\n";
    $prompt .= "- 'products 50-200 quantity' → {quantityMin: 50, quantityMax: 200, productName: null}\n";
    $prompt .= "\n";
    $prompt .= "- Return ONLY the JSON object, no other text\n";
    
    $input = [
        'prompt' => $prompt,
        'max_tokens' => 512
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
        CURLOPT_TIMEOUT => 60
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
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
    
    // Extract JSON from response
    $output = trim($output);
    
    // Try to find JSON object in response
    if (preg_match('/\{.*\}/s', $output, $matches)) {
        $filterParams = json_decode($matches[0], true);
    } else {
        // Try to parse entire response as JSON
        $filterParams = json_decode($output, true);
    }
    
    if (!is_array($filterParams)) {
        throw new Exception('AI response could not be parsed as filter parameters');
    }
    
    // Clear any accidental output before JSON
    ob_clean();
    
    // Validate and return filter parameters
    echo json_encode([
        'success' => true,
        'filters' => $filterParams
    ]);
    
} catch (Exception $e) {
    // Clear any accidental output before JSON
    ob_clean();
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

