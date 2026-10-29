<!doctype html>
<html><body style="font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#111;max-width:520px;margin:0 auto;padding:16px">
  <h2 style="margin:0 0 12px">{{ $title }}</h2>
  @foreach ($lines as $line)
    <p style="margin:0 0 10px">{{ $line }}</p>
  @endforeach
  <p style="color:#777;font-size:13px;margin-top:20px">Ride Coop</p>
</body></html>
