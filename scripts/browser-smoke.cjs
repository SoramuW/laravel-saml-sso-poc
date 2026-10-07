// Run with Playwright installed in the verification environment; no frontend build required.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const base = process.env.APP_TEST_URL || 'http://localhost:8000';
  const browser = await chromium.launch({
    headless: true,
    ...(process.env.BROWSER_HOST_GATEWAY ? { args: [`--host-resolver-rules=MAP localhost ${process.env.BROWSER_HOST_GATEWAY}`] } : {}),
    ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}),
  });
  let page;
  try {
    const context = await browser.newContext();
    page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(30000);
    page.on('response', response => { if (response.status() >= 400) console.error('HTTP', response.status(), response.url().split('?')[0]); });
    fs.mkdirSync('test-results', { recursive: true });
    const metadataUrl = new URL(`${base}/saml/metadata`);
    if (process.env.BROWSER_HOST_GATEWAY) metadataUrl.hostname = process.env.BROWSER_HOST_GATEWAY;
    const metadata = await context.request.get(metadataUrl.toString());
    assert.equal(metadata.status(), 200);
    const xml = await metadata.text();
    assert.match(xml, /entityID="laravel-saml"/);
    assert.ok(xml.includes(`${base}/saml/acs`));
    assert.ok(xml.includes(`${base}/saml/sls`));
    await page.goto(base);
    await page.getByRole('link', { name: 'SAMLログイン', exact: true }).click();
    await page.locator('#username').fill('testuser');
    await page.locator('#password').fill('local-test-only');
    await page.locator('#kc-login').click();
    await page.waitForURL(`${base}/`);
    assert.ok((await page.locator('body').innerText()).includes('SAML Attributes'));
    for (const value of ['testuser', 'test@example.com', 'firstName', 'lastName', 'Test', 'User']) {
      assert.ok((await page.locator('body').innerText()).includes(value), `Missing attribute: ${value}`);
    }
    await page.screenshot({ path: 'test-results/logged-in.png', fullPage: true });
    console.log('PASS: Metadata, signed SAML login, NameID and all mapped attributes');
    await page.getByRole('link', { name: 'SAMLログアウト', exact: true }).click();
    await page.waitForURL(`${base}/`);
    await page.getByRole('link', { name: 'SAMLログイン', exact: true }).waitFor();
    await page.screenshot({ path: 'test-results/logged-out.png', fullPage: true });
    // Reuse the same browser cookies. Seeing the password form proves IdP SSO ended.
    await page.getByRole('link', { name: 'SAMLログイン', exact: true }).click();
    await page.locator('#username').waitFor();
    await page.locator('#password').waitFor();
    console.log('PASS: Single Logout ends Laravel and Keycloak sessions');
  } catch (error) {
    console.error(error);
    if (page) { console.error('Page:', page.url().split('?')[0], (await page.locator('body').innerText()).slice(0,1200)); await page.screenshot({ path: 'test-results/failure.png', fullPage: true }); }
    process.exitCode = 1;
  } finally {
    await browser.close();
  }
})();
