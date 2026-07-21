import js from "@eslint/js";
import globals from "globals";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import tseslint from "typescript-eslint";

export default tseslint.config(
  {
    ignores: [
      "dist",
      ".next",
      "node_modules",
      "examples",
      "out",
      "build",
      "next-env.d.ts",
      "skills",
    ],
  },
  {
    extends: [js.configs.recommended, ...tseslint.configs.recommended],
    files: ["**/*.{ts,tsx}"],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    plugins: {
      "react-hooks": reactHooks,
      "react-refresh": reactRefresh,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      "react-refresh/only-export-components": ["warn", { allowConstantExport: true }],

      // ── TypeScript rules (Round 8: restored from 'off' to 'warn') ──────
      // Using 'warn' (not 'error') so the build doesn't fail on existing
      // violations — operators see the warnings in the lint output and can
      // fix them iteratively. Upgrade to 'error' once the codebase is clean.
      "@typescript-eslint/no-unused-vars": ["warn", {
        argsIgnorePattern: "^_",
        varsIgnorePattern: "^_",
        caughtErrorsIgnorePattern: "^_",
      }],
      "@typescript-eslint/no-explicit-any": "warn",
      "@typescript-eslint/no-empty-object-type": "warn",
      "@typescript-eslint/ban-ts-comment": "warn",
      "@typescript-eslint/no-non-null-assertion": "warn",
      "@typescript-eslint/prefer-as-const": "warn",
      "@typescript-eslint/no-unused-disable-directive": "warn",

      // ── General rules (Round 8: restored) ──────────────────────────────
      "prefer-const": "warn",
      "no-unused-vars": "off", // handled by @typescript-eslint/no-unused-vars
      "no-console": "off",     // handled by the logger utility (R7) — console
                               // calls are replaced with logger.* which no-op
                               // in production. No need to lint here.
      "no-empty": "warn",

      // ── React Hooks (Round 8: restored) ────────────────────────────────
      // exhaustive-deps was 'off' — restoring to 'warn' to catch missing
      // dependency arrays in useEffect/useCallback/useMemo. This is the
      // highest-value React rule for preventing stale-closure bugs.
      "react-hooks/exhaustive-deps": "warn",
    },
  },
);
