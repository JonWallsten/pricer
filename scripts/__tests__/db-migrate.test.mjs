import { describe, it, expect } from "vitest";
import { join } from "node:path";
import { writeFileSync, mkdirSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { getMigrationFiles, parseMigrationFilename } from "../db-migrate.mjs";

describe("getMigrationFiles", () => {
  const tmpDir = join(tmpdir(), "migrate-test-" + Date.now());

  it("returns sorted SQL files", () => {
    mkdirSync(tmpDir, { recursive: true });
    writeFileSync(join(tmpDir, "003_third.sql"), "");
    writeFileSync(join(tmpDir, "001_first.sql"), "");
    writeFileSync(join(tmpDir, "002_second.sql"), "");
    writeFileSync(join(tmpDir, "README.md"), ""); // non-SQL

    const files = getMigrationFiles(tmpDir);
    expect(files).toEqual(["001_first.sql", "002_second.sql", "003_third.sql"]);

    rmSync(tmpDir, { recursive: true, force: true });
  });

  it("returns empty array for empty directory", () => {
    const emptyDir = join(tmpdir(), "migrate-empty-" + Date.now());
    mkdirSync(emptyDir, { recursive: true });

    expect(getMigrationFiles(emptyDir)).toEqual([]);

    rmSync(emptyDir, { recursive: true, force: true });
  });
});

describe("parseMigrationFilename", () => {
  it("parses underscore-separated filename", () => {
    const result = parseMigrationFilename("003_create_flour_blends.sql");
    expect(result).toEqual({ sequence: 3, name: "create_flour_blends" });
  });

  it("parses hyphen-separated filename", () => {
    const result = parseMigrationFilename("001-create-users.sql");
    expect(result).toEqual({ sequence: 1, name: "create-users" });
  });

  it("parses high sequence numbers", () => {
    const result = parseMigrationFilename("042_add_index.sql");
    expect(result).toEqual({ sequence: 42, name: "add_index" });
  });

  it("returns null for invalid filename", () => {
    expect(parseMigrationFilename("README.md")).toBeNull();
    expect(parseMigrationFilename("no-number.sql")).toBeNull();
  });
});
