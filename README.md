# Supabase Stay Alive

A simple PHP script to keep your Supabase databases active by connecting to them daily. This prevents Supabase from automatically pausing unused databases after a week of inactivity.

## Features

- ✅ Connect to multiple Supabase databases
- ⚡ Concurrent database pings for speed
- 🔧 Environment variable configuration
- 📊 Detailed logging and error reporting
- 🔄 Perfect for cron jobs
- 🛡️  Robust error handling
- 🐘 Pure PHP - no Node.js required

## Requirements

- PHP 7.4 or higher
- Composer (for dependency management)
- cURL extension (usually included with PHP)

## Installation

1. Clone or download this repository
2. Install dependencies:
   ```bash
   composer install
   ```

3. Copy the example environment file:
   ```bash
   cp env.example .env
   ```

4. Edit `.env` and add your Supabase database credentials

## Configuration

Edit your `.env` file with your Supabase database information:

```env
# Database 1
DB1_NAME=My Main App Database
DB1_URL=https://your-project-id.supabase.co
DB1_ANON_KEY=your-anon-key-here

# Database 2
DB2_NAME=My Second Database
DB2_URL=https://another-project-id.supabase.co
DB2_ANON_KEY=another-anon-key-here

# Add more databases as needed...
```

### Finding Your Supabase Credentials

1. Go to your [Supabase Dashboard](https://supabase.com/dashboard)
2. Select your project
3. Go to Settings → API
4. Copy:
   - **URL**: Your project URL (e.g., `https://abcdefghijk.supabase.co`)
   - **Anon Key**: Your anonymous/public key

## Usage

### Manual Run

**Full Version (with Composer dependencies):**
```bash
composer run start
```

or

```bash
php stayalive.php
```

**Simple Version (no Composer required):**
```bash
php stayalive-simple.php
```

### Setting up a Cron Job

To run the script daily at 9 AM, add this to your crontab:

```bash
# Edit crontab
crontab -e

# Add this line (adjust path to your script directory)
# For full version:
0 9 * * * cd /path/to/supabase-stayalive && php stayalive.php >> /var/log/supabase-stayalive.log 2>&1

# Or for simple version (no Composer required):
0 9 * * * cd /path/to/supabase-stayalive && php stayalive-simple.php >> /var/log/supabase-stayalive.log 2>&1
```

Alternative cron schedules:
- Every 12 hours: `0 */12 * * *`
- Every 6 hours: `0 */6 * * *`
- Every day at midnight: `0 0 * * *`

### Example Output

```
🚀 Supabase Stay Alive Script Started
📊 Found 2 database(s) to ping
⏰ Timestamp: 2024-01-15T09:00:00.000Z

🏓 Pinging My Main App Database...
✅ My Main App Database - Connection successful

🏓 Pinging My Second Database...
✅ My Second Database - Connection successful

📈 Summary:
✅ Successful: 2
❌ Failed: 0
🔄 Total: 2

🎉 All databases pinged successfully!
```

## Two Versions Available

**Full Version (`stayalive.php`):**
- Uses Composer for dependency management
- Includes advanced HTTP client (Guzzle) for better performance
- Supports concurrent requests for faster execution
- Requires `composer install`

**Simple Version (`stayalive-simple.php`):**
- Zero dependencies - uses only built-in PHP functions
- Uses cURL for HTTP requests
- Sequential database pings (slightly slower)
- Works immediately without Composer

## How It Works

The script:
1. Loads database configurations from environment variables
2. Makes HTTP requests to Supabase REST API endpoints
3. Tries multiple endpoints to ensure database activity
4. Provides detailed feedback and error reporting
5. Exits with appropriate status codes for cron job monitoring

## Troubleshooting

### No databases configured
- Check that your `.env` file exists and contains database configurations
- Ensure environment variables follow the `DB{N}_URL` and `DB{N}_ANON_KEY` pattern

### Connection failures
- Verify your Supabase URL and anon key are correct
- Check that your Supabase project is not paused or deleted
- Ensure your network connection is working

### Cron job not running
- Check cron logs: `tail -f /var/log/cron`
- Verify the path in your crontab is correct
- Ensure PHP is available in your system PATH
- Make sure Composer dependencies are installed: `composer install`
- Ensure the script has execute permissions: `chmod +x stayalive.php`

## License

MIT License - feel free to use this script for your projects! 