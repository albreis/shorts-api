const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const newVersion = process.argv[2];

if (!newVersion) {
  console.error('Usage: npm run deploy <version>');
  process.exit(1);
}

const rootDir = path.join(__dirname, '..');
const phpFile = path.join(rootDir, 'shorts-api.php');
const readmeFile = path.join(rootDir, 'readme.txt');
const updateFile = path.join(rootDir, 'update.json');
const packageFile = path.join(rootDir, 'package.json');

// 1. Update shorts-api.php
let phpContent = fs.readFileSync(phpFile, 'utf8');
phpContent = phpContent.replace(/Version:\s*[\d\.]+/, `Version: ${newVersion}`);
fs.writeFileSync(phpFile, phpContent);
console.log(`Updated version in shorts-api.php to ${newVersion}`);

// 2. Update readme.txt
let readmeContent = fs.readFileSync(readmeFile, 'utf8');
readmeContent = readmeContent.replace(/Stable tag:\s*[\d\.]+/, `Stable tag: ${newVersion}`);
fs.writeFileSync(readmeFile, readmeContent);
console.log(`Updated stable tag in readme.txt to ${newVersion}`);

// 3. Update update.json
const updateData = {
  version: newVersion,
  download_url: `https://github.com/albreis/shorts-api/archive/refs/tags/v${newVersion}.zip`
};
fs.writeFileSync(updateFile, JSON.stringify(updateData, null, 2));
console.log(`Updated update.json to version ${newVersion}`);

// 4. Update package.json
let packageContent = JSON.parse(fs.readFileSync(packageFile, 'utf8'));
packageContent.version = newVersion;
fs.writeFileSync(packageFile, JSON.stringify(packageContent, null, 2));
console.log(`Updated package.json to version ${newVersion}`);

// 5. Git commands
try {
  execSync(`git add .`, { stdio: 'inherit' });
  execSync(`git commit -m "chore: release v${newVersion}"`, { stdio: 'inherit' });
  execSync(`git tag v${newVersion}`, { stdio: 'inherit' });
  execSync(`git push`, { stdio: 'inherit' });
  execSync(`git push --tags`, { stdio: 'inherit' });
  console.log('Successfully deployed and tagged version ' + newVersion);
} catch (error) {
  console.error('Error during git commands:', error.message);
  process.exit(1);
}
