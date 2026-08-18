// Vitest configuration for ScoutMagic's first-party browser JavaScript unit
// tests (development/test tooling only — see AGENTS.md § CSS / frontend and
// ARCHITECTURE.md § 15 Tests). Tests run against the real production files
// under public/assets/js/ in a jsdom-simulated DOM, with no PHP server, no
// database, and no network (fetch/Service Worker/WebAuthn are mocked in the
// tests themselves where needed).
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        coverage: {
            provider: 'v8',
            // 'lcov' writes <reportsDirectory>/lcov.info — the exact path
            // sonar-project.properties' sonar.javascript.lcov.reportPaths
            // and .github/workflows/ci.yml's javascript-tests job both
            // reference (coverage/js/lcov.info).
            reporter: ['text', 'lcov'],
            reportsDirectory: 'coverage/js',
            // Only first-party JavaScript intended for testing is measured.
            include: ['public/assets/js/**/*.js'],
            exclude: [
                // Third-party vendored libraries (Bootstrap, Chart.js, ...)
                // are never first-party coverage — see AGENTS.md.
                'public/assets/vendor/**',
                'tests/**',
                'node_modules/**',
            ],
        },
    },
});
