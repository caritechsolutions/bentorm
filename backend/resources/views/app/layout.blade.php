<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Ride Coop')</title>
<style>
  :root { --bg:#f6f7f9; --card:#fff; --ink:#1f2328; --muted:#6b7280; --line:#e5e7eb; --brand:#0e7bb6; --yellow:#febd25; --red:#ea483b; --ok:#15803d; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b0f14; --card:#141a22; --ink:#e5e7eb; --muted:#9ca3af; --line:#263040; } }
  * { box-sizing:border-box; }
  body { margin:0; font:16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background:var(--bg); color:var(--ink); padding:16px 16px 40px; }
  main { max-width:640px; margin:0 auto; }
  h1 { font-size:24px; margin:4px 0 12px; } h2 { font-size:18px; margin:24px 0 8px; } p { margin:0 0 12px; }
  .muted { color:var(--muted); } .small { font-size:14px; }
  .card { background:var(--card); border-radius:14px; padding:16px; margin:0 0 12px; border:1px solid var(--line); }
  a { color:var(--brand); } a.row { display:flex; justify-content:space-between; align-items:center; gap:12px; text-decoration:none; color:inherit; }
  .chev:after { content:"›"; font-size:22px; color:var(--muted); }
  .btn { display:block; width:100%; padding:14px; border:0; border-radius:12px; font-size:16px; font-weight:600; text-align:center; cursor:pointer; text-decoration:none; }
  .btn-primary { background:var(--brand); color:#fff; } .btn-ghost { background:transparent; color:var(--brand); border:1px solid var(--line); }
  input, select, textarea { width:100%; font:inherit; padding:12px; border-radius:10px; border:1px solid var(--line); background:var(--card); color:var(--ink); margin:4px 0 12px; }
  label { font-weight:600; font-size:14px; }
  .pill { display:inline-block; font-size:12px; padding:2px 10px; border-radius:999px; background:var(--line); }
  .pill.open { background:#fef3c7; color:#92400e; } .pill.closed { background:#dcfce7; color:#166534; }
  .flash { background:#dcfce7; color:#166534; padding:12px; border-radius:10px; margin-bottom:12px; }
  .error { color:var(--red); font-size:14px; margin:-8px 0 12px; }
  table { width:100%; border-collapse:collapse; } td, th { padding:8px 4px; border-bottom:1px solid var(--line); text-align:left; font-size:14px; } td.num, th.num { text-align:right; white-space:nowrap; }
  .msg { padding:10px 12px; border-radius:12px; margin:8px 0; max-width:85%; } .msg.me { background:var(--brand); color:#fff; margin-left:auto; } .msg.them { background:var(--card); border:1px solid var(--line); }
  .article img { max-width:100%; }
</style>
</head>
<body><main>
@if (session('flash'))<div class="flash">{{ session('flash') }}</div>@endif
@yield('content')
</main></body>
</html>
