# GitHub Repository Setup Guide

This guide explains how to structure your GitHub repository to work with our multi-tenant LEMP stack deployment system. Follow these instructions to ensure your site deploys correctly.

## Table of Contents

1. [Required Repository Structure](#1-required-repository-structure)
2. [Directory Rules and Behavior](#2-directory-rules-and-behavior)
3. [Information You Need to Provide](#3-information-you-need-to-provide)
4. [How Deployment Works](#4-how-deployment-works)
5. [Best Practices](#5-best-practices)
6. [Common Issues](#6-common-issues)

---

## 1. Required Repository Structure

Your GitHub repository **MUST** follow this exact structure:

```
your-repo/
├── public/              # REQUIRED: All web-accessible files
│   ├── index.php        # or index.html (your site's entry point)
│   ├── index.html       # Alternative entry point
│   ├── assets/          # CSS, JS, images, fonts, etc.
│   │   ├── css/
│   │   ├── js/
│   │   └── images/
│   └── uploads/          # OPTIONAL: User-uploaded files
│                        # ⚠️ MUST be at public/uploads/ to be preserved
├── db/                  # REQUIRED: Database files (even if empty)
│   ├── *.sqlite3        # SQLite database files
│   ├── *.sqlite         # Alternative SQLite extension
│   └── *.db             # Alternative database extension
└── (other files)        # README, docs, etc. (not deployed)
```

### Quick Start Example

```
my-website/
├── public/
│   ├── index.html
│   ├── styles.css
│   ├── script.js
│   ├── images/
│   │   └── logo.png
│   └── uploads/         # ← User uploads go here
├── db/
│   └── .gitkeep         # Keep this directory in git
└── README.md
```

---

## 2. Directory Rules and Behavior

### The `public/` Directory

**Purpose**: Contains all files that will be publicly accessible on your website.

**Rules**:
- ✅ All HTML, PHP, CSS, JavaScript, images, and other web assets go here
- ✅ This directory is **fully mirrored** to the server
- ✅ Files you delete from `public/` in your repo will be deleted from the server
- ✅ Files you add or modify will be updated on the server
- ✅ Your entry point (`index.php` or `index.html`) must be directly inside `public/`

**Example Structure**:
```
public/
├── index.php           # ← Entry point
├── about.php
├── contact.php
├── assets/
│   ├── css/
│   │   └── main.css
│   ├── js/
│   │   └── app.js
│   └── images/
│       └── logo.png
└── uploads/            # ← Special handling (see below)
```

### The `public/uploads/` Directory ⚠️

**Purpose**: Store user-uploaded files that should persist across deployments.

**Critical Rule**: The `uploads/` folder **MUST** be located at `public/uploads/` in your repository.

**Special Behavior**:
- ✅ Existing files on the server are **never deleted** during deployment
- ✅ Existing files on the server are **never overwritten** during deployment
- ✅ Only **new files** from your repository are copied to the server
- ✅ This ensures user-uploaded content is never lost

**What This Means**:
- If a user uploads `photo.jpg` to your site, it will remain on the server even after you deploy updates
- You can include seed/example files in `public/uploads/` in your repo, but they won't overwrite existing files
- Files deleted from `public/uploads/` in your repo will **NOT** be deleted from the server

**Correct Location**:
```
your-repo/
└── public/
    └── uploads/        # ✅ CORRECT - inside public/
        └── .gitkeep
```

**Incorrect Locations** (will NOT be preserved):
```
your-repo/
├── uploads/            # ❌ WRONG - not inside public/
└── public/
    └── files/          # ❌ WRONG - wrong name, must be "uploads"
```

### The `db/` Directory

**Purpose**: Contains database files (SQLite) and related data.

**Rules**:
- ✅ Database files (`.sqlite3`, `.sqlite`, `.db`) are **protected by default**
- ✅ Existing database files on the server are **never overwritten** during normal deployments
- ✅ Only **new database files** from your repository are copied to the server
- ✅ This prevents accidental data loss

**What This Means**:
- You can include seed/initial database files in your repo
- Production databases are safe from accidental overwrites
- If you need to update a database file, contact your server administrator

**Example Structure**:
```
db/
├── app.sqlite3         # Your database file
└── seed-data.sql       # Optional: SQL scripts
```

### Files Outside `public/` and `db/`

**Purpose**: Documentation, development files, and other non-deployed content.

**Rules**:
- ✅ These files are **not deployed** to the server
- ✅ Use for README, documentation, development scripts, etc.
- ✅ Safe to include anything you don't want on the live site

**Example**:
```
your-repo/
├── public/             # ← Deployed
├── db/                 # ← Deployed
├── README.md           # ← Not deployed
├── docs/               # ← Not deployed
│   └── api.md
└── scripts/            # ← Not deployed
    └── build.sh
```

---

## 3. Information You Need to Provide

When setting up your site, you'll need to provide the following information to your server administrator:

### Required Information

1. **Domain Name**
   - Your website's domain (e.g., `example.com`)
   - Include `www` subdomain if you want it (e.g., `www.example.com`)

2. **GitHub Repository**
   - Format: `username/repository-name`
   - Example: `johndoe/my-website`
   - Can be public or private

3. **Git Branch**
   - The branch to deploy (typically `main` or `master`)
   - Default: `main`

4. **GitHub Personal Access Token**
   - Required for the server to access your repository
   - **How to create**:
     1. Go to GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic)
     2. Click "Generate new token (classic)"
     3. Give it a name (e.g., "Production Server Access")
     4. Select the `repo` scope (for private repos) or no scope needed (for public repos)
     5. Click "Generate token"
     6. **Copy the token immediately** (starts with `ghp_`) - you won't see it again
   - **Security**: This token gives access to your repository. Only share it with trusted server administrators.

### Optional Information

5. **Additional Subdomains** (if needed)
   - If you need SSL certificates for additional subdomains (e.g., `api.example.com`, `admin.example.com`)
   - Provide these when setting up your site

---

## 4. How Deployment Works

### Automatic Deployment

Once your site is set up, deployments happen automatically:
- Your repository is synced from GitHub every 2 minutes
- Changes are deployed to the server automatically
- You just need to push to your configured branch

### Deployment Process

1. **Repository Sync**: The server pulls the latest code from your GitHub repository
2. **File Deployment**: Files are copied from your repo to the server:
   - `public/` → web root (publicly accessible)
   - `db/` → database directory (protected, not web-accessible)
3. **File Handling**:
   - Files in `public/` are fully mirrored (adds, updates, deletes)
   - Files in `public/uploads/` are preserved (only new files added)
   - Database files are protected (only new files added)

### What Happens When You Push Changes

**Scenario 1: You update a file in `public/`**
```
You: Update public/index.html
Push to GitHub
↓
Server syncs and deploys
↓
Website shows updated index.html ✅
```

**Scenario 2: You delete a file from `public/`**
```
You: Delete public/old-page.html
Push to GitHub
↓
Server syncs and deploys
↓
old-page.html is removed from website ✅
```

**Scenario 3: User uploads a file, then you deploy**
```
User: Uploads photo.jpg to your site (stored in public/uploads/)
You: Push code changes to GitHub
↓
Server syncs and deploys
↓
photo.jpg remains on server ✅
Your code changes are deployed ✅
```

**Scenario 4: You add a new database file**
```
You: Add db/seed.sqlite3 to repository
Push to GitHub
↓
Server syncs and deploys
↓
seed.sqlite3 is copied to server ✅
```

**Scenario 5: You try to overwrite existing database**
```
You: Update db/production.sqlite3 in repository
Push to GitHub
↓
Server syncs and deploys
↓
production.sqlite3 on server is NOT overwritten ⚠️
(Contact admin if you need to update production database)
```

---

## 5. Best Practices

### Repository Organization

✅ **DO**:
- Keep all web files in `public/`
- Use `public/uploads/` for user-uploaded content
- Include a `db/` directory (even if empty)
- Use clear, organized folder structure
- Include a README with setup instructions

❌ **DON'T**:
- Put web files in the root of your repository
- Put uploads outside of `public/uploads/`
- Commit sensitive data (API keys, passwords) to the repository
- Commit large binary files unnecessarily
- Put database files in `public/` (they won't be accessible anyway)

### File Naming

- Use lowercase with hyphens for files and directories: `my-page.html`, `user-profile.php`
- Keep file extensions consistent: `.html`, `.php`, `.css`, `.js`
- Entry point should be `index.php` or `index.html`

### PHP Compatibility

- The server runs **PHP 8.1**
- Ensure your PHP code is compatible with PHP 8.1
- Test your code locally with PHP 8.1 before deploying

### Database Files

- Use SQLite for simple applications (`.sqlite3` extension recommended)
- Never commit production databases with real user data
- Use seed/initial database files for development
- Document your database schema in your repository

### Security

- Never commit sensitive information (API keys, passwords, tokens)
- Use environment variables or configuration files that aren't committed
- Keep your GitHub Personal Access Token secure
- Regularly rotate your access tokens

---

## 6. Common Issues

### Issue: Files Not Appearing on Website

**Possible Causes**:
- Files are not in the `public/` directory
- Files are in a subdirectory that doesn't exist
- Entry point is missing (`index.php` or `index.html`)

**Solution**:
- Verify your repository structure matches the required format
- Ensure your entry point is directly in `public/`
- Check that you've pushed changes to the correct branch

### Issue: User Uploads Are Being Deleted

**Possible Causes**:
- Uploads directory is not at `public/uploads/`
- Uploads directory has a different name
- Files are being stored outside the uploads directory

**Solution**:
- **Verify the uploads directory is at `public/uploads/`** (this is critical!)
- Check your code is saving files to the correct location
- Ensure the directory exists in your repository structure

### Issue: Database Changes Not Reflecting

**Possible Causes**:
- Database file already exists on server (protected from overwrite)
- Database file has wrong extension
- Database file is in wrong location

**Solution**:
- Database files are protected by default - existing files won't be overwritten
- If you need to update a production database, contact your server administrator
- Verify database files are in the `db/` directory with correct extensions (`.sqlite3`, `.sqlite`, `.db`)

### Issue: PHP Errors or Code Not Executing

**Possible Causes**:
- PHP syntax errors
- PHP 8.1 compatibility issues
- Missing PHP extensions

**Solution**:
- Check your PHP code for syntax errors
- Ensure compatibility with PHP 8.1
- Test locally with PHP 8.1 before deploying
- Contact your server administrator if you need specific PHP extensions

### Issue: CSS/JavaScript Not Loading

**Possible Causes**:
- Incorrect file paths in HTML
- Files not in `public/` directory
- Browser cache issues

**Solution**:
- Use relative paths from your entry point
- Ensure all assets are in `public/` (or subdirectories of `public/`)
- Clear browser cache or use cache-busting techniques

### Issue: SSL Certificate Not Working

**Possible Causes**:
- DNS not properly configured
- Domain not pointing to server
- DNS propagation delay

**Solution**:
- Verify DNS is correctly configured (A records pointing to server IP)
- Wait for DNS propagation (can take up to 48 hours, usually much faster)
- Contact your server administrator to check SSL certificate status

---

## Quick Reference Checklist

Before contacting your server administrator to set up your site, ensure:

- [ ] Repository has `public/` directory with entry point (`index.php` or `index.html`)
- [ ] Repository has `db/` directory (can be empty)
- [ ] If using uploads, they are at `public/uploads/`
- [ ] All web files are inside `public/`
- [ ] You have a GitHub Personal Access Token ready
- [ ] You know your domain name
- [ ] DNS is configured (or will be configured)
- [ ] Your code is compatible with PHP 8.1

---

## Summary

**Key Takeaways**:

1. **Structure**: Your repo must have `public/` and `db/` directories
2. **Uploads**: Must be at `public/uploads/` to be preserved during deployments
3. **Protection**: Database files and uploads are protected from accidental overwrites
4. **Deployment**: Automatic - just push to your configured branch
5. **PHP**: Server runs PHP 8.1 - ensure compatibility

**What You Control**:
- Repository structure and organization
- Code and file contents
- When to push changes

**What the Server Handles**:
- Deployment automation
- SSL certificates
- File permissions
- Database protection
- Server configuration

If you have questions or encounter issues not covered here, contact your server administrator with:
- Your domain name
- Description of the issue
- Any error messages you're seeing
- Steps you've already taken to resolve it

---

**Last Updated**: User-facing guide for multi-tenant LEMP stack deployment
