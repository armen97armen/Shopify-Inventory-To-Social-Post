# AI Tweet Generator

A modern single-page web application that generates and posts promotional Twitter/X posts based on Shopify product information and customizable AI prompts. Features a beautiful dark/light theme system with a sleek glass-style UI.

**Version:** 1.3.0

## Features

- **Shopify Integration**: Automatically scrapes product information and images from Shopify product pages
- **AI-Powered Generation**: Uses Replicate API with GPT-5-Nano and Grok-4 models for intelligent tweet generation
- **Style Analysis**: Analyzes any X (Twitter) profile to match your brand's writing style
- **Editable AI Prompts**: User-friendly settings panel to edit all AI prompts without touching code
- **Theme System**: Beautiful dark/light mode with glass-style UI and smooth transitions
- **Settings Management**: Centralized configuration via JSON with UI editor for API keys and prompts
- **Direct Posting**: Posts generated tweets with images directly to X (Twitter)
- **Smart Truncation**: Automatically calculates tweet length based on link size to stay under Twitter's limits

## Technology Stack

- **Frontend**: 
  - HTML5, CSS3 (with Tailwind CSS CDN)
  - JavaScript (ES6+), jQuery
  - Modern UI with Inter font and custom letter spacing
  - Theme system with CSS variables (dark/light modes)
- **Backend**: PHP 8.1+
- **Libraries**: 
  - TwitterOAuth (Twitter API integration)
  - Composer CaBundle (SSL certificate handling)
- **APIs**: 
  - Twitter/X API v1.1 and v2
  - Replicate API (Grok-4 for style analysis, GPT-5-Nano for tweet generation)

## Installation

1. **Prerequisites**:
   - PHP 8.1 or higher
   - cURL extension enabled
   - DOM extension enabled
   - Apache/XAMPP or similar web server

2. **Setup**:
   ```bash
   # The vendor directory already contains the required libraries
   # Just start your web server and navigate to index.html
   ```

3. **Configure**:
   - Copy `config/settings.default.json` and add your API credentials (this file is excluded from Git for security)
   - Or configure everything via the Settings panel in the UI (settings are saved to localStorage)
   - Get Replicate API token from: https://replicate.com/account/api-tokens
   - Get Twitter/X API credentials from: https://developer.twitter.com/en/portal/dashboard

## Usage

1. Open `index.html` in your web browser (via web server - XAMPP, Apache, etc.)
2. Click the **Settings** icon (⚙️) in the top right to:
   - Configure your X profile link for style analysis
   - Enter API credentials (Replicate token, Twitter API keys)
   - Edit AI prompts (Tweet Generation, Capitalization, Style Analysis)
3. Enter a Shopify product URL in the input field
4. Click **"Generate Tweet"** to create a promotional tweet
5. Review the generated tweet and preview
6. Click **"POST IT"** to publish to X (Twitter) with the product image

## File Structure

```
.
├── index.html                  # Main UI interface
├── process.php                 # Backend: AI generation & Shopify scraping
├── post.php                    # Backend: Twitter/X posting
├── prompts_handler.php         # Backend: Load/save AI prompt templates
├── composer.json               # PHP package configuration
├── .gitignore                  # Git ignore rules (excludes config/settings.default.json)
├── README.md                   # This file
├── config/                     # Configuration files
│   └── settings.default.json   # Default settings template (excluded from Git)
├── prompts/                    # AI prompt templates (editable via UI)
│   ├── gpt_generation_prompt.txt
│   ├── style_analysis_prompt.txt
│   └── capitalization_prompt.txt
├── css/                        # Stylesheets
│   └── base.css                # Base styles (non-color, shared across themes)
├── themes/                     # Theme CSS files (colors only)
│   ├── dark.css                # Dark theme (default)
│   └── light.css               # Light theme
├── docs/                       # Documentation
│   ├── DESIGN_SYSTEM.md
│   ├── REDESIGN_SUMMARY.md
│   └── THEME_GUIDE.md
└── vendor/                     # PHP dependencies (via Composer)
    └── (TwitterOAuth, CaBundle)
```

## How It Works

### Generation Process (`process.php`)

1. **Load Settings**: Reads API tokens and configuration from `config/settings.default.json`
2. **Analyze Writing Style**: Uses Grok-4 (via Replicate) to analyze writing style from the specified X profile
3. **Scrape Product**: Extracts product information and main image from Shopify page using DOMDocument
4. **Generate Tweet**: Uses GPT-5-Nano with style analysis, product image URL, and dynamic character limit
5. **Capitalize**: Applies natural capitalization using GPT-5-Nano with a dedicated capitalization prompt
6. **Validate Length**: AI ensures tweet stays under Twitter's character limit (270 - link length - 2)

### Posting Process (`post.php`)

1. Loads Twitter API credentials from `config/settings.default.json`
2. Downloads product image from Shopify
3. Uploads image to Twitter via media/upload API
4. Posts tweet with attached media using Twitter API v1.1

### Prompt Management (`prompts_handler.php`)

- GET request: Loads prompt templates from `prompts/` directory
- POST request: Saves edited prompts back to `prompts/` directory
- Allows user-side prompt editing without touching server files

## API Credentials

**Security Note**: API credentials are stored in `config/settings.default.json`, which is excluded from Git. 

For production use:
- Keep `config/settings.default.json` out of version control (already in `.gitignore`)
- Use environment variables for sensitive data
- Implement proper authentication for the web interface
- Consider using secure configuration files outside the web root

## Customization

### AI Prompts (User-Friendly)

Edit AI prompts directly in the UI Settings panel:
1. Click the Settings icon (⚙️)
2. Scroll to the "AI Prompts" section
3. Edit any of the three prompts:
   - **Tweet Generation Prompt**: Controls how tweets are generated
   - **Capitalization Prompt**: Controls natural capitalization style
   - **Style Analysis Prompt**: Controls how writing style is analyzed
4. Click "Save Settings" to apply changes

Prompts are saved to `prompts/` directory and persist across sessions.

### Shopify Scraping

The `scrapeShopify()` function in `process.php` uses XPath to extract product data. You may need to adjust selectors for different Shopify themes:

```php
$imageNodes = $xpath->query('//meta[@property="og:image"]');  // Main image
```

Currently extracts:
- Product image URL from Open Graph meta tags
- Product information from page title

### Theme Customization

Themes are in `themes/` directory:
- `dark.css`: Dark theme colors (default)
- `light.css`: Light theme colors
- Both use CSS variables for consistent color swapping

Base styles (non-color) are in `css/base.css` to ensure consistency across themes.

## Error Handling

The application includes basic error handling:

- Missing input validation
- API error responses
- Network failure handling
- User-friendly error messages

## Requirements & Limitations

### Requirements
- PHP 8.1+ with cURL and DOM extensions
- Valid Replicate API token
- Valid Twitter/X API credentials (API Key, Secret, Access Token, Access Secret)
- Web server (Apache/XAMPP or similar)

### Limitations
- AI model accuracy depends on prompt engineering
- Shopify scraping may break with theme changes
- Twitter character limits: 270 characters (with link)
- No authentication for web interface (add if exposing publicly)
- API credentials stored in JSON (consider environment variables for production)

## License

This project is provided as-is for demonstration purposes.

## Support

For issues or questions, please check:
- Twitter API documentation: https://developer.twitter.com
- Replicate API documentation: https://replicate.com/docs
- TwitterOAuth library: https://twitteroauth.com


