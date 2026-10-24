@extends('app.layout')
@section('title', 'Statement')
@section('content')
  <h1>Statement</h1>
  <div class="card" style="text-align:center">
    <div class="muted small">{{ $balance >= 0 ? 'Ride Coop owes you' : 'You owe Ride Coop' }}</div>
    <div style="font-size:34px;font-weight:700;color:{{ $balance >= 0 ? 'var(--ok)' : 'var(--red)' }}">BBD {{ number_format(abs($balance), 2) }}</div>
    <div class="muted small">{{ $balance >= 0 ? 'Card rides, deliveries and tips, less payouts.' : 'Ride Coop\'s share of cash rides you collected, less what you\'ve paid.' }}</div>
  </div>
  <div class="card">
    <a class="row" href="/app/statement/bank">
      <span><strong>Payout account</strong><br><span class="muted small">
        @if ($profile?->bank_account_number) {{ $profile->bank_name }} · ••••{{ substr(preg_replace('/\D/', '', $profile->bank_account_number), -4) }} @else Add your bank account to get paid @endif
      </span></span><span class="chev"></span>
    </a>
  </div>
  <h2>Recent activity</h2>
  @if ($entries->isEmpty())
    <p class="muted">Nothing yet — your rides and deliveries show here.</p>
  @else
    <div class="card"><table>
      @foreach ($entries as $e)
        <tr>
          <td>{{ $e->description }}<br><span class="muted small">{{ $e->created_at?->setTimezone($tz)->format('j M, g:i a') }}</span></td>
          <td class="num" style="color:{{ $e->amount >= 0 ? 'var(--ok)' : 'var(--red)' }}">{{ $e->amount >= 0 ? '+' : '−' }}{{ number_format(abs($e->amount), 2) }}</td>
        </tr>
      @endforeach
    </table></div>
  @endif
  <p class="muted small">Questions about a payout? <a href="/app/support/new?category=payment">Contact support</a></p>
@endsection
