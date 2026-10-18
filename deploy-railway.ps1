# Railway Deployment Script
# This script deploys the student e-request application to Railway

$railwayCli = "C:\Users\flean\AppData\Local\Programs\railway\railway.exe"
$projectDir = "c:\xampp\htdocs\student-e-request-v"

# Set environment variables for Railway
$env:RAILWAY_PROJECT_ID = "cceba7af-62de-4433-9dcc-f69e669af1ee"
$env:RAILWAY_ENVIRONMENT_ID = "38d1991a-56c3-46a0-84e1-c07c0f4d46a0"
$env:RAILWAY_SERVICE_ID = "bb8d8e07-4794-40e3-8010-128755d87ff7"

Write-Host "Deploying to Railway service: hearty-fulfillment" -ForegroundColor Green
Write-Host "Project: student-e-request-v" -ForegroundColor Green
Write-Host ""

# Change to project directory
Set-Location $projectDir

# Deploy
Write-Host "Uploading and deploying..." -ForegroundColor Yellow
& $railwayCli up --detach

Write-Host ""
Write-Host "Deployment initiated!" -ForegroundColor Green
Write-Host "Check your Railway dashboard for build status:" -ForegroundColor Cyan
Write-Host "https://railway.com/project/cceba7af-62de-4433-9dcc-f69e669af1ee/service/bb8d8e07-4794-40e3-8010-128755d87ff7" -ForegroundColor Cyan
