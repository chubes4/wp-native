import { execFileSync } from "node:child_process";
import { test } from "node:test";

test("shell package typechecks", () => {
  execFileSync("tsc", ["-b"], {
    cwd: new URL("..", import.meta.url),
    stdio: "pipe",
  });
});
