# Broadside end-to-end demo

An isolated Kanboard (`kb-broadside`, 127.0.0.1:8097) with Broadside and Harbor mounted read-only. It never touches the shared `kb-suite`.

1. `chmod -R o+rX .` in this repo, then `docker compose -f Test/e2e/compose.yml up -d`.
2. Create a project, and an agent user with project-member role and a password, using the app token (`settings.api_token` in the container DB). Save the password in a mode-600 file and point `BROADSIDE_PAGEBOT_PASS_FILE` at it.
3. Build kanboard-mcp to `/tmp/kanboard-mcp-broadside` and publish as the agent:
   `python3 Test/e2e/mcp_call.py '{"action":"publish","project_id":1,"title":"Build status","file_path":"Test/e2e/status-report.html"}'`
4. From the `kanboard-plugins` folder (for Playwright): `node Broadside/Test/e2e/smoke.mjs <outdir>` logs in as admin/admin, checks the frame, the sandbox, a poll vote and a comment, and saves screenshots.
