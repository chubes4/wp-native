const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const projectRoot = path.resolve(__dirname, '..');
const read = (relativePath) =>
  fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');

const hasIOS = fs.existsSync(path.join(projectRoot, 'ios'));
const hasAndroid = fs.existsSync(path.join(projectRoot, 'android'));
assert.ok(hasIOS || hasAndroid, 'Expo prebuild did not generate a native project');

if (hasIOS) {
  const podProperties = JSON.parse(read('ios/Podfile.properties.json'));
  assert.equal(podProperties['ios.deploymentTarget'], '17.0');
  assert.match(read('ios/Podfile'), /use_expo_modules!/);
}

if (hasAndroid) {
  const gradleProperties = read('android/gradle.properties');
  const repositoryLine = gradleProperties
    .split('\n')
    .find((line) => line.startsWith('android.extraMavenRepos='));
  assert.ok(repositoryLine, 'Android Maven repositories were not configured');
  assert.deepEqual(JSON.parse(repositoryLine.split('=', 2)[1]), [
    { url: 'https://a8c-libs.s3.amazonaws.com/android' },
  ]);
}

console.log('Clean prebuild applied the native build requirements.');
