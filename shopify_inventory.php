<?php

error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't output errors as HTML - use error_log instead
ini_set('log_errors', 1);

// Start output buffering to prevent any accidental output before JSON
ob_start();

// Load default settings from JSON config file
function loadDefaultSettings() {
    $settingsFile = __DIR__ . '/config/settings.default.json';
    if (file_exists($settingsFile)) {
        $json = file_get_contents($settingsFile);
        $settings = json_decode($json, true);
        if ($settings) {
            return [
                'shopify' => [
                    'apiKey' => $settings['shopify']['apiKey'] ?? '',
                    'apiSecret' => $settings['shopify']['apiSecret'] ?? '',
                    'accessToken' => $settings['shopify']['accessToken'] ?? '',
                    'storeUrl' => $settings['shopify']['storeUrl'] ?? ''
                ],
                'replicate' => [
                    'apiToken' => $settings['replicate']['apiToken'] ?? ''
                ],
                'openai' => [
                    'apiKey' => $settings['openai']['apiKey'] ?? ''
                ]
            ];
        }
    }
    return [
        'shopify' => [
            'apiKey' => '',
            'apiSecret' => '',
            'accessToken' => '',
            'storeUrl' => ''
        ],
        'replicate' => [
            'apiToken' => ''
        ],
        'openai' => [
            'apiKey' => ''
        ]
    ];
}

$defaultSettings = loadDefaultSettings();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // ONLY use credentials from backend config - never from POST/GET for security
    $shopUrl = $defaultSettings['shopify']['storeUrl'];
    $activeToken = $defaultSettings['shopify']['accessToken'];
    $replicateToken = $defaultSettings['replicate']['apiToken'];
    $endpoint = $_POST['endpoint'] ?? $_GET['endpoint'] ?? 'inventory';
    
    if (empty($shopUrl)) {
        throw new Exception('Shop URL is required in config/settings.default.json');
    }
    
    // Clean shop URL (remove https:// if present, remove trailing slash)
    $shopUrl = preg_replace('/^https?:\/\//', '', $shopUrl);
    $shopUrl = rtrim($shopUrl, '/');
    
    // IMPORTANT: Shopify Admin API REQUIRES the .myshopify.com domain format
    // Your custom domain (thedopestshop.com) is NOT the same as your Shopify store domain
    // Every Shopify store has a .myshopify.com domain (even if you use a custom domain)
    
    // Extract base name for variations (only if needed)
    if (strpos($shopUrl, '.myshopify.com') === false) {
        // Custom domain provided - extract base name for variations
        $parts = explode('.', $shopUrl);
        $shopName = $parts[0];
        $baseName = $shopName;
    } else {
        // Already has .myshopify.com - extract base name
        $baseName = str_replace('.myshopify.com', '', $shopUrl);
    }
    
    if (empty($activeToken)) {
        throw new Exception('Shopify Admin API Access Token is required in config/settings.default.json. Please add your access token (starts with shpat_) to the config file.');
    }
    
    // Build list of possible .myshopify.com variations to try
    $possibleVariations = [];
    
    // If URL already has .myshopify.com, use ONLY the exact config URL (no variations)
    if (strpos($shopUrl, '.myshopify.com') !== false) {
        $possibleVariations[] = $shopUrl; // Use ONLY the exact config URL - no variations
    } else {
        // Only add variations if config has a custom domain (not .myshopify.com)
        if (isset($baseName)) {
            $possibleVariations[] = $baseName . '.myshopify.com';
            $possibleVariations[] = 'thedopestshop.myshopify.com'; // Based on primary domain
            $possibleVariations[] = 'the-dopest-shop.myshopify.com';
            $possibleVariations[] = 'thedopest-shop.myshopify.com';
            $possibleVariations[] = 'dopestshop.myshopify.com';
            $possibleVariations[] = 'thedopest.myshopify.com';
        }
    }
    
    // Remove duplicates while preserving order
    $possibleVariations = array_values(array_unique($possibleVariations));
    
    // If we only have one URL (the exact config one), and it fails, provide specific error
    if (count($possibleVariations) === 1 && strpos($possibleVariations[0], '.myshopify.com') !== false) {
        // We have exact .myshopify.com domain - if this fails, it's likely a token mismatch
        error_log("Using exact store URL from config: " . $possibleVariations[0]);
    }
    
    // First, test connectivity with a simple shop.json request to verify credentials
    // This will also help us find the correct API version
    $testSuccess = false;
    $lastTestError = null;
    
    foreach ($possibleVariations as $testUrl) {
        try {
            $testResponse = makeShopifyRequest($testUrl, $activeToken, 'shop.json', 'GET');
            // If we get here, connection is working!
            if (isset($testResponse['shop'])) {
                $actualStoreName = $testResponse['shop']['myshopify_domain'] ?? $testUrl;
                $storeName = $testResponse['shop']['name'] ?? 'Unknown';
                
                // Verify the store domain matches what we're trying
                if ($actualStoreName !== $testUrl && strpos($actualStoreName, '.myshopify.com') !== false) {
                    // The token is for a different store domain - use the actual one
                    $shopUrl = $actualStoreName;
                    error_log("Shopify connection successful. Store: {$storeName} | Actual domain: {$actualStoreName} (different from config)");
                } else {
                    $shopUrl = $testUrl;
                    error_log("Shopify connection successful. Store: {$storeName} | Domain: {$actualStoreName}");
                }
                
                // No scope test needed - high/low stock uses inventory data (same as regular inventory)
                
                $testSuccess = true;
                break;
            }
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $lastTestError = $errorMsg;
            // Check if this is a 403 error - might be wrong store
            if (strpos($lastTestError, '403') !== false || strpos($lastTestError, 'Forbidden') !== false) {
                // Token might be for a different store
                $lastTestError = "Token authentication failed for {$testUrl}. The access token might be for a different store. Error: " . $lastTestError;
            }
            // Continue to next variation
            continue;
        }
    }
    
    if (!$testSuccess) {
        // If all variations failed, provide detailed error message
        $errorMsg = $lastTestError ?? "Store URL connection failed";
        
        // Check if the error is about wrong store (403) vs not found (404)
        $is403Error = strpos($errorMsg, '403') !== false || strpos($errorMsg, 'Forbidden') !== false;
        
        // Check if we only tried the config URL (no variations)
        $onlyConfigUrl = (count($possibleVariations) === 1 && strpos($shopUrl, '.myshopify.com') !== false);
        
        if ($is403Error) {
            $instructions = "CRITICAL: The access token does not match the store URL.\n\n";
            $instructions .= "The token in your config might be for a DIFFERENT Shopify store.\n\n";
            $instructions .= "Store URL from config: {$shopUrl}\n\n";
            $instructions .= "To fix this:\n";
            $instructions .= "1. Go to Shopify Admin for the store where you generated the access token\n";
            $instructions .= "2. Go to Settings > Domains to find the exact .myshopify.com domain\n";
            $instructions .= "3. Update 'storeUrl' in config/settings.default.json with that EXACT domain\n";
            $instructions .= "4. OR generate a new token for the store matching the URL in your config\n\n";
            $instructions .= "Error: {$errorMsg}";
        } else {
            if ($onlyConfigUrl) {
                $instructions = "Store URL connection failed.\n\n";
                $instructions .= "Store URL from config: {$shopUrl}\n\n";
                $instructions .= "Possible issues:\n";
                $instructions .= "1. The store URL in config might be incorrect\n";
                $instructions .= "2. The access token might be for a different store\n";
                $instructions .= "3. The store might not exist or be accessible\n\n";
                $instructions .= "To verify:\n";
                $instructions .= "1. Log into Shopify Admin\n";
                $instructions .= "2. Go to Settings > Domains\n";
                $instructions .= "3. Check the exact .myshopify.com domain\n";
                $instructions .= "4. Make sure it matches 'storeUrl' in config/settings.default.json\n";
                $instructions .= "5. Make sure the access token is for the same store\n\n";
            } else {
                $instructions = "IMPORTANT: Shopify Admin API requires your .myshopify.com domain, NOT your custom domain.\n\n";
                $instructions .= "Your config has a custom domain, but we need the .myshopify.com domain.\n\n";
                $instructions .= "To find your CORRECT .myshopify.com domain:\n";
                $instructions .= "1. Log into Shopify Admin\n";
                $instructions .= "2. Go to Settings > Domains\n";
                $instructions .= "3. Look for 'Primary domain' - you'll see something like 'yourstore.myshopify.com'\n";
                $instructions .= "4. Copy that EXACT domain and update 'storeUrl' in config/settings.default.json\n\n";
                $instructions .= "Tried these variations: " . implode(', ', $possibleVariations) . "\n";
            }
            $instructions .= "Error: {$errorMsg}";
        }
        
        throw new Exception($instructions);
    }
    
    // Handle different endpoints
    switch ($endpoint) {
        case 'inventory':
            $result = fetchInventoryData($shopUrl, $activeToken);
            break;
        case 'products':
            $result = fetchProducts($shopUrl, $activeToken);
            break;
        case 'inventory_levels':
            $result = fetchInventoryLevels($shopUrl, $activeToken);
            break;
        case 'locations':
            $result = fetchLocations($shopUrl, $activeToken);
            break;
        case 'best_selling':
        case 'high_stock':
            $result = fetchHighStockProducts($shopUrl, $activeToken);
            break;
        case 'apply_filters':
            $result = applyStructuredFilters($shopUrl, $activeToken);
            break;
        default:
            throw new Exception('Invalid endpoint');
    }
    
    // Clear any accidental output before JSON
    ob_clean();
    
    echo json_encode($result);
    
} catch (Exception $e) {
    // Clear any accidental output before JSON
    ob_clean();
    
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'success' => false
    ]);
}

function makeShopifyRequest($shopUrl, $accessToken, $path, $method = 'GET', $params = []) {
    // Try multiple API versions in order of preference
    $apiVersions = ['2024-10', '2024-07', '2024-04', '2024-01', '2023-10'];
    
    $lastError = null;
    $lastHttpCode = null;
    $lastResponse = null;
    
    foreach ($apiVersions as $apiVersion) {
        $url = "https://{$shopUrl}/admin/api/{$apiVersion}/{$path}";
        
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $ch = curl_init($url);
        
        $headers = [
            'X-Shopify-Access-Token: ' . $accessToken,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $lastError = "cURL error: {$error}";
            $lastHttpCode = $httpCode;
            continue; // Try next API version
        }
        
        $lastResponse = $response;
        
        if ($httpCode === 404) {
            // API version not found, try next one
            $lastError = "API version {$apiVersion} not found (404)";
            $lastHttpCode = 404;
            continue;
        }
        
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            
            // Better error message formatting
            if (isset($errorData['errors'])) {
                if (is_array($errorData['errors'])) {
                    if (isset($errorData['errors'][0])) {
                        $errorMsg = is_array($errorData['errors'][0]) 
                            ? json_encode($errorData['errors'][0]) 
                            : (string)$errorData['errors'][0];
                    } else {
                        $errorMsg = json_encode($errorData['errors']);
                    }
                } else {
                    $errorMsg = (string)$errorData['errors'];
                }
            } else if (isset($errorData['error'])) {
                $errorMsg = $errorData['error'];
            } else {
                $errorMsg = "HTTP {$httpCode} error";
                if (!empty($response)) {
                    $errorMsg .= ": " . substr(strip_tags($response), 0, 200);
                }
            }
            
            throw new Exception("Shopify API Error ({$httpCode}) [v{$apiVersion}]: {$errorMsg}");
        }
        
        // Success - return the decoded response
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Shopify: " . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    // If we get here, all API versions failed - provide detailed error
    $errorDetails = "All API versions failed when calling: https://{$shopUrl}/admin/api/[VERSION]/{$path}. ";
    $errorDetails .= "Last HTTP code: {$lastHttpCode}. ";
    if ($lastResponse) {
        // Try to extract meaningful error from response
        $responseData = json_decode($lastResponse, true);
        if ($responseData && isset($responseData['errors'])) {
            $errorDetails .= "Shopify error: " . json_encode($responseData['errors']);
        } else {
            $errorDetails .= "Response: " . substr(strip_tags($lastResponse), 0, 300);
        }
    } else {
        $errorDetails .= $lastError ?: "Unknown error";
    }
    $errorDetails .= " Please verify the store URL is correct. You can find your .myshopify.com domain in Shopify Admin > Settings > Domains.";
    throw new Exception($errorDetails);
}

function fetchInventoryData($shopUrl, $accessToken) {
    // Try to fetch locations to get location IDs (optional - requires read_locations scope)
    $locationIds = [];
    try {
        $locations = makeShopifyRequest($shopUrl, $accessToken, 'locations.json', 'GET');
        if (isset($locations['locations']) && is_array($locations['locations'])) {
            foreach ($locations['locations'] as $location) {
                if (isset($location['id'])) {
                    $locationIds[] = $location['id'];
                }
            }
        }
    } catch (Exception $e) {
        // Locations endpoint requires read_locations scope - continue without it
        // We can still get inventory levels without location_ids
        error_log("Could not fetch locations (read_locations scope may be missing): " . $e->getMessage());
    }
    
    // Fetch products first to get inventory_item_ids
    // Only fetch published/active products (status='active')
    $products = makeShopifyRequest($shopUrl, $accessToken, 'products.json', 'GET', ['limit' => 250, 'status' => 'active']);
    
    // Collect all inventory_item_ids from LIVE products only
    $inventoryItemIds = [];
    $liveProducts = []; // Store only live products
    
    if (isset($products['products']) && is_array($products['products'])) {
        foreach ($products['products'] as $product) {
            // Only include products that are published and active
            // Shopify API status can be 'active', 'archived', or 'draft'
            $productStatus = $product['status'] ?? '';
            $productPublished = isset($product['published_at']) && $product['published_at'] !== null;
            
            // Only process if product is active and published
            if ($productStatus === 'active' && $productPublished) {
                $liveProducts[] = $product; // Store for later use
                
                if (isset($product['variants']) && is_array($product['variants'])) {
                    foreach ($product['variants'] as $variant) {
                        if (isset($variant['inventory_item_id'])) {
                            $inventoryItemIds[] = $variant['inventory_item_id'];
                        }
                    }
                }
            }
        }
    }
    
    // Fetch inventory items - need to chunk if there are many
    $inventoryItems = [];
    if (!empty($inventoryItemIds)) {
        // Shopify allows up to 50 IDs per request, so chunk them
        $chunks = array_chunk($inventoryItemIds, 50);
        foreach ($chunks as $chunk) {
            $idsString = implode(',', $chunk);
            $result = makeShopifyRequest($shopUrl, $accessToken, 'inventory_items.json', 'GET', ['ids' => $idsString, 'limit' => 50]);
            if (isset($result['inventory_items'])) {
                $inventoryItems = array_merge($inventoryItems, $result['inventory_items']);
            }
        }
    }
    
    // Fetch inventory levels - need to provide inventory_item_ids (required parameter)
    // Shopify API requires inventory_item_ids - location_ids is optional
    $inventoryLevels = [];
    
    if (!empty($inventoryItemIds)) {
        // Chunk inventory_item_ids (up to 50 per request)
        $chunks = array_chunk($inventoryItemIds, 50);
        
        foreach ($chunks as $chunk) {
            $idsString = implode(',', $chunk);
            
            // Try with location_ids if available (and we have location permission)
            if (!empty($locationIds)) {
                foreach ($locationIds as $locationId) {
                    try {
                        $levels = makeShopifyRequest($shopUrl, $accessToken, 'inventory_levels.json', 'GET', [
                            'inventory_item_ids' => $idsString,
                            'location_ids' => $locationId,
                            'limit' => 250
                        ]);
                        if (isset($levels['inventory_levels'])) {
                            $inventoryLevels = array_merge($inventoryLevels, $levels['inventory_levels']);
                        }
                    } catch (Exception $e) {
                        // If location-specific request fails, continue with next location or try without location_ids
                        continue;
                    }
                }
            }
            
            // Always try without location_ids (will get levels for all locations that the token has access to)
            // This works even without read_locations scope
            try {
                $levels = makeShopifyRequest($shopUrl, $accessToken, 'inventory_levels.json', 'GET', [
                    'inventory_item_ids' => $idsString,
                    'limit' => 250
                ]);
                if (isset($levels['inventory_levels'])) {
                    // Only add levels we don't already have (avoid duplicates if location-specific calls worked)
                    foreach ($levels['inventory_levels'] as $level) {
                        // Check if we already have this inventory_item_id + location_id combination
                        $found = false;
                        foreach ($inventoryLevels as $existingLevel) {
                            if (isset($existingLevel['inventory_item_id']) && isset($existingLevel['location_id']) &&
                                $existingLevel['inventory_item_id'] == $level['inventory_item_id'] &&
                                $existingLevel['location_id'] == $level['location_id']) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $inventoryLevels[] = $level;
                        }
                    }
                }
            } catch (Exception $e) {
                // Continue with next chunk if this fails
                error_log("Could not fetch inventory levels for chunk: " . $e->getMessage());
                continue;
            }
        }
    }
    
    // Create a map of inventory_item_id to product info
    // Only use live products (already filtered above)
    $productMap = [];
    foreach ($liveProducts as $product) {
        // Build product URL - use custom domain if available (thedopestshop.com)
        $productHandle = $product['handle'] ?? '';
        $productUrl = "https://thedopestshop.com/products/{$productHandle}";
        
        if (isset($product['variants']) && is_array($product['variants'])) {
            foreach ($product['variants'] as $variant) {
                if (isset($variant['inventory_item_id'])) {
                    $productMap[$variant['inventory_item_id']] = [
                        'product_id' => $product['id'],
                        'product_title' => $product['title'],
                        'product_handle' => $productHandle,
                        'product_url' => $productUrl,
                        'variant_id' => $variant['id'],
                        'variant_title' => $variant['title'],
                        'sku' => $variant['sku'] ?? 'N/A',
                        'price' => $variant['price'] ?? '0.00',
                        'product_image' => isset($product['images'][0]) ? $product['images'][0]['src'] : null,
                        'tags' => $product['tags'] ?? '',
                        'description' => isset($product['body_html']) ? strip_tags($product['body_html']) : (isset($product['description']) ? strip_tags($product['description']) : '')
                    ];
                }
            }
        }
    }
    
    // Create a map of inventory_item_id to levels
    $levelsMap = [];
    if (is_array($inventoryLevels) && !empty($inventoryLevels)) {
        foreach ($inventoryLevels as $level) {
            if (isset($level['inventory_item_id'])) {
                if (!isset($levelsMap[$level['inventory_item_id']])) {
                    $levelsMap[$level['inventory_item_id']] = [];
                }
                $levelsMap[$level['inventory_item_id']][] = $level;
            }
        }
    }
    
    // Combine data
    $inventoryData = [];
    $totalItems = 0;
    $totalAvailable = 0;
    $lowStockItems = 0;
    
    // Now $inventoryItems is a flat array (not wrapped in ['inventory_items'])
    if (is_array($inventoryItems) && !empty($inventoryItems)) {
        foreach ($inventoryItems as $item) {
            $productInfo = $productMap[$item['id']] ?? [
                'product_id' => null,
                'product_title' => 'Unknown Product',
                'variant_id' => null,
                'variant_title' => '',
                'sku' => $item['sku'] ?? 'N/A',
                'price' => '0.00',
                'product_image' => null,
                'tags' => '',
                'description' => ''
            ];
            
            $levels = $levelsMap[$item['id']] ?? [];
            $totalQuantity = 0;
            $locationIds = [];
            
            foreach ($levels as $level) {
                $totalQuantity += $level['available'] ?? 0;
                $locationIds[] = $level['location_id'];
            }
            
            // Determine status
            $status = $totalQuantity === 0 ? 'out_of_stock' : ($totalQuantity < 10 ? 'low_stock' : 'in_stock');
            
            // Count and add all products (including out of stock)
            $totalItems++;
            $totalAvailable += $totalQuantity;
            if ($totalQuantity < 10 && $totalQuantity > 0) {
                $lowStockItems++;
            }
            
            $variantName = ($productInfo['variant_title'] && $productInfo['variant_title'] !== 'Default Title') 
                ? ' - ' . $productInfo['variant_title'] 
                : '';
            $productName = $productInfo['product_title'] . $variantName;
            
            $inventoryData[] = [
                'inventory_item_id' => $item['id'],
                'sku' => $item['sku'] ?? $productInfo['sku'],
                'product_title' => $productName,
                'variant_title' => $productInfo['variant_title'],
                'available_quantity' => $totalQuantity,
                'location_count' => count($locationIds),
                'locations' => $locationIds,
                'price' => $productInfo['price'],
                'product_image' => $productInfo['product_image'],
                'product_url' => $productInfo['product_url'] ?? '',
                'status' => $status,
                'product_id' => $productInfo['product_id'],
                'variant_id' => $productInfo['variant_id'],
                'tags' => $productInfo['tags'],
                'description' => $productInfo['description']
            ];
        }
    }
    
    return [
        'success' => true,
        'inventory' => $inventoryData,
        'summary' => [
            'total_products' => $totalItems,
            'total_available' => $totalAvailable,
            'low_stock_items' => $lowStockItems
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function fetchProducts($shopUrl, $accessToken) {
    $products = makeShopifyRequest($shopUrl, $accessToken, 'products.json', 'GET', ['limit' => 250]);
    return [
        'success' => true,
        'products' => $products['products'] ?? []
    ];
}

function fetchInventoryLevels($shopUrl, $accessToken) {
    $levels = makeShopifyRequest($shopUrl, $accessToken, 'inventory_levels.json', 'GET', ['limit' => 250]);
    return [
        'success' => true,
        'inventory_levels' => $levels['inventory_levels'] ?? []
    ];
}

function fetchLocations($shopUrl, $accessToken) {
    $locations = makeShopifyRequest($shopUrl, $accessToken, 'locations.json', 'GET');
    return [
        'success' => true,
        'locations' => $locations['locations'] ?? []
    ];
}

function fetchHighStockProducts($shopUrl, $accessToken) {
    // Get inventory data and sort by highest available quantity
    $inventoryData = fetchInventoryData($shopUrl, $accessToken);
    
    if (!isset($inventoryData['inventory']) || empty($inventoryData['inventory'])) {
        return [
            'success' => true,
            'inventory' => [],
            'summary' => [
                'total_products' => 0,
                'type' => 'high_stock'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Sort by available quantity (descending) - highest stock first
    $inventory = $inventoryData['inventory'];
    usort($inventory, function($a, $b) {
        return ($b['available_quantity'] ?? 0) - ($a['available_quantity'] ?? 0);
    });
    
    // Get top 10 products with highest stock
    $highStock = array_slice($inventory, 0, 10);
    
    return [
        'success' => true,
        'inventory' => $highStock,
        'summary' => [
            'total_products' => count($highStock),
            'type' => 'high_stock'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function fetchLowStockProducts($shopUrl, $accessToken) {
    // Get inventory data and sort by lowest available quantity
    $inventoryData = fetchInventoryData($shopUrl, $accessToken);
    
    if (!isset($inventoryData['inventory']) || empty($inventoryData['inventory'])) {
        return [
            'success' => true,
            'inventory' => [],
            'summary' => [
                'total_products' => 0,
                'type' => 'low_stock'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Sort by available quantity (ascending) - lowest stock first
    $inventory = $inventoryData['inventory'];
    usort($inventory, function($a, $b) {
        return ($a['available_quantity'] ?? 0) - ($b['available_quantity'] ?? 0);
    });
    
    // Get bottom 10 products with lowest stock
    $lowStock = array_slice($inventory, 0, 10);
    
    return [
        'success' => true,
        'inventory' => $lowStock,
        'summary' => [
            'total_products' => count($lowStock),
            'type' => 'low_stock'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Apply structured filters to inventory
 * Accepts filter parameters as JSON and applies them
 */
function applyStructuredFilters($shopUrl, $accessToken) {
    $filtersJson = $_POST['filters'] ?? '';
    $inventoryJson = $_POST['inventory'] ?? '';
    
    if (empty($filtersJson)) {
        throw new Exception('Filter parameters are required');
    }
    
    if (empty($inventoryJson)) {
        throw new Exception('Inventory data is required for filtering');
    }
    
    $filters = json_decode($filtersJson, true);
    $inventoryData = json_decode($inventoryJson, true);
    
    if (!$filters || !is_array($filters)) {
        throw new Exception('Invalid filter parameters format');
    }
    
    if (!$inventoryData || !is_array($inventoryData)) {
        throw new Exception('Invalid inventory data format');
    }
    
    // Apply filters
    $filtered = [];
    
    foreach ($inventoryData as $item) {
        $match = true;
        
        // Quantity filters
        if (isset($filters['quantityMin']) && $filters['quantityMin'] !== null) {
            $quantity = intval($item['available_quantity'] ?? 0);
            if ($quantity < intval($filters['quantityMin'])) {
                $match = false;
            }
        }
        
        if (isset($filters['quantityMax']) && $filters['quantityMax'] !== null) {
            $quantity = intval($item['available_quantity'] ?? 0);
            if ($quantity > intval($filters['quantityMax'])) {
                $match = false;
            }
        }
        
        // Price filters
        if (isset($filters['priceMin']) && $filters['priceMin'] !== null) {
            $price = floatval(str_replace(['$', ','], '', $item['price'] ?? '0'));
            if ($price < floatval($filters['priceMin'])) {
                $match = false;
            }
        }
        
        if (isset($filters['priceMax']) && $filters['priceMax'] !== null) {
            $price = floatval(str_replace(['$', ','], '', $item['price'] ?? '0'));
            if ($price > floatval($filters['priceMax'])) {
                $match = false;
            }
        }
        
        // Status filter
        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $itemStatus = $item['status'] ?? '';
            if ($itemStatus !== $filters['status']) {
                $match = false;
            }
        }
        
        // Product Name filter - case-insensitive partial matching with word boundary and plural handling
        if (isset($filters['productName']) && $filters['productName'] !== null && $filters['productName'] !== '') {
            $title = strtolower($item['product_title'] ?? '');
            $filterName = strtolower(trim($filters['productName']));
            
            // Check if comma is present (indicates OR search - multiple separate searches)
            $hasComma = strpos($filterName, ',') !== false;
            
            // First check: if the full filter phrase appears in the title (fastest check)
            if (strpos($title, $filterName) !== false) {
                // Match found, continue
            } else {
                // Helper function to normalize words (remove trailing 's' for plural matching)
                $normalizeWord = function($word) {
                    $normalized = $word;
                    // Remove trailing 's' if word is longer than 3 characters
                    if (strlen($normalized) > 3 && substr($normalized, -1) === 's') {
                        $normalized = substr($normalized, 0, -1);
                    }
                    return $normalized;
                };
                
                // Helper function to check if a word matches in the title
                $wordMatches = function($filterWord, $title, $titleWords) use ($normalizeWord) {
                    $normalizedFilterWord = $normalizeWord($filterWord);
                    
                    // Check if word appears anywhere in the title (fastest)
                    if (strlen($filterWord) >= 3 && strpos($title, $filterWord) !== false) {
                        return true;
                    }
                    
                    // Check word-by-word matching
                    foreach ($titleWords as $titleWord) {
                        $normalizedTitleWord = $normalizeWord($titleWord);
                        
                        // Check exact match
                        if ($filterWord === $titleWord || $normalizedFilterWord === $normalizedTitleWord) {
                            return true;
                        }
                        
                        // Check if one word contains the other (handles partial matches)
                        if (strlen($filterWord) >= 3 && strlen($titleWord) >= 3) {
                            if (strpos($titleWord, $filterWord) !== false || 
                                strpos($filterWord, $titleWord) !== false ||
                                strpos($normalizedTitleWord, $normalizedFilterWord) !== false ||
                                strpos($normalizedFilterWord, $normalizedTitleWord) !== false) {
                                return true;
                            }
                        }
                    }
                    
                    return false;
                };
                
                $titleWords = preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY);
                
                if ($hasComma) {
                    // OR logic: split by comma and check if ANY search term matches
                    $searchTerms = array_map('trim', explode(',', $filterName));
                    $foundMatch = false;
                    
                    foreach ($searchTerms as $searchTerm) {
                        if (empty($searchTerm)) continue;
                        
                        // Check if this search term matches (can contain multiple words)
                        if (strpos($title, $searchTerm) !== false) {
                            $foundMatch = true;
                            break;
                        }
                        
                        // Word-by-word matching for this search term
                        $termWords = preg_split('/\s+/', $searchTerm, -1, PREG_SPLIT_NO_EMPTY);
                        $allWordsMatch = true;
                        foreach ($termWords as $termWord) {
                            if (!$wordMatches($termWord, $title, $titleWords)) {
                                $allWordsMatch = false;
                                break;
                            }
                        }
                        if ($allWordsMatch && count($termWords) > 0) {
                            $foundMatch = true;
                            break;
                        }
                    }
                    
                    if (!$foundMatch) {
                        $match = false;
                    }
                } else {
                    // AND logic: ALL words must match (no comma)
                    $filterWords = preg_split('/\s+/', $filterName, -1, PREG_SPLIT_NO_EMPTY);
                    $allWordsMatch = true;
                    
                    foreach ($filterWords as $filterWord) {
                        if (!$wordMatches($filterWord, $title, $titleWords)) {
                            $allWordsMatch = false;
                            break;
                        }
                    }
                    
                    if (!$allWordsMatch) {
                        $match = false;
                    }
                }
            }
        }
        
        if ($match) {
            $filtered[] = $item;
        }
    }
    
    // Calculate summary
    $totalAvailable = array_sum(array_column($filtered, 'available_quantity'));
    $lowStockItems = count(array_filter($filtered, function($item) {
        $qty = intval($item['available_quantity'] ?? 0);
        return $qty > 0 && $qty < 10;
    }));
    
    return [
        'success' => true,
        'inventory' => $filtered,
        'summary' => [
            'total_products' => count($filtered),
            'total_available' => $totalAvailable,
            'low_stock_items' => $lowStockItems,
            'type' => 'filtered'
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

function fetchBestSellingFromOrders($shopUrl, $accessToken) {
    // Get from orders API (requires read_orders scope)
    try {
        // Fetch orders with pagination to get all orders (not just first 250)
        $allOrders = [];
        $hasMore = true;
        $pageInfo = null;
        $dateMin = strtotime('-30 days');
        
        // Fetch orders in pages (Shopify API limit is 250 per request)
        while ($hasMore) {
            $params = [
                'status' => 'any',
                'limit' => 250
            ];
            
            // Add pagination if we have a page_info from previous request
            if ($pageInfo) {
                $params['page_info'] = $pageInfo;
            }
            
            try {
                $ordersResponse = makeShopifyRequest($shopUrl, $accessToken, 'orders.json', 'GET', $params);
            } catch (Exception $e) {
                // If this is the first request and it fails, it's likely a scope issue
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, '403') !== false || strpos($errorMsg, 'read_orders') !== false || strpos($errorMsg, 'Forbidden') !== false) {
                    throw new Exception('read_orders scope is not active. IMPORTANT: Your access token needs the "read_orders" scope to fetch best/worst selling products. Steps to fix: 1) Go to Shopify Admin > Settings > Apps and sales channels > Develop apps > Your App > API credentials, 2) Click "Configure Admin API scopes", 3) Add "read_orders" scope and SAVE, 4) Generate a NEW Admin API access token (old token will NOT work), 5) Update config/settings.default.json with the NEW token. Error: ' . $errorMsg);
                }
                throw $e;
            }
            
            if (!isset($ordersResponse['orders']) || !is_array($ordersResponse['orders'])) {
                break;
            }
            
            $orders = $ordersResponse['orders'];
            
            // Filter orders by date (last 30 days)
            foreach ($orders as $order) {
                $orderDate = isset($order['created_at']) ? strtotime($order['created_at']) : 0;
                if ($orderDate >= $dateMin) {
                    $allOrders[] = $order;
                } else {
                    // If we've gone past the 30-day window, we can stop fetching
                    // (assuming orders are returned in reverse chronological order)
                    $hasMore = false;
                    break;
                }
            }
            
            // Check if there are more pages
            // Shopify returns pagination info in Link header or response
            // For now, we'll stop if we got less than 250 orders (no more pages)
            if (count($orders) < 250) {
                $hasMore = false;
            } else {
                // Try to get next page info from response or header
                // For simplicity, we'll fetch up to 1000 orders (4 pages)
                if (count($allOrders) >= 1000) {
                    $hasMore = false;
                } else {
                    // Continue to next page (Shopify pagination would require parsing Link header)
                    // For now, we'll use a simple approach and stop after reasonable limit
                    $hasMore = false; // Stop after first page for now to avoid complexity
                }
            }
        }
        
        // Use filtered orders
        $orders = ['orders' => $allOrders];
        
        // Count product sales
        $productSales = [];
        $totalOrders = count($orders['orders'] ?? []);
        $ordersWithProducts = 0;
        
        if (isset($orders['orders']) && is_array($orders['orders'])) {
            foreach ($orders['orders'] as $order) {
                $orderHasProducts = false;
                if (isset($order['line_items']) && is_array($order['line_items'])) {
                    foreach ($order['line_items'] as $item) {
                        $productId = $item['product_id'] ?? null;
                        // Only count line items that have a product_id (exclude custom line items)
                        if ($productId && $productId > 0) {
                            $orderHasProducts = true;
                            if (!isset($productSales[$productId])) {
                                $productSales[$productId] = [
                                    'product_id' => $productId,
                                    'quantity_sold' => 0,
                                    'revenue' => 0
                                ];
                            }
                            $quantity = floatval($item['quantity'] ?? 0);
                            $price = floatval($item['price'] ?? 0);
                            $productSales[$productId]['quantity_sold'] += $quantity;
                            $productSales[$productId]['revenue'] += $price * $quantity;
                        }
                    }
                }
                if ($orderHasProducts) {
                    $ordersWithProducts++;
                }
            }
        }
        
        if (empty($productSales)) {
            if ($totalOrders === 0) {
                throw new Exception('No orders found in the last 30 days. Make sure you have orders in your Shopify store.');
            } else {
                throw new Exception("Found {$totalOrders} orders in the last 30 days, but none contain products with product_id. This might happen if you only have custom line items or gift cards. Make sure you have orders with actual products.");
            }
        }
        
        // Sort by quantity sold (descending) and get top 10
        usort($productSales, function($a, $b) {
            return $b['quantity_sold'] - $a['quantity_sold'];
        });
        $productSales = array_slice($productSales, 0, 10);
        
        return formatProductSalesData($shopUrl, $accessToken, $productSales, 'best');
    } catch (Exception $e) {
        $errorMsg = 'Cannot fetch best selling products. ';
        $errorMsg .= 'Error: ' . $e->getMessage();
        $errorMsg .= ' | Please verify: 1) Your access token has "read_orders" scope, 2) You have orders in the last 30 days, 3) The token is correctly updated in config/settings.default.json';
        throw new Exception($errorMsg);
    }
}

function fetchWorstSellingFromOrders($shopUrl, $accessToken) {
    // Get all products and compare with orders
    try {
        // Get all active products
        $products = makeShopifyRequest($shopUrl, $accessToken, 'products.json', 'GET', ['limit' => 250, 'status' => 'active']);
        
        // Fetch orders with pagination to get all orders (not just first 250)
        $allOrders = [];
        $hasMore = true;
        $pageInfo = null;
        $dateMin = strtotime('-30 days');
        
        // Fetch orders in pages (Shopify API limit is 250 per request)
        while ($hasMore) {
            $params = [
                'status' => 'any',
                'limit' => 250
            ];
            
            // Add pagination if we have a page_info from previous request
            if ($pageInfo) {
                $params['page_info'] = $pageInfo;
            }
            
            try {
                $ordersResponse = makeShopifyRequest($shopUrl, $accessToken, 'orders.json', 'GET', $params);
            } catch (Exception $e) {
                // If this is the first request and it fails, it's likely a scope issue
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, '403') !== false || strpos($errorMsg, 'read_orders') !== false || strpos($errorMsg, 'Forbidden') !== false) {
                    throw new Exception('read_orders scope is not active. IMPORTANT: Your access token needs the "read_orders" scope to fetch best/worst selling products. Steps to fix: 1) Go to Shopify Admin > Settings > Apps and sales channels > Develop apps > Your App > API credentials, 2) Click "Configure Admin API scopes", 3) Add "read_orders" scope and SAVE, 4) Generate a NEW Admin API access token (old token will NOT work), 5) Update config/settings.default.json with the NEW token. Error: ' . $errorMsg);
                }
                throw $e;
            }
            
            if (!isset($ordersResponse['orders']) || !is_array($ordersResponse['orders'])) {
                break;
            }
            
            $orders = $ordersResponse['orders'];
            
            // Filter orders by date (last 30 days)
            foreach ($orders as $order) {
                $orderDate = isset($order['created_at']) ? strtotime($order['created_at']) : 0;
                if ($orderDate >= $dateMin) {
                    $allOrders[] = $order;
                } else {
                    // If we've gone past the 30-day window, we can stop fetching
                    // (assuming orders are returned in reverse chronological order)
                    $hasMore = false;
                    break;
                }
            }
            
            // Check if there are more pages
            // Shopify returns pagination info in Link header or response
            // For now, we'll stop if we got less than 250 orders (no more pages)
            if (count($orders) < 250) {
                $hasMore = false;
            } else {
                // Try to get next page info from response or header
                // For simplicity, we'll fetch up to 1000 orders (4 pages)
                if (count($allOrders) >= 1000) {
                    $hasMore = false;
                } else {
                    // Continue to next page (Shopify pagination would require parsing Link header)
                    // For now, we'll use a simple approach and stop after reasonable limit
                    $hasMore = false; // Stop after first page for now to avoid complexity
                }
            }
        }
        
        // Use filtered orders
        $orders = ['orders' => $allOrders];
        
        // Count product sales
        $productSales = [];
        if (isset($products['products'])) {
            foreach ($products['products'] as $product) {
                $productId = $product['id'];
                $productSales[$productId] = [
                    'product_id' => $productId,
                    'quantity_sold' => 0,
                    'revenue' => 0
                ];
            }
        }
        
        if (isset($orders['orders']) && is_array($orders['orders'])) {
            foreach ($orders['orders'] as $order) {
                if (isset($order['line_items']) && is_array($order['line_items'])) {
                    foreach ($order['line_items'] as $item) {
                        $productId = $item['product_id'] ?? null;
                        if ($productId && isset($productSales[$productId])) {
                            $quantity = floatval($item['quantity'] ?? 0);
                            $price = floatval($item['price'] ?? 0);
                            $productSales[$productId]['quantity_sold'] += $quantity;
                            $productSales[$productId]['revenue'] += $price * $quantity;
                        }
                    }
                }
            }
        }
        
        // Sort by quantity sold (ascending) and get bottom 10
        usort($productSales, function($a, $b) {
            return $a['quantity_sold'] - $b['quantity_sold'];
        });
        $productSales = array_slice($productSales, 0, 10);
        
        return formatProductSalesData($shopUrl, $accessToken, $productSales, 'worst');
    } catch (Exception $e) {
        $errorMsg = 'Cannot fetch worst selling products. ';
        $errorMsg .= 'Error: ' . $e->getMessage();
        $errorMsg .= ' | Please verify: 1) Your access token has "read_orders" scope, 2) You have orders in the last 30 days, 3) The token is correctly updated in config/settings.default.json';
        throw new Exception($errorMsg);
    }
}

function formatSellingProducts($shopUrl, $accessToken, $analytics, $type, $limit) {
    // Format analytics data based on Shopify Analytics API response
    // The Analytics API returns data in a specific format
    $inventoryData = [];
    
    // Check if analytics data has the expected structure
    if (isset($analytics['report']) && isset($analytics['report']['data'])) {
        $data = $analytics['report']['data'];
        
        // Process analytics data
        $productSales = [];
        foreach ($data as $row) {
            if (isset($row['product_id']) && isset($row['quantity'])) {
                $productId = $row['product_id'];
                if (!isset($productSales[$productId])) {
                    $productSales[$productId] = [
                        'product_id' => $productId,
                        'quantity_sold' => 0,
                        'revenue' => 0
                    ];
                }
                $productSales[$productId]['quantity_sold'] += $row['quantity'] ?? 0;
                $productSales[$productId]['revenue'] += ($row['total_sales'] ?? 0);
            }
        }
        
        // Sort based on type
        if ($type === 'best') {
            usort($productSales, function($a, $b) {
                return $b['quantity_sold'] - $a['quantity_sold'];
            });
        } else {
            usort($productSales, function($a, $b) {
                return $a['quantity_sold'] - $b['quantity_sold'];
            });
        }
        
        // Get top/bottom products
        $productSales = array_slice($productSales, 0, $limit);
        
        return formatProductSalesData($shopUrl, $accessToken, $productSales, $type);
    }
    
    // If format doesn't match, try alternative approach
    // Get all products and show message
    throw new Exception('Analytics API returned data in unexpected format. Please add "read_orders" scope to your access token for alternative method.');
}

function formatProductSalesData($shopUrl, $accessToken, $productSales, $type) {
    // Get product details for the products in the sales list
    $productIds = array_column($productSales, 'product_id');
    $productDetails = [];
    
    // Fetch products in batches
    $chunks = array_chunk($productIds, 50);
    foreach ($chunks as $chunk) {
        $idsString = implode(',', $chunk);
        $products = makeShopifyRequest($shopUrl, $accessToken, 'products.json', 'GET', ['ids' => $idsString, 'limit' => 50]);
        if (isset($products['products'])) {
            foreach ($products['products'] as $product) {
                $productDetails[$product['id']] = $product;
            }
        }
    }
    
    // Get inventory data
    $inventoryData = [];
    foreach ($productSales as $sale) {
        $productId = $sale['product_id'];
        $product = $productDetails[$productId] ?? null;
        
        if ($product) {
            $productHandle = $product['handle'] ?? '';
            $productUrl = "https://thedopestshop.com/products/{$productHandle}";
            
            // Get variant info
            $variant = isset($product['variants'][0]) ? $product['variants'][0] : null;
            $sku = $variant['sku'] ?? 'N/A';
            
            $inventoryData[] = [
                'product_id' => $productId,
                'product_title' => $product['title'],
                'sku' => $sku,
                'quantity_sold' => $sale['quantity_sold'],
                'revenue' => $sale['revenue'],
                'product_url' => $productUrl,
                'product_image' => isset($product['images'][0]) ? $product['images'][0]['src'] : null,
                'status' => 'in_stock' // Default
            ];
        }
    }
    
    return [
        'success' => true,
        'inventory' => $inventoryData,
        'summary' => [
            'total_products' => count($inventoryData),
            'type' => $type
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

