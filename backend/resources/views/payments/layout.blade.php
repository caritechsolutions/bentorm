<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'Ride Coop')</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --ink:#111827; --muted:#6b7280; --ok:#15803d; --bad:#b91c1c; --brand:#0f766e; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b0f14; --card:#141a22; --ink:#e5e7eb; --muted:#9ca3af; } }
  body { margin:0; font:16px/1.5 system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:var(--bg); color:var(--ink);
         min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; box-sizing:border-box; }
  .card { background:var(--card); border-radius:16px; padding:28px; max-width:420px; width:100%; box-shadow:0 4px 24px rgba(0,0,0,.08); text-align:center; }
  h1 { font-size:22px; margin:0 0 8px; } p { color:var(--muted); margin:0 0 20px; }
  .amount { font-size:34px; font-weight:700; margin:8px 0 20px; color:var(--ink); }
  button, a.btn { display:block; width:100%; padding:14px; border:0; border-radius:10px; font-size:16px; font-weight:600; cursor:pointer; margin-top:10px; text-decoration:none; box-sizing:border-box; }
  .approve { background:var(--ok); color:#fff; } .decline { background:transparent; color:var(--bad); border:1px solid var(--bad); }
  .primary { background:var(--brand); color:#fff; } .ok { color:var(--ok); } .bad { color:var(--bad); }
  .tag { display:inline-block; font-size:12px; padding:2px 8px; border-radius:999px; background:#fef3c7; color:#92400e; margin-bottom:12px; }
</style>
</head>
<body><div class="card">@yield('content')</div></body>
</html>
