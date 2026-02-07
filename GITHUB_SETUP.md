# GitHub Setup Guide

This guide will walk you through publishing Epstein Suite to GitHub.

## ✅ Files Created

All necessary files have been created:

- ✅ `.gitignore` - Excludes .env, scripts/, storage/, logs
- ✅ `.env.example` - Template with placeholder values
- ✅ `LICENSE` - AGPL-3.0 with data pipeline exception
- ✅ `CONTRIBUTING.md` - Contributor guidelines
- ✅ `README_OPENSOURCE.md` - Public-facing README
- ✅ `CODE_OF_CONDUCT.md` - Community standards
- ✅ `.github/PULL_REQUEST_TEMPLATE.md` - PR template
- ✅ `.github/ISSUE_TEMPLATE/bug_report.md` - Bug report template
- ✅ `.github/ISSUE_TEMPLATE/feature_request.md` - Feature request template

## 🚀 Steps to Publish

### Step 1: Final Pre-Flight Checks

1. **Verify .env is NOT staged:**
   ```bash
   # This should show .env is ignored
   cat .gitignore | grep "^.env$"
   ```

2. **Rename README files:**
   ```bash
   # Backup your current README (has CLAUDE.md references)
   mv README.md README_INTERNAL.md

   # Use the open source README
   mv README_OPENSOURCE.md README.md
   ```

3. **Review files one last time:**
   ```bash
   # Check .gitignore
   cat .gitignore

   # Check .env.example (should have NO real credentials)
   cat .env.example
   ```

### Step 2: Initialize Git Repository

```bash
cd /Users/kevinchamplin/kevinchamplin.com/epstein.kevinchamplin.com

# Initialize git
git init

# Verify .env will be ignored
git check-ignore .env
# Should output: .env

# Stage all files (except those in .gitignore)
git add .

# Check what will be committed (make sure .env is NOT in the list)
git status

# Verify scripts/ is not staged
git ls-files scripts/
# Should show nothing (or error)
```

### Step 3: First Commit

```bash
# Create initial commit
git commit -m "Initial commit: Epstein Suite web application

Open source transparency database for DOJ Epstein Files.

Features:
- Full-text search across 4,700+ documents
- AI-powered Q&A with GPT-5-nano
- Entity browser (people, organizations, locations)
- Flight log viewer with map visualization
- Email client interface
- Responsive UI with TailwindCSS

Data pipeline not included (proprietary).
Licensed under AGPL-3.0."
```

### Step 4: Create GitHub Repository

1. Go to https://github.com/new
2. Repository name: `epstein-suite` (or your choice)
3. Description: "Searchable transparency database for DOJ Epstein Files"
4. Visibility: **Public**
5. **DO NOT** initialize with README, .gitignore, or license (we have them)
6. Click "Create repository"

### Step 5: Push to GitHub

```bash
# Add remote (replace YOUR_USERNAME with your GitHub username)
git remote add origin git@github.com:YOUR_USERNAME/epstein-suite.git

# Or use HTTPS:
# git remote add origin https://github.com/YOUR_USERNAME/epstein-suite.git

# Verify remote
git remote -v

# Push to GitHub
git branch -M main
git push -u origin main
```

### Step 6: Configure Repository Settings

On GitHub, go to your repository settings:

1. **General**
   - Add topics: `transparency`, `php`, `mysql`, `openai`, `epstein`, `documents`, `search`
   - Add website: `https://epsteinsuite.com`

2. **Features**
   - ✅ Enable Issues
   - ✅ Enable Discussions (optional, for Q&A)
   - ✅ Enable Projects (optional, for roadmap)
   - ✅ Enable Wiki (optional)

3. **Social Preview**
   - Upload a preview image (screenshot of your site)

4. **About** (right sidebar on main repo page)
   - Description: "Searchable transparency database for DOJ Epstein Files"
   - Website: https://epsteinsuite.com
   - Topics: transparency, php, mysql, openai, documents, search

## 🔐 Critical Security Checklist

Before pushing, verify:

- [ ] `.env` file is NOT in git staging area
- [ ] `scripts/` directory is NOT in git staging area
- [ ] `storage/` directory is NOT in git staging area
- [ ] `CRONS.md` is NOT in git staging area
- [ ] `DAILY_OPS.md` is NOT in git staging area
- [ ] `.env.example` contains NO real credentials
- [ ] No API keys in any committed files

**Double-check:**
```bash
# These should all return nothing (or be empty)
git ls-files | grep "^.env$"
git ls-files | grep "^scripts/"
git ls-files | grep "^CRONS.md$"
git ls-files | grep "^DAILY_OPS.md$"

# Check for accidental credentials in staged files
git diff --cached | grep -i "sk-proj-"
git diff --cached | grep -i "password.*="
```

## 📝 After Publishing

### Rotate Credentials (Important!)

Since your `.env` was visible on your local machine before git was initialized, consider rotating:

1. **OpenAI API Key** - Generate new key at platform.openai.com
2. **Database Password** - Change on production server
3. **Admin Password** - Update in `.env`
4. **Mailgun API Key** - Regenerate if needed

### Add Repository Description

On GitHub, edit the "About" section (top right) to include:
```
🔍 Searchable transparency database for DOJ Epstein Files

Features:
• Full-text search across documents
• AI-powered Q&A
• Entity browser & network graphs
• Flight log mapping
• Email threading

Live: https://epsteinsuite.com
```

### Create First GitHub Release (Optional)

```bash
# Tag your first release
git tag -a v1.0.0 -m "Initial public release"
git push origin v1.0.0
```

Then on GitHub:
1. Go to Releases
2. Draft a new release
3. Choose tag v1.0.0
4. Title: "v1.0.0 - Initial Public Release"
5. Describe what's included

### Pin Important Issues

Create and pin these issues to guide contributors:

1. "Good First Issues" - Label easy tasks for newcomers
2. "Help Wanted" - Features you'd like help with
3. "Roadmap" - Future plans

## 🤝 Promoting Your Repository

### In Your README (already done)

- [x] Live site link (epsteinsuite.com)
- [x] Clear feature list
- [x] Setup instructions
- [x] Contributing guidelines
- [x] License information

### External Promotion

Consider sharing on:
- Twitter/X with #transparency #opensource
- Reddit: r/programming, r/opensource, r/privacy
- Hacker News: Show HN post
- Your personal blog/website
- LinkedIn

### GitHub Profile

Add to your GitHub profile README:
```markdown
## Featured Project: Epstein Suite

Searchable transparency database for DOJ Epstein Files.
Open source web app with AI-powered search.

[View Project →](https://github.com/YOUR_USERNAME/epstein-suite)
```

## 📊 Monitoring

### Watch for Issues

Enable email notifications for:
- Issues
- Pull requests
- Discussions
- Security alerts

### GitHub Insights

Check regularly:
- Traffic (visitors, clones)
- Community (contributors, forks)
- Issues (open, closed, response time)

## 🆘 Troubleshooting

### "Permission denied (publickey)"

If push fails with SSH error:
```bash
# Switch to HTTPS
git remote set-url origin https://github.com/YOUR_USERNAME/epstein-suite.git
```

### "Large files detected"

If git complains about large files:
```bash
# Check file sizes
git ls-files -s | awk '{print $4, $2}' | sort -n -r | head -20

# If you accidentally added storage/
git rm -r --cached storage/
git commit -m "Remove storage directory"
```

### "Credential exposure detected"

If GitHub detects credentials:
1. Immediately rotate the exposed credential
2. Remove from git history:
   ```bash
   # Use BFG Repo-Cleaner or git-filter-branch
   # Contact admin@kevinchamplin.com if you need help
   ```

## 🎉 You're Live!

Once pushed, your repository will be at:
```
https://github.com/YOUR_USERNAME/epstein-suite
```

Share it with:
- The person who asked about contributing
- Your network
- Relevant communities

## 📞 Need Help?

If you run into issues:
1. Check this guide again
2. Search GitHub documentation
3. Email admin@kevinchamplin.com
4. Ask in GitHub Discussions (once repo is public)

---

**Remember:** Your data pipeline stays private. Contributors help improve the web app. You maintain control through pull request reviews.

**Good luck! 🚀**
