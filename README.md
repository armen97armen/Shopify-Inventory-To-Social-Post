# Shopify Inventory To Social Post

A single-page web application that generates and posts promotional Twitter/X posts based on Shopify product information and customizable style preferences.

## Features

- **Shopify Integration**: Scrapes product information and images from Shopify product pages
- **AI-Powered Generation**: Uses AI to generate promotional tweets in customizable styles
- **Settings Panel**: Configure X (Twitter) profile link to match your brand's writing style
- **Auto-Generated Posts**: Creates promotional tweets based on product information
- **Direct Posting**: Posts generated tweets with images directly to X (Twitter)

## Technology Stack

- **Frontend**: HTML, CSS, JavaScript, jQuery, AJAX
- **Backend**: PHP
- **Libraries**: 
  - TwitterOAuth (Twitter API integration)
  - Composer CaBundle (SSL certificate handling)
- **APIs**: 
  - Twitter/X API v1.1 and v2
  - Replicate API (GPT-5-Nano for AI processing)

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
   ```bash
   # Copy the example files and add your API credentials
   cp process.php.example process.php
   cp post.php.example post.php
   
   # Edit process.php and add your Replicate API token
   # Edit post.php and add your Twitter/X API credentials
   ```
   
   - Get Replicate API token from: https://replicate.com/account/api-tokens
   - Get Twitter/X API credentials from: https://developer.twitter.com/en/portal/dashboard

## Usage

1. Open `index.html` in your web browser
2. (Optional) Click the settings icon in the top right to configure your X profile link
3. Enter a Shopify product URL
4. Click "Generate Tweet"
5. Review the generated tweet and preview
6. Click "POST IT" to publish to X (Twitter)

## File Structure

```
.
├── index.html              # Main interface
├── process.php             # Handles generation (AI, scraping) - not in repo
├── post.php                # Handles posting tweets - not in repo
├── composer.json           # Package configuration
├── .gitignore             # Git ignore rules
├── README.md              # This file
├── config/
│   └── examples/          # Example configuration files
│       ├── process.php.example
│       └── post.php.example
├── prompts/               # AI prompt templates
│   ├── gpt_generation_prompt.txt
│   ├── style_analysis_prompt.txt
│   └── capitalization_prompt.txt
├── themes/                # Theme CSS files
│   ├── dark.css          # Dark theme (default)
│   └── light.css         # Light theme
├── docs/                  # Documentation (archived)
│   ├── DESIGN_SYSTEM.md
│   ├── REDESIGN_SUMMARY.md
│   └── THEME_GUIDE.md
└── vendor/                # Libraries (TwitterOAuth, CaBundle) - not in repo
```

## How It Works

### Generation Process (`process.php`)

1. **Analyze Writing Style**: Uses GPT-5-Nano to analyze writing style from X profile based on handle/URL
2. **Scrape Product**: Extracts product image from Shopify page using DOMDocument
3. **Generate Tweet**: Uses GPT-5-Nano with style analysis and product image to generate promotional tweet
4. **Clean & Format**: Processes AI output to ensure proper formatting, character limits, and title case capitalization

### Posting Process (`post.php`)

1. Downloads product image
2. Uploads image to Twitter via media/upload API
3. Posts tweet with attached media

## API Credentials

**Note**: The application uses hardcoded API credentials. For production:

- Store credentials in environment variables
- Use secure configuration files outside the web root
- Implement proper authentication and authorization

## Customization

### Shopify Scraping

The `scrapeShopify()` function in `process.php` uses XPath to extract product data. You may need to adjust selectors for different Shopify themes:

```php
$titleNodes = $xpath->query('//title');           // Page title
$priceNodes = $xpath->query('//*[contains(@class, "price")]');  // Price
$imageNodes = $xpath->query('//meta[@property="og:image"]');    // Main image
```

### AI Prompts

Modify prompts in `process.php` to change AI behavior:

- Style analysis: Line 56
- Image analysis: Line 64
- Tweet generation: Lines 68-75

## Error Handling

The application includes basic error handling:

- Missing input validation
- API error responses
- Network failure handling
- User-friendly error messages

## Limitations

- Requires valid Twitter API credentials
- Requires valid Replicate API token
- AI model accuracy depends on prompt engineering
- Shopify scraping may break with theme changes
- No authentication for web interface (add if exposing publicly)

## License

This project is provided as-is for demonstration purposes.

## Support

For issues or questions, please check:
- Twitter API documentation: https://developer.twitter.com
- Replicate API documentation: https://replicate.com/docs
- TwitterOAuth library: https://twitteroauth.com


