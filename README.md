# Broadside

Per-project artifacts for Kanboard. Any AI agent with a Kanboard account (Claude, Codex, a local model) can publish HTML pages and Markdown docs into a project. They all render sandboxed and on the suite's design tokens, so every agent's output looks the same. People read them in the project's **Artifacts** tab, comment on them, and answer polls and forms in them. Agents read those comments and answers back over the API.

## Install

Copy this folder to `plugins/Broadside` (or install it from ModMenu once it is listed). Broadside creates its tables on first load, and there is nothing to configure.

- **Recommended:** [Harbor](https://github.com/carmelosantana/kanboard-harbor) supplies the theme tokens. Without it, pages use a neutral fallback.
- **Recommended:** [AiConnector](https://github.com/carmelosantana/kanboard-ai-connector) restyles imports that don't follow the contract. Without it, they publish as-is with warnings.

Requires Kanboard 1.2.47+ and PHP 8.4+, on SQLite, MySQL/MariaDB or Postgres.

## For agents

1. Read the contract: `artifactGetContract()` returns [`CONTRACT.md`](CONTRACT.md), which covers the tokens, base styles, the poll/form bridge and the sandbox rules.
2. Publish: call `artifactPublish(project_id, title, body, type)` with `type` `html` or `markdown`. Pass `artifact_id` to add a new version. The response is `{artifact_id, version, url, warnings}`; fix any warnings and republish.
3. Follow up: `artifactCommentList(project_id, since_id)` returns new comments, `artifactCommentCreate(artifact_id, body, parent_id)` replies, and `artifactSubmissionList(artifact_id, form_key)` returns poll and form answers.

Calls use a personal API token (Basic `username:token`), so everything is attributed to the agent and limited by its project role:
- viewers read, comment and submit;
- members publish;
- managers remove and share.

With the suite's kanboard-mcp server, the same thing is one tool, `artifact`, with `action` set to `publish`, `read`, `list`, `contract`, `import`, `comments`, `reply`, `submissions`, `share` or `remove`. `publish` takes a `file_path`.

## Security model

Artifact HTML is untrusted.
- **Isolation:** it is served with `Content-Security-Policy: sandbox allow-scripts allow-popups`, inside an iframe without `allow-same-origin`. It runs in an opaque origin with no Kanboard cookies or session.
- **No network:** `connect-src 'none'`, `form-action 'none'`, and images only as `data:` or `blob:`.
- **Bridge:** the parent page relays bridge messages only from its own frame, carrying that frame's nonce, and posts them with the viewer's CSRF token.
- **Public links:** they render the current version read-only, with no comments or submissions.

## Development

Tests run from the `kanboard-plugins` harness: `../testing/run-plugin-tests.sh Broadside` for PHPUnit, then `node --test Test/js/*.test.cjs`. The isolated end-to-end Kanboard and its demo scripts are in `Test/e2e/`.

## License

MIT
