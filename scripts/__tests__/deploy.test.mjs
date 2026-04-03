import { describe, it, expect } from "vitest";
import { parseDeployFlags } from "../deploy.mjs";

describe("parseDeployFlags", () => {
  it("defaults to all false", () => {
    expect(parseDeployFlags([])).toEqual({
      dryRun: false,
      apiOnly: false,
      frontendOnly: false,
      credentialsOnly: false,
    });
  });

  it("parses --dry-run", () => {
    const flags = parseDeployFlags(["--dry-run"]);
    expect(flags.dryRun).toBe(true);
    expect(flags.apiOnly).toBe(false);
    expect(flags.frontendOnly).toBe(false);
    expect(flags.credentialsOnly).toBe(false);
  });

  it("parses --api-only", () => {
    const flags = parseDeployFlags(["--api-only"]);
    expect(flags.apiOnly).toBe(true);
    expect(flags.frontendOnly).toBe(false);
  });

  it("parses --frontend-only", () => {
    const flags = parseDeployFlags(["--frontend-only"]);
    expect(flags.frontendOnly).toBe(true);
    expect(flags.apiOnly).toBe(false);
  });

  it("parses --credentials-only", () => {
    const flags = parseDeployFlags(["--credentials-only"]);
    expect(flags.credentialsOnly).toBe(true);
    expect(flags.apiOnly).toBe(false);
    expect(flags.frontendOnly).toBe(false);
  });

  it("parses multiple flags together", () => {
    const flags = parseDeployFlags(["--dry-run", "--api-only"]);
    expect(flags.dryRun).toBe(true);
    expect(flags.apiOnly).toBe(true);
    expect(flags.frontendOnly).toBe(false);
  });

  it("throws on unknown flag", () => {
    expect(() => parseDeployFlags(["--invalid"])).toThrow(
      "Unknown flag: --invalid",
    );
  });
});
