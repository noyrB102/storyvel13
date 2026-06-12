---
description: Deploy storyVel13 to Laravel VPS via Forge
---

# Deploy StoryVel13 to Laravel VPS

## Prerequisites
- Laravel Forge account ($19/mo)
- Laravel VPS subscription (already active)
- GitHub account

## Step 1: Create GitHub Repository

1. Go to https://github.com/new
2. Repository name: `storyvel13`
3. Visibility: Private (recommended)
4. **Do NOT** initialize with README (Forge will do this)
5. Click "Create repository"

## Step 2: Push Local Code to GitHub

```bash
cd /Users/bryonswanson/Documents/devDesk/storyVel13

# Initialize git (if not already done)
git init

# Add all files
git add .

# Commit
git commit -m "Initial commit - StoryVel13 with Ideas, Voice Wizard, and Templates"

# Add GitHub remote (replace YOUR_USERNAME)
git remote add origin https://github.com/YOUR_USERNAME/storyvel13.git

# Push to main branch
git branch -M main
git push -u origin main
```

## Step 3: Create Laravel VPS Server in Forge

1. In Forge, click "Servers" → "Create Server"
2. **Name:** `storyvel13-prod`
3. **Provider:** Laravel VPS (as shown in your screenshot)
4. Click "Continue"
5. Select your plan (suggest 2GB RAM for AI processing)
6. **Region:** Pick closest to your 97-year-old user (e.g., US East if he's in eastern US)
7. **PHP Version:** 8.4
8. Click "Create Server"
9. Wait 5-10 minutes for provisioning

## Step 4: Create Site in Forge

1. Once server is ready, click "Add Site"
2. **Domain:** Enter the server's IP address (shown in Forge, e.g., `203.0.113.45`)
   - Leave "Enable Wildcard Subdomains" unchecked
   - Leave "Enable SSL" unchecked (we're using IP)
3. **Project Type:** Laravel
4. **Web Directory:** `/public`
5. Click "Create Site"

## Step 5: Connect GitHub Repository

1. In the site settings, click "Git Repository"
2. **Provider:** GitHub
3. **Repository:** `YOUR_USERNAME/storyvel13`
4. **Branch:** `main`
5. Click "Install Repository"
6. Authorize Forge to access your GitHub if prompted

## Step 6: Configure Environment

1. Click "Environment" tab
2. Edit the `.env` file with these values:

```env
APP_NAME="StoryVel"
APP_ENV=production
APP_KEY=(Forge will generate this)
APP_DEBUG=false
APP_URL=http://YOUR_SERVER_IP

# Database - SQLite (simplest for this use case)
DB_CONNECTION=sqlite
DB_DATABASE=/home/forge/storyvel13/database/database.sqlite

# AI Keys (from your .env)
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...

# Storage
FILESYSTEM_DISK=local

# Queue (sync for simplicity)
QUEUE_CONNECTION=sync

# Mail (optional - can leave default)
MAIL_MAILER=log
```

3. Click "Save"

## Step 7: Deploy!

1. Click "Deploy Now" button
2. Forge will:
   - Clone from GitHub
   - Run `composer install`
   - Run migrations
   - Set up Nginx
3. Wait 2-3 minutes

## Step 8: Post-Deploy Setup

1. **Create SQLite database:**
   ```bash
   # SSH into server via Forge's "SSH" button, then:
   touch /home/forge/storyvel13/database/database.sqlite
   php artisan migrate --force
   ```

2. **Storage link:**
   ```bash
   php artisan storage:link
   ```

3. **Set permissions:**
   ```bash
   chmod -R 775 /home/forge/storyvel13/storage
   chmod -R 775 /home/forge/storyvel13/bootstrap/cache
   ```

## Step 9: Test Access

1. Visit `http://YOUR_SERVER_IP` in browser
2. Should see the StoryVel welcome page
3. Register/login to test full functionality
4. Try creating a story with AI generation

## Step 10: iPhone Setup for 97-Year-Old

1. On his iPhone, open Safari
2. Enter: `http://YOUR_SERVER_IP`
3. When "Not Secure" warning appears:
   - Tap "Show Details"
   - Tap "Visit This Website"
4. **Bookmark:** Tap share button → "Add to Home Screen"
5. He now has a home screen icon that opens directly to StoryVel

## Updating the App

When you make changes locally:

```bash
cd /Users/bryonswanson/Documents/devDesk/storyVel13
git add .
git commit -m "Description of changes"
git push origin main
```

Forge will auto-deploy! (Or click "Deploy Now" in Forge if auto-deploy is off)

## Troubleshooting

**500 Error after deploy:**
- Check Forge logs: Site → Logs → PHP
- Usually: `php artisan storage:link` or permissions issue

**AI not working:**
- Verify ANTHROPIC_API_KEY and OPENAI_API_KEY in Environment tab

**Images not saving:**
- Check `storage/app/public` exists and is writable
- Run `php artisan storage:link` again

**Livewire not working:**
- Check `APP_URL` matches your IP exactly
- Try adding to `.env`: `SESSION_DOMAIN=YOUR_SERVER_IP`

## Costs

- Laravel Forge: $19/month
- Laravel VPS (2GB): ~$20/month
- **Total: ~$39/month**
- Includes: SSL (if you add domain later), backups, monitoring

## Next Steps (Optional)

If you want HTTPS later:
1. Buy domain (e.g., `grandpastories.com`)
2. In Forge, add domain to site
3. Enable "Let's Encrypt" SSL (free, one-click)
4. iPhone will show secure lock icon
