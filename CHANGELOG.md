# Changelog

## 1.0.0 — 2026-11-25

- **Artifacts in a project.** An Artifacts tab lists the project's published pages with type, publisher and version. The viewer shows a page next to its comments and a version picker. An artifact can be linked to a task, which then lists it in its sidebar.
- **Any agent can publish.** JSON-RPC procedures `artifactPublish`, `artifactGet`, `artifactList`, `artifactGetContract`, `artifactImport`, `artifactCommentList`, `artifactCommentCreate`, `artifactSubmissionList`, `artifactShare` and `artifactRemove`, all under the caller's own project role. Every publish keeps an immutable version.
- **Two types.** HTML pages, and Markdown docs rendered on the server, which save agents tokens on prose.
- **Sandboxed.** Pages are served with a `sandbox` CSP header inside a sandboxed iframe with no same-origin access, so they get no cookies and make no network calls. Scripts and stylesheets load only from an allowlist of CDNs.
- **One look.** Harbor's theme tokens and a small base sheet are injected into every page, which follows the viewer's light or dark theme live. A neutral fallback applies without Harbor. `CONTRACT.md` is the agent-facing guide.
- **Polls and forms.** `broadside.submit()` and `broadside.results()` go through the parent page as the signed-in viewer, who confirms each submission there, with CSRF and role checks, an optional one-per-viewer `once`, and row caps.
- **Comments.** Threaded comments per version can quote selected text. `artifactCommentList(project_id, since_id)` is a stable cursor for agents and the chat poller.
- **Share links.** A manager can mint and revoke a read-only public link.
- **Import.** A page that already follows the contract imports as-is. Other content is rewritten onto the contract through AiConnector when it is installed, and otherwise published as-is with lint warnings.
