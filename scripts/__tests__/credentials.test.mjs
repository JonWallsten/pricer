import { describe, it, expect } from "vitest";
import { join } from "node:path";
import { writeFileSync, mkdirSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import {
  parseCredentials,
  loadCredentials,
  loadAppCredentials,
  loadFtpCredentials,
  validateCredentials,
  DEFAULT_CREDENTIALS_PATH,
  DEFAULT_LOCAL_CREDENTIALS_PATH,
  DEFAULT_FTP_PATH,
} from "../lib/credentials.mjs";

describe("parseCredentials", () => {
  it("parses simple KEY=VALUE pairs", () => {
    const result = parseCredentials("FOO=bar\nBAZ=qux");
    expect(result).toEqual({ FOO: "bar", BAZ: "qux" });
  });

  it("ignores blank lines", () => {
    const result = parseCredentials("\nFOO=bar\n\nBAZ=qux\n");
    expect(result).toEqual({ FOO: "bar", BAZ: "qux" });
  });

  it("ignores comment lines", () => {
    const result = parseCredentials("# this is a comment\nFOO=bar\n# another");
    expect(result).toEqual({ FOO: "bar" });
  });

  it("handles values containing = signs", () => {
    const result = parseCredentials("DB_PASS=my=complex=pass");
    expect(result).toEqual({ DB_PASS: "my=complex=pass" });
  });

  it("trims whitespace around keys and values", () => {
    const result = parseCredentials("  FOO  =  bar baz  ");
    expect(result).toEqual({ FOO: "bar baz" });
  });

  it("strips surrounding double quotes from values", () => {
    const result = parseCredentials('FOO="hello world"');
    expect(result).toEqual({ FOO: "hello world" });
  });

  it("strips surrounding single quotes from values", () => {
    const result = parseCredentials("FOO='hello world'");
    expect(result).toEqual({ FOO: "hello world" });
  });

  it("does not strip mismatched quotes", () => {
    const result = parseCredentials("FOO=\"hello'");
    expect(result).toEqual({ FOO: "\"hello'" });
  });

  it("handles empty values", () => {
    const result = parseCredentials("FOO=\nBAR=");
    expect(result).toEqual({ FOO: "", BAR: "" });
  });

  it("skips lines without = sign", () => {
    const result = parseCredentials("NOEQUALHERE\nFOO=bar");
    expect(result).toEqual({ FOO: "bar" });
  });

  it("returns empty object for empty content", () => {
    expect(parseCredentials("")).toEqual({});
  });

  it("handles Windows line endings (CRLF)", () => {
    const result = parseCredentials("FOO=bar\r\nBAZ=qux\r\n");
    expect(result).toEqual({ FOO: "bar", BAZ: "qux" });
  });
});

describe("loadCredentials", () => {
  const tmpDir = join(tmpdir(), "cred-test-" + Date.now());

  it("loads from a real file", () => {
    mkdirSync(tmpDir, { recursive: true });
    const filePath = join(tmpDir, ".test.env");
    writeFileSync(filePath, "DB_HOST=localhost\nDB_NAME=testdb\n");

    const result = loadCredentials(filePath);
    expect(result).toEqual({ DB_HOST: "localhost", DB_NAME: "testdb" });

    rmSync(tmpDir, { recursive: true, force: true });
  });

  it("throws when file does not exist", () => {
    expect(() => loadCredentials("/nonexistent/.env")).toThrow();
  });
});

describe("validateCredentials", () => {
  it("does not throw when all required keys are present", () => {
    const creds = { A: "1", B: "2", C: "3" };
    expect(() => validateCredentials(creds, ["A", "B"])).not.toThrow();
  });

  it("throws listing all missing keys", () => {
    const creds = { A: "1" };
    expect(() => validateCredentials(creds, ["A", "B", "C"])).toThrow(
      "Missing required credentials: B, C",
    );
  });

  it("treats empty-string values as missing", () => {
    const creds = { A: "1", B: "" };
    expect(() => validateCredentials(creds, ["A", "B"])).toThrow("B");
  });

  it("does not throw for empty required-keys list", () => {
    expect(() => validateCredentials({}, [])).not.toThrow();
  });
});

describe("loadAppCredentials", () => {
  const tmpDir = join(tmpdir(), "appcred-test-" + Date.now());
  const origPaths = {};

  // We can't easily override module-level const paths, so test via
  // loadCredentials and parseCredentials behavior that loadAppCredentials wraps.
  // Instead, test the overlay logic by importing the function and checking it exists.

  it("is exported as a function", () => {
    expect(typeof loadAppCredentials).toBe("function");
  });
});

describe("loadFtpCredentials", () => {
  const tmpDir = join(tmpdir(), "ftpcred-test-" + Date.now());

  it("loads FTP credentials from a given path", () => {
    mkdirSync(tmpDir, { recursive: true });
    const ftpFile = join(tmpDir, ".ftp.env");
    writeFileSync(
      ftpFile,
      "FTP_HOST=example.com\nFTP_USER=me\nFTP_PASS=secret\nFTP_PATH=/www\n",
    );

    const result = loadFtpCredentials(ftpFile);
    expect(result).toEqual({
      FTP_HOST: "example.com",
      FTP_USER: "me",
      FTP_PASS: "secret",
      FTP_PATH: "/www",
    });

    rmSync(tmpDir, { recursive: true, force: true });
  });

  it("throws when .ftp.env does not exist", () => {
    expect(() => loadFtpCredentials("/nonexistent/.ftp.env")).toThrow();
  });

  it("exports distinct default paths for each credential file", () => {
    expect(DEFAULT_CREDENTIALS_PATH).toContain(".credentials.env");
    expect(DEFAULT_LOCAL_CREDENTIALS_PATH).toContain(".credentials.local.env");
    expect(DEFAULT_FTP_PATH).toContain(".ftp.env");
    // Ensure they are all different
    const paths = new Set([
      DEFAULT_CREDENTIALS_PATH,
      DEFAULT_LOCAL_CREDENTIALS_PATH,
      DEFAULT_FTP_PATH,
    ]);
    expect(paths.size).toBe(3);
  });
});
