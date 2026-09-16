// Automated accessibility check. axe-core finds roughly a third of real a11y
// defects; the SOP still requires one manual screen-reader pass (Stage 2.5),
// because automation cannot judge whether the reading order makes sense.
//
// This file existing is what turns the CI a11y step on — the workflow runs it
// only when e2e/a11y.spec.ts is present, so the gate cannot be "green" while
// silently doing nothing.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('home has no detectable a11y violations', async ({ page }) => {
  await page.goto('/');
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
    .analyze();
  expect(results.violations).toEqual([]);
});
