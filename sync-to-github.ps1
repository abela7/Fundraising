# Auto-sync bold-knuth commits to GitHub main branch
# Run this script after committing on bold-knuth branch

Write-Host "🔄 Syncing bold-knuth to GitHub main..." -ForegroundColor Cyan

# Paths
$boldKnuthPath = "c:\Users\Abela\.claude-worktrees\Fundraising\bold-knuth"
$mainPath = "C:/xampp/htdocs/Fundraising"

# Check if there are commits to sync
Push-Location $boldKnuthPath
$commitsAhead = git log origin/main..HEAD --oneline
Pop-Location

if ([string]::IsNullOrWhiteSpace($commitsAhead)) {
    Write-Host "✅ No new commits to sync. Everything is up to date!" -ForegroundColor Green
    exit 0
}

Write-Host "📦 Found commits to sync:" -ForegroundColor Yellow
Write-Host $commitsAhead

# Pull latest main
Write-Host "`n⬇️  Pulling latest main..." -ForegroundColor Cyan
Push-Location $mainPath
git pull origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Failed to pull main" -ForegroundColor Red
    Pop-Location
    exit 1
}

# Merge bold-knuth into main
Write-Host "`n🔀 Merging bold-knuth into main..." -ForegroundColor Cyan
git merge bold-knuth --no-edit
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Merge failed. Please resolve conflicts manually." -ForegroundColor Red
    Pop-Location
    exit 1
}

# Push to GitHub
Write-Host "`n⬆️  Pushing to GitHub..." -ForegroundColor Cyan
git push origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Failed to push to GitHub" -ForegroundColor Red
    Pop-Location
    exit 1
}

Pop-Location

Write-Host "`n✅ Successfully synced to GitHub!" -ForegroundColor Green
Write-Host "Your changes are now live on GitHub main branch." -ForegroundColor Green
