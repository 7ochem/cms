const {exec} = require('child_process');
const path = require('path');
const {chromium, expect} = require('@playwright/test');
const docker = require('./_docker');
const craft = require('./_craft');

module.exports = async (config) => {
  console.log('Setting up');
  const {baseURL, db, password, projectPath, storageState, testDir, username} =
    config.projects[0].use;

  const dockerComposeYaml = path.join(testDir, '../docker-compose.yaml');

  await docker.up();

  await craft.createProject();

  await craft.install(username, password);

  // await craft.createUser(username, password);

  // Backup DB for quick restores
  // const dbBackupPath = path.resolve(path.join(testDir, '.backup.sql'));
  // await exec(`${projectPath}/craft db/backup ${dbBackupPath}`, (error, stdout, stderr) => {
  //   console.log(stdout);
  // });

  const browser = await chromium.launch();
  const page = await browser.newPage();

  await page.goto(new URL('./login', baseURL).href);
  await page.fill('#loginName', username);
  await page.fill('#password', password);

  await Promise.all([page.waitForNavigation(), page.click('button#submit')]);

  const title = page.locator('h1');
  await expect(title).toHaveText('Dashboard');

  // Save signed-in state
  await page.context().storageState({path: storageState});
  // await page.close();
  await browser.close();
};
