<!doctype html>
<html><body style="font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#111;max-width:520px;margin:0 auto;padding:16px">
  <h2 style="margin:0 0 4px">Thanks for riding with Ride Coop</h2>
  <p style="color:#555;margin:0 0 16px">{{ $trip->completed_at?->setTimezone($tz)->format('D j M Y, g:i a') }}</p>
  <p style="margin:0 0 4px"><strong>From:</strong> {{ $trip->pickup_address ?? 'Pickup' }}</p>
  <p style="margin:0 0 16px"><strong>To:</strong> {{ $trip->dropoff_address ?? 'Drop-off' }}</p>
  <table style="width:100%;border-collapse:collapse">
    @foreach (($trip->fare_breakdown['lines'] ?? []) as $line)
      <tr><td style="padding:4px 0">{{ $line['label'] }}</td><td style="text-align:right">{{ number_format($line['amount'], 2) }}</td></tr>
    @endforeach
    <tr><td style="padding:8px 0;border-top:1px solid #ddd"><strong>Total</strong></td>
        <td style="text-align:right;border-top:1px solid #ddd"><strong>{{ $trip->currency }} {{ number_format($trip->final_fare, 2) }}</strong></td></tr>
  </table>
  <p style="color:#555">Paid by {{ $trip->payment_method->value }}. Driver: {{ $trip->driver?->name }}{{ $trip->vehicle ? ' · '.$trip->vehicle->plate : '' }}</p>
</body></html>
