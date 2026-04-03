import { defineConfig } from "vitest/config";

export default defineConfig({
  test: {
    include: ["scripts/__tests__/api.test.mjs"],
    testTimeout: 15000, // API calls can be slow
    hookTimeout: 15000,
  },
});
