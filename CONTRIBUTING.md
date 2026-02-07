# Contributing to Epstein Suite

Thank you for your interest in contributing to Epstein Suite! This project aims to provide transparent, searchable access to public records related to the Epstein case.

## 🎯 What We're Looking For

We welcome contributions in these areas:

### High Priority
- **UI/UX Improvements** - Better mobile experience, accessibility, dark mode
- **Search Enhancements** - Better filtering, faceted search, advanced queries
- **Performance Optimizations** - Faster page loads, query optimization
- **Data Visualizations** - Charts, graphs, timeline views
- **Bug Fixes** - Any bugs you encounter while using the site

### Welcome Contributions
- **Documentation** - Improve setup guides, add code comments
- **Testing** - Add automated tests (we don't have any yet!)
- **Code Quality** - Refactoring, PSR-12 compliance improvements
- **New Features** - Export functionality, bookmarking, annotations

### Not Looking For
- Changes to data ingestion pipeline (it's not open sourced)
- Changes to AI prompts related to safety/privacy
- Removal of victim privacy protections

## 🚀 Getting Started

### Prerequisites
- PHP 8.4+
- MySQL 8.0+
- Git

### Local Setup

1. **Fork and clone the repository**
   ```bash
   git clone https://github.com/YOUR_USERNAME/epstein-suite.git
   cd epstein-suite
   ```

2. **Set up your database**
   ```bash
   # Create database
   mysql -u root -p
   CREATE DATABASE epstein_db;
   exit;

   # Import schema
   mysql -u root -p epstein_db < config/schema.sql
   ```

3. **Configure environment**
   ```bash
   # Copy environment template
   cp .env.example .env

   # Edit .env with your local database credentials
   # You don't need OpenAI API key for basic development
   ```

4. **Start development server**
   ```bash
   php -S localhost:8000
   ```

5. **Visit http://localhost:8000**

### Working with Test Data

The production database contains 4,700+ documents but is not included. For local development:

**Option A: Work with empty database**
- Good for UI/layout changes
- Test with no data edge cases

**Option B: Create sample test data**
```sql
-- Add a few test documents
INSERT INTO documents (title, status, file_url, created_at) VALUES
('Test Document 1', 'processed', 'https://example.com/doc1.pdf', NOW()),
('Test Document 2', 'processed', 'https://example.com/doc2.pdf', NOW());

INSERT INTO entities (name, type, created_at) VALUES
('John Doe', 'PERSON', NOW()),
('Test Corp', 'ORG', NOW());
```

## 📝 Development Guidelines

### Code Standards

**PHP**
- Follow PSR-12 coding standard
- Use strict typing: `declare(strict_types=1);`
- Always use prepared statements (never string interpolation for SQL)
- Type hint all function parameters and returns

**Good:**
```php
declare(strict_types=1);

function getDocument(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
```

**Bad:**
```php
function getDocument($id) {
    return db()->query("SELECT * FROM documents WHERE id = $id")->fetch();
}
```

### Security Requirements

- ✅ Always use PDO prepared statements
- ✅ Escape output with `htmlspecialchars($var, ENT_QUOTES)`
- ✅ Validate and sanitize user input
- ✅ Never expose credentials or API keys
- ✅ Maintain victim privacy protections

### Testing Your Changes

```bash
# Check PHP syntax
php -l your-file.php

# Test all PHP files
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \;

# Start dev server and manually test
php -S localhost:8000
```

## 🔄 Contribution Workflow

### 1. Create a Branch

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/bug-description
```

Use descriptive branch names:
- `feature/dark-mode`
- `fix/search-pagination-bug`
- `improve/mobile-navigation`

### 2. Make Your Changes

- Write clean, readable code
- Follow existing code style
- Add comments for complex logic
- Test thoroughly in your local environment

### 3. Commit Your Changes

Write clear, descriptive commit messages:

```bash
git add .
git commit -m "Add dark mode toggle to header

- Adds toggle switch to main navigation
- Persists preference to localStorage
- Updates all pages to respect dark mode setting
- Improves contrast for accessibility"
```

**Good commit messages:**
- Start with a verb (Add, Fix, Update, Improve, Remove)
- First line is a brief summary (50 chars or less)
- Add details in the body if needed

### 4. Push and Create Pull Request

```bash
git push origin feature/your-feature-name
```

Then on GitHub:
1. Go to the original repository
2. Click "New Pull Request"
3. Select your branch
4. Fill out the PR template

### Pull Request Guidelines

**Title:** Clear and descriptive
- ✅ "Add export to CSV functionality"
- ✅ "Fix search pagination on mobile"
- ❌ "Update files"
- ❌ "Changes"

**Description:** Explain what and why
```markdown
## What
Adds a dark mode toggle to the site header

## Why
Many users prefer dark mode for extended reading sessions

## How
- Added CSS variables for color theming
- Toggle button in header persists to localStorage
- All pages check localStorage on load

## Testing
- Tested on Chrome, Firefox, Safari
- Verified localStorage persistence
- Checked color contrast for accessibility
```

**Before submitting:**
- [ ] Code follows PSR-12 style
- [ ] No PHP syntax errors
- [ ] Tested locally
- [ ] No sensitive data (credentials, API keys)
- [ ] No debugging code (var_dump, console.log)

## 🐛 Reporting Bugs

### Before Submitting
1. Check existing issues to avoid duplicates
2. Test on the latest version
3. Gather information about your environment

### Bug Report Template

**Title:** Short, specific description
- ✅ "Search returns no results for quoted phrases"
- ❌ "Search broken"

**Content:**
```markdown
**Describe the bug**
Clear description of what's wrong

**To Reproduce**
1. Go to '...'
2. Click on '...'
3. See error

**Expected behavior**
What should happen

**Actual behavior**
What actually happens

**Environment**
- Browser: Chrome 120
- OS: macOS 14.2
- URL: https://epsteinsuite.com/...

**Screenshots**
If applicable
```

## 💡 Suggesting Features

We love feature ideas! Before suggesting:
1. Check if it's already requested
2. Consider if it fits the project's mission
3. Think through implementation implications

**Use this template:**
```markdown
**Feature Description**
What you want to add

**Use Case**
Why this would be valuable

**Proposed Implementation**
Ideas for how it could work (optional)

**Alternatives Considered**
Other approaches you thought about
```

## 🎨 Design Guidelines

### UI/UX Principles
- **Simple**: Clean, uncluttered interfaces
- **Fast**: Prioritize performance
- **Accessible**: WCAG 2.1 AA compliance where possible
- **Mobile-first**: Works great on small screens

### Design System
- **Colors**: TailwindCSS slate palette
- **Typography**: Inter font family
- **Spacing**: TailwindCSS spacing scale
- **Components**: Consistent rounded corners, shadows

## 📚 Codebase Overview

### Key Files

**Entry Points**
- `index.php` - Homepage/search
- `document.php` - Document detail view
- `ask.php` - AI chat interface
- `drive.php` - Document browser
- `flight_logs.php` - Flight log viewer

**Shared Includes**
- `includes/db.php` - Database connection
- `includes/ai_helpers.php` - OpenAI integration
- `includes/cache.php` - File-based caching
- `includes/header_suite.php` - Page header
- `includes/footer_suite.php` - Page footer

**API Endpoints**
- `api/ask.php` - AI chat API
- `api/insights.php` - Statistics API
- `api/manual_download.php` - Document processing triggers

**Admin** (HTTP Basic Auth protected)
- `admin/index.php` - Operations dashboard
- `admin/_auth.php` - Auth middleware

### Database Schema
See `config/schema.sql` for full structure. Key tables:
- `documents` - Document metadata
- `pages` - OCR text per page (FULLTEXT indexed)
- `entities` - People, organizations, locations
- `document_entities` - Many-to-many relationships
- `emails` - Email threads (FULLTEXT indexed)
- `flight_logs` - Flight manifest records

## 🤝 Code of Conduct

### Our Standards
- **Respectful**: Treat everyone with respect
- **Constructive**: Give helpful, actionable feedback
- **Focused**: Stay on topic, keep discussions productive
- **Professional**: This is about transparency, not conspiracy theories

### Unacceptable Behavior
- Harassment, personal attacks, trolling
- Publishing others' private information
- Attempting to identify redacted victims
- Off-topic political arguments
- Conspiracy theory discussions

### Enforcement
Violations may result in temporary or permanent ban from contributing.

## ❓ Questions?

- **General questions**: Open a GitHub Discussion
- **Bug reports**: Open an issue
- **Security issues**: Email admin@kevinchamplin.com privately
- **Feature ideas**: Open an issue with [FEATURE] tag

## 📜 License

By contributing, you agree that your contributions will be licensed under the AGPL-3.0 License. You retain copyright to your contributions, but grant this project a perpetual license to use them.

---

**Thank you for contributing to transparency and accountability!** 🙏
