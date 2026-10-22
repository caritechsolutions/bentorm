<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f5f6f7;font-family:Arial,Helvetica,sans-serif;color:#393d3f">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:460px;margin:0 auto;background:#ffffff;border-radius:12px">
    <tr><td style="padding:28px 28px 8px;font-size:22px;font-weight:bold">{{ $brand }}</td></tr>
    <tr><td style="padding:0 28px;font-size:15px;line-height:1.5">Your sign-in code is:</td></tr>
    <tr><td style="padding:16px 28px;font-size:34px;font-weight:bold;letter-spacing:8px;color:#0e7bb6">{{ $code }}</td></tr>
    <tr><td style="padding:0 28px 28px;font-size:14px;line-height:1.5;color:#6b7075">
      It expires in {{ $minutes }} minutes. We sent the same code to your phone by text — use whichever arrives first.<br><br>
      If you didn't try to sign in, you can ignore this email. Never share this code with anyone, including someone claiming to be from {{ $brand }}.
    </td></tr>
  </table>
</body>
</html>
