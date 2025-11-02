<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // Load prompts from files
        $promptsDir = __DIR__ . '/prompts/';
        
        $prompts = [
            'gptGeneration' => file_exists($promptsDir . 'gpt_generation_prompt.txt') 
                ? file_get_contents($promptsDir . 'gpt_generation_prompt.txt') 
                : '',
            'capitalization' => file_exists($promptsDir . 'capitalization_prompt.txt') 
                ? file_get_contents($promptsDir . 'capitalization_prompt.txt') 
                : '',
            'styleAnalysis' => file_exists($promptsDir . 'style_analysis_prompt.txt') 
                ? file_get_contents($promptsDir . 'style_analysis_prompt.txt') 
                : ''
        ];
        
        echo json_encode(['success' => true, 'prompts' => $prompts]);
        
    } elseif ($method === 'POST') {
        // Save prompts to files
        $promptsDir = __DIR__ . '/prompts/';
        
        $gptGeneration = $_POST['gptGeneration'] ?? '';
        $capitalization = $_POST['capitalization'] ?? '';
        $styleAnalysis = $_POST['styleAnalysis'] ?? '';
        
        // Save each prompt file
        $results = [];
        
        if (isset($_POST['gptGeneration'])) {
            $result = file_put_contents($promptsDir . 'gpt_generation_prompt.txt', $gptGeneration);
            $results['gptGeneration'] = $result !== false;
        }
        
        if (isset($_POST['capitalization'])) {
            $result = file_put_contents($promptsDir . 'capitalization_prompt.txt', $capitalization);
            $results['capitalization'] = $result !== false;
        }
        
        if (isset($_POST['styleAnalysis'])) {
            $result = file_put_contents($promptsDir . 'style_analysis_prompt.txt', $styleAnalysis);
            $results['styleAnalysis'] = $result !== false;
        }
        
        $allSuccess = !in_array(false, $results, true);
        
        echo json_encode([
            'success' => $allSuccess,
            'results' => $results
        ]);
        
    } else {
        throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

