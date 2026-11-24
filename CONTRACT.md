# Broadside artifact contract

Publish one self-contained page per artifact with a personal Kanboard API credential.
Publish again with the same `artifact_id` to create a new version. Use `task_id` to
link a task in the artifact's project and `source_url` to record where content came
from. The body can be up to 5 MiB, including embedded images.

## Page types

- `html` (default) accepts a full HTML document or a fragment. Use semantic HTML
  with custom CSS or JavaScript where needed.
- `markdown` renders on the server into the same sandbox and styles. Raw HTML in
  Markdown is escaped. Use HTML for interactive pages.

## Theme and base styles

Broadside injects Harbor's theme and preset tokens, with a neutral fallback when
Harbor is absent. Use the tokens in your CSS so styles follow the viewer's light
or dark theme. Pair a background with its corresponding foreground.

| Tokens | Purpose |
| --- | --- |
| `--background`, `--foreground` | Page background and default text |
| `--card`, `--card-foreground` | Card surface and text |
| `--primary`, `--primary-foreground` | Primary action or link colour and text on a primary surface |
| `--secondary`, `--secondary-foreground` | Secondary action surface and text |
| `--muted`, `--muted-foreground` | Subdued surface and supporting text |
| `--accent`, `--accent-foreground` | Highlight surface and text |
| `--border` | Dividers and component outlines |
| `--input`, `--input-surface` | Form field border and background |
| `--ring` | Keyboard focus outline |
| `--radius`, `--radius-md`, `--radius-sm` | Standard, medium and small corner rounding |
| `--space-1`, `--space-2`, `--space-3`, `--space-4`, `--space-6` | Spacing scale from 0.25 to 1.5 rem |
| `--control-height` | Minimum height for buttons and text fields |

The base stylesheet supplies system typography, page padding, heading spacing,
link styling, tables, inline code, scrollable code blocks, blockquotes, dividers,
buttons, form controls and visible keyboard focus. Add `class="card"` for a
padded, bordered surface or `class="bs-doc"` for a centred, readable measure.
Markdown documents receive `.bs-doc` automatically.

## Submissions and results

In the signed-in viewer, `broadside.submit(formKey, data, opts)` returns a promise
for `{id}` and records a submission under the viewer's identity. Use
`{once: true}` in `opts` for one submission per viewer and form. Form keys use
1–64 lowercase ASCII letters, digits, underscores or hyphens. Send a JSON object
of up to 64 KiB as `data`.

`broadside.results(formKey)` returns a promise for
`{form_key, count, rows, mine}`. Each row contains `username`, `data` and
`created_at`. Submissions and results are available to signed-in users with
project access. Handle rejected promises in the page.

An HTML poll that records one vote per viewer and refreshes the tally after a vote:

```html
<h1>Which draft should we review?</h1>
<button type="button" data-choice="a">Draft A</button>
<button type="button" data-choice="b">Draft B</button>
<p id="tally" role="status"></p>
<script>
  const tally = document.querySelector('#tally');

  async function refresh() {
    const { rows } = await broadside.results('draft_poll');
    const a = rows.filter(({ data }) => data.choice === 'a').length;
    const b = rows.filter(({ data }) => data.choice === 'b').length;
    tally.textContent = `Draft A — ${a} · Draft B — ${b}`;
  }

  function showError(error) {
    tally.textContent = error.message;
  }

  for (const button of document.querySelectorAll('button[data-choice]')) {
    button.addEventListener('click', async () => {
      try {
        await broadside.submit('draft_poll', { choice: button.dataset.choice }, { once: true });
        await refresh();
      } catch (error) {
        showError(error);
      }
    });
  }

  refresh().catch(showError);
</script>
```

## Sandbox and network

Artifact documents allow inline CSS and JavaScript. External scripts and
stylesheets may load over HTTPS from these CDN hosts:

- `cdnjs.cloudflare.com`
- `cdn.jsdelivr.net`
- `unpkg.com`
- `cdn.tailwindcss.com`
- `code.jquery.com`

Google Fonts CSS can load from `fonts.googleapis.com`, with font files from
`fonts.gstatic.com`. Embed images as `data:` URLs. Use the bridge for submissions
and results. The sandbox blocks network connections from JavaScript, including
`fetch`, XMLHttpRequest and WebSockets, as well as native form submissions.

## Minimal page

```html
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Release notes</title>
  <style>
    .status { color: var(--primary); }
  </style>
</head>
<body>
  <main class="bs-doc">
    <h1>Release notes</h1>
    <section class="card">
      <h2 class="status">Ready for review</h2>
      <p>The draft is ready for the project team.</p>
    </section>
  </main>
</body>
</html>
```
