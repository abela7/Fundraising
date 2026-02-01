# GitHub Auto-Sync Setup ✅

Your repository is now configured to automatically sync `bold-knuth` commits to GitHub's `main` branch!

## What Was Set Up

1. **Git Post-Commit Hook** - Automatically syncs when you commit on `bold-knuth` branch
2. **PowerShell Script** - Manual sync script: `sync-to-github.ps1`
3. **Git Alias** - Quick command: `git sync-github`

## How It Works

### Automatic (Recommended)
When you commit on the `bold-knuth` branch, the post-commit hook will:
1. Check if there are commits ahead of `origin/main`
2. Merge `bold-knuth` into `main`
3. Push to GitHub automatically

**You don't need to do anything!** Just commit normally on `bold-knuth`.

### Manual Options

If you want to sync manually:

**Option 1: PowerShell Script**
```powershell
.\sync-to-github.ps1
```

**Option 2: Git Alias**
```bash
git sync-github
```

## Troubleshooting

If auto-sync fails:
- Check for merge conflicts
- Run `.\sync-to-github.ps1` manually to see detailed error messages
- Make sure both worktrees are accessible

## Files Created

- `sync-to-github.ps1` - PowerShell sync script
- `C:/xampp/htdocs/Fundraising/.git/hooks/post-commit` - Auto-sync hook
- `.gitconfig-alias.txt` - Reference for git alias (already configured)

---

**You're all set!** Commit on `bold-knuth` and your changes will automatically appear on GitHub! 🚀
