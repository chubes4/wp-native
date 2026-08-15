/**
 * Copyright (C) 2026 wp-native contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

const assert = require('node:assert/strict');
const test = require('node:test');
const plugin = require('../plugin/index.cjs');

test('configures the supported native build requirements', () => {
  assert.deepEqual(plugin.buildProperties, {
    ios: {
      deploymentTarget: '17.0',
    },
    android: {
      extraMavenRepos: ['https://a8c-libs.s3.amazonaws.com/android'],
    },
  });
});
