# Todo imports-only migration: browser proof

From the repository root, with workspace and Composer dependencies installed:

```sh
node tests/integration/todo-migration/browser.mjs
```

This test needs Linux, PHP with `pdo_pgsql`, PostgreSQL 15+ with logical replication,
`psql`, and `agent-browser` with Chromium installed. The defaults match the orb's
test cluster (`127.0.0.1:55433`, user `postgres`). Override with `ZERO_TEST_PG_HOST`,
`ZERO_TEST_PG_PORT`, `ZERO_TEST_PG_USER` and `ZERO_TEST_PG_PASSWORD`. The role must
be able to create a database and run Zero replication. No existing app database
is used. The runner creates and removes its own database, slots, application
directory, replica, workers and browser session.

The test copies `forms.tsx`, replaces exactly its two imports, typechecks the
result against the freshly generated controller, and bundles it for a real
Inertia React page. A fixture login establishes an ordinary Laravel web session;
native Zero callbacks forward the session cookie. The test holds the actual
mutation push before Laravel handles it. The ordinary show page must render the
locally created row (including its decoded timestamp) with working controls and
stay mounted through confirmation. A warm reload must show persisted rows even
with the Zero WebSocket blocked; delete must show the surviving sibling before
commit. The test also checks the URL, history, recovered controls, request log and
Postgres rows. A separate WAL update proves that the departed page
remains mounted after the client has received the committed change. A late
rejection must leave the same Notes heading mounted and show the provider's
default mutation-error notice. The last checks independently revoke row
visibility and class-level page access.

Set `TODO_EVIDENCE` to a directory to save screenshots and observations. In an
Amp orb, also set `TODO_ACTION_VIDEO` to the `using-agent-browser` skill's
`scripts/action-video.mjs` and `AGENT_BROWSER_EXECUTABLE_PATH` to its desktop
pointer Chrome wrapper to record the create interaction. Neither is required
for the assertions.
