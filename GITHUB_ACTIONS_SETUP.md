# GitHub Actions Setup for Tweet Scheduling

GitHub Actions will automatically check and post scheduled tweets every minute, even when your webpage is closed.

## Setup Instructions

### 1. Add Secret to GitHub Repository

1. Go to your GitHub repository: `https://github.com/armen97armen/Shopify-Inventory-To-Social-Post`
2. Click **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**
4. Name: `SCHEDULER_URL`
5. Value: Your website URL (e.g., `https://yourdomain.com` or `https://your-app.onrender.com`)
6. Click **Add secret**

**Important**: Make sure your website is publicly accessible (or use Render.com/Heroku/Vercel deployment URL)

### 2. Enable GitHub Actions

1. Go to your repository **Settings** → **Actions** → **General**
2. Under "Workflow permissions", select: **Read and write permissions**
3. Check **Allow GitHub Actions to create and approve pull requests**
4. Save changes

### 3. Deploy Your Application

Your application needs to be publicly accessible. Options:

- **Render.com**: Free tier available
- **Heroku**: Free tier (limited)
- **Railway.app**: Free credits
- **Your own server**: If publicly accessible

### 4. Test the Workflow

1. Go to **Actions** tab in your GitHub repository
2. You should see "Scheduled Tweet Posting" workflow
3. Click on it → **Run workflow** (manual trigger for testing)
4. Check logs to see if it successfully calls your scheduler endpoint

## How It Works

- GitHub Actions runs the workflow every minute (via cron schedule)
- The workflow makes an HTTP request to `your-url/run_scheduler.php`
- Your `run_scheduler.php` endpoint calls `scheduler.php`
- `scheduler.php` checks the database for due tweets and posts them

## Requirements

- Your website must be publicly accessible
- `run_scheduler.php` endpoint must be accessible via HTTP
- Database must be accessible from your deployed application

## Free Tier Limitations

GitHub Actions free tier includes:
- **2,000 minutes/month** for private repositories
- **Unlimited minutes** for public repositories
- Each run uses ~1 minute
- Running every minute = ~1,440 minutes/day = ~43,200 minutes/month

**For private repos**: You'll hit the limit (~2,000 minutes = ~33 hours of checks per month)

**Solution for private repos**: Change cron schedule to run every 5 minutes instead:
```yaml
- cron: '*/5 * * * *'  # Every 5 minutes instead of every minute
```

## Manual Triggering

You can manually trigger the workflow anytime:
1. Go to **Actions** tab
2. Select "Scheduled Tweet Posting" workflow
3. Click **Run workflow** button
