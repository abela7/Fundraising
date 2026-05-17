const { chromium, webkit, devices } = require('playwright');

async function sample(browserType, name, contextOptions = {}) {
  const browser = await browserType.launch({ headless: true });
  const context = await browser.newContext(contextOptions);
  const page = await context.newPage();
  await page.goto('http://localhost/Fundraising/invitation/hmamat.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  const start = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('.blood-unit')).map((el, i) => ({
      i,
      transform: getComputedStyle(el).transform,
      opacity: getComputedStyle(el).opacity,
      rectTop: Math.round(el.getBoundingClientRect().top),
      rectLeft: Math.round(el.getBoundingClientRect().left)
    }));
  });
  await page.waitForTimeout(1800);
  const later = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('.blood-unit')).map((el, i) => ({
      i,
      transform: getComputedStyle(el).transform,
      opacity: getComputedStyle(el).opacity,
      rectTop: Math.round(el.getBoundingClientRect().top),
      rectLeft: Math.round(el.getBoundingClientRect().left)
    }));
  });
  console.log('\n=== ' + name + ' ===');
  console.log(JSON.stringify({ start, later }, null, 2));
  await browser.close();
}

(async () => {
  await sample(chromium, 'chromium-desktop', { viewport: { width: 1280, height: 900 } });
  await sample(chromium, 'chromium-mobile', { ...devices['iPhone 13'] });
  await sample(webkit, 'webkit-mobile', { ...devices['iPhone 13'] });
})();
