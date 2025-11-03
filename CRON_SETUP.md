# Cron Job Setup for Tweet Scheduler

The scheduler script (`scheduler.php`) needs to run every minute to check for and post due tweets.

## Linux/Unix/Mac Setup

Add this line to your crontab (run `crontab -e`):

```bash
* * * * * /usr/bin/php /path/to/your/project/scheduler.php >> /path/to/your/project/data/scheduler.log 2>&1
```

Replace `/path/to/your/project` with your actual project path.

**Example for XAMPP:**
```bash
* * * * * /usr/bin/php /opt/lampp/htdocs/new.com/www/scheduler.php >> /opt/lampp/htdocs/new.com/www/data/scheduler.log 2>&1
```

## Windows Setup (Task Scheduler)

1. Open **Task Scheduler** (search for it in Windows Start menu)
2. Click **Create Basic Task**
3. Name it: "Tweet Scheduler"
4. Set trigger: **Daily** → Select **Recur every: 1 minute**
5. Action: **Start a program**
6. Program/script: `C:\xampp\php\php.exe` (or your PHP path)
7. Add arguments: `C:\xampp\htdocs\new.com\www\scheduler.php`
8. Start in: `C:\xampp\htdocs\new.com\www`
9. Check **Open the Properties dialog for this task when I click Finish**
10. In Properties:
    - **General** tab: Check "Run whether user is logged on or not"
    - **Triggers** tab: Edit trigger → Repeat task every: **1 minute** → Duration: **Indefinitely**
    - **Settings** tab: Check "Allow task to be run on demand"

## Verify Cron is Working

1. Schedule a tweet for 1-2 minutes in the future
2. Check the `data/scheduled_tweets.db` database or the scheduled tweets UI
3. After the scheduled time, the tweet status should change to "posted" or "failed"
4. Check `data/scheduler.log` (if configured) for any errors

## Troubleshooting

- **Database permission errors**: Ensure the `data/` directory is writable (chmod 755 or 777)
- **PHP not found**: Use full path to PHP executable (e.g., `C:\xampp\php\php.exe`)
- **Timezone issues**: All scheduled times are stored in UTC. The cron job converts automatically.
- **Twitter API errors**: Check error messages in the database `error_message` field
